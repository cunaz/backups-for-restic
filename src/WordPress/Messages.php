<?php
/**
 * Translated texts for the codes of the core.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Doctor\Check;

defined( 'ABSPATH' ) || exit;

/**
 * The core reports stable codes; this class turns them into translated sentences with a hint
 * for what to do (docs/02-architecture.md §14). Everything an administrator reads passes
 * through here or through the admin templates.
 */
final class Messages {

	/**
	 * Message and hint for a job error code.
	 *
	 * @param string $code Error code.
	 * @return array{message: string, hint: string} Texts.
	 */
	public static function job_error( string $code ): array {
		return match ( $code ) {
			'wrong_password'                 => [
				'message' => __( 'The repository password is wrong.', 'backups-for-restic' ),
				'hint'    => __( 'Enter the password from your recovery kit in the settings.', 'backups-for-restic' ),
			],
			'authentication_failed'          => [
				'message' => __( 'A file in the repository failed its integrity check.', 'backups-for-restic' ),
				'hint'    => __( 'The repository may be damaged or tampered with. Run "restic check" from another computer before you continue.', 'backups-for-restic' ),
			],
			'repository_damaged'             => [
				'message' => __( 'The repository contains data the plugin cannot read.', 'backups-for-restic' ),
				'hint'    => __( 'Start a repository check (on the dashboard, or with "wp backups-for-restic check"), or run "restic check" from another computer, to find out more.', 'backups-for-restic' ),
			],
			'no_snapshots'                   => [
				'message' => __( 'The repository lists no snapshots, so prune did not delete anything.', 'backups-for-restic' ),
				'hint'    => __( 'An empty listing must never mean "delete everything". Check the repository URL.', 'backups-for-restic' ),
			],
			'retention_external'             => [
				'message' => __( 'Old snapshots are cleaned up from another computer (retention mode "external").', 'backups-for-restic' ),
				'hint'    => __( 'The repository is or was append-only. Switch retention back to the plugin in the settings if deletions are allowed again.', 'backups-for-restic' ),
			],
			'repository_unsupported_version' => [
				'message' => __( 'This repository uses compression, which needs the zstd PHP extension.', 'backups-for-restic' ),
				'hint'    => __( 'Ask your host for the zstd extension, or use a repository created by this plugin.', 'backups-for-restic' ),
			],
			'repository_locked'              => [
				'message' => __( 'Another program holds a lock on the repository.', 'backups-for-restic' ),
				'hint'    => __( 'The plugin tries again automatically. If nothing else uses the repository, stale locks expire after 30 minutes.', 'backups-for-restic' ),
			],
			'repository_changed'             => [
				'message' => __( 'Another program kept changing the repository while this job worked on it.', 'backups-for-restic' ),
				'hint'    => __( 'The job started over three times and then stopped. If other computers use this repository, let them work at a time when no job of this site is running, and make sure the scheduled jobs of this site are not paused for long.', 'backups-for-restic' ),
			],
			'repository_append_only'         => [
				'message' => __( 'The server refused to delete data because the repository is append-only.', 'backups-for-restic' ),
				'hint'    => __( 'That protects your backups. Clean up old snapshots from another computer.', 'backups-for-restic' ),
			],
			'file_plan_too_large'            => [
				'message' => __( 'Online review supports up to one million selected entries and up to one million names in each folder.', 'backups-for-restic' ),
				'hint'    => __( 'Review a smaller selection. If a single folder exceeds the limit, restore its files offline with restic.', 'backups-for-restic' ),
			],
			'file_space'                     => [
				'message' => __( 'There is too little disk space to complete these file changes.', 'backups-for-restic' ),
				'hint'    => __( 'Free space on the destination, then open a new preview to check the estimate and available space.', 'backups-for-restic' ),
			],
			'protected_path_invalid'         => [
				'message' => __( 'A WordPress location that file apply protects is not an absolute path without "." or ".." segments.', 'backups-for-restic' ),
				'hint'    => __( 'Check WP_CONTENT_DIR, WPMU_PLUGIN_DIR and BACKUPS_FOR_RESTIC_PRIVATE_DIR in wp-config.php, then open the preview again. The staged files remain available for download.', 'backups-for-restic' ),
			],
			'index_too_large'                => [
				'message' => __( 'PHP has too little memory available for this job.', 'backups-for-restic' ),
				'hint'    => __( 'WordPress, other plugins, the repository index and working buffers share PHP\'s memory limit. Ask your host to increase memory_limit. This can also happen with an empty repository when other code already uses most of the allowance.', 'backups-for-restic' ),
			],
			'decompression_limit'            => [
				'message' => __( 'A repository object is too large for PHP to decompress safely.', 'backups-for-restic' ),
				'hint'    => __( 'For a trusted repository, ask your host to increase the PHP memory limit. You can also use restic on another computer to verify the repository and restore the snapshot.', 'backups-for-restic' ),
			],
			'quota_exceeded'                 => [
				'message' => __( 'The repository server has no space left.', 'backups-for-restic' ),
				'hint'    => __( 'Increase the quota of the repository or remove old snapshots.', 'backups-for-restic' ),
			],
			'upload_rejected'                => [
				'message' => __( 'The server rejected an upload because its content did not match its name.', 'backups-for-restic' ),
				'hint'    => __( 'This usually means a network device changed the data. The next backup tries again.', 'backups-for-restic' ),
			],
			'unauthorized'                   => [
				'message' => __( 'The repository server rejected the username or password.', 'backups-for-restic' ),
				'hint'    => __( 'Check the REST username and password in the settings.', 'backups-for-restic' ),
			],
			'repository_missing'             => [
				'message' => __( 'No repository was found at the configured address.', 'backups-for-restic' ),
				'hint'    => __( 'Check the repository URL, or create the repository in the setup wizard.', 'backups-for-restic' ),
			],
			'repository_exists'              => [
				'message' => __( 'A repository already exists at this address.', 'backups-for-restic' ),
				'hint'    => __( 'Connect to it with its password instead of creating a new one.', 'backups-for-restic' ),
			],
			'repository_unreachable'         => [
				'message' => __( 'The repository server could not be reached.', 'backups-for-restic' ),
				'hint'    => __( 'The plugin tries again automatically. If this persists, check the URL and whether your host allows outgoing HTTPS.', 'backups-for-restic' ),
			],
			'secrets_undecryptable'          => [
				'message' => __( 'The stored passwords can no longer be decrypted.', 'backups-for-restic' ),
				'hint'    => __( 'The security keys in wp-config.php probably changed. Enter the passwords again.', 'backups-for-restic' ),
			],
			'staging_unverified'             => [
				'message' => __( 'The restore stopped because the privacy of its staging directory could not be verified.', 'backups-for-restic' ),
				'hint'    => __( 'Check the working directory on the Environment tab, or configure BACKUPS_FOR_RESTIC_PRIVATE_DIR outside the web root.', 'backups-for-restic' ),
			],
			'environment'                    => [
				'message' => __( 'This server cannot run the backup right now.', 'backups-for-restic' ),
				'hint'    => __( 'The checks on the Environment tab show what is missing.', 'backups-for-restic' ),
			],
			'database_preview_missing'       => [
				'message' => __( 'The staged selection does not contain the complete database dump.', 'backups-for-restic' ),
				'hint'    => __( 'Select the whole database directory or restore the whole snapshot.', 'backups-for-restic' ),
			],
			'database_restore_prefix'        => [
				'message' => __( 'The database dump uses another table prefix.', 'backups-for-restic' ),
				'hint'    => __( 'Rehearse on a site with the original table prefix. For a manual import, open the snapshot again, stage the database directory with rehearsal disabled, and download the SQL files or request a ZIP.', 'backups-for-restic' ),
			],
			'database_restore_collation'     => [
				'message' => __( 'The destination database cannot use a character set or collation in this snapshot.', 'backups-for-restic' ),
				'hint'    => __( 'Compare the source and destination database versions. Open the snapshot again, stage its database directory with rehearsal disabled, and download the SQL files or request a ZIP for import on a compatible server.', 'backups-for-restic' ),
			],
			'database_restore_privilege'     => [
				'message' => __( 'The database account lacks permission to rehearse this snapshot.', 'backups-for-restic' ),
				'hint'    => __( 'The rehearsal creates, reads, inserts, updates and drops its own temporary InnoDB tables in the WordPress database. Your database administrator can verify the account permissions.', 'backups-for-restic' ),
			],
			'database_restore_format'        => [
				'message' => __( 'The SQL dump could not be verified by the database rehearsal.', 'backups-for-restic' ),
				'hint'    => __( 'The job log explains which part of the dump was refused. Stage the snapshot without a database rehearsal to download its SQL files.', 'backups-for-restic' ),
			],
			'database_restore_failed'        => [
				'message' => __( 'The database rehearsal could not finish.', 'backups-for-restic' ),
				'hint'    => __( 'See the job log for the database operation that stopped. You can stage and download the SQL files separately.', 'backups-for-restic' ),
			],
			'database_restore_packet' => [
				'message' => __( 'A row batch of the snapshot is larger than the database server accepts in one statement.', 'backups-for-restic' ),
				'hint'    => __( 'Ask the host to raise max_allowed_packet on the database server. When the plugin refused a row batch before sending it, the job log names the limit that batch needs. For an import by hand, stage the snapshot’s database directory again with the rehearsal switched off and download the SQL files or request a ZIP; the importing client and the database server both need a packet limit large enough for the largest statement.', 'backups-for-restic' ),
			],
			'database_restore_busy' => [
				'message' => __( 'The database is busy or the restore connection was interrupted.', 'backups-for-restic' ),
				'hint'    => __( 'The job tried again several times from its saved database progress and operation witness, then stopped. When the database server answers and no other client holds long locks on the site tables, start the rehearsal or the database change again.', 'backups-for-restic' ),
			],
			'database_admin_collision' => [
				'message' => __( 'The current administrator cannot be preserved safely.', 'backups-for-restic' ),
				'hint'    => __( 'Check the administrator’s ID, login, email and local role assignments in both installations. Resolve the conflict before starting a new rehearsal.', 'backups-for-restic' ),
			],
			'database_wordpress_version' => [
				'message' => __( 'The snapshot and destination use different WordPress database versions.', 'backups-for-restic' ),
				'hint'    => __( 'Use a destination with a matching WordPress schema, or import the staged SQL into a separate database and run the WordPress upgrade there.', 'backups-for-restic' ),
			],
			'database_review_required' => [
				'message' => __( 'This restore needs a new verified database rehearsal.', 'backups-for-restic' ),
				'hint'    => __( 'Stage the complete database with the rehearsal option, then review its table plan before applying it.', 'backups-for-restic' ),
			],
			'database_exchange_changed' => [
				'message' => __( 'The reviewed database exchange is no longer available.', 'backups-for-restic' ),
				'hint'    => __( 'Open a new review and check the destination, permissions, administrator account and recovery window.', 'backups-for-restic' ),
			],
			'database_exchange_failed' => [
				'message' => __( 'The database could not complete the reviewed table exchange.', 'backups-for-restic' ),
				'hint'    => __( 'Check the job result and log for the recorded generation and the database operation that stopped. Use the staged SQL files for a manual restore if the server cannot support this exchange.', 'backups-for-restic' ),
			],
			'database_exchange_cleanup' => [
				'message' => __( 'The database restore could not finish its cleanup.', 'backups-for-restic' ),
				'hint'    => __( 'Keep the private job directory, reconnect the original database with its restore permissions, and allow the next heartbeat to retry.', 'backups-for-restic' ),
			],
			'database_exchange_sessions' => [
				'message' => __( 'Other connections to the site database did not end during maintenance in several attempts, so the table exchange did not start and the job stopped.', 'backups-for-restic' ),
				'hint'    => __( 'Persistent database connections, long-running WP-CLI processes or another application using the same database account keep connections open. Stop them, then review and confirm the database change again.', 'backups-for-restic' ),
			],
			'performance_schema' => [
				'message' => __( 'The database server does not list every connection, so the table exchange cannot tell whether earlier requests have ended.', 'backups-for-restic' ),
				'hint'    => __( 'The live table exchange needs MySQL 8.0.22 or later with the Performance Schema enabled and enough Performance Schema thread instances for every connection (performance_schema_max_thread_instances), and a database account with the PROCESS privilege. Otherwise use the manual SQL restore procedure.', 'backups-for-restic' ),
			],
			'database_exchange_foreign' => [
				'message' => __( 'A database restore keeps private tables on another database server or in another database.', 'backups-for-restic' ),
				'hint'    => __( 'Backups go on. Before another database restore, drop those tables there by hand and discard the binding with "wp backups-for-restic database-discard" and the ID of that restore job; the command lists the table names.', 'backups-for-restic' ),
			],
			'database_binding_missing' => [
				'message' => __( 'No finished database restore holds a binding with this job ID.', 'backups-for-restic' ),
				'hint'    => __( 'Check the job ID in the job history.', 'backups-for-restic' ),
			],
			'database_binding_verified' => [
				'message' => __( 'The private tables of this database restore are in the connected database, so its binding is not discarded.', 'backups-for-restic' ),
				'hint'    => __( 'The heartbeat removes them when their recovery window ends.', 'backups-for-restic' ),
			],
			'database_exchange_rebuilt' => [
				'message' => __( 'A private table kept for the database undo was rebuilt and has a new physical identity.', 'backups-for-restic' ),
				'hint'    => __( 'OPTIMIZE TABLE, ALTER TABLE … FORCE and TRUNCATE TABLE renumber a table, so the undo cannot prove that it is the original generation. Restore from a snapshot instead; the job lists the tables to check and drop by hand.', 'backups-for-restic' ),
			],
			'database_dump_failed'           => [
				'message' => __( 'The database could not be read.', 'backups-for-restic' ),
				'hint'    => __( 'The log of the job contains the database error.', 'backups-for-restic' ),
			],
			'job_conflict'                   => [
				'message' => __( 'Another backup job is still running.', 'backups-for-restic' ),
				'hint'    => __( 'Wait for it to finish or cancel it.', 'backups-for-restic' ),
			],
			'job_unreadable'                 => [
				'message' => __( 'A stored job cannot be read by this version of the plugin.', 'backups-for-restic' ),
				'hint'    => __( 'Install a plugin version that can read the job. Its files are kept, and new jobs and repository changes stay blocked until it can be read.', 'backups-for-restic' ),
			],
			'configuration'                  => [
				'message' => __( 'The backup is not configured completely.', 'backups-for-restic' ),
				'hint'    => __( 'Finish the setup wizard or check the settings.', 'backups-for-restic' ),
			],
			'setup_incomplete'               => [
				'message' => __( 'The setup is not finished yet.', 'backups-for-restic' ),
				'hint'    => __( 'Finish the setup wizard and confirm that you stored the recovery kit. Backups and maintenance start after that.', 'backups-for-restic' ),
			],
			default                          => [
				'message' => __( 'An unexpected error stopped the job.', 'backups-for-restic' ),
				'hint'    => __( 'The log of the job has the details. Please report it if it happens again.', 'backups-for-restic' ),
			],
		};
	}

