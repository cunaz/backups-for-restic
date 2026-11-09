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
}
