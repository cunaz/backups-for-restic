<?php
/**
 * How often a user may try the WordPress password where the plugin asks for it again.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the wrong WordPress passwords of each user at the routes that ask for the password
 * again (docs/03-security-and-threat-model.md §5.5). Without a limit a stolen session or
 * application password could try passwords there at the speed of the hash.
 *
 * - The first three wrong passwords in a row cost nothing but the refusal.
 * - After the fourth the next attempt waits one minute, and every further wrong password
 *   doubles the wait: 1, 2, 4, 8, 16 and 32 minutes.
 * - From the tenth on, every wrong password locks the confirmation of that user for an hour.
 * - The right password forgets the count, and so does a day without an attempt.
 *
 * An attempt is counted before its password is checked, under the lock of the state option,
 * so that requests sent at the same moment cannot share one try; the right password takes
 * its count back. While a user waits, no password of theirs is checked at all, so that the
 * answer during a wait says nothing about the password. The count is the user's own:
 * nothing one user sends changes the count of another, and only the right password ends it
 * early.
 */
final readonly class ConfirmationThrottle {

	/** Wrong passwords in a row that need no wait. */
	public const int FREE = 3;

	/** Seconds of the first wait, after the first wrong password beyond the free ones; it doubles with each further one. */
	public const int FIRST_WAIT = 60;

	/** From this many wrong passwords in a row on, each one locks the confirmation. */
	public const int LOCK_AFTER = 10;

	/** Seconds a lock lasts. */
	public const int LOCK_SECONDS = 3600;

	/** Seconds without an attempt after which the count starts again. */
	public const int FORGET_SECONDS = 86400;

	/** Entry of the state option that holds the counts. */
	public const string ENTRY = 'password_confirmation';

	/**
	 * Uses the state option and a clock.
	 *
	 * @param SiteState $state Where the counts are kept, under its lock.
	 * @param Clock     $clock Time.
	 */
	public function __construct( private SiteState $state, private Clock $clock ) {
	}

	/**
	 * Starts an attempt of a user. An attempt that carries a password is counted as wrong at
	 * once; succeeded() takes the count back when the password is right.
	 *
	 * @param int  $user    WordPress user ID.
	 * @param bool $counted Whether the attempt carries a password to check. One without a
	 *                      password is refused without being counted, as core's login does.
	 * @return int 0 if the password may be checked now, otherwise the seconds until it may.
	 * @throws StorageFailed If the count cannot be stored; the password must not be checked then.
	 */
	public function begin( int $user, bool $counted = true ): int {
		$now  = $this->clock->now()[0];
		$wait = 0;
		$this->state->update(
			self::ENTRY,
			static function ( array $entry ) use ( $user, $now, $counted, &$wait ): array {
				$records = self::current( $entry, $now );
				$record  = $records[ self::key( $user ) ] ?? null;
				$wait    = null === $record ? 0 : max( 0, $record['until'] - $now );
				if ( 0 === $wait && $counted ) {
					$failures                      = ( $record['failures'] ?? 0 ) + 1;
					$records[ self::key( $user ) ] = [
						'failures' => $failures,
						'last'     => $now,
						'until'    => $now + self::delay( $failures ),
					];
				}
				return $records;
			},
			true
		);
		return $wait;
	}

	/**
	 * Forgets the wrong passwords of a user who just gave the right one.
	 *
	 * @param int $user WordPress user ID.
	 * @throws StorageFailed If the state option cannot be changed.
	 */
	public function succeeded( int $user ): void {
		$now = $this->clock->now()[0];
		$this->state->update(
			self::ENTRY,
			static function ( array $entry ) use ( $user, $now ): array {
				$records = self::current( $entry, $now );
				unset( $records[ self::key( $user ) ] );
				return $records;
			},
			true
		);
	}

	/**
	 * How long a user has to wait before a password of theirs is checked again.
	 *
	 * @param int $user WordPress user ID.
	 * @return int Seconds, 0 if none.
	 */
	public function waiting( int $user ): int {
		$now    = $this->clock->now()[0];
		$record = self::current( $this->state->get( self::ENTRY ), $now )[ self::key( $user ) ] ?? null;
		return null === $record ? 0 : max( 0, $record['until'] - $now );
	}

	/**
	 * The wait that follows a number of wrong passwords in a row.
	 *
	 * @param int $failures Wrong passwords in a row, the one just counted included.
	 * @return int Seconds before the next password is checked.
	 */
	public static function delay( int $failures ): int {
		if ( $failures <= self::FREE ) {
			return 0;
		}
		if ( $failures >= self::LOCK_AFTER ) {
			return self::LOCK_SECONDS;
		}
		return self::FIRST_WAIT * 2 ** ( $failures - self::FREE - 1 );
	}

	/**
	 * The records that still count. A record of a day without an attempt is dropped, and so
	 * is anything that is no record. A wait longer than a lock, which a clock set back can
	 * leave, is cut to a lock.
	 *
	 * @param array<string, mixed> $entry The stored entry.
	 * @param int                  $now   Unix time.
	 * @return array<string, array{failures: int, last: int, until: int}> Records by key.
	 */
	private static function current( array $entry, int $now ): array {
		$records = [];
		foreach ( $entry as $key => $record ) {
			if ( ! is_array( $record ) || ! is_int( $record['failures'] ?? null ) || ! is_int( $record['last'] ?? null ) || ! is_int( $record['until'] ?? null ) ) {
				continue;
			}
			if ( $now - $record['last'] >= self::FORGET_SECONDS && $now >= $record['until'] ) {
				continue;
			}
			$records[ $key ] = [
				'failures' => max( 0, $record['failures'] ),
				'last'     => $record['last'],
				'until'    => min( $record['until'], $now + self::LOCK_SECONDS ),
			];
		}
		return $records;
	}

	/**
	 * The key of a user's record. Not the bare ID: PHP would turn it into an integer key.
	 *
	 * @param int $user WordPress user ID.
	 * @return string Key.
	 */
	private static function key( int $user ): string {
		return 'user_' . $user;
	}
}
