<?php
/**
 * Facts about the site that a backup records.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Paths, URL and versions, collected by the WordPress layer so that planning stays testable.
 */
final readonly class SitePaths {

	/**
	 * Describes the site.
	 *
	 * @param string $abspath           ABSPATH without trailing slash.
	 * @param string $content_dir       WP_CONTENT_DIR.
	 * @param string $uploads_dir       Base directory of uploads.
	 * @param string $home_url          Home URL.
	 * @param string $table_prefix      Database table prefix.
	 * @param string $wordpress_version WordPress version.
	 * @param string $plugin_version    Plugin version.
	 */
	public function __construct(
		public string $abspath,
		public string $content_dir,
		public string $uploads_dir,
		public string $home_url,
		public string $table_prefix,
		public string $wordpress_version,
		public string $plugin_version
	) {
	}

	/**
	 * Host name recorded in snapshots: the site's host, not the machine (ADR 0009).
	 *
	 * @return string Host.
	 */
	public function hostname(): string {
		$host = parse_url( $this->home_url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? strtolower( $host ) : 'localhost';
	}

	/**
	 * Tag that identifies the site even when several share a repository.
	 *
	 * @return string Tag such as site:1a2b3c4d5e6f.
	 */
	public function site_tag(): string {
		return 'site:' . substr( hash( 'sha256', $this->home_url ), 0, 12 );
	}
}
