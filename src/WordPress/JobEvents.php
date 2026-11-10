<?php
/**
 * Job state changes as WordPress actions and notifications.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobObserver;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Fires the plugin's actions when a job starts or ends (docs/02-architecture.md §13.5) and
 * hands finished jobs to the notifier. The actions carry IDs and codes only, never secrets or
 * repository data.
 */
final class JobEvents implements JobObserver {

	/**
	 * Creates the observer.
	 *
	 * @param Container $container Services.
	 */
	public function __construct( private readonly Container $container ) {
	}

	/**
	 * A job started or ended.
	 *
	 * @param Job      $job      The job, in its new state.
	 * @param JobState $previous The state before.
	 */
	public function state_changed( Job $job, JobState $previous ): void {
		/**
		 * Fires when a job of the backup plugin starts or ends.
		 *
		 * @param string $job_id   Job ID.
		 * @param string $type     backup, unlock, forget, prune or check.
		 * @param string $state    New state: running, succeeded, failed or cancelled.
		 * @param string $previous State before: queued or running.
		 */
		do_action( 'backups_for_restic_job_state_changed', $job->id, $job->type->value, $job->state->value, $previous->value );
		if ( JobType::Backup === $job->type && JobState::Succeeded === $job->state ) {
			/**
			 * Fires after a backup succeeded.
			 *
			 * @param string $job_id   Job ID.
			 * @param string $snapshot ID of the new snapshot.
			 */
			do_action( 'backups_for_restic_backup_succeeded', $job->id, Decoded::string( $job->result['snapshot'] ?? null ) );
		}
		if ( JobType::Backup === $job->type && JobState::Failed === $job->state ) {
			/**
			 * Fires after a backup failed.
			 *
			 * @param string $job_id Job ID.
			 * @param string $code   Error code (docs/02-architecture.md §14).
			 */
			do_action( 'backups_for_restic_backup_failed', $job->id, $job->error->code ?? 'internal' );
		}
		if ( ! $job->state->is_terminal() ) {
			return;
		}
		if ( in_array( $job->type, [ JobType::Backup, JobType::Forget, JobType::Prune ], true ) ) {
			delete_transient( 'backups_for_restic_snapshots' );
		}
		$this->container->notifier()->job_finished( $job, time() );
	}
}
