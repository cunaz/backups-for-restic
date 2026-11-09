<?php
/**
 * Wall clock.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The system clock with microsecond resolution.
 */
final class SystemClock implements Clock {

	/**
	 * Returns the current Unix time.
	 *
	 * @return array{0: int, 1: int} Seconds and nanoseconds.
	 */
	public function now(): array {
		$parts = explode( ' ', microtime() );
		return [ (int) $parts[1], (int) round( (float) $parts[0] * 1000000 ) * 1000 ];
	}
}
