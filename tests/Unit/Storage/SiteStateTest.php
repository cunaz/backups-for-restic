<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\SiteState;
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
}
