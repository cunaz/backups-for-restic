<?php
/**
 * What a tick did.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one tick, used by the drivers to decide whether to chain another one.
 */
final readonly class TickReport {

	/**
	 * Creates the report.
	 *
	 * @param string   $status idle (no job), busy (another runner), waiting (backoff) or advanced.
	 * @param Job|null $job    The job, if any.
	 */
	public function __construct( public string $status, public ?Job $job = null ) {
	}

	/**
	 * Whether a job still needs ticks.
	 *
	 * @return bool True while a job is queued or running.
	 */
	public function has_more_work(): bool {
		return null !== $this->job && ! $this->job->state->is_terminal();
	}

	/**
	 * Whether this process should start the next tick right away: it advanced the job and work
	 * remains. A busy or waiting report must not chain, or every request would start the next
	 * one until the other tick's lease or the back-off ends.
	 *
	 * @return bool True after a tick that did work on an unfinished job.
	 */
	public function should_chain(): bool {
		return 'advanced' === $this->status && $this->has_more_work();
	}
}
