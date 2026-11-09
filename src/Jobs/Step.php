<?php
/**
 * One step of a job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A resumable piece of a job. advance() works until the step is done or the deadline has
 * passed, keeping everything it needs for the next tick in the job document or in files.
 */
interface Step {

	/**
	 * Advances the step.
	 *
	 * @param Job      $job      Job; the step may update progress, result, data and warnings.
	 * @param EventLog $log      The job's log.
	 * @param float    $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when the step is done, false when it needs another tick.
	 */
	public function advance( Job $job, EventLog $log, float $deadline ): bool;
}
