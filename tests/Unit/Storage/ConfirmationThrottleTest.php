<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\ConfirmationThrottle;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * How often a user may try the WordPress password where the plugin asks for it again: a
 * few mistakes are free, then each wrong password makes the next attempt wait longer, and
 * from the tenth on each one locks the confirmation for an hour.
 */
final class ConfirmationThrottleTest extends TestCase {

	private const int USER = 7;

	private InMemoryOptions $options;

	private FrozenClock $clock;

	protected function setUp(): void {
		$this->options = new InMemoryOptions();
		$this->clock   = new FrozenClock();
	}

	private function throttle(): ConfirmationThrottle {
		return new ConfirmationThrottle( new SiteState( $this->options ), $this->clock );
	}

	/**
	 * Makes wrong attempts, each as soon as the one before allows it.
	 *
	 * @return list<int> The wait that followed each attempt.
	 */
	private function wrong( int $times, int $user = self::USER ): array {
		$waits = [];
		for ( $attempt = 0; $attempt < $times; $attempt++ ) {
			self::assertSame( 0, $this->throttle()->begin( $user ), 'The attempt is checked.' );
			$waits[] = $this->throttle()->waiting( $user );
			$this->clock->advance( end( $waits ) );
		}
		return $waits;
	}

	public function test_three_wrong_passwords_are_free_and_the_wait_doubles_after_that(): void {
		self::assertSame( [ 0, 0, 0, 60, 120, 240, 480, 960, 1920 ], $this->wrong( 9 ) );
	}

	public function test_from_the_tenth_wrong_password_on_each_one_locks_for_an_hour(): void {
		$waits = $this->wrong( 12 );

		self::assertSame( [ 3600, 3600, 3600 ], array_slice( $waits, 9 ) );
		self::assertSame( ConfirmationThrottle::LOCK_SECONDS, ConfirmationThrottle::delay( 100 ) );
	}

	public function test_while_a_user_waits_no_password_is_checked_and_nothing_more_is_counted(): void {
		$this->wrong( 4 );
		$this->clock->advance( -60 );

		foreach ( [ 0, 10, 49 ] as $later ) {
			$this->clock->advance( $later );
			self::assertGreaterThan( 0, $this->throttle()->begin( self::USER ), 'Refused before the password is looked at.' );
		}
		self::assertSame( 1, $this->throttle()->begin( self::USER ) );

		$this->clock->advance( 1 );
		self::assertSame( 0, $this->throttle()->begin( self::USER ), 'The refused attempts did not make the wait longer.' );
		self::assertSame( 120, $this->throttle()->waiting( self::USER ), 'This is the fifth counted attempt, not the eighth.' );
	}

	public function test_attempts_at_the_same_moment_cannot_share_one_try(): void {
		// Each attempt is counted before its password is checked, so requests sent together
		// are counted one by one, and the fifth waits for the fourth.
		foreach ( [ 0, 0, 0, 0 ] as $expected ) {
			self::assertSame( $expected, $this->throttle()->begin( self::USER ) );
		}
		self::assertSame( 60, $this->throttle()->begin( self::USER ) );
	}

	public function test_the_right_password_forgets_the_count(): void {
		$this->wrong( 5 );
		self::assertSame( 0, $this->throttle()->begin( self::USER ) );
		$this->throttle()->succeeded( self::USER );

		self::assertSame( 0, $this->throttle()->waiting( self::USER ) );
		self::assertSame( [ 0, 0, 0, 60 ], $this->wrong( 4 ), 'The count starts again.' );
	}

	public function test_one_users_wrong_passwords_slow_down_nobody_else(): void {
		$this->wrong( 10 );
		self::assertSame( 0, $this->throttle()->begin( self::USER ) );
		self::assertSame( 3600, $this->throttle()->waiting( self::USER ) );

		self::assertSame( 0, $this->throttle()->waiting( 8 ) );
		self::assertSame( [ 0, 0, 0 ], $this->wrong( 3, 8 ) );
		$this->throttle()->succeeded( 8 );

		self::assertGreaterThan( 0, $this->throttle()->waiting( self::USER ), 'Another user\'s right password does not end the lock.' );
	}

	public function test_an_attempt_without_a_password_is_not_counted_but_waits_as_well(): void {
		foreach ( range( 1, 12 ) as $attempt ) {
			self::assertSame( 0, $this->throttle()->begin( self::USER, false ), (string) $attempt );
		}
		self::assertSame( [ 0, 0, 0, 60 ], $this->wrong( 4 ) );

		$this->clock->advance( -1 );
		self::assertSame( 1, $this->throttle()->begin( self::USER, false ) );
	}

	public function test_a_day_without_an_attempt_starts_the_count_again_and_an_hour_does_not(): void {
		$this->wrong( 10 );
		self::assertSame( 0, $this->throttle()->begin( self::USER ), 'The lock of the tenth is over.' );
		self::assertSame( 3600, $this->throttle()->waiting( self::USER ), 'The count went on.' );

		$this->clock->advance( ConfirmationThrottle::FORGET_SECONDS );
		self::assertSame( [ 0, 0, 0, 60 ], $this->wrong( 4 ) );
	}

	public function test_a_clock_set_back_waits_one_lock_at_most(): void {
		$this->wrong( 10 );
		$this->clock->advance( -3 * 86400 );

		self::assertSame( ConfirmationThrottle::LOCK_SECONDS, $this->throttle()->waiting( self::USER ) );
	}

	public function test_what_is_no_record_is_dropped_and_stale_records_of_others_go(): void {
		$this->wrong( 4, 8 );
		$this->clock->advance( ConfirmationThrottle::FORGET_SECONDS );
		$state = $this->options->values[ SiteState::OPTION ];
		$state[ ConfirmationThrottle::ENTRY ]['user_9'] = 'not a record';

		$this->options->values[ SiteState::OPTION ] = $state;
		$this->throttle()->begin( self::USER );

		self::assertSame( [ 'user_' . self::USER ], array_keys( $this->options->values[ SiteState::OPTION ][ ConfirmationThrottle::ENTRY ] ) );
	}

	public function test_an_attempt_that_cannot_be_counted_is_refused(): void {
		$directory = TempDir::create( 'throttle-lock' );
		try {
			// A directory cannot be opened as a lock file, even when testing as root.
			mkdir( $directory . '/state.lock' );
			$throttle = new ConfirmationThrottle( new SiteState( $this->options, new FileLock( $directory . '/state.lock' ) ), $this->clock );

			$this->expectException( StorageFailed::class );
			$throttle->begin( self::USER );
		} finally {
			TempDir::remove( $directory );
		}
	}
}
