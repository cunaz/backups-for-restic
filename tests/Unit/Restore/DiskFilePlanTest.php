<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Storage\Files;
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

	private static function row( int $number, string $reason = 'changed_since_apply' ): array {
		return ApplyManifest::row( Bytes::base64url_encode( '/site/file-' . $number ), '/site/file-' . $number, 'keep', $reason );
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

	public function test_a_durable_plan_writes_each_page_as_a_new_version_and_keeps_the_counted_one(): void {
		$events = [];
		$kept   = new DiskFilePlan(
			$this->directory,
			true,
			function ( string $operation, string $path ) use ( &$events ): void {
				$events[] = $operation . ' ' . ( str_ends_with( $path, '.tmp' ) ? 'temporary' : basename( $path ) );
			}
		);
		$names  = function (): array {
			$found = glob( $this->directory . '/rows*' );
			$names = array_map( basename( ... ), false === $found ? [] : $found );
			sort( $names );
			return $names;
		};
		$kept->put( 'rows', 0, self::row( 0 ) );
		$kept->flush();
		self::assertSame( [ 'rows-0.1' ], $names() );
		// The next record's version is a new file: the one that the document counts stays.
		$kept->put( 'rows', 1, self::row( 1 ) );
		$kept->flush();
		self::assertSame( [ 'rows-0.1', 'rows-0.2' ], $names() );
		// Once the document counts two, the version with one record is removed after the next.
		$kept->put( 'rows', 2, self::row( 2 ) );
		$kept->flush();
		self::assertSame( [ 'rows-0.2', 'rows-0.3' ], $names() );
		self::assertSame( [ 'sync temporary', 'rename rows-0.3', 'sync ' . basename( $this->directory ), 'unlink rows-0.1' ], array_slice( $events, -4 ) );
		self::assertSame( [ self::row( 0 ), self::row( 1 ), self::row( 2 ) ], json_decode( (string) file_get_contents( $this->directory . '/rows-0.3' ), true, flags: JSON_THROW_ON_ERROR ) );
		// No index exists before the plan is sealed, which computes it from the counted version.
		self::assertFileDoesNotExist( $this->directory . '/rows.idx' );
		$sealed = new DiskFilePlan( $this->directory, true );
		self::assertSame( 2, $sealed->open( ( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => 2 ], 0, 0, null, [ 'keep' => 2 ] ) )['version'] );
		self::assertSame( [ self::row( 0 ), self::row( 1 ) ], $sealed->page( 0 ) );
		self::assertSame( [], glob( $this->directory . '/*.tmp' ) );
	}

	public function test_the_records_that_a_count_names_are_sealed_after_a_stop_at_any_write(): void {
		$store = $this->directory . '/job';
		$plan  = $store . '/kept';
		$count = $store . '/count';
		mkdir( $plan, 0700, true );
		$taken = [];
		// What the disk holds after each synchronisation, rename and removal of the plan and of
		// the document that counts its records: what a stop right after that write leaves.
		$snap = function ( string $operation, string $path ) use ( &$taken, $store ): void {
			$taken[] = [ $operation . ' ' . basename( $path ), self::files( $store ) ];
		};
		// A worker that keeps one entry: its page version, then the document that counts it.
		$keep = static function ( int $counted, array $record, ?\Closure $observer ) use ( $plan, $count ): void {
			$kept = new DiskFilePlan( $plan, true, $observer );
			$kept->put( 'rows', $counted, $record );
			$kept->flush();
			Files::write_durable( $count, (string) ( $counted + 1 ), $observer );
		};
		$rows = [];
		for ( $number = 0; $number < 104; ++$number ) {
			$rows[] = self::row( $number );
			// Every write is observed around the end of the first page.
			$keep( $number, $rows[ $number ], $number >= 97 ? $snap : null );
		}
		self::assertSame( [ 'rows-0.100', 'rows-1.3', 'rows-1.4' ], array_values( array_filter( array_keys( self::files( $plan ) ), static fn ( string $name ): bool => str_starts_with( $name, 'rows' ) ) ) );
		$first = $taken;
		// Three writes of the page and three of the document for each of the seven entries, and
		// the removal of the version that the count replaced, which the second record of a
		// page does not have.
		self::assertCount( 7 * 6 + 6, $first );
		$checked = 0;
		foreach ( $first as [ $after, $files ] ) {
			$counted = $this->sealed_after( $store, $files, $rows, $after );
			// The worker comes back and keeps the same entry for another reason, and is stopped
			// again at any write: the list holds the counted entries, with the reason of the
			// version that the document counted.
			self::restore( $store, $files );
			$taken = [];
			$again = self::row( $counted, 'deleted_since_apply' );
			$keep( $counted, $again, $snap );
			self::assertContains( 'rename count', array_column( $taken, 0 ) );
			foreach ( $taken as [ $retried, $retry_files ] ) {
				$this->sealed_after( $store, $retry_files, [ ...array_slice( $rows, 0, $counted ), $again ], $after . ', then ' . $retried );
				++$checked;
			}
		}
		self::assertGreaterThan( 6 * count( $first ), $checked );
	}

	/**
	 * Puts a stopped worker's files back, seals the plan with the count that its document
	 * holds, and reads every counted record.
	 *
	 * @param string                    $store Directory of the plan and its document.
	 * @param array<string,string>      $files What the disk held.
	 * @param list<array<string,mixed>> $expected Records in the order they were counted.
	 * @param string                    $stop Where the worker was stopped.
	 * @return int The count of the document.
	 */
	private function sealed_after( string $store, array $files, array $expected, string $stop ): int {
		self::restore( $store, $files );
		$count  = (int) file_get_contents( $store . '/count' );
		$digest = ( new DiskFilePlan( $store . '/kept', true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => $count ], 0, 0, null, [ 'keep' => $count ] );
		$sealed = new DiskFilePlan( $store . '/kept', true );
		self::assertSame( $count, $sealed->open( $digest )['entries'], $stop );
		$read = [];
		for ( $offset = 0; $offset < $count; $offset += DiskFilePlan::PAGE ) {
			$read = [ ...$read, ...$sealed->page( $offset ) ];
		}
		self::assertSame( array_slice( $expected, 0, $count ), $read, $stop );
		return $count;
	}

	/**
	 * The files below a directory, by relative path.
	 *
	 * @return array<string,string>
	 */
	private static function files( string $directory ): array {
		$files = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[ substr( $file->getPathname(), strlen( $directory ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Makes a directory hold exactly the given files.
	 *
	 * @param array<string,string> $files Contents by relative path.
	 */
	private static function restore( string $directory, array $files ): void {
		TempDir::remove( $directory );
		foreach ( $files as $name => $content ) {
			if ( ! is_dir( dirname( $directory . '/' . $name ) ) ) {
				mkdir( dirname( $directory . '/' . $name ), 0700, true );
			}
			file_put_contents( $directory . '/' . $name, $content );
		}
		if ( ! is_dir( $directory . '/kept' ) ) {
			mkdir( $directory . '/kept', 0700, true );
		}
	}

	public function test_a_counted_version_that_does_not_hold_its_records_is_refused(): void {
		$kept = new DiskFilePlan( $this->directory, true );
		$kept->put( 'rows', 0, self::row( 0 ) );
		$kept->flush();
		$kept->put( 'rows', 1, self::row( 1 ) );
		$kept->flush();
		// A version named for two records that holds one is not sealed as two.
		file_put_contents( $this->directory . '/rows-0.2', (string) json_encode( [ self::row( 1 ) ], JSON_UNESCAPED_SLASHES ) );
		try {
			( new DiskFilePlan( $this->directory, true ) )->seal( 'apply', 'restore', true, false, [ 'rows' => 2 ], 0, 0, null, [ 'keep' => 2 ] );
			self::fail( 'A damaged version must not be sealed.' );
		} catch ( StorageFailed $refused ) {
			self::assertStringContainsString( 'does not hold the records', $refused->getMessage() );
		}
		// Nor is one put on as the base of the next record, or a missing counted version.
		$this->expectException( StorageFailed::class );
		( new DiskFilePlan( $this->directory, true ) )->put( 'rows', 2, self::row( 2 ) );
	}

	public function test_a_page_begun_beyond_the_count_is_not_sealed(): void {
		$kept = new DiskFilePlan( $this->directory, true );
		for ( $number = 0; $number <= DiskFilePlan::PAGE; ++$number ) {
			$kept->put( 'rows', $number, self::row( $number ) );
			$kept->flush();
		}
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
