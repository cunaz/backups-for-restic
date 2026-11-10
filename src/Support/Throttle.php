<?php
/**
 * Pauses between units of work.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The gentle CPU mode (advanced.cpu_throttle = gentle) for hosts that limit CPU time: engine
 * loops call pause() after each unit of work, and after every quarter second of work it
 * sleeps a tenth of a second, so a tick keeps a CPU core busy at most about 70 % of the time.
 */
final class Throttle {

	/**
	 * Time source in seconds.
	 *
	 * @var \Closure(): float
	 */
	private readonly \Closure $now;

	/**
	 * Sleeps for the given seconds.
	 *
	 * @var \Closure(float): void
	 */
	private readonly \Closure $sleep;

	/**
	 * When the current stretch of work began.
	 *
	 * @var float
	 */
	private float $since;

	/**
	 * Creates a throttle.
	 *
	 * @param float                        $work  Seconds of work before a pause.
	 * @param float                        $rest  Seconds of each pause.
	 * @param (\Closure(): float)|null     $now   Time source; microtime(true) by default.
	 * @param (\Closure(float): void)|null $sleep Sleep; usleep() by default.
	 */
	public function __construct( private readonly float $work, private readonly float $rest, ?\Closure $now = null, ?\Closure $sleep = null ) {
		$this->now   = $now ?? static fn (): float => microtime( true );
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) round( $seconds * 1000000 ) );
		};
		$this->since = ( $this->now )();
	}

	/**
	 * The gentle mode: a tenth of a second of rest after every quarter second of work.
	 *
	 * @return self Throttle.
	 */
	public static function gentle(): self {
		return new self( 0.25, 0.1 );
	}

	/**
	 * Called after a unit of work; sleeps once enough work has accumulated.
	 */
	public function pause(): void {
		if ( ( $this->now )() - $this->since < $this->work ) {
			return;
		}
		( $this->sleep )( $this->rest );
		$this->since = ( $this->now )();
	}
}
