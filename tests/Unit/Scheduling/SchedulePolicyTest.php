<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Settings\Settings;

final class SchedulePolicyTest extends TestCase {

	private static function at( string $local, string $zone = 'Europe/Berlin' ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( $zone ) ) )->getTimestamp();
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, string, ?string, ?string}>
	 */
	public static function slots(): iterable {
		$daily = [
			'schedule.backup.frequency' => 'daily',
			'schedule.backup.hour'      => 3,
			'schedule.backup.minute'    => 17,
		];
		yield 'daily, before the time' => [ $daily, '2026-11-09 02:00', '2026-11-08 03:17', '2026-11-09 03:17' ];
		yield 'daily, after the time' => [ $daily, '2026-11-09 10:00', '2026-11-09 03:17', '2026-11-10 03:17' ];
		yield 'daily, exactly' => [ $daily, '2026-11-09 03:17', '2026-11-09 03:17', '2026-11-10 03:17' ];
		yield 'daily across the switch to winter time' => [ $daily, '2026-10-25 10:00', '2026-10-25 03:17', '2026-10-26 03:17' ];
		$spring = [ 'schedule.backup.hour' => 2 ] + $daily;
		yield 'spring gap is due after the clocks move forward' => [ $spring, '2026-03-29 03:00', '2026-03-28 02:17', '2026-03-29 03:17' ];
		// The repeated hour belongs to one slot. Both occurrences of 02:05 must show the
		// same upcoming 02:17, also after the clock moved back for the second occurrence.
		yield 'before the repeated hour' => [ $spring, '2026-10-25 02:05:00+02:00', '2026-10-24 02:17', '2026-10-25 02:17:00+01:00' ];
		yield 'after the clock moved back' => [ $spring, '2026-10-25 02:05:00+01:00', '2026-10-24 02:17', '2026-10-25 02:17:00+01:00' ];
		yield 'daily after a spring gap' => [ $spring, '2026-03-29 04:00', '2026-03-29 03:17', '2026-03-30 02:17' ];
		yield 'twice daily on the spring change' => [ [ 'schedule.backup.frequency' => 'twice_daily' ] + $spring, '2026-03-29 14:30', '2026-03-29 14:17', '2026-03-30 02:17' ];
		yield 'weekly after a spring gap' => [
			[
				'schedule.backup.frequency' => 'weekly',
				'schedule.backup.weekday'   => 0,
			] + $spring,
			'2026-03-29 04:00',
			'2026-03-29 03:17',
			'2026-04-05 02:17',
		];
		yield 'twice daily, afternoon' => [ [ 'schedule.backup.frequency' => 'twice_daily' ] + $daily, '2026-11-09 20:00', '2026-11-09 15:17', '2026-11-10 03:17' ];
		yield 'twice daily, early morning' => [ [ 'schedule.backup.frequency' => 'twice_daily' ] + $daily, '2026-11-09 01:00', '2026-11-08 15:17', '2026-11-09 03:17' ];
		yield 'weekly on Sunday, on Monday' => [
			[
				'schedule.backup.frequency' => 'weekly',
				'schedule.backup.weekday'   => 0,
			] + $daily,
			'2026-11-09 12:00',
			'2026-11-08 03:17',
			'2026-11-15 03:17',
		];
		yield 'weekly on Monday, early on Monday' => [
			[
				'schedule.backup.frequency' => 'weekly',
				'schedule.backup.weekday'   => 1,
			] + $daily,
			'2026-11-09 01:00',
			'2026-11-02 03:17',
			'2026-11-09 03:17',
		];
		yield 'manual' => [ [ 'schedule.backup.frequency' => 'manual' ], '2026-11-09 12:00', null, null ];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider( 'slots' )]
	public function test_slots_follow_the_site_time_zone( array $settings, string $now, ?string $last, ?string $next ): void {
		$values = Settings::from_stored( $settings );
		$zone   = new \DateTimeZone( 'Europe/Berlin' );
		self::assertSame( null === $last ? null : self::at( $last ), SchedulePolicy::last_backup_slot( $values, $zone, self::at( $now ) ) );
		self::assertSame( null === $next ? null : self::at( $next ), SchedulePolicy::next_backup_slot( $values, $zone, self::at( $now ) ) );
	}

	/**
	 * November 2026 begins on a Sunday; the 9th is a Monday.
	 *
	 * @return iterable<string, array{string, array<string, mixed>, string, ?string, ?string}>
	 */
	public static function maintenance_slots(): iterable {
		$prune = [
			'schedule.prune.frequency' => 'weekly',
			'schedule.prune.weekday'   => 0,
			'schedule.prune.hour'      => 4,
		];
		yield 'weekly prune after a spring gap' => [ 'prune', [ 'schedule.prune.hour' => 2 ] + $prune, '2026-03-29 04:00', '2026-03-29 03:00', '2026-04-05 02:00' ];
		yield 'prune weekly on Sunday, on Monday' => [ 'prune', $prune, '2026-11-09 12:00', '2026-11-08 04:00', '2026-11-15 04:00' ];
		yield 'prune weekly on Monday, early on Monday' => [ 'prune', [ 'schedule.prune.weekday' => 1 ] + $prune, '2026-11-09 03:00', '2026-11-02 04:00', '2026-11-09 04:00' ];
		yield 'prune monthly on the first Sunday' => [ 'prune', [ 'schedule.prune.frequency' => 'monthly' ] + $prune, '2026-11-09 12:00', '2026-11-01 04:00', '2026-12-06 04:00' ];
		yield 'prune monthly on the first Tuesday, before it' => [
			'prune',
			[
				'schedule.prune.frequency' => 'monthly',
				'schedule.prune.weekday'   => 2,
			] + $prune,
			'2026-11-02 12:00',
			'2026-10-06 04:00',
			'2026-11-03 04:00',
		];
		yield 'prune on demand' => [ 'prune', [ 'schedule.prune.frequency' => 'manual' ], '2026-11-09 12:00', null, null ];
		$check = [
			'schedule.check.frequency' => 'monthly',
			'schedule.check.day'       => 1,
			'schedule.check.hour'      => 5,
		];
		yield 'check monthly on the 1st' => [ 'check', $check, '2026-11-09 12:00', '2026-11-01 05:00', '2026-12-01 05:00' ];
		yield 'check monthly on the 1st, before the hour' => [ 'check', $check, '2026-11-01 04:00', '2026-10-01 05:00', '2026-11-01 05:00' ];
		yield 'check monthly on the 28th' => [ 'check', [ 'schedule.check.day' => 28 ] + $check, '2026-11-09 12:00', '2026-10-28 05:00', '2026-11-28 05:00' ];
		yield 'check weekly on the prune weekday' => [ 'check', [ 'schedule.check.frequency' => 'weekly' ] + $check + $prune, '2026-11-09 12:00', '2026-11-08 05:00', '2026-11-15 05:00' ];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider( 'maintenance_slots' )]
	public function test_maintenance_slots( string $task, array $settings, string $now, ?string $last, ?string $next ): void {
		$values = Settings::from_stored( $settings );
		$zone   = new \DateTimeZone( 'Europe/Berlin' );
		self::assertSame( null === $last ? null : self::at( $last ), SchedulePolicy::last_maintenance_slot( $values, $zone, self::at( $now ), $task ) );
		self::assertSame( null === $next ? null : self::at( $next ), SchedulePolicy::next_maintenance_slot( $values, $zone, self::at( $now ), $task ) );
	}

	public function test_a_handled_slot_counts_until_it_lies_more_than_an_hour_ahead_of_the_clock(): void {
		$now     = self::at( '2026-11-20 14:00' );
		$current = self::at( '2026-11-20 03:17' );
		self::assertSame( $current, SchedulePolicy::handled( $current, $current, $now ) );
		self::assertSame( 0, SchedulePolicy::handled( 0, $current, $now ), 'Before the first slot nothing is handled.' );
		self::assertSame( $now + 3600, SchedulePolicy::handled( $now + 3600, $current, $now ), 'A clock corrected back by an hour keeps what it handled.' );
		self::assertSame( $current, SchedulePolicy::handled( $now + 3601, $current, $now ) );
		self::assertSame( $current, SchedulePolicy::handled( self::at( '2027-11-20 03:17' ), $current, $now ) );
		self::assertSame( $now + 86400, SchedulePolicy::handled( $now + 86400, null, $now ), 'Without a schedule there is no slot to put it back to.' );
		self::assertFalse( SchedulePolicy::ahead( $now + SchedulePolicy::CLOCK_TOLERANCE, $now ) );
		self::assertTrue( SchedulePolicy::ahead( $now + SchedulePolicy::CLOCK_TOLERANCE + 1, $now ) );
	}

	/**
	 * Heartbeats as Cron::start_due() handles one schedule: a handled slot ahead of the
	 * clock is put back first, and the job of a slot newer than the handled one runs, except
	 * for the first slot, which is only recorded.
	 *
	 * @param list<int> $times Clock readings of the heartbeats, in the order they happen.
	 * @return array{runs: list<int>, resets: int, handled: int} Slots whose job ran, slots put back, last handled slot.
	 */
	private static function heartbeats( Settings $settings, \DateTimeZone $zone, string $task, array $times, int $handled = 0 ): array {
		$runs   = [];
		$resets = 0;
		foreach ( $times as $now ) {
			$slot      = 'backup' === $task ? SchedulePolicy::last_backup_slot( $settings, $zone, $now ) : SchedulePolicy::last_maintenance_slot( $settings, $zone, $now, $task );
			$corrected = SchedulePolicy::handled( $handled, $slot, $now );
			if ( $corrected !== $handled ) {
				$handled = $corrected;
				++$resets;
			}
			if ( null !== $slot && $slot > $handled ) {
				if ( 0 !== $handled ) {
					$runs[] = $slot;
				}
				$handled = max( $slot, $handled );
			}
		}
		return [
			'runs'    => $runs,
			'resets'  => $resets,
			'handled' => $handled,
		];
	}

	/**
	 * Clock readings every five minutes.
	 *
	 * @return list<int>
	 */
	private static function every_five_minutes( int $from, int $until ): array {
		return range( $from, $until, 300 );
	}

	/**
	 * @return iterable<string, array{string, array<string, mixed>, string, string, int}>
	 */
	public static function schedules_across_daylight_saving(): iterable {
		$daily = [
			'schedule.backup.frequency' => 'daily',
			'schedule.backup.minute'    => 30,
		];
		foreach ( [
			'Europe/Berlin'       => [ '2026-03-29', '2026-10-25' ],
			'America/New_York'    => [ '2026-03-08', '2026-11-01' ],
			'Australia/Lord_Howe' => [ '2026-04-05', '2026-10-04' ],
		] as $zone => $days ) {
			foreach ( $days as $day ) {
				foreach ( [ 1, 2, 3 ] as $hour ) {
					yield "daily at {$hour}:30, {$zone} {$day}" => [ $zone, [ 'schedule.backup.hour' => $hour ] + $daily, 'backup', $day, 1 ];
				}
				yield "twice daily, {$zone} {$day}" => [
					$zone,
					[
						'schedule.backup.hour'      => 2,
						'schedule.backup.frequency' => 'twice_daily',
					] + $daily,
					'backup',
					$day,
					2,
				];
				yield "weekly prune, {$zone} {$day}" => [
					$zone,
					[
						'schedule.prune.frequency' => 'weekly',
						'schedule.prune.hour'      => 2,
						'schedule.prune.weekday'   => (int) ( new \DateTimeImmutable( $day ) )->format( 'w' ),
					],
					'prune',
					$day,
					0,
				];
			}
		}
	}

	/**
	 * With a clock that only moves forward, nothing that was handled lies ahead of it: no slot
	 * is put back, and no slot runs twice, also on the days the clocks change.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider( 'schedules_across_daylight_saving' )]
	public function test_heartbeats_across_a_daylight_saving_change_run_each_slot_once_and_reset_nothing( string $zone, array $settings, string $task, string $day, int $per_day ): void {
		$zone  = new \DateTimeZone( $zone );
		$from  = ( new \DateTimeImmutable( $day . ' 00:00', $zone ) )->modify( '-3 days' )->getTimestamp();
		$until = ( new \DateTimeImmutable( $day . ' 00:00', $zone ) )->modify( '+3 days' )->getTimestamp();
		$times = self::every_five_minutes( $from, $until );

		$result = self::heartbeats( Settings::from_stored( $settings ), $zone, $task, $times );

		self::assertSame( 0, $result['resets'] );
		self::assertSame( array_values( array_unique( $result['runs'] ) ), $result['runs'], 'No slot runs twice.' );
		$dates = array_map( static fn ( int $slot ): string => ( new \DateTimeImmutable( '@' . $slot ) )->setTimezone( $zone )->format( 'Y-m-d' ), $result['runs'] );
		foreach ( array_count_values( $dates ) as $date => $count ) {
			self::assertLessThanOrEqual( max( 1, $per_day ), $count, $date );
		}
		if ( $per_day > 0 ) {
			// Six days of heartbeats; the first slot is only recorded.
			self::assertGreaterThanOrEqual( 5 * $per_day, count( $result['runs'] ) );
		}
		$lead = PHP_INT_MIN;
		foreach ( $times as $now ) {
			$slot = 'backup' === $task ? SchedulePolicy::last_backup_slot( Settings::from_stored( $settings ), $zone, $now ) : SchedulePolicy::last_maintenance_slot( Settings::from_stored( $settings ), $zone, $now, $task );
			$lead = max( $lead, (int) $slot - $now );
		}
		self::assertLessThanOrEqual( 0, $lead, 'A slot is never ahead of the clock that computed it.' );
	}

	public function test_a_clock_corrected_back_by_less_than_an_hour_does_not_run_a_handled_slot_again(): void {
		$zone     = new \DateTimeZone( 'Europe/Berlin' );
		$settings = Settings::from_stored(
			[
				'schedule.backup.frequency' => 'daily',
				'schedule.backup.hour'      => 3,
				'schedule.backup.minute'    => 17,
			]
		);
		$before   = self::every_five_minutes( self::at( '2026-11-19 12:00' ), self::at( '2026-11-20 03:20' ) );
		// At 03:20 the clock is corrected back by 59 minutes and goes on from 02:21.
		$after = self::every_five_minutes( self::at( '2026-11-20 02:21' ), self::at( '2026-11-21 12:00' ) );

		$result = self::heartbeats( $settings, $zone, 'backup', [ ...$before, ...$after ] );

		self::assertSame( 0, $result['resets'] );
		self::assertSame( [ self::at( '2026-11-20 03:17' ), self::at( '2026-11-21 03:17' ) ], $result['runs'] );
	}

	/**
	 * @return iterable<string, array{string, array<string, mixed>, string, string}>
	 */
	public static function schedules_with_a_clock_that_was_ahead(): iterable {
		yield 'daily backup' => [ 'backup', [ 'schedule.backup.frequency' => 'daily' ], '2026-11-21 03:17', '2026-11-20 03:17' ];
		yield 'weekly prune' => [
			'prune',
			[
				'schedule.prune.frequency' => 'weekly',
				'schedule.prune.weekday'   => 0,
			],
			'2026-11-22 04:00',
			'2026-11-15 04:00',
		];
		yield 'monthly check' => [ 'check', [ 'schedule.check.frequency' => 'monthly' ], '2026-12-01 05:00', '2026-11-01 05:00' ];
	}

	/**
	 * A virtual machine restored with a clock a year ahead runs its jobs at the slots of that
	 * clock for a day; then NTP sets the clock back. The slot handled by the wrong clock is
	 * put back once, and the schedule goes on with its next slot instead of waiting a year.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider( 'schedules_with_a_clock_that_was_ahead' )]
	public function test_a_clock_stepped_forward_and_back_resumes_the_schedule_at_its_next_slot( string $task, array $settings, string $next, string $current ): void {
		$zone     = new \DateTimeZone( 'Europe/Berlin' );
		$settings = Settings::from_stored( $settings );
		$normal   = self::every_five_minutes( self::at( '2026-10-01 00:00' ), self::at( '2026-11-20 08:00' ) );
		$ahead    = self::every_five_minutes( self::at( '2027-11-20 08:05' ), self::at( '2027-12-21 08:00' ) );
		$back     = self::every_five_minutes( self::at( '2026-11-20 10:00' ), self::at( $next ) + 600 );

		$before  = self::heartbeats( $settings, $zone, $task, $normal );
		$wrong   = self::heartbeats( $settings, $zone, $task, $ahead, $before['handled'] );
		$correct = self::heartbeats( $settings, $zone, $task, $back, $wrong['handled'] );

		self::assertGreaterThan( self::at( '2027-01-01 00:00' ), $wrong['handled'], 'The wrong clock handled a slot a year ahead.' );
		self::assertSame( 1, $correct['resets'], 'Put back once, at the first heartbeat after the clock was set back.' );
		self::assertSame( [ self::at( $next ) ], $correct['runs'], 'The next slot runs, and only it.' );
		self::assertSame( self::at( $next ), $correct['handled'] );
		self::assertSame( self::at( $current ), SchedulePolicy::handled( $wrong['handled'], self::at( $current ), self::at( '2026-11-20 10:00' ) ), 'It is put back to the current slot, which counts as handled.' );
	}
}
