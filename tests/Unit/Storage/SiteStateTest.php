<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\InMemoryOptions;

/**
 * What the plugin remembers belongs to one repository.
 */
final class SiteStateTest extends TestCase {

	private const int NOW = 1_800_000_000;

	private SiteState $state;

	protected function setUp(): void {
		$this->state = new SiteState( new InMemoryOptions() );
	}

	private function remember_results(): void {
		foreach ( SiteState::OF_THE_REPOSITORY as $key ) {
			$this->state->set( $key, [ 'job' => 'a job of the first repository' ] );
		}
		$this->state->set( 'schedule', [ 'backup_slot' => 12 ] );
		$this->state->set( 'exposure', [ 'exposed' => false ] );
	}

	public function test_state_from_before_the_record_is_taken_to_describe_the_first_repository(): void {
		$this->remember_results();

		self::assertFalse( $this->state->bind( 'first', self::NOW ), 'An update of the plugin drops nothing.' );

		self::assertSame( [ 'job' => 'a job of the first repository' ], $this->state->get( 'last_backup' ) );
		self::assertSame(
			[
				'id'    => 'first',
				'since' => 0,
			],
			$this->state->get( 'repository' ),
			'Since when is not known, so the stale notice counts from the setup.'
		);
	}

	public function test_state_from_before_the_record_is_not_taken_for_a_repository_that_replaced_the_known_one(): void {
		$this->remember_results();

		self::assertTrue( $this->state->bind( 'second', self::NOW, 'first' ), 'The settings knew the repository at this address by another ID.' );

		self::assertSame( [], $this->state->get( 'last_backup' ) );
		self::assertSame( self::NOW, $this->state->get( 'repository' )['since'] ?? null );
	}

	public function test_state_from_before_the_record_stays_with_the_repository_the_settings_knew(): void {
		$this->remember_results();

		self::assertFalse( $this->state->bind( 'first', self::NOW, 'first' ) );

		self::assertSame( [ 'job' => 'a job of the first repository' ], $this->state->get( 'last_backup' ) );
	}

	public function test_another_address_drops_the_results_also_when_nothing_was_recorded_yet(): void {
		$this->remember_results();

		self::assertTrue( $this->state->bind( '', self::NOW ), 'Saved by a version without the record: the results still are the old repository\'s.' );

		self::assertSame( [], $this->state->get( 'last_backup' ) );
		self::assertSame( [ 'backup_slot' => 12 ], $this->state->get( 'schedule' ) );
		self::assertSame( self::NOW, $this->state->get( 'repository' )['since'] ?? null );
	}

	public function test_the_same_repository_again_changes_nothing(): void {
		$this->state->bind( 'first', self::NOW );
		$this->remember_results();

		self::assertFalse( $this->state->bind( 'first', self::NOW + 60 ) );

		self::assertSame( [ 'job' => 'a job of the first repository' ], $this->state->get( 'check_alert' ) );
		self::assertSame( 0, $this->state->get( 'repository' )['since'] ?? null );
	}

	public function test_another_repository_drops_the_results_of_the_old_one_and_nothing_else(): void {
		$this->state->bind( 'first', self::NOW );
		$this->remember_results();

		self::assertTrue( $this->state->bind( 'second', self::NOW + 60 ) );

		foreach ( SiteState::OF_THE_REPOSITORY as $key ) {
			self::assertSame( [], $this->state->get( $key ), $key );
		}
		self::assertSame( [ 'backup_slot' => 12 ], $this->state->get( 'schedule' ), 'The schedule is the site\'s.' );
		self::assertSame( [ 'exposed' => false ], $this->state->get( 'exposure' ) );
		self::assertSame(
			[
				'id'    => 'second',
				'since' => self::NOW + 60,
			],
			$this->state->get( 'repository' )
		);
	}

