<?php
/**
 * WP-CLI commands.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\RestoreRequest;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\SnapshotBrowser;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Manages backups from the command line (docs/02-architecture.md §13.3). Optional: every
 * command has an equivalent in the admin area.
 */
final class CliCommand {

	/**
	 * Registers the command.
	 *
	 * @param Container $container Services.
	 */
	public static function register( Container $container ): void {
		// The maintenance gate recognises the command by this name before WordPress loads.
		\WP_CLI::add_command( FileMaintenanceGate::COMMAND, new self( $container ) );
	}

	/**
	 * Creates the command.
	 *
	 * @param Container $container Services.
	 */
	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Checks whether this server can run backups.
	 */
	public function doctor(): void {
		$blocked = false;
		foreach ( $this->container->doctor( true ) as $check ) {
			$texts   = Messages::check( $check );
			$blocked = $blocked || 'critical' === $check->status;
			\WP_CLI::line( sprintf( '[%s] %s %s', $check->status, $texts['label'], $texts['description'] ) );
		}
		if ( $blocked ) {
			\WP_CLI::error( __( 'This server cannot run backups yet.', 'backups-for-restic' ) );
		}
		\WP_CLI::success( __( 'This server can run backups.', 'backups-for-restic' ) );
	}

	/**
	 * Prints repository credentials and the commands for offline recovery.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Allow the complete kit to be written to a file or pipe.
	 *
	 * @subcommand recovery-kit
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function recovery_kit( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['force'] ) && ( ! function_exists( 'stream_isatty' ) || ! stream_isatty( STDOUT ) ) ) {
			\WP_CLI::error( __( 'The recovery kit contains credentials. Use an interactive terminal or pass --force to write it to a private destination.', 'backups-for-restic' ) );
		}
		if ( get_current_user_id() > 0 && ! RestRoutes::allowed() ) {
			\WP_CLI::error( __( 'The selected WordPress user cannot manage backups.', 'backups-for-restic' ) );
		}
		if ( ! $this->container->settings()->bool( 'setup.completed' ) ) {
			\WP_CLI::error( __( 'Complete the backup setup before exporting the recovery kit.', 'backups-for-restic' ) );
		}
		try {
			\WP_CLI::line( RecoveryKit::deliver( $this->container, 'cli' ) );
		} catch ( PluginException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Starts a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the backup in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function backup( array $args, array $assoc_args ): void {
		$this->start_job( JobType::Backup, $assoc_args );
	}

	/**
	 * Removes this site's snapshots that the retention policy no longer keeps.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function forget( array $args, array $assoc_args ): void {
		$this->start_job( JobType::Forget, $assoc_args );
	}

	/**
	 * Deletes the packs that no snapshot uses.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function prune( array $args, array $assoc_args ): void {
		$this->start_job( JobType::Prune, $assoc_args );
	}

	/**
	 * Checks the repository and reads a share of its packs.
	 *
	 * ## OPTIONS
	 *
	 * [--read-data-subset=<percent>]
	 * : Share of packs to download and verify, 0 to 100. Default: the setting (2).
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function check( array $args, array $assoc_args ): void {
		$data = [];
		if ( isset( $assoc_args['read-data-subset'] ) ) {
			$percent = filter_var(
				$assoc_args['read-data-subset'],
				FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => 0,
						'max_range' => 100,
					],
				]
			);
			if ( false === $percent ) {
				\WP_CLI::error( __( '--read-data-subset must be a number from 0 to 100.', 'backups-for-restic' ) );
			}
			$data['read_data_subset_percent'] = $percent;
		}
		$this->start_job( JobType::Check, $assoc_args, $data );
	}

	/**
	 * Restores snapshot files into private staging.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot>
	 * : Full snapshot ID or unambiguous prefix of at least eight characters.
	 *
	 * [<path>...]
	 * : Absolute paths in the snapshot. Omit to stage the whole snapshot.
	 *
	 * [--archive]
	 * : Also prepare a ZIP download of the staged files.
	 *
	 * [--database-preview]
	 * : Rehearse importing the selected complete database dump into temporary tables.
	 *
	 * [--wait]
	 * : Run the restore in this process and print the staging directory.
	 *
	 * @param list<string>          $args       Snapshot ID and optional paths.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$snapshot = $args[0] ?? '';
		try {
			if ( 64 !== strlen( $snapshot ) ) {
				$browser = $this->container->snapshot_browser();
				if ( null === $browser ) {
					\WP_CLI::error( Messages::job_error( 'configuration' )['message'] );
				}
				$snapshot = $browser->resolve( $snapshot );
			} else {
				SnapshotBrowser::full_id( $snapshot );
			}
		} catch ( PluginException $e ) {
			\WP_CLI::error( Messages::snapshot_error( SnapshotBrowser::failure( $e )[0] )['message'] );
		}
		try {
			$request = RestoreRequest::from_input( $snapshot, isset( $args[1] ) ? array_slice( $args, 1 ) : null, isset( $assoc_args['archive'] ), isset( $assoc_args['database-preview'] ) );
		} catch ( \BackupsForRestic\Exception\InvalidArgument $e ) {
			\WP_CLI::error( __( 'Choose up to 256 absolute paths inside the snapshot.', 'backups-for-restic' ) );
		}
		$this->start_job( JobType::Restore, $assoc_args, $request->to_data() );
	}

	/**
	 * Creates the requested job and runs or chains it.
	 *
	 * @param JobType               $type       Job type.
	 * @param array<string, string> $assoc_args Options.
	 * @param array<string, mixed>  $data       Job data.
	 */
	private function start_job( JobType $type, array $assoc_args, array $data = [] ): void {
		$this->check_start( $type );
		try {
			// The data is part of the new job document: a later save could meet the job's first tick.
			$job = $this->container->jobs()->create( $type, 'cli', time(), $data );
		} catch ( JobConflict $conflict ) {
			\WP_CLI::error( Messages::job_error( JobError::from_throwable( $conflict )->code )['message'] );
		} catch ( StorageFailed ) {
			\WP_CLI::error( __( 'The job could not be started. Check free space and write permissions in the private working directory.', 'backups-for-restic' ) );
		}
		/* translators: %s: job ID. */
		\WP_CLI::line( sprintf( __( 'Job %s created.', 'backups-for-restic' ), $job->id ) );
		if ( isset( $assoc_args['wait'] ) ) {
			$this->run_until_idle( 300.0 );
			$this->report( $job->id );
			return;
		}
		Cron::chain( $this->container );
	}