	/**
	 * Message and hint for a code of a read of snapshots (Restore\SnapshotBrowser); every
	 * other code is a job error.
	 *
	 * @param string $code Error code.
	 * @return array{message: string, hint: string} Texts.
	 */
	public static function snapshot_error( string $code ): array {
		return match ( $code ) {
			'invalid_snapshot_id'     => [
				'message' => __( 'This is not a snapshot ID.', 'backups-for-restic' ),
				'hint'    => __( 'A snapshot ID has 64 characters: the digits 0 to 9 and the letters a to f. The command line also takes its first 8 or more characters if only one snapshot starts with them.', 'backups-for-restic' ),
			],
			'invalid_snapshot_path'   => [
				'message' => __( 'This is not a path inside a snapshot.', 'backups-for-restic' ),
				'hint'    => __( 'A path starts with a slash and names one directory after the other, without "." and "..".', 'backups-for-restic' ),
			],
			'invalid_snapshot_query'  => [
				'message' => __( 'The request for snapshots has a value that is not allowed.', 'backups-for-restic' ),
				'hint'    => __( 'The scope is "site" or "repository". Offset and limit are whole numbers, and the limit is at least 1.', 'backups-for-restic' ),
			],
			'snapshot_not_found'      => [
				'message' => __( 'The repository has no snapshot with this ID.', 'backups-for-restic' ),
				'hint'    => __( 'It may have been removed since the list was loaded. Load the list of snapshots again.', 'backups-for-restic' ),
			],
			'snapshot_path_not_found' => [
				'message' => __( 'The snapshot has no directory with this path.', 'backups-for-restic' ),
				'hint'    => __( 'The path does not exist in this snapshot, or it is a file or a link. Links are not followed.', 'backups-for-restic' ),
			],
			default                   => self::job_error( $code ),
		};
	}

