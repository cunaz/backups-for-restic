<?php
/**
 * The memory limit of a request for tests that run late in the unit suite.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Engine\MemoryBudget;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Gives a test the room that a request of the plugin has beside its index.
 *
 * The steps of a job take their budget from PHP's memory limit and from the memory that the
 * process has reserved (Engine\MemoryBudget): an index may bring the reserved memory up to
 * the limit less 32 MiB. The unit suite runs in one process under a limit of 128 MiB, and with
 * opcache off that process keeps the compiled code of every class it has loaded. By the time
 * the job steps run it has reserved 92 to 94 MiB, so their index had the last 2 to 4 MiB, and
 * one more test class anywhere before them made them refuse it as too large for the host
 * (IndexTooLarge), on some PHP versions and not on others. A request of the plugin holds none
 * of the suite.
 *
 * So the tests of the job steps, which are not tests of that edge, run under twice the suite's
 * limit, unless the process already has that much or no limit. A test that is about a limit
 * sets one of its own inside and puts this one back.
 */
trait RoomOfARequest {

	/** The limit under which the tests run: twice the 128 MiB of phpunit.xml.dist. */
	private const int ROOM_BYTES = 268435456;

	/**
	 * The limit of the suite while the test runs under a higher one, or null.
	 *
	 * @var string|null
	 */
	private ?string $suite_limit = null;

	/** Raises PHP's memory limit for the test. */
	#[Before]
	protected function give_room(): void {
		$budget = MemoryBudget::from_ini( (string) ini_get( 'memory_limit' ) );
		if ( null === $budget || $budget->limit >= self::ROOM_BYTES ) {
			return;
		}
		$previous = ini_set( 'memory_limit', (string) self::ROOM_BYTES ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The room of a request, for tests that share a process with the suite.
		if ( is_string( $previous ) ) {
			$this->suite_limit = $previous;
		}
	}

	/** Puts the suite's limit back. */
	#[After]
	protected function take_room(): void {
		if ( null !== $this->suite_limit ) {
			ini_set( 'memory_limit', $this->suite_limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Back to the limit of the suite.
			$this->suite_limit = null;
		}
	}
}
