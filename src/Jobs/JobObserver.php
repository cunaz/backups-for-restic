<?php
/**
 * Hears about job state changes.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Called when a job starts and when it ends, after the job document is saved. The WordPress
 * layer fires its actions and sends notifications from here; the runner stays unaware of both.
 */
interface JobObserver {

	/**
	 * A job changed its state.
	 *
	 * @param Job      $job      The job, in its new state.
	 * @param JobState $previous The state before.
	 */
	public function state_changed( Job $job, JobState $previous ): void;
}