	/**
	 * Label and description of a Doctor result.
	 *
	 * @param Check $check Result.
	 * @return array{label: string, description: string} Texts.
	 */
	public static function check( Check $check ): array {
		$bytes = is_int( $check->values['bytes'] ?? null ) ? size_format( $check->values['bytes'] ) : '';
		return match ( $check->id . '/' . $check->code ) {
			'extensions/present'                         => self::pair( __( 'Required PHP extensions are present', 'backups-for-restic' ), '' ),
			'extensions/missing'                         => self::pair(
				__( 'Required PHP extensions are missing', 'backups-for-restic' ),
				/* translators: %s: comma-separated list of PHP extensions. */
				sprintf( __( 'Ask your host to enable: %s.', 'backups-for-restic' ), (string) ( $check->values['extensions'] ?? '' ) )
			),
			'memory_limit/enough'                        => self::pair( __( 'The PHP memory limit is sufficient', 'backups-for-restic' ), '' ),
			'memory_limit/low'                           => self::pair(
				__( 'The PHP memory limit is low', 'backups-for-restic' ),
				/* translators: %s: memory size such as 96 MB. */
				sprintf( __( 'Backups work with %s, but 128 MB or more is safer.', 'backups-for-restic' ), $bytes )
			),
			'memory_limit/too_low'                       => self::pair(
				__( 'The PHP memory limit is too low for backups', 'backups-for-restic' ),
				/* translators: %s: memory size such as 64 MB. */
				sprintf( __( 'The limit is %s; at least 96 MB are needed. Your host can raise memory_limit.', 'backups-for-restic' ), $bytes )
			),
			'time_limit/enough'                          => self::pair( __( 'The PHP time limit is sufficient', 'backups-for-restic' ), '' ),
			'time_limit/short'                           => self::pair(
				__( 'The PHP time limit is short', 'backups-for-restic' ),
				__( 'Backups still work, in more and shorter steps.', 'backups-for-restic' )
			),
			'disk_space/unknown', 'disk_space/enough'    => self::pair( __( 'There is enough free disk space', 'backups-for-restic' ), '' ),
			'disk_space/low'                             => self::pair(
				__( 'Free disk space is low', 'backups-for-restic' ),
				/* translators: %s: size such as 200 MB. */
				sprintf( __( 'Only %s are free for caches and uploads in progress.', 'backups-for-restic' ), $bytes )
			),
			'disk_space/too_low'                         => self::pair(
				__( 'Not enough free disk space for backups', 'backups-for-restic' ),
				/* translators: %s: size such as 50 MB. */
				sprintf( __( 'Only %s are free; at least 128 MB are needed.', 'backups-for-restic' ), $bytes )
			),
			'private_directory/unavailable'              => self::pair(
				__( 'The private working directory could not be initialized', 'backups-for-restic' ),
				__( 'Set BACKUPS_FOR_RESTIC_PRIVATE_DIR in wp-config.php to a dedicated, writable directory outside the web root. Use an empty directory for a new installation.', 'backups-for-restic' )
			),
			'private_directory/exposed'                  => self::pair(
				__( 'The working directory can be read from the web', 'backups-for-restic' ),
				__( 'Your web server ignores the protection files. Backups stay disabled until BACKUPS_FOR_RESTIC_PRIVATE_DIR in wp-config.php points to a directory outside the web root.', 'backups-for-restic' )
			),
			'private_directory/outside_web_root'         => self::pair( __( 'The working directory is outside the web root', 'backups-for-restic' ), '' ),
			'private_directory/unverified'               => self::pair(
				__( 'The protection of the working directory could not be verified', 'backups-for-restic' ),
				__( 'The site could not request its own address. Consider a directory outside the web root via BACKUPS_FOR_RESTIC_PRIVATE_DIR.', 'backups-for-restic' )
			),
			'private_directory/protected_inside_web_root' => self::pair(
				__( 'The working directory is protected', 'backups-for-restic' ),
				__( 'It lies inside the web root. A directory outside the web root, set with BACKUPS_FOR_RESTIC_PRIVATE_DIR in wp-config.php, is safer still.', 'backups-for-restic' )
			),
			'secrets/undecryptable'                      => self::pair(
				__( 'The stored passwords cannot be decrypted', 'backups-for-restic' ),
				__( 'The security keys in wp-config.php probably changed. Enter the passwords again.', 'backups-for-restic' )
			),
			'secrets/salts_missing'                      => self::pair(
				__( 'Not all security keys are set in wp-config.php', 'backups-for-restic' ),
				__( 'Without them, a copy of the database is enough to read the stored passwords. Add all eight keys and salts to wp-config.php.', 'backups-for-restic' )
			),
			'secrets/protected'                          => self::pair( __( 'The stored passwords are encrypted', 'backups-for-restic' ), '' ),
			'cron/loopback_blocked'                      => self::pair(
				__( 'The site cannot call itself', 'backups-for-restic' ),
				__( 'Backups then advance only on visits. Set up a cron job in your hosting control panel that requests wp-cron.php every few minutes.', 'backups-for-restic' )
			),
			'cron/wp_cron_disabled'                      => self::pair(
				__( 'WP-Cron is disabled', 'backups-for-restic' ),
				__( 'Make sure a cron job of your host requests wp-cron.php every few minutes.', 'backups-for-restic' )
			),
			'cron/loopback_works'                        => self::pair( __( 'Background processing works', 'backups-for-restic' ), '' ),
			'zstd/needed'                                => self::pair(
				__( 'The zstd PHP extension is missing', 'backups-for-restic' ),
				__( 'This repository uses compression, which the plugin can only read with zstd.', 'backups-for-restic' )
			),
			'jit/enabled'                                => self::pair(
				__( 'The JIT compiler of PHP is switched on', 'backups-for-restic' ),
				__( 'With it, PHP has crashed in the middle of backups. If jobs take long or stop for no visible reason, ask your host to set opcache.jit=disable for this site.', 'backups-for-restic' )
			),
			'jit/off'                                    => self::pair( __( 'The JIT compiler of PHP is off', 'backups-for-restic' ), '' ),
			'repository_server/unreachable'              => self::pair(
				__( 'The repository server did not answer', 'backups-for-restic' ),
				__( 'Jobs try again by themselves. If this stays, ask your host whether outgoing HTTPS connections to the server and its port are allowed.', 'backups-for-restic' )
			),
			'repository_server/reached'                  => self::pair( __( 'The repository server answers', 'backups-for-restic' ), '' ),
			'repository_server/not_tested'               => self::pair( __( 'The repository server was not tested yet', 'backups-for-restic' ), '' ),
			default                                      => self::pair( __( 'Compression support is not needed', 'backups-for-restic' ), '' ),
		};
	}

