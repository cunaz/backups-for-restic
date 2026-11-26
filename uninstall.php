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
$backups_for_restic_refusal     = [];
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
	$backups_for_restic_clean   = false;
	$backups_for_restic_refusal = $backups_for_restic_failure->context();
}
if ( ! $backups_for_restic_clean ) {
	$backups_for_restic_job = isset( $backups_for_restic_refusal['job'] ) && is_string( $backups_for_restic_refusal['job'] ) ? $backups_for_restic_refusal['job'] : '';
	$backups_for_restic_why = isset( $backups_for_restic_refusal['code'] ) ? $backups_for_restic_refusal['code'] : '';
	// A stored job that cannot be read blocks the deletion until an administrator makes it readable.
	if ( '' !== $backups_for_restic_job && 'job_inaccessible' === $backups_for_restic_why ) {
		/* translators: %s: job ID, which is also the name of the job's folder. */
		wp_die( esc_html( sprintf( __( 'Backups for restic cannot be deleted yet: the PHP user cannot open the stored job %s. Its folder under jobs/ in the private working directory, which has the job\'s ID as its name, or its document job.json there belongs to another user or lacks read or search permission, for example after a WP-CLI command that ran as root or as another user. Give the PHP user back ownership of that folder and of everything in it, with permission to read and search them, or put the job\'s own files back where a link or something else is in their place. Then retry deletion.', 'backups-for-restic' ), $backups_for_restic_job ) ) );
	}
	if ( '' !== $backups_for_restic_job && 'job_unreadable' === $backups_for_restic_why ) {
		/* translators: %s: job ID, which is also the name of the job's folder. */
		wp_die( esc_html( sprintf( __( 'Backups for restic cannot be deleted yet: this version cannot read the stored job %s. Install a plugin version that can read the job, let it finish the job\'s cleanup, then retry deletion.', 'backups-for-restic' ), $backups_for_restic_job ) ) );
	}
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
