<?php
/**
 * Finds the code for a step.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a job type and step name to its implementation. The composition root builds the steps
 * with their dependencies; tests provide scripted ones.
 */
interface StepProvider {

	/**
	 * Returns the step.
	 *
	 * @param JobType $type Job type.
	 * @param string  $name Step name from JobType::steps() or cleanup_steps().
	 * @return Step Step.
	 */
	public function step( JobType $type, string $name ): Step;
}
