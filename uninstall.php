<?php
/**
 * Uninstall handler.
 *
 * Removes options, secrets, transients, scheduled events and the plugin's files in the private
 * directory. The repository on the server is never touched (FR-15): the backups stay
 * restorable with the restic command line and the recovery kit.
 *
 * This file must stay parseable by old PHP versions, because the refusal below runs on them:
 * everything that needs PHP 8.3 lives in src/.
 *
 * @package BackupsForRestic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Without the cleanup, staged plaintext, retained database tables and the encrypted
// secrets would stay behind with nothing left to expire them; WordPress deletes the plugin
// after this file returns, so it must not return.
if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	wp_die( esc_html__( 'Switch back to PHP 8.3 or later before deleting Backups for restic, so it can safely remove its restored files, the database tables it kept and its stored passwords.', 'backups-for-restic' ) );
}

if ( PHP_INT_SIZE < 8 ) {
	wp_die( esc_html__( 'Switch to 64-bit PHP before deleting Backups for restic so it can safely clean up local restore data.', 'backups-for-restic' ) );
}

require_once __DIR__ . '/src/autoload.php';

$backups_for_restic_container = new \BackupsForRestic\WordPress\Container( __DIR__ . '/backups-for-restic.php' );
// A filter may have moved the working directory since a previous restore.
$backups_for_restic_uploads     = wp_upload_dir( null, false );
$backups_for_restic_directories = [
	$backups_for_restic_container->private_dir(),
	\BackupsForRestic\Storage\PrivateDirectory::locate(
		defined( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) ? \BackupsForRestic\WordPress\Container::constant_string( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) : null,
		WP_CONTENT_DIR,
		$backups_for_restic_uploads['basedir']
	),
];
$backups_for_restic_deadline    = microtime( true ) + 15.0;
$backups_for_restic_paths       = [];
try {
	$backups_for_restic_clean = true;
	foreach ( $backups_for_restic_directories as $backups_for_restic_directory ) {
		$backups_for_restic_path = realpath( $backups_for_restic_directory->path );
		if ( false === $backups_for_restic_path || isset( $backups_for_restic_paths[ $backups_for_restic_path ] ) ) {
			continue;
		}
		$backups_for_restic_paths[ $backups_for_restic_path ] = $backups_for_restic_directory;
		if ( ! $backups_for_restic_container->clean_database_previews( true, $backups_for_restic_deadline, $backups_for_restic_directory ) ) {
			$backups_for_restic_clean = false;
			break;
		}
	}
} catch ( \BackupsForRestic\Exception\PluginException $backups_for_restic_failure ) {
	// The variable is named: a catch without one is a parse error before PHP 8.0.
	$backups_for_restic_clean = false;
}
if ( ! $backups_for_restic_clean ) {
	wp_die( esc_html__( 'Restore cleanup could not finish. Check the job history, database connection and DROP permission. Reactivate the plugin to finish an interrupted job, then retry deletion.', 'backups-for-restic' ) );
}
foreach ( [ 'backups_for_restic_settings', 'backups_for_restic_secrets', 'backups_for_restic_state', 'backups_for_restic_notifications', 'backups_for_restic_schema_version' ] as $backups_for_restic_option ) {
	delete_option( $backups_for_restic_option );
}
foreach ( [ 'backups_for_restic_snapshots', 'backups_for_restic_poll', 'backups_for_restic_loopback', 'backups_for_restic_reachable' ] as $backups_for_restic_transient ) {
	delete_transient( $backups_for_restic_transient );
}
wp_clear_scheduled_hook( 'backups_for_restic_heartbeat' );
wp_clear_scheduled_hook( 'backups_for_restic_tick' );

foreach ( $backups_for_restic_paths as $backups_for_restic_directory ) {
	$backups_for_restic_directory->remove_contents();
}
