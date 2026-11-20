<?php
/**
 * The plugin's private working directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Where jobs, caches, packs under construction and the scrypt table live (ADR 0007).
 *
 * Created with owner-only permissions and deny rules for Apache and IIS. Deny files are a
 * second line of defence only: the Doctor fetches a probe file over HTTP and blocks setup
 * and backups while the directory is reachable from the web.
 */
final readonly class PrivateDirectory {

	public const string NAME = 'backups-for-restic';

	public const array SUBDIRECTORIES = [ 'jobs', 'cache', 'tmp', 'kdf', 'logs', 'probe', 'restores' ];

	/** Identifies a directory initialized by this plugin before any recursive cleanup. */
	private const string OWNER = '.backups-for-restic';

	private const string INDEX = "<?php\n// Silence is golden.\n";

	private const string HTACCESS = "# Nothing in this directory may be served.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	private const string WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * Uses a directory.
	 *
	 * @param string $path Absolute path without trailing slash.
	 */
	public function __construct( public string $path ) {
	}

	/**
	 * Chooses the location: the constant if defined, otherwise wp-content, otherwise uploads.
	 *
	 * @param string|null $configured  BACKUPS_FOR_RESTIC_PRIVATE_DIR, if defined.
	 * @param string      $content_dir WP_CONTENT_DIR.
	 * @param string      $uploads_dir Base directory of uploads.
	 * @return self Directory.
	 */
	public static function locate( ?string $configured, string $content_dir, string $uploads_dir ): self {
		if ( null !== $configured && '' !== trim( $configured ) ) {
			return new self( rtrim( $configured, '/' ) );
		}
		$preferred = rtrim( $content_dir, '/' ) . '/' . self::NAME;
		if ( is_dir( $preferred ) || is_writable( $content_dir ) ) {
			return new self( $preferred );
		}
		return new self( rtrim( $uploads_dir, '/' ) . '/' . self::NAME );
	}

	/**
	 * Creates the directory, its deny files and subdirectories. Safe to call on every request.
	 *
	 * @throws StorageFailed If the directory cannot be created.
	 */
	public function ensure(): void {
		$umask = umask( 0077 );
		try {
			$this->claim();
			$this->make_directory( $this->path );
			$this->write_if_missing( '.htaccess', self::HTACCESS );
			$this->write_if_missing( 'web.config', self::WEB_CONFIG );
			$this->write_if_missing( 'index.php', self::INDEX );
			foreach ( self::SUBDIRECTORIES as $name ) {
				if ( is_link( $this->subdirectory( $name ) ) ) {
					StorageFailed::raise( 'A private subdirectory is a symbolic link.', [ 'file' => $name ] );
				}
				$this->make_directory( $this->path . '/' . $name );
			}
		} finally {
			umask( $umask );
		}
	}

	/**
	 * Claims an empty directory or recognizes an installation from before the marker existed.
	 * Check before chmod or creating protection files: a mistaken configuration must never
	 * change the site's root or turn a shared cache into this plugin's uninstall target.
	 *
	 * @throws StorageFailed If the configured location is unsuitable.
	 */
	private function claim(): void {
		if ( ! $this->dedicated_path() ) {
			throw new StorageFailed( 'A dedicated private directory is required.' );
		}
		if ( ! is_dir( $this->path ) ) {
			$this->make_directory( $this->path );
		}
		if ( $this->marked() ) {
			return;
		}
		$entries = scandir( $this->path );
		if ( [ '.', '..' ] !== $entries && ! $this->legacy_directory() && ! $this->marked() ) {
			throw new StorageFailed( 'Choose an empty directory for private backup files.' );
		}
		// Empty marker, visible in one operation. A concurrent initializer may have created it.
		$marker = $this->path . '/' . self::OWNER;
		// The exclusive open also refuses dangling symlinks and a concurrently created marker.
		$handle = @fopen( $marker, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent initializer may own this marker.
		if ( false !== $handle ) {
			fclose( $handle );
		}
		if ( ! $this->marked() ) {
			throw new StorageFailed( 'The private directory could not be marked as owned.' );
		}
	}

	/**
	 * Rejects relative paths, dot segments, filesystem roots and aliases of roots before
	 * creating directories, changing their permissions or cleaning an installation.
	 *
	 * @return bool Whether this path can name a dedicated directory.
	 */
	private function dedicated_path(): bool {
		$path = str_replace( DIRECTORY_SEPARATOR, '/', $this->path );
		if ( '' === $path || str_contains( $path, "\0" ) || 1 === preg_match( '~(?:^|/)[.]{1,2}(?:/|$)~', $path ) ) {
			return false;
		}
		if ( ! str_starts_with( $path, '/' ) && ( '\\' !== DIRECTORY_SEPARATOR || 1 !== preg_match( '~^[A-Za-z]:/~', $path ) ) ) {
			return false;
		}
		$real = realpath( $this->path );
		return dirname( $this->path ) !== $this->path && ( false === $real || dirname( $real ) !== $real );
	}

	/**
	 * Whether this installation has recorded ownership.
	 *
	 * @phpstan-impure Other requests can claim this directory between checks.
	 * @return bool True for a regular marker.
	 */
	private function marked(): bool {
		$marker = $this->path . '/' . self::OWNER;
		clearstatcache( true, $marker );
		return ! is_link( $marker ) && is_file( $marker ) && 0 === filesize( $marker );
	}

	/**
	 * Recognizes the previous directory layout by its plugin-specific protection file and
	 * working directories. Unrelated directories may also contain jobs/ or cache/.
	 *
	 * @return bool Whether a previous installation initialized the directory.
	 */
	private function legacy_directory(): bool {
		return is_dir( $this->subdirectory( 'jobs' ) ) && is_dir( $this->subdirectory( 'probe' ) )
			&& ( $this->matches( '.htaccess', self::HTACCESS ) || $this->matches( 'web.config', self::WEB_CONFIG ) );
	}

	/**
	 * Checks a small file for the exact content written by this plugin.
	 *
	 * @param string $name    File name.
	 * @param string $content Expected bytes.
	 * @return bool Whether the file is ours.
	 */
	private function matches( string $name, string $content ): bool {
		$file = $this->path . '/' . $name;
		return ! is_link( $file ) && is_file( $file ) && is_readable( $file ) && strlen( $content ) === filesize( $file ) && $content === file_get_contents( $file );
	}

	/**
	 * Absolute path of a subdirectory.
	 *
	 * @param string $name One of SUBDIRECTORIES.
	 * @return string Path.
	 */
	public function subdirectory( string $name ): string {
		return $this->path . '/' . $name;
	}

	/**
	 * Whether the directory lies inside another one, such as the web root.
	 *
	 * @param string $root Absolute path.
	 * @return bool True if inside or equal.
	 */
	public function is_inside( string $root ): bool {
		$real_path = realpath( $this->path );
		$real_root = realpath( $root );
		return str_starts_with( $this->path . '/', rtrim( $root, '/' ) . '/' )
			|| ( false !== $real_path && false !== $real_root && str_starts_with( $real_path . DIRECTORY_SEPARATOR, rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) );
	}

	/**
	 * Writes a probe file with random content. The Doctor requests its URL; if the content
	 * comes back, the directory is exposed.
	 *
	 * @param RandomSource $random Randomness for name and content.
	 * @return array{file: string, token: string} Absolute path and expected content.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public function write_probe( RandomSource $random ): array {
		$this->ensure();
		$token = bin2hex( $random->bytes( 16 ) );
		$file  = $this->subdirectory( 'probe' ) . '/' . bin2hex( $random->bytes( 8 ) ) . '.txt';
		Files::write_atomic( $file, $token );
		return [
			'file'  => $file,
			'token' => $token,
		];
	}

	/**
	 * The CA certificate that an administrator uploaded for a server with a certificate of its
	 * own authority (ca.pem). Whoever asks expects it to be there: restic cannot reach such a
	 * server without it.
	 *
	 * @return string PEM text.
	 * @throws StorageFailed With the code ca_certificate_missing if the file is missing, unreadable or empty.
	 */
	public function ca_certificate(): string {
		$file = $this->path . '/ca.pem';
		$pem  = is_file( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( false === $pem || '' === trim( $pem ) ) {
			StorageFailed::raise( 'The CA certificate of the repository server is missing from the private directory.', [ 'code' => 'ca_certificate_missing' ] );
		}
		return trim( $pem );
	}

	/**
	 * Deletes everything the plugin put into the directory, then the directory if it is empty.
	 * Only known names are touched, so a directory chosen with BACKUPS_FOR_RESTIC_PRIVATE_DIR that
	 * also holds other files keeps them.
	 */
	public function remove_contents(): void {
		if ( ! $this->dedicated_path() || ! is_dir( $this->path ) || ( ! $this->marked() && ! $this->legacy_directory() ) ) {
			return;
		}
		foreach ( [ ...self::SUBDIRECTORIES, 'spool' ] as $name ) {
			Files::remove_tree( $this->path . '/' . $name );
		}
		foreach ( [
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
			'index.php'  => self::INDEX,
		] as $name => $content ) {
			if ( $this->matches( $name, $content ) ) {
				unlink( $this->path . '/' . $name );
			}
		}
		foreach ( [ 'runner.lock', 'state.lock', 'notify.lock', 'staging.lock', 'ca.pem', self::OWNER ] as $name ) {
			if ( is_file( $this->path . '/' . $name ) ) {
				unlink( $this->path . '/' . $name );
			}
		}
		$entries = is_dir( $this->path ) ? scandir( $this->path ) : false;
		if ( [ '.', '..' ] === $entries && ! is_link( $this->path ) ) {
			rmdir( $this->path );
		}
	}

	/**
	 * Free disk space where the directory lives.
	 *
	 * @return int|null Bytes, or null if the host does not tell.
	 */
	public function free_space(): ?int {
		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $this->path ) : false;
		return false === $free ? null : (int) $free;
	}

	/**
	 * Creates one directory with owner-only permissions.
	 *
	 * @param string $directory Path.
	 * @throws StorageFailed If it cannot be created.
	 */
	private function make_directory( string $directory ): void {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
			StorageFailed::raise( 'The private directory could not be created.', [ 'path' => $directory ] );
		}
		if ( ! chmod( $directory, 0700 ) ) {
			StorageFailed::raise( 'The private directory could not be made owner-only.', [ 'path' => $directory ] );
		}
	}

	/**
	 * Writes a file unless it exists.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @throws StorageFailed If it cannot be written.
	 */
	private function write_if_missing( string $name, string $content ): void {
		$file = $this->path . '/' . $name;
		if ( ! is_file( $file ) ) {
			Files::write_atomic( $file, $content );
		}
	}
}
