<?php
/**
 * Time source.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the current time; tests use a frozen clock.
 */
interface Clock {

	/**
	 * Returns the current Unix time with nanoseconds.
	 *
	 * @return array{0: int, 1: int} Seconds and nanoseconds.
	 */
	public function now(): array;
}
