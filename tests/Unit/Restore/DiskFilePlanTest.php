<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** The claims and sealed totals of an undo review and the paged list of the entries an undo kept. */
final class DiskFilePlanTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'disk-plan' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private static function row( int $number ): array {
		return ApplyManifest::row( Bytes::base64url_encode( '/site/file-' . $number ), '/site/file-' . $number, 'keep', 'changed_since_apply' );
	}

	public function test_a_claim_is_withdrawn_only_for_the_row_that_made_it(): void {
		$plan = new DiskFilePlan( $this->directory );
		self::assertSame( 3, $plan->claim( 'remove', '/site/new.txt', 3 ) );
		self::assertSame( 3, $plan->claim( 'remove', '/site/new.txt', 5 ) );
		// Another row, another kind or another name leaves the claim.
		$plan->release( 'remove', '/site/new.txt', 5 );
		$plan->release( 'mkdir', '/site/new.txt', 3 );
		$plan->release( 'remove', '/site/other.txt', 3 );
		self::assertSame( 3, $plan->lookup( 'remove', '/site/new.txt' ) );
		$plan->release( 'remove', '/site/new.txt', 3 );
		self::assertNull( $plan->lookup( 'remove', '/site/new.txt' ) );
		$plan->flush();
		// The withdrawal is in the registry on disk, and the name can be claimed again.
		$again = new DiskFilePlan( $this->directory );
		self::assertNull( $again->lookup( 'remove', '/site/new.txt' ) );
		self::assertSame( 4, $again->claim( 'remove', '/site/new.txt', 4 ) );
		$again->flush();
		self::assertSame( 4, ( new DiskFilePlan( $this->directory ) )->lookup( 'remove', '/site/new.txt' ) );
	}

	public function test_an_undo_review_seals_its_totals_by_action(): void {
		$plan = new DiskFilePlan( $this->directory );
		$plan->put( 'rows', 0, self::row( 0 ) );
		$digest = $plan->seal(
			'apply',
			'restore',
			true,
			false,
			[ 'rows' => 1 ],
			0,
			0,
			0,
			[
				'restore' => 0,
				'remove'  => 0,
				'keep'    => 1,
			]
		);
		$header = ( new DiskFilePlan( $this->directory ) )->open( $digest );
		self::assertSame(
			[
				'keep'    => 1,
				'remove'  => 0,
				'restore' => 0,
			],
			$header['actions']
		);
		// A review sealed before the totals existed has none.
		$plain = $plan->seal( 'apply', 'restore', true, false, [ 'rows' => 1 ], 1, 0, 0 );
		self::assertNull( ( new DiskFilePlan( $this->directory ) )->open( $plain )['actions'] );
	}

	public function test_invalid_totals_make_the_sealed_plan_unreadable(): void {
		$plan = new DiskFilePlan( $this->directory );
		$plan->put( 'rows', 0, self::row( 0 ) );
		$plan->seal( 'apply', 'restore', true, false, [ 'rows' => 1 ], 0, 0, 0, [ 'keep' => 1 ] );
		$header            = json_decode( (string) file_get_contents( $this->directory . '/sealed.json' ), true, flags: JSON_THROW_ON_ERROR );
		$header['actions'] = [ 'keep' => DiskFilePlan::MAX_ENTRIES ];
		$body              = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		file_put_contents( $this->directory . '/sealed.json', $body );
		self::assertSame( [ 'keep' => DiskFilePlan::MAX_ENTRIES ], ( new DiskFilePlan( $this->directory ) )->open( hash( 'sha256', $body ) )['actions'] );
		foreach ( [ [ 'keep' => -1 ], [ 'keep' => '1' ], [ 'keep' => DiskFilePlan::MAX_ENTRIES + 1 ], [ 3 ], 'keep' ] as $actions ) {
			$header            = json_decode( (string) file_get_contents( $this->directory . '/sealed.json' ), true, flags: JSON_THROW_ON_ERROR );
			$header['actions'] = $actions;
			$body              = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			file_put_contents( $this->directory . '/sealed.json', $body );
			try {
				( new DiskFilePlan( $this->directory ) )->open( hash( 'sha256', $body ) );
				self::fail( 'Invalid totals must not be read.' );
			} catch ( StorageFailed $refused ) {
				self::assertStringContainsString( 'invalid totals', $refused->getMessage() );
			}
		}
	}

	public function test_a_durable_index_is_replaced_as_a_whole_and_a_torn_slot_is_refused(): void {
		$kept  = new DiskFilePlan( $this->directory, true );
		$index = $this->directory . '/rows.idx';
		$kept->put( 'rows', 0, self::row( 0 ) );
		$kept->flush();
		clearstatcache();
		$committed = [ fileinode( $index ), (string) file_get_contents( $index ) ];
		$kept->put( 'rows', 1, self::row( 1 ) );
		$kept->flush();
		clearstatcache();
		// The page's new slot is in a new file, which replaced the committed one only once it was
		// complete: a stop or a short write while it was written leaves the committed slot.
		self::assertNotSame( $committed[0], fileinode( $index ) );
		self::assertSame( 64, filesize( $index ) );
		self::assertNotSame( $committed[1], file_get_contents( $index ) );
		self::assertSame( [], glob( $this->directory . '/*.tmp' ) );
		// A slot with parts of two digests, as a write in place could leave it, matches the counted
		// records in no way and is refused.
		file_put_contents( $index, substr( $committed[1], 0, 32 ) . substr( (string) file_get_contents( $index ), 32 ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'does not match its index' );
		( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => 1 ], 0, 0, null, [ 'keep' => 1 ] );
	}

	public function test_a_page_written_before_its_slot_is_cut_to_its_counted_records_when_sealed(): void {
		$kept = new DiskFilePlan( $this->directory, true );
		$kept->put( 'rows', 0, self::row( 0 ) );
		$kept->flush();
		$slot = (string) file_get_contents( $this->directory . '/rows.idx' );
		// The worker wrote the page with the next record and was stopped before that page's slot.
		$kept->put( 'rows', 1, self::row( 1 ) );
		$kept->flush();
		file_put_contents( $this->directory . '/rows.idx', $slot );
		$digest = ( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => 1 ], 0, 0, null, [ 'keep' => 1 ] );
		$sealed = new DiskFilePlan( $this->directory, true );
		self::assertSame( 1, $sealed->open( $digest )['entries'] );
		self::assertSame( [ self::row( 0 ) ], $sealed->page( 0 ) );
		// A page whose counted records are not the ones its slot was written for is refused.
		file_put_contents( $this->directory . '/rows-0', (string) json_encode( [ self::row( 2 ), self::row( 1 ) ], JSON_UNESCAPED_SLASHES ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'does not match its index' );
		( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => 1 ], 0, 0, null, [ 'keep' => 1 ] );
	}

	public function test_a_page_written_beyond_the_count_is_cut_when_the_list_is_sealed(): void {
		$kept = new DiskFilePlan( $this->directory, true );
		for ( $number = 0; $number <= DiskFilePlan::PAGE; ++$number ) {
			$kept->put( 'rows', $number, self::row( $number ) );
			$kept->flush();
		}
		self::assertSame( 128, filesize( $this->directory . '/rows.idx' ) );
		// The worker was stopped before its document counted the entry that began page two.
		$digest = ( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => DiskFilePlan::PAGE ], 0, 0, null, [ 'keep' => DiskFilePlan::PAGE ] );
		clearstatcache();
		self::assertSame( 64, filesize( $this->directory . '/rows.idx' ) );
		$sealed = new DiskFilePlan( $this->directory, true );
		self::assertSame( DiskFilePlan::PAGE, $sealed->open( $digest )['entries'] );
		self::assertCount( DiskFilePlan::PAGE, $sealed->page( 0 ) );
		self::assertSame( Bytes::base64url_encode( '/site/file-99' ), $sealed->row( 99 )['path'] );
		$this->expectException( StorageFailed::class );
		$sealed->row( 100 );
	}
}