	/**
	 * Message for a rejected input field.
	 *
	 * @param array<string, scalar|null> $error Error with code.
	 * @return string Message.
	 */
	public static function validation( array $error ): string {
		return match ( $error['code'] ?? '' ) {
			'not_https'            => __( 'The address must start with https://.', 'backups-for-restic' ),
			'credentials_in_url'   => __( 'Enter username and password in their own fields, not in the address.', 'backups-for-restic' ),
			'query_in_url'         => __( 'The address must not contain "?" or "#".', 'backups-for-restic' ),
			'not_borgbase'         => __( 'A BorgBase repository address ends in .repo.borgbase.com. For another server, choose "Own restic REST server".', 'backups-for-restic' ),
			'invalid_url'          => __( 'This is not a valid address.', 'backups-for-restic' ),
			'server_incompatible'  => __( 'The server does not support partial downloads, which restic needs.', 'backups-for-restic' ),
			'defined_in_wp_config' => __( 'This value is set in wp-config.php and cannot be changed here.', 'backups-for-restic' ),
			'out_of_range'         => sprintf(
				/* translators: 1: minimum, 2: maximum. */
				__( 'Enter a number from %1$d to %2$d.', 'backups-for-restic' ),
				(int) ( $error['min'] ?? 0 ),
				(int) ( $error['max'] ?? 0 )
			),
			'too_long'             => __( 'The value is too long.', 'backups-for-restic' ),
			'invalid_characters'   => __( 'The value contains characters that are not allowed.', 'backups-for-restic' ),
			'invalid_duration'     => __( 'Enter a duration such as 2d for two days, 12h for twelve hours or 1y6m; it must not be zero.', 'backups-for-restic' ),
			'retention_floor'      => __( 'Keep every snapshot of at least one day (1d or more), or always keep at least one newest snapshot.', 'backups-for-restic' ),
			'invalid_email'        => __( 'Enter one valid e-mail address per line.', 'backups-for-restic' ),
			default                => __( 'This value is not valid.', 'backups-for-restic' ),
		};
	}