	/**
	 * Ends the command with an error if a job of a type may not start now: before the setup is
	 * completed, without a repository, or for forget and prune in retention mode external.
	 *
	 * @param JobType $type Job type.
	 */
	private function check_start( JobType $type ): void {
		$refusal = $type->refusal( $this->container->settings() );
		if ( null !== $refusal ) {
			\WP_CLI::error( Messages::job_error( $refusal )['message'] );
		}
	}

	/**
	 * Advances the active job.
	 *
	 * ## OPTIONS
	 *
	 * [--until-idle]
	 * : Keep going until no job is left.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function tick( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['until-idle'] ) ) {
			$this->run_until_idle( $this->container->tick_budget() );
			return;
		}
		$report = Cron::tick( $this->container, null, false );
		\WP_CLI::line( $report->status . ( null === $report->job ? '' : ' ' . $report->job->id . ' ' . $report->job->state->value . ' ' . $report->job->step ) );
	}

	/**
	 * Shows the last backup and the active job.
	 */
	public function status(): void {
		$last   = $this->container->state()->get( 'last_backup' );
		$active = $this->container->jobs()->active();
		\WP_CLI::line( sprintf( '%s: %s', __( 'Last backup', 'backups-for-restic' ), is_int( $last['finished'] ?? null ) ? gmdate( 'Y-m-d H:i:s', $last['finished'] ) . ' UTC' : '-' ) );
		\WP_CLI::line( sprintf( '%s: %s', __( 'Active job', 'backups-for-restic' ), null === $active ? '-' : $active->id . ' (' . Messages::step( $active->step ) . ')' ) );
	}

