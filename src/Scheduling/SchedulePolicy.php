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
	 * Seconds by which a time that the plugin recorded may lie after the current time before
	 * it counts as wrong: one hour.
	 *
	 * Times are recorded from the clock, and a handled slot is never later than the moment it
	 * was handled, so with a clock that only moves forward nothing recorded lies ahead of it;
	 * a daylight-saving change moves no Unix time. What puts a recorded time a little ahead
	 * is a clock that is corrected back: NTP steps it by fractions of a second, a leap second
	 * by one, servers that share a site differ by seconds, and a clock kept in local time
	 * steps back the hour of a daylight-saving change. Within the hour a time is kept, so the
	 * slot it handled does not run a second time when the clock reaches it again. Anything
	 * later was recorded by a clock that was wrong, and would hold back the schedule and the
	 * stale notice for as long as the clock had been ahead. One hour is far below the
	 * shortest schedule interval, twelve hours, and the shortest stale threshold, eighteen.
	 */
	public const int CLOCK_TOLERANCE = 3600;

	/**
	 * Whether a time that the plugin recorded lies after now by more than CLOCK_TOLERANCE:
	 * the clock was set back since it recorded the time, after it had been ahead.
	 *
	 * @param int $time Recorded Unix time.
	 * @param int $now  Unix time.
	 * @return bool True if the time cannot be one that this clock recorded.
	 */
	public static function ahead( int $time, int $now ): bool {
		return $time > $now + self::CLOCK_TOLERANCE;
	}

	/**
	 * The slot that counts as handled. A stored slot that lies ahead of the clock was
	 * handled by a clock that was wrong. It is replaced by the current slot, which counts as
	 * handled from then on: a backup ran while the clock was ahead, and the schedule goes on
	 * with its next slot instead of waiting until the clock reaches the wrong one. Without a
	 * schedule there is no current slot, and the stored one stays until there is one.
	 *
	 * @param int      $stored  Handled slot as stored; 0 before the first one.
	 * @param int|null $current The most recent slot at or before now; null without a schedule.
	 * @param int      $now     Unix time.
	 * @return int The slot that counts as handled.
	 */
	public static function handled( int $stored, ?int $current, int $now ): int {
		return null !== $current && self::ahead( $stored, $now ) ? $current : $stored;
	}

	/**
	 * After how long without a successful backup the backups count as stale: the configured
	 * hours, but at least one and a half backup intervals, so that a weekly schedule does not
	 * raise an alarm between two regular runs.
	 *
	 * @param Settings $settings Settings.
	 * @return int|null Seconds, or null when backups run only manually.
	 */
	public static function stale_after( Settings $settings ): ?int {
		$interval = match ( $settings->string( 'schedule.backup.frequency' ) ) {
			'twice_daily' => 43200,
			'daily'       => 86400,
			'weekly'      => 604800,
			default       => null,
		};
		if ( null === $interval ) {
			return null;
		}
		return max( $settings->int( 'notifications.email.stale_after_hours' ) * 3600, intdiv( 3 * $interval, 2 ) );
	}

	/**
	 * The most recent backup slot at or before now.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @return int|null Unix time of the slot, or null for manual backups only.
	 */
	public static function last_backup_slot( Settings $settings, \DateTimeZone $zone, int $now ): ?int {
		return self::backup_slot( $settings, $zone, $now, false );
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
		return self::backup_slot( $settings, $zone, $now, true );
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
		return self::maintenance_slot( $settings, $zone, $now, $task, false );
	}

	/**
	 * The next slot of a maintenance task after now.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @param string        $task     prune or check.
	 * @return int|null Unix time of the slot, or null if the task runs only on demand.
	 */
	public static function next_maintenance_slot( Settings $settings, \DateTimeZone $zone, int $now, string $task ): ?int {
		return self::maintenance_slot( $settings, $zone, $now, $task, true );
	}

	/**
	 * Chooses the previous or next slot from dates on the local calendar. Setting the
	 * configured time independently on each date keeps the normalization of a missing
	 * spring-forward hour on that date alone.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @param bool          $next     Whether to choose a future slot.
	 * @return int|null Unix time.
	 */
	private static function backup_slot( Settings $settings, \DateTimeZone $zone, int $now, bool $next ): ?int {
		$frequency = $settings->string( 'schedule.backup.frequency' );
		if ( 'manual' === $frequency ) {
			return null;
		}
		$day    = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone )->setTime( 12, 0 );
		$hour   = $settings->int( 'schedule.backup.hour' );
		$minute = $settings->int( 'schedule.backup.minute' );
		if ( 'weekly' === $frequency ) {
			return self::weekly_slot( $day, $settings->int( 'schedule.backup.weekday' ), $hour, $minute, $now, $next );
		}
		$hours = 'twice_daily' === $frequency ? [ $hour, ( $hour + 12 ) % 24 ] : [ $hour ];
		$slots = [];
		foreach ( [ $day->modify( '-1 day' ), $day, $day->modify( '+1 day' ) ] as $date ) {
			foreach ( $hours as $scheduled_hour ) {
				$slots[] = $date->setTime( $scheduled_hour, $minute )->getTimestamp();
			}
		}
		return self::nearest( $slots, $now, $next );
	}

	/**
	 * Computes a maintenance slot directly from the configured weekday or month day.
	 *
	 * @param Settings      $settings Settings.
	 * @param \DateTimeZone $zone     Site time zone.
	 * @param int           $now      Unix time.
	 * @param string        $task     prune or check.
	 * @param bool          $next     Whether to choose a future slot.
	 * @return int|null Unix time.
	 */
	private static function maintenance_slot( Settings $settings, \DateTimeZone $zone, int $now, string $task, bool $next ): ?int {
		$frequency = $settings->string( 'schedule.' . $task . '.frequency' );
		$day       = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone )->setTime( 12, 0 );
		$hour      = $settings->int( 'schedule.' . $task . '.hour' );
		$weekday   = $settings->int( 'schedule.prune.weekday' );
		if ( 'weekly' === $frequency ) {
			return self::weekly_slot( $day, $weekday, $hour, 0, $now, $next );
		}
		if ( 'monthly' !== $frequency ) {
			return null;
		}
		$first = $day->modify( 'first day of this month' );
		$slots = [];
		foreach ( [ $first->modify( '-1 month' ), $first, $first->modify( '+1 month' ) ] as $month ) {
			$offset  = 'check' === $task ? $settings->int( 'schedule.check.day' ) - 1 : ( $weekday - (int) $month->format( 'w' ) + 7 ) % 7;
			$slots[] = $month->modify( '+' . $offset . ' days' )->setTime( $hour, 0 )->getTimestamp();
		}
		return self::nearest( $slots, $now, $next );
	}

	/**
	 * Chooses a weekly slot on the given weekday.
	 *
	 * @param \DateTimeImmutable $day     Local date at noon.
	 * @param int                $weekday Scheduled weekday.
	 * @param int                $hour    Scheduled hour.
	 * @param int                $minute  Scheduled minute.
	 * @param int                $now     Unix time.
	 * @param bool               $next    Whether to choose a future slot.
	 * @return int|null Unix time.
	 */
	private static function weekly_slot( \DateTimeImmutable $day, int $weekday, int $hour, int $minute, int $now, bool $next ): ?int {
		$offset = ( $weekday - (int) $day->format( 'w' ) + 7 ) % 7;
		$date   = $day->modify( '+' . $offset . ' days' );
		$slots  = [];
		foreach ( [ $date->modify( '-7 days' ), $date, $date->modify( '+7 days' ) ] as $scheduled ) {
			$slots[] = $scheduled->setTime( $hour, $minute )->getTimestamp();
		}
		return self::nearest( $slots, $now, $next );
	}

	/**
	 * Picks the most recent due slot or the earliest future one.
	 *
	 * @param list<int> $slots Candidates on both sides of now.
	 * @param int       $now   Unix time.
	 * @param bool      $next  Whether to choose a future slot.
	 * @return int|null Unix time.
	 */
	private static function nearest( array $slots, int $now, bool $next ): ?int {
		sort( $slots, SORT_NUMERIC );
		$last = null;
		foreach ( $slots as $slot ) {
			if ( $slot > $now ) {
				return $next ? $slot : $last;
			}
			$last = $slot;
		}
		return $next ? null : $last;
	}
}
