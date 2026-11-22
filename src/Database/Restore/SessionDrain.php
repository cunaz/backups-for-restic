<?php
/**
 * Waits for requests that started before WordPress entered maintenance.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress checks its maintenance marker once, before it connects to the database. A request
 * that passed the check earlier keeps running, and its statements that queue behind the
 * exchange's table locks would run against the other generation afterwards. After a grace
 * period that covers the step from that check to the connection, the other sessions of the
 * site's account are listed once. The exchange starts only after every listed session ended.
 * A session that appears later belongs to a request that the marker stopped or admitted.
 */
final readonly class SessionDrain {

	/**
	 * Sets the bounds of the wait.
	 *
	 * @param float $grace Seconds between entering maintenance and listing the sessions.
	 * @param float $bound Seconds the listed sessions may take to end.
	 * @param float $minimum Seconds of waiting granted even when the tick is nearly spent.
	 * @param float $poll Seconds between two checks.
	 */
	public function __construct( private float $grace = 2.0, private float $bound = 60.0, private float $minimum = 5.0, private float $poll = 0.2 ) {
	}

	/**
	 * Seconds a tick should have left before it enters maintenance for the wait.
	 *
	 * @return float Grace period plus the minimum wait.
	 */
	public function budget(): float {
		return $this->grace + $this->minimum;
	}

	/**
	 * Waits until no session listed after the grace period remains. The wait ends at the
	 * tick's deadline, but never later than the bound and never sooner than the minimum.
	 *
	 * @param Connection $connection The session that will take the exchange locks.
	 * @param string     $database Site database, as bound by the reviewed plan.
	 * @param float      $entered Time maintenance was entered, from microtime().
	 * @param float      $deadline End of the tick.
	 * @throws DatabaseFailed If listed sessions remain, with the code database_exchange_sessions.
	 */
	public function wait( Connection $connection, string $database, float $entered, float $deadline ): void {
		$catalog = new SwapCatalog( $connection );
		$listed  = $entered + $this->grace;
		self::pause( $listed - microtime( true ) );
		$until   = $listed + min( $this->bound, max( $this->minimum, $deadline - $listed ) );
		$waiting = $catalog->sessions( $database );
		while ( [] !== $waiting ) {
			$left = $until - microtime( true );
			if ( $left <= 0.0 ) {
				DatabaseFailed::raise( 'Database sessions from before maintenance were still open when the wait ended.', [ 'code' => 'database_exchange_sessions' ] );
			}
			self::pause( min( $this->poll, $left ) );
			$waiting = array_intersect( $waiting, $catalog->sessions( $database ) );
		}
	}

	/**
	 * Sleeps for a positive interval.
	 *
	 * @param float $seconds Interval; nothing happens for zero or less.
	 */
	private static function pause( float $seconds ): void {
		usleep( (int) max( 0.0, $seconds * 1e6 ) );
	}
}
