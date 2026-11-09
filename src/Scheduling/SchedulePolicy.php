<?php
/**
 * When scheduled backups are due.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Scheduling;

use BackupsForRestic\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the most recent scheduled slot in the site's time zone. The heartbeat starts a
 * backup when that slot is newer than the last one it handled, so a site that was offline or
 * had no visitors catches up with one backup, not with a backlog.
 */
final class SchedulePolicy {

	/**
	 * The most recent backup slot at or before now.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @return int|null Unix time of the slot, or null for manual backups only.
	 */
	public static function last_backup_slot( Settings $settings, \DateTimeZone $zone, int $now ): ?int {
		$frequency = $settings->string( 'schedule.backup.frequency' );
		$hour      = $settings->int( 'schedule.backup.hour' );
		$minute    = $settings->int( 'schedule.backup.minute' );
		$local     = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$today     = $local->setTime( $hour, $minute );

		switch ( $frequency ) {
			case 'daily':
				$slot = $today <= $local ? $today : $today->modify( '-1 day' );
				return $slot->getTimestamp();
			case 'twice_daily':
				$candidates = [ $today, $today->modify( '+12 hours' ), $today->modify( '-12 hours' ), $today->modify( '-1 day' ) ];
				$best       = null;
				foreach ( $candidates as $candidate ) {
					if ( $candidate <= $local && ( null === $best || $candidate > $best ) ) {
						$best = $candidate;
					}
				}
				return $best?->getTimestamp();
			case 'weekly':
				$days = ( (int) $local->format( 'w' ) - $settings->int( 'schedule.backup.weekday' ) + 7 ) % 7;
				$slot = $today->modify( '-' . $days . ' days' );
				return ( $slot <= $local ? $slot : $slot->modify( '-7 days' ) )->getTimestamp();
			default:
				return null;
		}
	}

	/**
	 * The next backup slot after now, for display.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @return int|null Unix time, or null for manual backups only.
	 */
	public static function next_backup_slot( Settings $settings, \DateTimeZone $zone, int $now ): ?int {
		$last = self::last_backup_slot( $settings, $zone, $now );
		if ( null === $last ) {
			return null;
		}
		$step = match ( $settings->string( 'schedule.backup.frequency' ) ) {
			'twice_daily' => '+12 hours',
			'weekly'      => '+7 days',
			default       => '+1 day',
		};
		return ( new \DateTimeImmutable( '@' . $last ) )->setTimezone( $zone )->modify( $step )->getTimestamp();
	}

	/**
	 * The most recent slot of a maintenance task at or before now.
	 *
	 * Prune runs weekly on its weekday or monthly on the first such weekday of the month. The
	 * check runs monthly on its day of the month, or weekly on the prune weekday, so that all
	 * maintenance shares one quiet day.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @param string        $task     prune or check.
	 * @return int|null Unix time of the slot, or null if the task runs only on demand.
	 */
	public static function last_maintenance_slot( Settings $settings, \DateTimeZone $zone, int $now, string $task ): ?int {
		$frequency = $settings->string( 'schedule.' . $task . '.frequency' );
		$hour      = $settings->int( 'schedule.' . $task . '.hour' );
		$weekday   = $settings->int( 'schedule.prune.weekday' );
		$local     = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );

		if ( 'weekly' === $frequency ) {
			$days = ( (int) $local->format( 'w' ) - $weekday + 7 ) % 7;
			$slot = $local->setTime( $hour, 0 )->modify( '-' . $days . ' days' );
			return ( $slot <= $local ? $slot : $slot->modify( '-7 days' ) )->getTimestamp();
		}
		if ( 'monthly' !== $frequency ) {
			return null;
		}
		$month = $local->modify( 'first day of this month' )->setTime( $hour, 0 );
		$slot  = self::monthly( $month, $task, $weekday, $settings );
		if ( $slot > $local ) {
			$slot = self::monthly( $month->modify( 'first day of previous month' ), $task, $weekday, $settings );
		}
		return $slot->getTimestamp();
	}

	/**
	 * The slot of a maintenance task within one month.
	 *
	 * @param \DateTimeImmutable $first    First day of the month at the task's hour.
	 * @param string             $task     prune or check.
	 * @param int                $weekday  Prune weekday, 0 for Sunday.
	 * @param Settings           $settings Settings.
	 * @return \DateTimeImmutable Slot.
	 */
	private static function monthly( \DateTimeImmutable $first, string $task, int $weekday, Settings $settings ): \DateTimeImmutable {
		if ( 'check' === $task ) {
			return $first->modify( '+' . ( $settings->int( 'schedule.check.day' ) - 1 ) . ' days' );
		}
		return $first->modify( '+' . ( ( $weekday - (int) $first->format( 'w' ) + 7 ) % 7 ) . ' days' );
	}
}