	/**
	 * Lists the snapshots in the repository. The host name is whatever the client that wrote
	 * the snapshot chose; it is printed with control characters quoted, as ls prints names.
	 */
	public function snapshots(): void {
		try {
			$repository = $this->container->access()->open_cached();
			if ( null === $repository ) {
				\WP_CLI::error( Messages::job_error( 'configuration' )['message'] );
			}
			foreach ( $repository->snapshots() as $id => $snapshot ) {
				\WP_CLI::line( sprintf( '%s  %s  %s', substr( $id, 0, 8 ), $snapshot->time, SnapshotBrowser::printable( $snapshot->hostname ) ) );
			}
		} catch ( PluginException $e ) {
			\WP_CLI::error( Messages::job_error( JobError::from_throwable( $e )->code )['message'] );
		}
	}

	/**
	 * Lists a directory of a snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot>
	 * : Snapshot ID, or its first 8 or more characters as "snapshots" prints them.
	 *
	 * [<path>]
	 * : Directory inside the snapshot. Default: /.
	 *
	 * ## EXAMPLES
	 *
	 *     wp backups-for-restic ls 1a2b3c4d
	 *     wp backups-for-restic ls 1a2b3c4d /var/www/html/wp-content/uploads
	 *
	 * @param list<string> $args Snapshot and path.
	 */
	public function ls( array $args ): void {
		try {
			$browser = $this->container->snapshot_browser();
			if ( null === $browser ) {
				\WP_CLI::error( Messages::job_error( 'configuration' )['message'] );
			}
			// A terminal takes the whole directory; each line is printed without control characters.
			$page = $browser->directory( $browser->resolve( $args[0] ?? '' ), $args[1] ?? '/', 0, null );
			foreach ( $page['entries'] as $entry ) {
				\WP_CLI::line( SnapshotBrowser::line( $entry ) );
			}
		} catch ( PluginException $e ) {
			\WP_CLI::error( Messages::snapshot_error( SnapshotBrowser::failure( $e )[0] )['message'] );
		}
	}

