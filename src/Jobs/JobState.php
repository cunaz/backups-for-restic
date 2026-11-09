<?php
/**
 * Lifecycle of a job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Where a job is in its life (docs/02-architecture.md §4.4).
 */
enum JobState: string {

	case Queued    = 'queued';
	case Running   = 'running';
	case Succeeded = 'succeeded';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';

	/**
	 * Whether the job has ended.
	 *
	 * @return bool True for succeeded, failed and cancelled.
	 */
	public function is_terminal(): bool {
		return in_array( $this, [ self::Succeeded, self::Failed, self::Cancelled ], true );
	}
}
