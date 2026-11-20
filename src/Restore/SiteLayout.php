<?php
/**
 * Maps the content of a verified site snapshot onto a fresh WordPress installation.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Engine\Restore\Selection;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobType;

defined( 'ABSPATH' ) || exit;

/**
 * The snapshot supplies only original paths. The receiving WordPress installation supplies
 * both writable destinations. An ordinary restore continues to use its original layout.
 *
 * @phpstan-type Binding array{snapshot: string, content: string, uploads: string, private: string}
 */
final readonly class SiteLayout {

	/**
	 * Creates a mapping between two installations.
	 *
	 * @param Binding $binding Verified snapshot metadata.
	 * @param string  $content Content root on the receiving site.
	 * @param string  $uploads Uploads root on the receiving site.
	 */
	private function __construct( private array $binding, private string $content, private string $uploads ) {
	}

	/**
	 * Records which snapshot paths can supply content, uploads and the database. The
	 * caller obtains the manifest from the authenticated snapshot reader.
	 *
	 * @param string               $snapshot Requested snapshot.
	 * @param array<string, mixed> $manifest Verified site manifest.
	 * @param string               $locator Manifest path inside the snapshot.
	 * @return Binding Checked binding.
	 * @throws InvalidArgument If the layout cannot safely be imported.
	 */
	public static function bind( string $snapshot, array $manifest, string $locator ): array {
		if ( true !== ( $manifest['database'] ?? null ) || ! str_ends_with( $locator, '/spool/manifest.json' ) ) {
			throw new InvalidArgument( 'Fresh installation requires a site snapshot with a database and a known manifest location.' );
		}
		return self::validated( $snapshot, $manifest['content'] ?? null, $manifest['uploads'] ?? null, dirname( $locator, 2 ) );
	}

	/**
	 * Selects the original paths to stage. The database and manifest travel with the
	 * content even when private storage or uploads were outside the site's content root.
	 *
	 * @param Binding $binding Verified source layout.
	 * @return list<string> Canonical selection.
	 */
	public static function selection( array $binding ): array {
		return ( new Selection( [ $binding['content'], $binding['uploads'], $binding['private'] . '/spool' ] ) )->paths;
	}

	/**
	 * Opens the source layout saved by the restore job.
	 *
	 * @param Job    $restore Completed restore job.
	 * @param string $current_content Receiving site's configured content directory.
	 * @param string $current_uploads Receiving site's configured uploads directory.
	 * @return self|null Layout, or null for an ordinary staged selection.
	 * @throws InvalidArgument If the job or either installation has ambiguous roots.
	 */
	public static function from_job( Job $restore, string $current_content, string $current_uploads ): ?self {
		$fresh = $restore->data['fresh_install'] ?? false;
		if ( false === $fresh ) {
			return null;
		}
		$data = $restore->data['site_layout'] ?? null;
		if ( true !== $fresh || JobType::Restore !== $restore->type || ! is_array( $data ) || ! is_string( $data['snapshot'] ?? null ) || $data['snapshot'] !== ( $restore->data['snapshot'] ?? null ) ) {
			throw new InvalidArgument( 'The fresh-install restore has no matching site layout.' );
		}
		$binding = self::validated( $data['snapshot'], $data['content'] ?? null, $data['uploads'] ?? null, $data['private'] ?? null );
		$content = self::root( $current_content );
		$uploads = self::root( $current_uploads );
		if ( ( $binding['content'] === $binding['uploads'] ) !== ( $content === $uploads ) ) {
			throw new InvalidArgument( 'These content and upload directories cannot be uniquely mapped between installations.' );
		}
		return new self( $binding, $content, $uploads );
	}

	/**
	 * Determines where a staged path can appear in the receiving site's content tree.
	 * Data used only for the database and configuration is never a live file candidate.
	 *
	 * @param string $source Original absolute path.
	 * @return string|null Live path, or null outside the import.
	 * @throws InvalidArgument If two source paths would claim the same live location.
	 */
	public function destination( string $source ): ?string {
		Path::parts( $source );
		if ( self::under( $source, $this->binding['private'] ) || 'wp-config.php' === strtolower( basename( $source ) ) ) {
			return null;
		}
		$target = self::project( $source, $this->binding['content'], $this->binding['uploads'], $this->content, $this->uploads );
		if ( null !== $target && self::project( $target, $this->content, $this->uploads, $this->binding['content'], $this->binding['uploads'] ) !== $source ) {
			throw new InvalidArgument( 'Two snapshot locations would map to the same live file.' );
		}
		return $target;
	}

	/**
	 * Whether traversal of an unmapped directory can reach selected content.
	 *
	 * @param string $source Canonical snapshot directory path.
	 * @return bool Whether to inspect children.
	 */
	public function is_ancestor( string $source ): bool {
		Path::parts( $source );
		$prefix = rtrim( $source, '/' ) . '/';
		return str_starts_with( $this->binding['content'], $prefix ) || str_starts_with( $this->binding['uploads'], $prefix );
	}

	/**
	 * Checks the portable shape of a binding whenever it crosses a storage boundary.
	 *
	 * @param string $snapshot Snapshot identifier.
	 * @param mixed  $content Original content directory.
	 * @param mixed  $uploads Original uploads directory.
	 * @param mixed  $private_dir Original private working directory.
	 * @return Binding Checked fields.
	 * @throws InvalidArgument If data or ranges are invalid.
	 */
	private static function validated( string $snapshot, mixed $content, mixed $uploads, mixed $private_dir ): array {
		$content     = self::root( $content );
		$uploads     = self::root( $uploads );
		$private_dir = self::root( $private_dir );
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $snapshot ) || self::under( $content, $private_dir ) || self::under( $uploads, $private_dir ) ) {
			throw new InvalidArgument( 'The site snapshot has an invalid identifier or overlapping private directory.' );
		}
		return [
			'snapshot' => $snapshot,
			'content'  => $content,
			'uploads'  => $uploads,
			'private'  => $private_dir,
		];
	}

	/**
	 * Normalizes one optional trailing slash and rejects ambiguous or overly broad roots.
	 *
	 * @param mixed $path Manifest or configuration value.
	 * @return string Absolute, non-root path.
	 * @throws InvalidArgument If the directory is ambiguous.
	 */
	private static function root( mixed $path ): string {
		if ( ! is_string( $path ) || [] === Path::parts( $path ) ) {
			throw new InvalidArgument( 'Fresh installation requires absolute content, upload and private subdirectories.' );
		}
		return rtrim( $path, '/' );
	}

	/**
	 * Applies the longest matching source root, respecting component boundaries.
	 *
	 * @param string $path Path in the original namespace.
	 * @param string $content Original content root.
	 * @param string $uploads Original uploads root.
	 * @param string $to_content Corresponding content root.
	 * @param string $to_uploads Corresponding uploads root.
	 * @return string|null Projected path.
	 */
	private static function project( string $path, string $content, string $uploads, string $to_content, string $to_uploads ): ?string {
		$in_content = self::under( $path, $content );
		if ( self::under( $path, $uploads ) && ( ! $in_content || strlen( $uploads ) >= strlen( $content ) ) ) {
			return $to_uploads . substr( $path, strlen( $uploads ) );
		}
		return $in_content ? $to_content . substr( $path, strlen( $content ) ) : null;
	}

	/**
	 * Checks equality or descent from a complete path component.
	 *
	 * @param string $path Path being mapped.
	 * @param string $root Allowed root.
	 * @return bool Whether contained.
	 */
	private static function under( string $path, string $root ): bool {
		return $path === $root || str_starts_with( $path, $root . '/' );
	}
}