	/**
	 * Previews file changes for an unexpired staged restore, one JSON object per entry.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : ID of a successful restore job.
	 *
	 * [--include-wp-config]
	 * : Include wp-config.php in the preview.
	 *
	 * @subcommand apply-plan
	 * @param list<string>          $args       Restore job ID.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function apply_plan( array $args, array $assoc_args ): void {
		$id      = $args[0] ?? '';
		$staging = $this->container->staging();
		$hours   = $this->container->settings()->int( 'restore.staging_expiry_hours' );
		$files   = $staging->files_directory( $id, $hours );
		if ( null === $files ) {
			\WP_CLI::error( __( 'This restore has no available staging directory.', 'backups-for-restic' ) );
		}
		$refused = false;
		try {
			$plan   = $this->container->apply_plan( isset( $assoc_args['include-wp-config'] ), $id );
			$report = $staging->open_download( $id, $hours, 'report', '' );
			if ( null === $report ) {
				\WP_CLI::error( __( 'The staging report is unavailable.', 'backups-for-restic' ) );
			}
			try {
				foreach ( ApplyManifest::report( $report ) as $entry ) {
					if ( ! in_array( $entry['type'], [ 'dir', 'file' ], true ) ) {
						$destination = $plan->destination( $entry['path'] );
						\WP_CLI::line(
							(string) wp_json_encode(
								[
									'path'        => GoQuote::quote( $entry['path'] ),
									'action'      => 'skip',
									'destination' => null === $destination ? null : GoQuote::quote( $destination ),
									'reason'      => 'unstaged_special_entry',
								],
								JSON_UNESCAPED_SLASHES
							)
						);
					}
				}
			} finally {
				fclose( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the verified native stream returned by Staging.
			}
			foreach ( $plan->actions( $files, true ) as $entry ) {
				$refused              = $refused || 'refuse' === $entry['action'];
				$entry['path']        = GoQuote::quote( $entry['path'] );
				$entry['destination'] = null === $entry['destination'] ? null : GoQuote::quote( $entry['destination'] );
				\WP_CLI::line( (string) wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
			}
		} catch ( PluginException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		if ( $refused ) {
			\WP_CLI::error( __( 'The preview contains refused entries.', 'backups-for-restic' ) );
		}
	}

	/**
	 * Reviews and reverses the file changes of an apply job. Each entry is reverted only if it
	 * still is as the apply left it; an entry that changed since is kept and listed with its
	 * reason, and stays undoable until the apply's recovery window ends.
	 *
	 * ## OPTIONS
	 *
	 * <apply-job>
	 * : ID of the original apply job.
	 *
	 * [--yes]
	 * : Confirm the displayed undo without a prompt.
	 *
	 * @param list<string>         $args Apply job.
	 * @param array<string,string> $assoc_args Confirmation options.
	 */
	public function undo( array $args, array $assoc_args ): void {
		$id      = $args[0] ?? '';
		$service = $this->container->file_changes();
		try {
			$preview = $service->request_preview( $id, true, false );
			$plan    = null;
			if ( $preview instanceof Job ) {
				$plan = $preview->id;
				$this->run_until_idle( $this->container->tick_budget() );
				$preview = $service->request_preview( $id, true, false, $plan );
			}
			if ( ! $preview instanceof Job && true === ( $preview['undone'] ?? null ) && is_array( $preview['totals'] ?? null ) ) {
				self::undo_totals( $preview['totals'] );
				\WP_CLI::success( __( 'Nothing is left to revert: every entry is as it was before the apply, or was removed since. The apply is now recorded as undone.', 'backups-for-restic' ) );
				return;
			}
			do {
				if ( $preview instanceof Job ) {
					\WP_CLI::error( __( 'The undo preview is still being prepared.', 'backups-for-restic' ) );
				}
				foreach ( $preview['entries'] as $entry ) {
					\WP_CLI::line( (string) wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
				}
				$next = $preview['next_offset'] ?? null;
				if ( null !== $next ) {
					$preview = $service->request_preview( $id, true, false, $plan, $next );
				}
			} while ( null !== $next );
			$totals = $preview['totals'] ?? null;
			if ( is_array( $totals ) ) {
				self::undo_totals( $totals );
				if ( 0 === $totals['restore'] + $totals['remove'] ) {
					$texts = Messages::job_error( 'undo_nothing_to_revert' );
					\WP_CLI::error( $texts['message'] . ' ' . $texts['hint'] );
				}
			}
			if ( ! $preview['applicable'] ) {
				\WP_CLI::error( __( 'The current live files cannot be safely reverted.', 'backups-for-restic' ) );
			}
			\WP_CLI::confirm( __( 'Undo these changes on this WordPress installation?', 'backups-for-restic' ), $assoc_args );
			$job = $service->start_undo( $id, $preview['digest'], '', $plan );
			$this->run_until_idle( $this->container->tick_budget() );
			$this->report( $job->id );
		} catch ( PluginException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Prints the totals of an undo review by action.
	 *
	 * @param array{restore:int,remove:int,keep:int} $totals Totals.
	 */
	private static function undo_totals( array $totals ): void {
		/* translators: 1: files to restore, 2: files and folders to remove, 3: entries to keep. */
		\WP_CLI::line( sprintf( __( 'To restore: %1$d. To remove: %2$d. To keep: %3$d.', 'backups-for-restic' ), $totals['restore'], $totals['remove'], $totals['keep'] ) );
	}

	/**
	 * Forgets a database restore binding whose private tables are on another database server
	 * or in another database. Nothing is dropped: the command lists the tables that a
	 * database administrator removes by hand where they are.
	 *
	 * ## OPTIONS
	 *
	 * <apply-job>
	 * : ID of the database apply job that holds the binding.
	 *
	 * [--yes]
	 * : Discard the binding without a prompt.
	 *
	 * @subcommand database-discard
	 * @param list<string>         $args Apply job.
	 * @param array<string,string> $assoc_args Confirmation options.
	 */
	public function database_discard( array $args, array $assoc_args ): void {
		$id      = $args[0] ?? '';
		$service = $this->container->database_changes();
		try {
			$binding = $service->foreign_binding( $id );
			/* translators: 1: database name, 2: MySQL server UUID. */
			\WP_CLI::line( sprintf( __( 'Private tables of this restore may remain in the database %1$s on the server %2$s:', 'backups-for-restic' ), $binding['database'], $binding['server'] ) );
			foreach ( $binding['tables'] as $table ) {
				\WP_CLI::line( $table );
			}
			\WP_CLI::confirm( __( 'Forget this binding? The plugin drops none of these tables; remove those that exist there by hand.', 'backups-for-restic' ), $assoc_args );
			$lock = $this->container->runner_lock();
			if ( ! $lock->acquire() ) {
				\WP_CLI::error( __( 'Another request is working on the jobs. Try again in a moment.', 'backups-for-restic' ) );
			}
			try {
				$service->discard( $id, get_current_user_id(), 'wp-cli' );
			} finally {
				$lock->release();
			}
		} catch ( PluginException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		\WP_CLI::success( __( 'The database restore binding was discarded.', 'backups-for-restic' ) );
	}

	/**
	 * Shows or changes settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, get or set.
	 *
	 * [<path>]
	 * : The setting, for example schedule.backup.hour.
	 *
	 * [<value>...]
	 * : The new value. Several values make a list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp backups-for-restic settings list
	 *     wp backups-for-restic settings get retention.keep.daily
	 *     wp backups-for-restic settings set retention.keep.daily 14
	 *     wp backups-for-restic settings set backup.extra_roots /var/www/shared /var/www/media
	 *
	 * @param list<string> $args Action, path and values.
	 */
	public function settings( array $args ): void {
		$settings = $this->container->settings();
		$values   = $settings->to_array();
		$action   = $args[0] ?? '';
		$path     = $args[1] ?? '';
		if ( 'list' === $action ) {
			foreach ( $values as $name => $value ) {
				\WP_CLI::line( $name . ' = ' . self::shown( $value ) . ( $settings->is_overridden( $name ) ? ' (wp-config.php)' : '' ) );
			}
			return;
		}
		if ( ! in_array( $action, [ 'get', 'set' ], true ) || ! array_key_exists( $path, $values ) ) {
			\WP_CLI::error( __( 'Usage: settings list, settings get <path>, or settings set <path> <value>. "settings list" names the paths.', 'backups-for-restic' ) );
		}
		if ( 'get' === $action ) {
			\WP_CLI::line( self::shown( $values[ $path ] ) );
			return;
		}
		if ( ! Schema::editable( $path ) ) {
			\WP_CLI::error( __( 'This setting cannot be changed here.', 'backups-for-restic' ) );
		}
		$given = array_slice( $args, 2 );
		if ( [] === $given ) {
			\WP_CLI::error( __( 'Give the new value after the path.', 'backups-for-restic' ) );
		}
		try {
			// One value as it is; several are the lines of a list.
			$saved = $this->container->save_settings( [ $path => implode( "\n", $given ) ] );
		} catch ( ValidationFailed $e ) {
			\WP_CLI::error( Messages::validation( $e->errors()[ $path ] ?? [] ) );
		}
		\WP_CLI::success( $path . ' = ' . self::shown( $saved->to_array()[ $path ] ?? null ) );
	}

	/**
	 * A setting's value as one line of text.
	 *
	 * @param mixed $value Value.
	 * @return string Text.
	 */
	private static function shown( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Cancels a job: the active one, or the one named.
	 *
	 * ## OPTIONS
	 *
	 * [<job>]
	 * : Job ID. Default: the active job.
	 *
	 * @param list<string> $args Job ID.
	 */
	public function cancel( array $args ): void {
		$id = $args[0] ?? $this->container->jobs()->active()?->id;
		if ( null === $id ) {
			\WP_CLI::success( __( 'No job is active.', 'backups-for-restic' ) );
			return;
		}
		$job = $this->container->runner()->cancel( $id, [ 'user' => 'wp-cli' ] );
		if ( null === $job ) {
			\WP_CLI::error( __( 'The job does not exist.', 'backups-for-restic' ) );
		}
		if ( ! $job->state->is_terminal() ) {
			// The cleanup releases the repository lock; do it now if no other tick is at work.
			Cron::tick( $this->container, null, false );
			$job = $this->container->jobs()->get( $id ) ?? $job;
		}
		\WP_CLI::success( $job->id . ' ' . Messages::state( $job->state->value ) . ( $job->state->is_terminal() ? '' : ' ' . __( '(cancel requested; the running step ends first)', 'backups-for-restic' ) ) );
	}

	/**
	 * Prints the log of a job: the latest one, or the one named.
	 *
	 * ## OPTIONS
	 *
	 * [<job>]
	 * : Job ID. Default: the latest job.
	 *
	 * [--lines=<number>]
	 * : How many of the last entries to print. Default: 50.
	 *
	 * @param list<string>          $args       Job ID.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function logs( array $args, array $assoc_args ): void {
		$jobs = $this->container->jobs();
		$job  = isset( $args[0] ) ? $jobs->get( $args[0] ) : ( $jobs->latest( 1 )[0] ?? null );
		if ( null === $job ) {
			\WP_CLI::error( __( 'The job does not exist.', 'backups-for-restic' ) );
		}
		$lines = max( 1, (int) ( $assoc_args['lines'] ?? 50 ) );
		\WP_CLI::line( $job->id . ' ' . Messages::job_type( $job->type->value ) . ' ' . Messages::state( $job->state->value ) );
		foreach ( $jobs->log( $job )->read( $lines ) as $entry ) {
			$context = is_array( $entry['context'] ?? null ) && [] !== $entry['context'] ? ' ' . (string) wp_json_encode( $entry['context'] ) : '';
			\WP_CLI::line( sprintf( '%s %-7s %s%s', gmdate( 'Y-m-d H:i:s', is_int( $entry['time'] ?? null ) ? $entry['time'] : 0 ), is_string( $entry['level'] ?? null ) ? $entry['level'] : '', is_string( $entry['message'] ?? null ) ? $entry['message'] : '', $context ) );
		}
	}

	/**
	 * Ticks until no job is left. While another tick is at work, for example one that a
	 * request to wp-cron.php started, this waits for it: that tick's report names no job, and
	 * the job is not done because somebody else is working on it.
	 *
	 * @param float $budget Seconds per tick.
	 */
	private function run_until_idle( float $budget ): void {
		do {
			$report = $this->container->runner()->tick( $budget );
			if ( null !== $report->job ) {
				\WP_CLI::line( $report->job->state->value . ' ' . Messages::step( $report->job->step ) );
			}
			if ( 'waiting' === $report->status || 'busy' === $report->status ) {
				sleep( 5 );
			}
		} while ( $report->has_more_work() || 'busy' === $report->status );
	}

	/**
	 * Prints how a job ended.
	 *
	 * @param string $id Job ID.
	 */
	private function report( string $id ): void {
		$job = $this->container->jobs()->get( $id );
		if ( null === $job || null !== $job->error ) {
			\WP_CLI::error( Messages::job_error( $job?->error->code ?? 'internal' )['message'] );
		}
		if ( JobState::Succeeded !== $job->state ) {
			\WP_CLI::error( Messages::state( $job->state->value ) );
		}
		$result = $job->result;
		if ( is_string( $result['skipped'] ?? null ) ) {
			\WP_CLI::warning( Messages::job_error( 'append_only' === $result['skipped'] ? 'repository_append_only' : 'retention_external' )['message'] );
			return;
		}
		match ( $job->type ) {
			/* translators: 1: removed snapshots, 2: kept snapshots. */
			JobType::Forget => \WP_CLI::success( sprintf( __( 'Forget finished: %1$d snapshots removed, %2$d kept.', 'backups-for-restic' ), (int) ( $result['removed'] ?? 0 ), (int) ( $result['keep'] ?? 0 ) ) ),
			/* translators: 1: deleted packs, 2: freed size. */
			JobType::Prune  => \WP_CLI::success( sprintf( __( 'Prune finished: %1$d packs deleted, %2$s freed.', 'backups-for-restic' ), (int) ( $result['packs_deleted'] ?? 0 ), size_format( (int) ( $result['bytes_deleted'] ?? 0 ) ) ) ),
			JobType::Check   => $this->report_check( $result ),
			/* translators: %s: restore directory under the private directory. */
			JobType::Restore => \WP_CLI::success( sprintf( __( 'Files staged in %s.', 'backups-for-restic' ), (string) $result['staging'] ) ),
			JobType::Undo => $this->report_undo( $job ),
			/* translators: %s: snapshot ID. */
			default         => \WP_CLI::success( sprintf( __( 'Backup finished: snapshot %s.', 'backups-for-restic' ), substr( (string) ( $result['snapshot'] ?? '' ), 0, 8 ) ) ),
		};
	}

	/**
	 * Prints what an undo did, and every entry it kept with its reason.
	 *
	 * @param Job $job Finished undo.
	 */
	private function report_undo( Job $job ): void {
		$result = $job->result;
		$counts = [];
		$offset = 0;
		do {
			$page = $this->container->file_changes()->kept_page( $job->id, $offset );
			foreach ( $page['entries'] as $entry ) {
				\WP_CLI::line( (string) wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
				$counts[ $entry['reason'] ] = ( $counts[ $entry['reason'] ] ?? 0 ) + 1;
			}
			$offset = $page['next_offset'];
		} while ( null !== $offset );
		foreach ( $counts as $reason => $count ) {
			/* translators: 1: number of kept entries, 2: why they were kept. */
			\WP_CLI::warning( sprintf( _n( 'Kept %1$d entry: %2$s.', 'Kept %1$d entries: %2$s.', $count, 'backups-for-restic' ), $count, Messages::undo_reason( $reason ) ) );
		}
		/* translators: 1: restored files, 2: removed entries, 3: kept entries, 4: entries that had nothing to undo. */
		$summary = sprintf( __( '%1$d restored, %2$d removed, %3$d kept, %4$d unchanged.', 'backups-for-restic' ), (int) ( $result['restored'] ?? 0 ), (int) ( $result['removed'] ?? 0 ), (int) ( $result['kept'] ?? 0 ), (int) ( $result['unchanged'] ?? 0 ) );
		if ( 'undone' === ( $result['apply_state'] ?? null ) ) {
			/* translators: %s: counts of what the undo did. */
			\WP_CLI::success( sprintf( __( 'The apply is fully undone: %s', 'backups-for-restic' ), $summary ) );
			return;
		}
		/* translators: %s: counts of what the undo did. */
		\WP_CLI::success( sprintf( __( 'The apply is partly undone: %s Entries that changed after the apply were kept. They stay undoable until its recovery window ends.', 'backups-for-restic' ), $summary ) );
	}

	/**
	 * Prints the outcome of a check; damage makes the command fail.
	 *
	 * @param array<string, int|string|bool|null> $result Job result.
	 */
	private function report_check( array $result ): void {
		$errors   = (int) ( $result['errors'] ?? 0 );
		$warnings = (int) ( $result['warnings'] ?? 0 );
		/* translators: 1: packs read, 2: blobs verified, 3: warnings. */
		$summary = sprintf( __( '%1$d packs read, %2$d blobs verified, %3$d warnings.', 'backups-for-restic' ), (int) ( $result['packs_read'] ?? 0 ), (int) ( $result['blobs_verified'] ?? 0 ), $warnings );
		if ( $errors > 0 ) {
			/* translators: 1: number of errors, 2: summary. */
			\WP_CLI::error( sprintf( __( 'The check found %1$d errors. %2$s', 'backups-for-restic' ), $errors, $summary ) );
		}
		/* translators: %s: summary. */
		\WP_CLI::success( sprintf( __( 'The check found no errors. %s', 'backups-for-restic' ), $summary ) );
	}
}
