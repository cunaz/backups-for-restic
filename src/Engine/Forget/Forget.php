<?php
/**
 * Retention.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Forget;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\SystemClock;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which snapshots to keep exactly like restic's ApplyPolicy (ADR 0022).
 *
 * Snapshots are processed newest first. Each bucket rule (last, hourly, daily, weekly,
 * monthly, yearly) keeps a snapshot when it falls into a new bucket value and the rule still
 * has counts left; the oldest snapshot is kept while a rule has counts left. Calendar values
 * are computed in the time zone offset stored with each snapshot, as Go does.
 */
final class Forget {

	private const array BUCKETS = [
		'last'    => 'last snapshot',
		'hourly'  => 'hourly snapshot',
		'daily'   => 'daily snapshot',
		'weekly'  => 'weekly snapshot',
		'monthly' => 'monthly snapshot',
		'yearly'  => 'yearly snapshot',
	];

	/** Go's zero time, 0001-01-01T00:00:00Z, in Unix seconds. */
	private const int ZERO_TIME_SECONDS = -62135596800;

	/**
	 * Applies a policy to one group of snapshots.
	 *
	 * @param array<string, Snapshot>    $snapshots Snapshots by hex ID.
	 * @param RetentionPolicy            $policy    Policy.
	 * @param array{0: int, 1: int}|null $now       Current time as Clock::now() gives it; null for the system clock.
	 * @return array{keep: list<string>, remove: list<string>, reasons: array<string, list<string>>} Decision.
	 * @throws InvalidArgument If the policy is empty.
	 */
	public static function apply( array $snapshots, RetentionPolicy $policy, ?array $now = null ): array {
		if ( $policy->is_empty() ) {
			throw new InvalidArgument( 'An empty retention policy would remove every snapshot.' );
		}

		$list = [];
		foreach ( $snapshots as $id => $snapshot ) {
			[ $seconds, $nanoseconds ] = Rfc3339::parse( $snapshot->time );
			$list[]                    = [ $id, $snapshot, $seconds, $nanoseconds, self::offset( $snapshot->time ) ];
		}
		// Newest first; usort is stable, like Go's sort.Stable.
		usort( $list, static fn ( array $a, array $b ): int => [ $b[2], $b[3] ] <=> [ $a[2], $a[3] ] );

		$keep    = [];
		$remove  = [];
		$reasons = [];
		if ( [] === $list ) {
			return compact( 'keep', 'remove', 'reasons' );
		}

		$latest  = self::latest( $list, $now ?? ( new SystemClock() )->now() );
		$counts  = [
			'last'    => $policy->last,
			'hourly'  => $policy->hourly,
			'daily'   => $policy->daily,
			'weekly'  => $policy->weekly,
			'monthly' => $policy->monthly,
			'yearly'  => $policy->yearly,
		];
		$within  = [
			'hourly'  => $policy->within_hourly,
			'daily'   => $policy->within_daily,
			'weekly'  => $policy->within_weekly,
			'monthly' => $policy->within_monthly,
			'yearly'  => $policy->within_yearly,
		];
		$last    = array_fill_keys( array_keys( self::BUCKETS ), -1 );
		$last_in = array_fill_keys( array_keys( $within ), -1 );
		$final   = count( $list ) - 1;

		foreach ( $list as $number => [ $id, $snapshot, $seconds, $nanoseconds, $offset ] ) {
			$matches = [];

			foreach ( $policy->keep_tags as $tags ) {
				if ( $snapshot->has_tags( $tags ) ) {
					$matches[] = 'has tags [' . implode( ', ', $tags ) . ']';
				}
			}

			if ( null !== $policy->within && ! $policy->within->is_zero() && self::after( $seconds, $nanoseconds, self::threshold( $latest, $policy->within ) ) ) {
				$matches[] = 'within ' . $policy->within->format();
			}

			foreach ( self::BUCKETS as $rule => $reason ) {
				$count = $counts[ $rule ];
				if ( $count > 0 || -1 === $count ) {
					$value = self::bucket( $rule, $seconds + $offset, $number );
					if ( $value !== $last[ $rule ] || $number === $final ) {
						$matches[]       = $value === $last[ $rule ] && $number === $final ? 'oldest ' . $reason : $reason;
						$last[ $rule ]   = $value;
						$counts[ $rule ] = $count > 0 ? $count - 1 : $count;
					}
				}
			}

			foreach ( $within as $rule => $duration ) {
				if ( null === $duration || $duration->is_zero() || ! self::after( $seconds, $nanoseconds, self::threshold( $latest, $duration ) ) ) {
					continue;
				}
				$value = self::bucket( $rule, $seconds + $offset, $number );
				if ( $value !== $last_in[ $rule ] || $number === $final ) {
					$reason           = $rule . ' within ' . $duration->format();
					$matches[]        = $value === $last_in[ $rule ] && $number === $final ? 'oldest ' . $reason : $reason;
					$last_in[ $rule ] = $value;
				}
			}

			if ( [] === $matches ) {
				$remove[] = $id;
				continue;
			}
			$keep[]         = $id;
			$reasons[ $id ] = $matches;
		}

		return compact( 'keep', 'remove', 'reasons' );
	}

