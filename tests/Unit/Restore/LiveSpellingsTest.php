<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Restore\LiveSpellings;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** The name index that a live writer's rechecks share within one request. */
final class LiveSpellingsTest extends TestCase {

	private string $root;
	private string $folder;

	protected function setUp(): void {
		$this->root   = TempDir::create( 'live-spellings' );
		$this->folder = $this->root . '/folder';
		mkdir( $this->folder );
		touch( $this->folder . '/Alpha' );
		touch( $this->folder . '/beta' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	/** Waits until a change in the folder alters its identity, which a kept listing needs. */
	private function settle(): void {
		clearstatcache( true, $this->folder );
		$ready = (int) filectime( $this->folder ) + 1 + LiveSpellings::CLOCK_MARGIN;
		while ( microtime( true ) < $ready ) {
			usleep( 10000 );
		}
	}

	public function test_a_settled_folder_is_listed_once_and_compares_exact_and_folded_names(): void {
		$this->settle();
		$index = new LiveSpellings();
		self::assertTrue( $index->unique( $this->folder, 'Alpha' ) );
		self::assertFalse( $index->unique( $this->folder, 'alpha' ) );
		self::assertFalse( $index->unique( $this->folder, 'BETA' ) );
		self::assertTrue( $index->unique( $this->folder, 'beta' ) );
		self::assertTrue( $index->unique( $this->folder, 'gamma' ) );
		self::assertSame( 1, $index->listings() );
	}

	public function test_a_name_that_another_process_adds_is_found_at_once(): void {
		$this->settle();
		$index = new LiveSpellings();
		self::assertTrue( $index->unique( $this->folder, 'gamma' ) );
		touch( $this->folder . '/GAMMA' );
		self::assertFalse( $index->unique( $this->folder, 'gamma' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_replaced_folder_is_listed_again(): void {
		$this->settle();
		$index = new LiveSpellings();
		self::assertTrue( $index->unique( $this->folder, 'gamma' ) );
		rename( $this->folder, $this->root . '/previous' );
		mkdir( $this->folder );
		touch( $this->folder . '/Gamma' );
		self::assertFalse( $index->unique( $this->folder, 'gamma' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_narrow_folder_is_listed_again_after_each_own_change(): void {
		$this->settle();
		$index = new LiveSpellings();
		self::assertTrue( $index->unique( $this->folder, 'delta' ) );
		touch( $this->folder . '/delta' );
		$index->changed( $this->folder . '/delta', true );
		// Another process adds a second spelling in the same second: a fresh listing sees it.
		touch( $this->folder . '/DELTA' );
		self::assertFalse( $index->unique( $this->folder, 'delta' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_wide_folder_learns_own_changes_until_its_change_second_and_the_margin_pass(): void {
		$this->settle();
		$index = new LiveSpellings( LiveSpellings::MAX_NAMES, 2 );
		self::assertTrue( $index->unique( $this->folder, 'delta' ) );
		touch( $this->folder . '/delta' );
		$index->changed( $this->folder . '/delta', true );
		self::assertTrue( $index->unique( $this->folder, 'delta' ) );
		self::assertFalse( $index->unique( $this->folder, 'Delta' ) );
		unlink( $this->folder . '/beta' );
		$index->changed( $this->folder . '/beta', false );
		self::assertTrue( $index->unique( $this->folder, 'BETA' ) );
		self::assertSame( 1, $index->listings() );
		// Whatever another process changed in that second, the first recheck after it and the
		// margin lists the folder again.
		touch( $this->folder . '/EPSILON' );
		$this->settle();
		self::assertFalse( $index->unique( $this->folder, 'epsilon' ) );
		self::assertSame( 2, $index->listings() );
		self::assertTrue( $index->unique( $this->folder, 'zeta' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_wide_folder_that_looks_settled_after_an_own_change_is_listed_again(): void {
		$this->settle();
		$index = new LiveSpellings( LiveSpellings::MAX_NAMES, 2 );
		self::assertTrue( $index->unique( $this->folder, 'gamma' ) );
		// A change after which the folder's change time stays where it was, as under the
		// attribute cache of a network file system: its identity cannot show another change.
		$index->changed( $this->folder . '/delta', true );
		self::assertTrue( $index->unique( $this->folder, 'gamma' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_listing_within_the_folders_change_second_answers_only_its_own_check(): void {
		touch( $this->folder . '/recent' );
		$index = new LiveSpellings();
		self::assertTrue( $index->unique( $this->folder, 'recent' ) );
		self::assertFalse( $index->unique( $this->folder, 'RECENT' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_folder_beyond_the_capacity_is_listed_for_each_check(): void {
		$this->settle();
		$index = new LiveSpellings( 3 );
		self::assertFalse( $index->unique( $this->folder, 'ALPHA' ) );
		self::assertTrue( $index->unique( $this->folder, 'beta' ) );
		self::assertSame( 2, $index->listings() );
	}

	public function test_a_missing_folder_has_no_unique_name(): void {
		$index = new LiveSpellings();
		self::assertFalse( $index->unique( $this->root . '/missing', 'name' ) );
		$index->changed( $this->root . '/missing/name', true );
		self::assertSame( 0, $index->listings() );
	}
}
