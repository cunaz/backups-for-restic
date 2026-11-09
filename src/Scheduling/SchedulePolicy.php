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
}
