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
 * period that covers the step from that check to the connection, the exchange waits until
 * the site's account has had no other session in the site's database for a quiet interval.
 * That includes a request from before maintenance that reconnects under a new connection ID,
 * and the requests that the maintenance gate admits.
 */
final readonly class SessionDrain {

	/**
	 * Current time in seconds.
	 *
	 * @var \Closure():float
	 */
	private \Closure $clock;

	/**
	 * Sleeps for a number of seconds.
	 *
	 * @var \Closure(float):void
	 */
	private \Closure $sleep;

	/**
	 * Sets the bounds of the wait.
	 *
	 * @param float                       $grace Seconds between entering maintenance and the first look at the sessions.
	 * @param float                       $bound Seconds the sessions may take to end.
	 * @param float                       $minimum Seconds of waiting granted even when the tick is nearly spent.
	 * @param float                       $poll Seconds between two checks.
	 * @param float                       $quiet Seconds without another session before the exchange starts.
	 * @param (\Closure():float)|null     $clock Current time; microtime() unless a test sets one.
	 * @param (\Closure(float):void)|null $sleep Sleeper; usleep() unless a test sets one.
	 */
	public function __construct( private float $grace = 2.0, private float $bound = 60.0, private float $minimum = 5.0, private float $poll = 0.2, private float $quiet = 1.0, ?\Closure $clock = null, ?\Closure $sleep = null ) {
		$this->clock = $clock ?? static fn (): float => microtime( true );
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1e6 ) );
		};
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
	 * Waits until the account has had no other session in the site's database for the quiet
	 * interval, looking first after the grace period. The wait ends at the tick's deadline,
	 * but never later than the bound and never sooner than the minimum.
	 *
	 * @param Connection $connection The session that will take the exchange locks.
	 * @param string     $database Site database, as bound by the reviewed plan.
	 * @param float      $entered Time maintenance was entered, on this drain's clock.
	 * @param float      $deadline End of the tick, on the same clock.
	 * @throws DatabaseFailed With the code database_exchange_sessions if other sessions stay, or
	 *                        performance_schema if the sessions cannot be listed.
	 */
	public function wait( Connection $connection, string $database, float $entered, float $deadline ): void {
		$catalog = new SwapCatalog( $connection );
		$listed  = $entered + $this->grace;
		$this->pause( $listed - ( $this->clock )() );
		$until = $listed + min( $this->bound, max( $this->minimum, $deadline - $listed ) );
		$since = null;
		for ( ;; ) {
			$others = $catalog->sessions( $database );
			$now    = ( $this->clock )();
			if ( [] === $others ) {
				$since ??= $now;
				if ( $now - $since >= $this->quiet ) {
					return;
				}
			} else {
				$since = null;
			}
			if ( $now >= $until ) {
				DatabaseFailed::raise( 'Other sessions of the site database account did not end, or did not stay away, before the wait ended.', [ 'code' => 'database_exchange_sessions' ] );
			}
			$this->pause( min( $this->poll, $until - $now ) );
		}
	}

	/**
	 * Sleeps for a positive interval.
	 *
	 * @param float $seconds Interval; nothing happens for zero or less.
	 */
	private function pause( float $seconds ): void {
		if ( $seconds > 0.0 ) {
			( $this->sleep )( $seconds );
		}
	}
}
