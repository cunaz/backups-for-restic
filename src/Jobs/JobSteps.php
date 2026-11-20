<?php
/**
 * Steps of all job types.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Routes maintenance jobs and restore jobs to their steps and everything else to the backup
 * steps.
 */
final class JobSteps implements StepProvider {

	/**
	 * Creates the router.
	 *
	 * @param StepProvider      $backup      Steps of backup and unlock jobs.
	 * @param StepProvider      $maintenance Steps of forget, prune and check jobs.
	 * @param StepProvider      $restore     Steps of restore jobs.
	 * @param StepProvider|null $changes Live file apply and undo steps.
	 */
	public function __construct( private readonly StepProvider $backup, private readonly StepProvider $maintenance, private readonly StepProvider $restore, private readonly ?StepProvider $changes = null ) {
	}

	/**
	 * Returns the step.
	 *
	 * @param JobType $type Job type.
	 * @param string  $name Step name.
	 * @return Step Step.
	 */
	public function step( JobType $type, string $name ): Step {
		if ( in_array( $type, [ JobType::Apply, JobType::Undo ], true ) && null !== $this->changes ) {
			return $this->changes->step( $type, $name );
		}
		if ( JobType::Restore === $type ) {
			return $this->restore->step( $type, $name );
		}
		return $type->is_maintenance() ? $this->maintenance->step( $type, $name ) : $this->backup->step( $type, $name );
	}
}