	public function test_an_address_whose_repository_is_not_known_yet_drops_the_results_too(): void {
		$this->state->bind( 'first', self::NOW );
		$this->remember_results();

		self::assertTrue( $this->state->bind( '', self::NOW + 60 ) );
		self::assertSame( [], $this->state->get( 'last_backup' ) );

		$this->state->set( 'last_backup', [ 'job' => 'cannot happen before the repository is opened' ] );
		self::assertTrue( $this->state->bind( 'first', self::NOW + 120 ), 'Even the old repository again: nothing tells that nothing happened to it in between.' );
		self::assertSame( [], $this->state->get( 'last_backup' ) );
		self::assertSame( self::NOW + 120, $this->state->get( 'repository' )['since'] ?? null );
	}

	public function test_storing_an_entry_keeps_what_another_request_stored_since_this_one_read(): void {
		$options                = new InMemoryOptions();
		$options->request_cache = true;
		$state                  = new SiteState( $options );
		// A job's tick reads the state when it starts and records its result many seconds later.
		self::assertSame( [], $state->get( 'last_backup' ) );
		$options->values[ SiteState::OPTION ] = [ 'recovery_kit' => [ 'views' => [ [ 'user' => 1 ] ] ] ];

		$state->set( 'last_backup', [ 'job' => 'the job of this tick' ] );

		self::assertSame(
			[
				'recovery_kit' => [ 'views' => [ [ 'user' => 1 ] ] ],
				'last_backup'  => [ 'job' => 'the job of this tick' ],
			],
			$options->values[ SiteState::OPTION ]
		);
		self::assertSame( [ 'views' => [ [ 'user' => 1 ] ] ], $state->get( 'recovery_kit' ), 'The request now knows it as well.' );
	}

	public function test_an_entry_is_updated_from_what_is_stored_now(): void {
		$options                = new InMemoryOptions();
		$options->request_cache = true;
		$state                  = new SiteState( $options );
		self::assertSame( [], $state->get( 'schedule' ) );
		$options->values[ SiteState::OPTION ] = [ 'schedule' => [ 'backup_slot' => 12 ] ];

		$state->update( 'schedule', static fn ( array $schedule ): array => [ 'forget_for' => 'a job' ] + $schedule );

		self::assertSame(
			[
				'forget_for'  => 'a job',
				'backup_slot' => 12,
			],
			$options->values[ SiteState::OPTION ]['schedule']
		);
	}

	public function test_binding_a_repository_keeps_what_another_request_stored_since_this_one_read(): void {
		$options                = new InMemoryOptions();
		$options->request_cache = true;
		$state                  = new SiteState( $options );
		self::assertSame( [], $state->get( 'repository' ) );
		$options->values[ SiteState::OPTION ] = [ 'schedule' => [ 'backup_slot' => 12 ] ];

		$state->bind( 'first', self::NOW );

		self::assertSame( [ 'backup_slot' => 12 ], $options->values[ SiteState::OPTION ]['schedule'] );
		self::assertSame( 'first', $options->values[ SiteState::OPTION ]['repository']['id'] );
	}

	public function test_binding_the_bound_repository_again_writes_nothing(): void {
		$options = new InMemoryOptions();
		$state   = new SiteState( $options );
		$state->bind( 'first', self::NOW );
		$writes = $options->writes;

		self::assertFalse( $state->bind( 'first', self::NOW + 60 ) );

		self::assertSame( $writes, $options->writes );
	}

	public function test_a_change_holds_the_lock_from_reading_to_writing(): void {
		$directory = TempDir::create( 'state' );
		try {
			$options = new InMemoryOptions();
			$state   = new SiteState( $options, new FileLock( $directory . '/state.lock' ) );
			$busy    = null;
			// What another request would meet that wants to change the state at this moment.
			$options->meanwhile = static function () use ( $directory, &$busy ): void {
				$handle = fopen( $directory . '/state.lock', 'c' );
				self::assertNotFalse( $handle );
				$busy = ! flock( $handle, LOCK_EX | LOCK_NB );
				fclose( $handle );
			};

			$state->set( 'last_backup', [ 'job' => 'a job' ] );

			self::assertTrue( $busy, 'It waits until this change is written.' );
			$handle = fopen( $directory . '/state.lock', 'c' );
			self::assertNotFalse( $handle );
			self::assertTrue( flock( $handle, LOCK_EX | LOCK_NB ), 'Afterwards the lock is free.' );
			fclose( $handle );
		} finally {
			TempDir::remove( $directory );
		}
	}
}
