<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Support\Throttle;

final class ThrottleTest extends TestCase {

	private float $time = 100.0;

	/** @var list<float> */
	private array $slept = [];

	private function throttle( float $work, float $rest ): Throttle {
		return new Throttle(
			$work,
			$rest,
			fn (): float => $this->time,
			function ( float $seconds ): void {
				$this->slept[] = $seconds;
				$this->time   += $seconds;
			}
		);
	}

	public function test_rests_after_each_stretch_of_work(): void {
		$throttle    = $this->throttle( 0.25, 0.1 );
		$this->time += 0.2;
		$throttle->pause();
		self::assertSame( [], $this->slept );
		$this->time += 0.05;
		$throttle->pause();
		self::assertSame( [ 0.1 ], $this->slept );
		$this->time += 0.24;
		$throttle->pause();
		self::assertSame( [ 0.1 ], $this->slept, 'The next stretch starts after the rest.' );
		$this->time += 0.01;
		$throttle->pause();
		self::assertSame( [ 0.1, 0.1 ], $this->slept );
	}

	public function test_the_gentle_mode_keeps_a_core_busy_about_seventy_percent_of_the_time(): void {
		$throttle = $this->throttle( 0.25, 0.1 );
		$start    = $this->time;
		for ( $unit = 0; $unit < 1000; $unit++ ) {
			$this->time += 0.01;
			$throttle->pause();
		}
		$busy = 10.0 / ( $this->time - $start );
		self::assertEqualsWithDelta( 0.71, $busy, 0.02 );
	}

	public function test_the_real_clock_sleeps(): void {
		$throttle = new Throttle( 0.0, 0.02 );
		$before   = microtime( true );
		$throttle->pause();
		self::assertGreaterThanOrEqual( 0.015, microtime( true ) - $before );
	}
}