	/**
	 * Name of a job step.
	 *
	 * @param string $step Step.
	 * @param string $type Job type when a step has a type-specific label.
	 * @return string Name.
	 */
	public static function step( string $step, string $type = '' ): string {
		if ( 'file_plan' === $type && 'planning' === $step ) {
			return __( 'Preparing the live file preview', 'backups-for-restic' );
		}
		return match ( $step ) {
			'preflight'          => __( 'Checking the environment', 'backups-for-restic' ),
			'unlocking'          => __( 'Unlocking the repository', 'backups-for-restic' ),
			'backing_up'         => __( 'Backing up files and database', 'backups-for-restic' ),
			'detecting'          => __( 'Checking whether the server allows deletions', 'backups-for-restic' ),
			'forgetting'         => __( 'Removing old snapshots', 'backups-for-restic' ),
			'pruning'            => __( 'Deleting data no snapshot uses', 'backups-for-restic' ),
			'checking'           => __( 'Checking the repository', 'backups-for-restic' ),
			'planning'           => __( 'Counting the files to restore', 'backups-for-restic' ),
			'restoring'          => __( 'Restoring files to the staging directory', 'backups-for-restic' ),
			'applying'           => __( 'Applying the reviewed live file changes', 'backups-for-restic' ),
			'undoing'            => __( 'Restoring the previous live files', 'backups-for-restic' ),
			'importing_database' => __( 'Importing the rehearsed database tables', 'backups-for-restic' ),
			'preparing_database' => __( 'Verifying the database exchange', 'backups-for-restic' ),
			'swapping_database'  => __( 'Switching the live database tables', 'backups-for-restic' ),
			'recording'          => __( 'Recording the result', 'backups-for-restic' ),
			'releasing'          => __( 'Releasing the repository', 'backups-for-restic' ),
			'cleaning_up'        => __( 'Cleaning up', 'backups-for-restic' ),
			default              => __( 'Waiting to start', 'backups-for-restic' ),
		};
	}

