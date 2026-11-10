<?php
/**
 * A step backed by a closure.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a method with the Step signature, so step providers can map names to methods.
 */
final class ClosureStep implements Step {

	/**
	 * Wraps a method.
	 *
	 * @param \Closure(Job, EventLog, float): bool $method Step.
	 */
	public function __construct( private readonly \Closure $method ) {
	}

	/**
	 * Runs the method.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True when done.
	 */
	public function advance( Job $job, EventLog $log, float $deadline ): bool {
		return ( $this->method )( $job, $log, $deadline );
	}
}
