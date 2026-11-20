<?php
/**
 * Continuous WordPress maintenance mode during a live file job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/** Publishes its marker exclusively and leaves another updater's marker untouched. */
final readonly class FileMaintenance {

	/**
	 * Names the live WordPress root.
	 *
	 * @param string $root WordPress installation directory.
	 * @param string $plugin Plugin basename.
	 * @param string $api Namespace URL of this installation.
	 * @param string $admin Admin page URL.
	 */
	public function __construct( private string $root, private string $plugin = 'backups-for-restic/backups-for-restic.php', private string $api = '', private string $admin = '' ) {
	}

	/**
	 * Takes or refreshes the same marker without an unprotected gap between ticks. Core
	 * recovers from an abandoned marker ten minutes after its last refresh.
	 *
	 * @param string $id Job ID.
	 * @param string $directory Private job directory.
	 * @param string $grant SHA-256 of the scoped control token, empty for CLI.
	 * @throws StorageFailed If the job cannot take or refresh maintenance.
	 */
	public function enter( string $id, string $directory, string $grant ): void {
		$this->require_directory( $id, $directory );
		if ( '' !== $grant && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $grant ) ) {
			throw new StorageFailed( 'The maintenance control grant is invalid.' );
		}
		$marker = $this->root . '/.maintenance';
		$temp   = $directory . '/maintenance.php';
		clearstatcache( true );
		$gate = dirname( __DIR__ ) . '/WordPress/FileMaintenanceGate.php';
		// Every value comes from the authorized job or site configuration. var_export
		// produces PHP string literals even when a path contains quote characters.
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generate quoted PHP literals for the core maintenance file.
		$args   = array_map( static fn ( string $value ): string => var_export( $value, true ), [ $id, $grant, $this->plugin, $this->api, $this->admin ] );
		$script = '<?php $upgrading = (float) filemtime(__FILE__); if (is_file(' . var_export( $gate, true ) . ')) { require_once ' . var_export( $gate, true ) . '; $upgrading = \BackupsForRestic\WordPress\FileMaintenanceGate::stamp(' . implode( ',', $args ) . ',(int) $upgrading); }' . "\n";
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$proof = $directory . '/maintenance.sha256';
		if ( ! is_file( $temp ) ) {
			if ( file_exists( $marker ) || is_link( $marker ) ) {
				throw new StorageFailed( 'Another updater owns the maintenance marker.' );
			}

			Files::write_atomic( $proof, hash( 'sha256', $script ) );
			Files::write_atomic( $temp, $script );
		}
		$ours = is_file( $temp ) && ! is_link( $temp ) ? lstat( $temp ) : false;
		if ( false === $ours || 0100000 !== ( $ours['mode'] & 0170000 ) || strlen( $script ) !== $ours['size'] || ! hash_equals( hash( 'sha256', $script ), (string) hash_file( 'sha256', $temp ) ) ) {
			throw new StorageFailed( 'The private maintenance marker is unavailable.' );
		}
		if ( ! file_exists( $marker ) && ! is_link( $marker ) && ( ! function_exists( 'link' ) || ! link( $temp, $marker ) ) ) {
			throw new StorageFailed( 'Maintenance requires writable WordPress and private storage on the same filesystem.' );
		}
		clearstatcache( true );
		$active = is_file( $marker ) && ! is_link( $marker ) ? lstat( $marker ) : false;
		if ( false === $active || $ours['dev'] !== $active['dev'] || $ours['ino'] !== $active['ino'] || ! touch( $temp ) ) {
			throw new StorageFailed( 'The active WordPress maintenance marker has changed.' );
		}
	}

	/**
	 * Clears only the inode hard-linked to this job's private marker. A killed worker
	 * leaves its private name intact for a later tick, including cancellation cleanup.
	 *
	 * @param string $id Job identifier.
	 * @param string $directory Private job directory.
	 * @throws StorageFailed If an owned maintenance marker cannot be removed.
	 */
	public function release( string $id, string $directory ): void {
		$this->require_directory( $id, $directory );
		$temp   = $directory . '/maintenance.php';
		$marker = $this->root . '/.maintenance';
		clearstatcache( true );
		if ( ! is_file( $temp ) || is_link( $temp ) ) {
			return;
		}
		$ours      = lstat( $temp );
		$proof     = $directory . '/maintenance.sha256';
		$expected  = is_file( $proof ) && ! is_link( $proof ) ? file_get_contents( $proof, false, null, 0, 65 ) : false;
		$unchanged = is_string( $expected ) && false !== $ours && $ours['size'] < 8192 && hash_equals( $expected, (string) hash_file( 'sha256', $temp ) );
		$active    = is_file( $marker ) && ! is_link( $marker ) ? lstat( $marker ) : false;
		if ( $unchanged && false !== $active && false !== $ours && $ours['dev'] === $active['dev'] && $ours['ino'] === $active['ino'] && ! unlink( $marker ) ) {
			throw new StorageFailed( 'The site could not leave maintenance mode.' );
		}
		if ( ! unlink( $temp ) ) {
			throw new StorageFailed( 'The private maintenance marker could not be removed.' );
		}
	}
	/**
	 * Checks local paths before using a job-owned marker.
	 *
	 * @param string $id Job ID.
	 * @param string $directory Canonical job directory.
	 * @throws StorageFailed If the job or installation directory is ambiguous.
	 */
	private function require_directory( string $id, string $directory ): void {
		if ( 1 !== preg_match( JobStore::ID_PATTERN, $id ) || basename( $directory ) !== $id || realpath( $directory ) !== $directory || realpath( $this->root ) !== $this->root ) {
			throw new StorageFailed( 'The maintenance job identity or root is invalid.' );
		}
	}
}
