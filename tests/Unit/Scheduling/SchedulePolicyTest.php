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
}
