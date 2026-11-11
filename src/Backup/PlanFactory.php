<?php
/**
 * What a backup of this site contains.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backup;

use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Support\ByteSource;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\StringByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plan of one backup (docs/02-architecture.md §6, ADR 0009): the distinct roots
 * ABSPATH, WP_CONTENT_DIR, uploads and extra roots; the excludes; and the virtual spool/
 * tree inside the private directory with the site manifest and the database dump. The rest
 * of the private directory is never backed up.
 */
final class PlanFactory {

	public const string TAG = 'backups-for-restic';

	/**
	 * Folders below WP_CONTENT_DIR left out by default: page caches, update leftovers, and
	 * archives of other backup plugins, which are large and deduplicate badly.
	 */
	public const array DEFAULT_EXCLUDES = [ 'cache', 'upgrade', 'upgrade-temp-backup', 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backups-dup-pro' ];

	private const string CACHEDIR_SIGNATURE = 'Signature: 8a477f597d28d172789f06886806bc55';

	/**
	 * Prepares the plan.
	 *
	 * @param SitePaths                            $site     Site facts.
	 * @param Settings                             $settings Settings.
	 * @param PrivateDirectory                     $private_dir  Private directory.
	 * @param list<string>                         $roots    Roots after filters, in addition to the standard ones.
	 * @param list<string>                         $excludes Exclude patterns after filters.
	 * @param (\Closure(): list<VirtualFile>)|null $database Files of the database dump, or null if disabled.
	 * @param string                               $job_id   Job, recorded as a run tag.
	 * @param int                                  $time     Start of the backup.
	 */
	public function __construct(
		private readonly SitePaths $site,
		private readonly Settings $settings,
		private readonly PrivateDirectory $private_dir,
		private readonly array $roots,
		private readonly array $excludes,
		private readonly ?\Closure $database,
		private readonly string $job_id,
		private readonly int $time
	) {
	}

	/**
	 * The plan.
	 *
	 * @return BackupPlan Plan.
	 */
	public function plan(): BackupPlan {
		$spool    = $this->private_dir->path . '/spool';
		$database = $spool . '/database';
		$this->ensure( $spool );
		if ( null !== $this->database ) {
			$this->ensure( $database );
		}

		$roots  = array_merge( [ $this->site->abspath, $this->site->content_dir, $this->site->uploads_dir ], $this->roots );
		$roots  = array_values( array_filter( $roots, static fn ( string $root ): bool => '' !== $root && is_dir( $root ) ) );
		$inside = false;
		foreach ( $roots as $root ) {
			$inside = $inside || $this->private_dir->is_inside( $root );
		}
		if ( ! $inside ) {
			$roots[] = $spool;
		}

		$private_dir = $this->private_dir->path;
		$patterns    = $this->exclude_patterns();
		$caches      = $this->settings->bool( 'backup.exclude_caches' );
		$exclude     = static function ( string $path, bool $is_dir ) use ( $private_dir, $spool, $patterns, $caches ): bool {
			if ( str_starts_with( $path . '/', $private_dir . '/' ) && $path !== $private_dir ) {
				return $path !== $spool && ! str_starts_with( $path, $spool . '/' );
			}
			if ( $is_dir && $caches && self::is_cache( $path ) ) {
				return true;
			}
			foreach ( $patterns as $pattern ) {
				if ( self::matches( $pattern, $path ) ) {
					return true;
				}
			}
			return false;
		};

		$manifest = $this->manifest( $roots, $patterns );
		$files    = $this->database;
		$time     = $this->time;
		$virtual  = static function ( string $directory ) use ( $spool, $database, $manifest, $files, $time ): array {
			if ( $directory === $spool ) {
				return [ new VirtualFile( 'manifest.json', static fn (): ByteSource => new StringByteSource( $manifest ), $time, 0600 ) ];
			}
			return $directory === $database && null !== $files ? $files() : [];
		};

		return new BackupPlan( $roots, [ self::TAG, $this->site->site_tag(), 'run:' . $this->job_id ], $exclude, $virtual );
	}

	/**
	 * All exclude patterns: defaults below WP_CONTENT_DIR, settings and filters.
	 *
	 * @return list<string> Patterns.
	 */
	private function exclude_patterns(): array {
		$patterns = array_map( fn ( string $name ): string => rtrim( $this->site->content_dir, '/' ) . '/' . $name, self::DEFAULT_EXCLUDES );
		return array_values( array_unique( array_merge( $patterns, $this->settings->list( 'backup.excludes' ), $this->excludes ) ) );
	}

	/**
	 * Whether a pattern matches a path. Patterns starting with "/" match the whole path,
	 * patterns without "/" match the last path element, others match the end of the path.
	 *
	 * @param string $pattern Glob pattern.
	 * @param string $path    Absolute path.
	 * @return bool True if excluded.
	 */
	private static function matches( string $pattern, string $path ): bool {
		if ( str_starts_with( $pattern, '/' ) ) {
			return fnmatch( rtrim( $pattern, '/' ), $path ) || fnmatch( rtrim( $pattern, '/' ) . '/*', $path );
		}
		if ( ! str_contains( $pattern, '/' ) ) {
			return fnmatch( $pattern, basename( $path ) );
		}
		return fnmatch( '*/' . trim( $pattern, '/' ), $path );
	}

	/**
	 * Whether a directory carries a cache marker: CACHEDIR.TAG with the standard signature,
	 * or .nobackup.
	 *
	 * @param string $path Directory.
	 * @return bool True if marked.
	 */
	private static function is_cache( string $path ): bool {
		if ( is_file( $path . '/.nobackup' ) ) {
			return true;
		}
		$tag = is_file( $path . '/CACHEDIR.TAG' ) ? file_get_contents( $path . '/CACHEDIR.TAG', false, null, 0, 43 ) : false;
		return self::CACHEDIR_SIGNATURE === $tag;
	}

	/**
	 * The site manifest (ADR 0009), so that a restore on another layout can map paths.
	 *
	 * @param list<string> $roots    Roots.
	 * @param list<string> $excludes Exclude patterns.
	 * @return string JSON.
	 */
	private function manifest( array $roots, array $excludes ): string {
		return (string) json_encode(
			[
				'format'     => 'backups-for-restic-site/1',
				'created'    => Rfc3339::format( $this->time ),
				'home_url'   => $this->site->home_url,
				'abspath'    => $this->site->abspath,
				'content'    => $this->site->content_dir,
				'uploads'    => $this->site->uploads_dir,
				'roots'      => $roots,
				'excludes'   => $excludes,
				'prefix'     => $this->site->table_prefix,
				'database'   => null !== $this->database,
				'versions'   => [
					'plugin'    => $this->site->plugin_version,
					'wordpress' => $this->site->wordpress_version,
					'php'       => PHP_VERSION,
				],
				'repository' => [ 'format' => $this->settings->int( 'repository.format_version' ) ],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
	}

	/**
	 * Creates a spool directory, which exists only so that the backup walks into it.
	 *
	 * @param string $path Directory.
	 * @throws StorageFailed If the directory cannot be created.
	 */
	private function ensure( string $path ): void {
		if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
			StorageFailed::raise( 'A spool directory could not be created in the private directory.', [ 'directory' => basename( $path ) ] );
		}
	}
}