	/**
	 * What a finding of the repository check means (ADR 0022).
	 *
	 * @param string $code Finding code.
	 * @return string Description.
	 */
	public static function check_finding( string $code ): string {
		return match ( $code ) {
			'index_unreadable'        => __( 'An index file cannot be read.', 'backups-for-restic' ),
			'pack_in_several_indexes' => __( 'A pack is listed in more than one index file.', 'backups-for-restic' ),
			'pack_missing'            => __( 'A pack that the index lists is missing on the server.', 'backups-for-restic' ),
			'pack_size'               => __( 'A pack has a different size than the index says.', 'backups-for-restic' ),
			'pack_unindexed'          => __( 'No index file lists a pack; "restic prune" removes it.', 'backups-for-restic' ),
			'snapshot_unreadable'     => __( 'A snapshot cannot be read.', 'backups-for-restic' ),
			'blob_missing'            => __( 'A snapshot uses data that is missing from the index.', 'backups-for-restic' ),
			'tree_unreadable'         => __( 'A directory listing of a snapshot cannot be read.', 'backups-for-restic' ),
			'pack_hash'               => __( 'The content of a pack does not match its name.', 'backups-for-restic' ),
			'pack_header'             => __( 'The header of a pack does not match the index.', 'backups-for-restic' ),
			'blob_corrupt'            => __( 'Stored data failed its integrity check.', 'backups-for-restic' ),
			'compressed_unverified'   => __( 'Compressed data could not be verified without the zstd PHP extension.', 'backups-for-restic' ),
			default                   => __( 'The check found a problem.', 'backups-for-restic' ),
		};
	}