	/**
	 * Groups snapshots like restic's --group-by.
	 *
	 * @param array<string, Snapshot> $snapshots Snapshots by hex ID.
	 * @param bool                    $by_host   Group by host name.
	 * @param bool                    $by_paths  Group by paths.
	 * @param bool                    $by_tags   Group by tags.
	 * @return array<string, array<string, Snapshot>> Groups by key.
	 */
	public static function group( array $snapshots, bool $by_host = true, bool $by_paths = false, bool $by_tags = true ): array {
		$groups = [];
		foreach ( $snapshots as $id => $snapshot ) {
			$paths = $snapshot->paths;
			$tags  = $snapshot->tags;
			sort( $paths, SORT_STRING );
			sort( $tags, SORT_STRING );
			$key                   = implode(
				"\0",
				[
					$by_host ? $snapshot->hostname : '',
					$by_paths ? implode( "\x1f", $paths ) : '',
					$by_tags ? implode( "\x1f", $tags ) : '',
				]
			);
			$groups[ $key ][ $id ] = $snapshot;
		}
		return $groups;
	}

	/**
	 * Deletes snapshot files.
	 *
	 * @param Repository   $repository Repository.
	 * @param list<string> $ids        Hex IDs to remove.
	 */
	public static function delete( Repository $repository, array $ids ): void {
		foreach ( $ids as $id ) {
			$repository->backend()->delete( ObjectType::Snapshots, $id );
		}
	}

	/**
	 * Computes restic's bucket value for a rule.
	 *
	 * @param string $rule   Rule name.
	 * @param int    $local  Seconds shifted into the snapshot's time zone.
	 * @param int    $number Position in the newest-first list.
	 * @return int Bucket value.
	 */
	private static function bucket( string $rule, int $local, int $number ): int {
		return match ( $rule ) {
			'last'    => $number,
			'hourly'  => (int) gmdate( 'YmdH', $local ),
			'daily'   => (int) gmdate( 'Ymd', $local ),
			'weekly'  => (int) gmdate( 'o', $local ) * 100 + (int) gmdate( 'W', $local ),
			'monthly' => (int) gmdate( 'Ym', $local ),
			default   => (int) gmdate( 'Y', $local ),
		};
	}

	/**
	 * Returns the time zone offset of a timestamp in seconds.
	 *
	 * @param string $time RFC 3339 timestamp.
	 * @return int Offset.
	 */
	private static function offset( string $time ): int {
		if ( 1 !== preg_match( '/([+-])(\d{2}):(\d{2})$/', $time, $parts ) ) {
			return 0;
		}
		return ( '-' === $parts[1] ? -1 : 1 ) * ( (int) $parts[2] * 3600 + (int) $parts[3] * 60 );
	}

	/**
	 * The time the within rules count back from, like restic's findLatestTimestamp: the newest
	 * snapshot that is not in the future, so that one snapshot from a wrong clock does not
	 * move the window past all others. If every snapshot is in the future, Go's zero time.
	 *
	 * @param list<array{0: string, 1: Snapshot, 2: int, 3: int, 4: int}> $snapshots Snapshots, newest first.
	 * @param array{0: int, 1: int}                                       $now       Current time.
	 * @return array{0: int, 1: int, 2: int} Seconds, nanoseconds and time zone offset.
	 */
	private static function latest( array $snapshots, array $now ): array {
		foreach ( $snapshots as [ , , $seconds, $nanoseconds, $offset ] ) {
			if ( [ $seconds, $nanoseconds ] < $now ) {
				return [ $seconds, $nanoseconds, $offset ];
			}
		}
		return [ self::ZERO_TIME_SECONDS, 0, 0 ];
	}

	/**
	 * Computes latest.AddDate(-years, -months, -days).Add(-hours) like Go.
	 *
	 * @param array{0: int, 1: int, 2: int} $latest   Seconds, nanoseconds and time zone offset.
	 * @param Duration                      $duration Duration.
	 * @return array{0: int, 1: int} Seconds and nanoseconds.
	 */
	private static function threshold( array $latest, Duration $duration ): array {
		[ $seconds, $nanoseconds, $offset ] = $latest;
		$timezone                           = new \DateTimeZone( sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( abs( $offset ), 3600 ), intdiv( abs( $offset ) % 3600, 60 ) ) );
		$date                               = ( new \DateTimeImmutable( '@' . $seconds ) )->setTimezone( $timezone );
		// Explicit signs: a part that is negative must not turn into "--1 days".
		$date = $date->modify( sprintf( '%+d years %+d months %+d days', -$duration->years, -$duration->months, -$duration->days ) );
		return [ $date->getTimestamp() - $duration->hours * 3600, $nanoseconds ];
	}

	/**
	 * Whether a time is strictly after a threshold.
	 *
	 * @param int                   $seconds     Seconds.
	 * @param int                   $nanoseconds Nanoseconds.
	 * @param array{0: int, 1: int} $threshold   Threshold.
	 * @return bool True if after.
	 */
	private static function after( int $seconds, int $nanoseconds, array $threshold ): bool {
		return [ $seconds, $nanoseconds ] > $threshold;
	}
}
