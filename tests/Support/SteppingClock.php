<?php
/**
 * A clock that moves by a fixed step at every reading.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Support\Clock;

/**
 * Each reading is the given step after the one before, so that a test can let a budget
 * that a service measures with its clock run out at a chosen check.
 */
final class SteppingClock implements Clock {

	private float $time;

	/**
	 * @param float $start Seconds of the reading before the first one.
	 * @param float $step  Seconds between two readings.
	 */
	public function __construct( float $start, private readonly float $step ) {
		$this->time = $start;
	}

	public function now(): array {
		$this->time += $this->step;
		return [ (int) floor( $this->time ), (int) round( fmod( $this->time, 1.0 ) * 1000000000 ) ];
	}
}
