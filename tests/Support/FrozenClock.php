<?php
/**
 * A clock that moves only when told.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Support\Clock;

/**
 * Deterministic time for tests.
 */
final class FrozenClock implements Clock {

	public function __construct( public int $seconds = 1767225600, public int $nanoseconds = 0 ) {
	}

	public function now(): array {
		return [ $this->seconds, $this->nanoseconds ];
	}

	public function advance( int $seconds ): void {
		$this->seconds += $seconds;
	}
}
