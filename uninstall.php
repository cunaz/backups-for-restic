<?php
/**
 * Uninstall handler.
 *
 * Removes options, secrets, transients, scheduled events and the plugin's files in the private
 * directory. The repository on the server is never touched (FR-15): the backups stay
 * restorable with the restic command line and the recovery kit.
 *
 * @package BackupsForRestic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	return;
}

require_once __DIR__ . '/src/autoload.php';

foreach ( [ 'backups_for_restic_settings', 'backups_for_restic_secrets', 'backups_for_restic_state', 'backups_for_restic_notifications', 'backups_for_restic_schema_version' ] as $backups_for_restic_option ) {
	delete_option( $backups_for_restic_option );
}
foreach ( [ 'backups_for_restic_snapshots', 'backups_for_restic_poll', 'backups_for_restic_loopback' ] as $backups_for_restic_transient ) {
	delete_transient( $backups_for_restic_transient );
}
wp_clear_scheduled_hook( 'backups_for_restic_heartbeat' );
wp_clear_scheduled_hook( 'backups_for_restic_tick' );

$backups_for_restic_uploads = wp_upload_dir( null, false );
\BackupsForRestic\Storage\PrivateDirectory::locate(
	defined( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) ? \BackupsForRestic\WordPress\Container::constant_string( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) : null,
	WP_CONTENT_DIR,
	$backups_for_restic_uploads['basedir']
)->remove_contents();