	/**
	 * Name of a job type.
	 *
	 * @param string $type Type.
	 * @return string Name.
	 */
	public static function job_type( string $type ): string {
		return match ( $type ) {
			'backup'         => __( 'Backup', 'backups-for-restic' ),
			'unlock'         => __( 'Unlock', 'backups-for-restic' ),
			'forget'         => __( 'Remove old snapshots', 'backups-for-restic' ),
			'prune'          => __( 'Prune', 'backups-for-restic' ),
			'check'          => __( 'Check', 'backups-for-restic' ),
			'restore'        => __( 'Restore', 'backups-for-restic' ),
			'file_plan'      => __( 'Preview file changes', 'backups-for-restic' ),
			'apply'          => __( 'Apply staged files', 'backups-for-restic' ),
			'undo'           => __( 'Undo file changes', 'backups-for-restic' ),
			'database_apply' => __( 'Apply staged database', 'backups-for-restic' ),
			'database_undo'  => __( 'Undo database changes', 'backups-for-restic' ),
			default          => $type,
		};
	}

	/**
	 * Name of a job state.
	 *
	 * @param string $state State.
	 * @return string Name.
	 */
	public static function state( string $state ): string {
		return match ( $state ) {
			'queued'    => __( 'Queued', 'backups-for-restic' ),
			'running'   => __( 'Running', 'backups-for-restic' ),
			'succeeded' => __( 'Succeeded', 'backups-for-restic' ),
			'failed'    => __( 'Failed', 'backups-for-restic' ),
			'cancelled' => __( 'Cancelled', 'backups-for-restic' ),
			default     => $state,
		};
	}

	/**
	 * Builds a label and description pair.
	 *
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @return array{label: string, description: string} Texts.
	 */
	private static function pair( string $label, string $description ): array {
		return [
			'label'       => $label,
			'description' => $description,
		];
	}
}
