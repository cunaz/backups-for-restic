<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\KeptList;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The list of the entries that an undo kept: written entry by entry while a job document counts
 * them and names the digest of the last page, and sealed with what the document names.
 */
final class KeptListTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'kept-list' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * A kept entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( int $number, string $reason = 'changed_since_apply' ): array {
		return ApplyManifest::row( Bytes::base64url_encode( '/site/file-' . $number ), '/site/file-' . $number, 'keep', $reason );
	}

	/**
	 * Keeps the entries one by one, as an undo does with what its document counts.
	 *
	 * @param list<array<string, mixed>> $rows Entries.
	 * @return string|null Digest of the last page, as the document then names it.
	 */
	private static function keep( KeptList $kept, array $rows ): ?string {
		$digest = null;
		foreach ( $rows as $count => $row ) {
			$digest = $kept->append( $count, $digest, $row );
		}
		return $digest;
	}

	/**
	 * Every entry of a sealed list, read as the undo's readers read it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function entries( string $directory, string $sealed ): array {
		$plan  = new DiskFilePlan( $directory );
		$count = $plan->open( $sealed )['entries'];
		$read  = [];
		for ( $offset = 0; $offset < $count; $offset += DiskFilePlan::PAGE ) {
			$read = [ ...$read, ...$plan->page( $offset ) ];
		}
		return $read;
	}

	/**
	 * The names of the list's files.
	 *
	 * @return list<string>
	 */
	private function names(): array {
		$found = glob( $this->directory . '/rows*' );
		$names = array_map( basename( ... ), false === $found ? [] : $found );
		sort( $names, SORT_STRING );
		return $names;
	}

	public function test_each_entry_writes_a_new_version_whose_digest_the_document_saves(): void {
		$events = [];
		$list   = new KeptList(
			$this->directory,
			function ( string $operation, string $path ) use ( &$events ): void {
				$events[] = $operation . ' ' . ( str_ends_with( $path, '.tmp' ) ? 'temporary' : basename( $path ) );
			}
		);
		$first  = $list->append( 0, null, self::row( 0 ) );
		self::assertSame( hash( 'sha256', (string) file_get_contents( $this->directory . '/rows-0.1' ) ), $first );
		self::assertSame( [ 'rows-0.1' ], $this->names() );
		// The next entry's version is a new file: the one that the document counts stays.
		$second = $list->append( 1, $first, self::row( 1 ) );
		self::assertSame( [ 'rows-0.1', 'rows-0.2' ], $this->names() );
		// Once the document counts two, the version with one entry is removed after the next.
		$third = $list->append( 2, $second, self::row( 2 ) );
		self::assertSame( [ 'rows-0.2', 'rows-0.3' ], $this->names() );
		self::assertSame( [ 'sync temporary', 'rename rows-0.3', 'sync ' . basename( $this->directory ), 'unlink rows-0.1' ], array_slice( $events, -4 ) );
		self::assertSame( [ self::row( 0 ), self::row( 1 ), self::row( 2 ) ], json_decode( (string) file_get_contents( $this->directory . '/rows-0.3' ), true, flags: JSON_THROW_ON_ERROR ) );
		// No index while no page is full; the seal gives the last page its slot.
		self::assertSame( [ 'rows-0.2', 'rows-0.3' ], $this->names() );
		$sealed = $list->seal( 'apply', 'restore', 3, $third );
		self::assertSame( $third, (string) file_get_contents( $this->directory . '/rows.idx' ) );
		self::assertSame( [ self::row( 0 ), self::row( 1 ), self::row( 2 ) ], $this->entries( $this->directory, $sealed ) );
		// A seal after a stop of this one writes the same summary.
		self::assertSame( $sealed, $list->seal( 'apply', 'restore', 3, $third ) );
		self::assertSame( [], glob( $this->directory . '/*.tmp' ) );
	}

	public function test_the_entries_that_the_document_counts_are_sealed_after_a_stop_at_any_write(): void {
		$store    = $this->directory . '/job';
		$document = $store . '/document';
		mkdir( $store . '/kept', 0700, true );
		$taken = [];
		// What the disk holds after each synchronisation, rename and removal of the list and of
		// the document that counts it: what a stop right after that write leaves.
		$snap = static function ( string $operation, string $path ) use ( &$taken, $store ): void {
			$taken[] = [ $operation . ' ' . basename( $path ), self::files( $store ) ];
		};
		// An undo that keeps one entry: the index at the start of a page, the page's version, then
		// the document with the count and the digest that it names.
		$keep   = static function ( int $counted, ?string $digest, array $row, ?\Closure $observer ) use ( $store, $document ): void {
			$named = ( new KeptList( $store . '/kept', $observer ) )->append( $counted, $digest, $row );
			Files::write_durable( $document, (string) json_encode( [ $counted + 1, $named ] ), $observer );
		};
		$rows   = [];
		$digest = null;
		for ( $number = 0; $number < 104; ++$number ) {
			$rows[] = self::row( $number );
			// Every write is observed around the end of the first page.
			$keep( $number, $digest, $rows[ $number ], $number >= 97 ? $snap : null );
			$digest = json_decode( (string) file_get_contents( $document ), true, flags: JSON_THROW_ON_ERROR )[1];
		}
		$first = $taken;
		// Three writes of the page and three of the document for each of the seven entries, the
		// removal of the version that the count replaced, which the second entry of a page does
		// not have, and three writes of the index when the document counts the full first page.
		self::assertCount( 7 * 6 + 6 + 3, $first );
		self::assertContains( 'rename rows.idx', array_column( $first, 0 ) );
		$checked = 0;
		foreach ( $first as [ $after, $files ] ) {
			[ $count, $named ] = $this->sealed_after( $store, $files, $rows, $after );
			// The worker comes back and keeps the same entry for another reason, and is stopped
			// again at any write: the list holds the counted entries, with the reason of the
			// version that the document counted.
			self::restore( $store, $files );
			$taken = [];
			$again = self::row( $count, 'deleted_since_apply' );
			$keep( $count, $named, $again, $snap );
			self::assertContains( 'rename document', array_column( $taken, 0 ) );
			foreach ( $taken as [ $retried, $retry_files ] ) {
				$this->sealed_after( $store, $retry_files, [ ...array_slice( $rows, 0, $count ), $again ], $after . ', then ' . $retried );
				++$checked;
			}
		}
		self::assertGreaterThan( 6 * count( $first ), $checked );
	}

	/**
	 * Puts back what a stopped worker left, seals the list with the count and digest that its
	 * document holds, and reads every counted entry.
	 *
	 * @param string                     $store Directory of the list and its document.
	 * @param array<string, string>      $files What the disk held.
	 * @param list<array<string, mixed>> $expected Entries in the order they were counted.
	 * @param string                     $stop Where the worker was stopped.
	 * @return array{0: int, 1: string|null} The count and digest of the document.
	 */
	private function sealed_after( string $store, array $files, array $expected, string $stop ): array {
		self::restore( $store, $files );
		[ $count, $digest ] = json_decode( (string) file_get_contents( $store . '/document' ), true, flags: JSON_THROW_ON_ERROR );
		$sealed             = ( new KeptList( $store . '/kept' ) )->seal( 'apply', 'restore', $count, $digest );
		self::assertSame( array_slice( $expected, 0, $count ), $this->entries( $store . '/kept', $sealed ), $stop );
		return [ $count, $digest ];
	}

	/**
	 * The files below a directory, by relative path.
	 *
	 * @return array<string, string>
	 */
	private static function files( string $directory ): array {
		$files = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[ substr( $file->getPathname(), strlen( $directory ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Makes a directory hold exactly the given files.
	 *
	 * @param array<string, string> $files Contents by relative path.
	 */
	private static function restore( string $directory, array $files ): void {
		TempDir::remove( $directory );
		mkdir( $directory . '/kept', 0700, true );
		foreach ( $files as $name => $content ) {
			file_put_contents( $directory . '/' . $name, $content );
		}
	}

	public function test_a_counted_last_page_that_changed_on_disk_is_refused(): void {
		$list   = new KeptList( $this->directory );
		$digest = (string) self::keep( $list, [ self::row( 0 ), self::row( 1 ), self::row( 2 ) ] );
		// The counted version is written again with valid entries, as many as before.
		file_put_contents( $this->directory . '/rows-0.3', (string) json_encode( [ self::row( 0 ), self::row( 1 ), self::row( 7 ) ], JSON_UNESCAPED_SLASHES ) );
		$calls = [
			'seal'       => static fn () => $list->seal( 'apply', 'restore', 3, $digest ),
			'next entry' => static fn () => $list->append( 3, $digest, self::row( 3 ) ),
		];
		foreach ( $calls as $case => $call ) {
			try {
				$call();
				self::fail( 'A changed page must be refused: ' . $case );
			} catch ( StorageFailed $refused ) {
				self::assertSame( 'A page of the kept entries changed after it was written.', $refused->getMessage(), $case );
			}
		}
		self::assertFileDoesNotExist( $this->directory . '/sealed.json' );
		self::assertFileDoesNotExist( $this->directory . '/rows-0.4' );
	}

	public function test_a_seal_reads_only_the_last_page(): void {
		$list   = new KeptList( $this->directory );
		$rows   = array_map( self::row( ... ), range( 0, 349 ) );
		$digest = (string) self::keep( $list, $rows );
		// Every full page goes before the seal: it would fail at the first one that it read.
		foreach ( [ 0, 1, 2 ] as $page ) {
			unlink( $this->directory . '/' . DiskFilePlan::page_version( 'rows', $page, DiskFilePlan::PAGE ) );
		}
		$plan = new DiskFilePlan( $this->directory );
		self::assertSame( 350, $plan->open( $list->seal( 'apply', 'restore', 350, $digest ) )['entries'] );
		self::assertSame( array_slice( $rows, 300 ), $plan->page( 300 ) );
		$this->expectException( StorageFailed::class );
		$plan->page( 0 );
	}

	public function test_a_full_page_that_changed_on_disk_is_refused_when_it_is_read(): void {
		$list   = new KeptList( $this->directory );
		$rows   = array_map( self::row( ... ), range( 0, 149 ) );
		$digest = (string) self::keep( $list, $rows );
		// The first page's version is written again with valid entries, as many as before. Its
		// slot came from the document when the document counted that page.
		$changed      = $rows;
		$changed[42]  = self::row( 999 );
		$first_page   = $this->directory . '/' . DiskFilePlan::page_version( 'rows', 0, DiskFilePlan::PAGE );
		$first_digest = hash( 'sha256', (string) file_get_contents( $first_page ) );
		file_put_contents( $first_page, (string) json_encode( array_slice( $changed, 0, DiskFilePlan::PAGE ), JSON_UNESCAPED_SLASHES ) );
		self::assertSame( $first_digest, substr( (string) file_get_contents( $this->directory . '/rows.idx' ), 0, 64 ) );
		$plan = new DiskFilePlan( $this->directory );
		$plan->open( $list->seal( 'apply', 'restore', 150, $digest ) );
		self::assertSame( array_slice( $rows, 100 ), $plan->page( 100 ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'A sealed file plan page changed.' );
		$plan->page( 0 );
	}

	public function test_a_full_page_gets_its_slot_from_the_document_at_the_next_entry_or_at_the_seal(): void {
		$rows = array_map( self::row( ... ), range( 0, 100 ) );
		foreach ( [ 'sealed', 'continued' ] as $case ) {
			$directory = $this->directory . '/' . $case;
			mkdir( $directory );
			$list   = new KeptList( $directory );
			$digest = (string) self::keep( $list, array_slice( $rows, 0, DiskFilePlan::PAGE ) );
			// The document counts the full page, and the index has no slot for it yet: a stop
			// before the next entry, or an undo that kept no more entries.
			self::assertFileDoesNotExist( $directory . '/rows.idx' );
			if ( 'sealed' === $case ) {
				// The cancelled undo seals the list without a further entry.
				self::assertSame( array_slice( $rows, 0, DiskFilePlan::PAGE ), $this->entries( $directory, $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, $digest ) ) );
				self::assertSame( $digest, (string) file_get_contents( $directory . '/rows.idx' ), $case );
				continue;
			}
			$next = $list->append( DiskFilePlan::PAGE, $digest, $rows[ DiskFilePlan::PAGE ] );
			self::assertSame( $digest, (string) file_get_contents( $directory . '/rows.idx' ), $case );
			// A stop after that slot, before the document counted the next entry: the same again.
			self::assertSame( $next, $list->append( DiskFilePlan::PAGE, $digest, $rows[ DiskFilePlan::PAGE ] ) );
			self::assertSame( $rows, $this->entries( $directory, $list->seal( 'apply', 'restore', DiskFilePlan::PAGE + 1, $next ) ) );
		}
	}

	public function test_a_page_begun_beyond_the_count_is_not_sealed(): void {
		$list   = new KeptList( $this->directory );
		$rows   = array_map( self::row( ... ), range( 0, DiskFilePlan::PAGE ) );
		$digest = (string) self::keep( $list, array_slice( $rows, 0, DiskFilePlan::PAGE ) );
		// The worker wrote the entry that begins page two and was stopped before the document
		// counted it.
		$list->append( DiskFilePlan::PAGE, $digest, $rows[ DiskFilePlan::PAGE ] );
		$plan = new DiskFilePlan( $this->directory );
		self::assertSame( DiskFilePlan::PAGE, $plan->open( $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, $digest ) )['entries'] );
		self::assertSame( 64, filesize( $this->directory . '/rows.idx' ) );
		self::assertSame( array_slice( $rows, 0, DiskFilePlan::PAGE ), $plan->page( 0 ) );
		$this->expectException( StorageFailed::class );
		$plan->row( DiskFilePlan::PAGE );
	}

	public function test_a_list_is_sealed_with_up_to_the_most_entries_that_a_plan_holds(): void {
		$list   = new KeptList( $this->directory );
		$digest = hash( 'sha256', 'page' );
		$most   = DiskFilePlan::MAX_ENTRIES;
		$cases  = [
			'no entry is appended to a full list' => [ static fn () => $list->append( $most, $digest, self::row( 0 ) ), 'The job does not name the counted page of its kept entries.' ],
			'no list is sealed beyond the limit'  => [ static fn () => $list->seal( 'apply', 'restore', $most + 1, $digest ), 'The job does not name the counted page of its kept entries.' ],
			// The count of a full list is accepted: the seal goes on to its last page, which is not here.
			'a full list is sealed'               => [ static fn () => $list->seal( 'apply', 'restore', $most, $digest ), 'A file of the kept entries is missing or unreadable.' ],
			'the last entry is appended'          => [ static fn () => $list->append( $most - 1, $digest, self::row( 0 ) ), 'A file of the kept entries is missing or unreadable.' ],
		];
		foreach ( $cases as $case => [ $call, $refusal ] ) {
			try {
				$call();
				self::fail( 'Refused: ' . $case );
			} catch ( StorageFailed $refused ) {
				self::assertSame( $refusal, $refused->getMessage(), $case );
			}
		}
	}

	public function test_what_the_document_does_not_name_is_refused(): void {
		$list   = new KeptList( $this->directory );
		$digest = (string) self::keep( $list, array_map( self::row( ... ), range( 0, DiskFilePlan::PAGE - 1 ) ) );
		$index  = $this->directory . '/rows.idx';
		$cases  = [
			'a count without a digest'            => [ static fn () => $list->append( DiskFilePlan::PAGE, null, self::row( 0 ) ), null, 'The job does not name the counted page of its kept entries.' ],
			'a digest without a count'            => [ static fn () => $list->append( 0, $digest, self::row( 0 ) ), null, 'The job does not name the counted page of its kept entries.' ],
			'a digest that is no digest'          => [ static fn () => $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, 'page' ), null, 'The job does not name the counted page of its kept entries.' ],
			'another digest of the last page'     => [ static fn () => $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, hash( 'sha256', 'other' ) ), null, 'A page of the kept entries changed after it was written.' ],
			'another slot for the full page'      => [ static fn () => $list->append( DiskFilePlan::PAGE, $digest, self::row( 0 ) ), hash( 'sha256', 'other' ), 'The index of the kept entries does not hold the pages that the job counts.' ],
			'another slot at the seal'            => [ static fn () => $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, $digest ), hash( 'sha256', 'other' ), 'The index of the kept entries does not hold the pages that the job counts.' ],
			'a slot more than the document names' => [ static fn () => $list->seal( 'apply', 'restore', DiskFilePlan::PAGE, $digest ), $digest . $digest, 'The index of the kept entries does not hold the pages that the job counts.' ],
			'a torn slot'                         => [ static fn () => $list->append( DiskFilePlan::PAGE, $digest, self::row( 0 ) ), substr( $digest, 0, 40 ), 'The index of the kept entries is damaged.' ],
		];
		foreach ( $cases as $case => [ $call, $slots, $refusal ] ) {
			if ( null === $slots ) {
				TempDir::remove( $index );
			} else {
				file_put_contents( $index, $slots );
			}
			try {
				$call();
				self::fail( 'Refused: ' . $case );
			} catch ( StorageFailed $refused ) {
				self::assertSame( $refusal, $refused->getMessage(), $case );
			}
		}
		// A counted version that is gone.
		TempDir::remove( $index );
		unlink( $this->directory . '/' . DiskFilePlan::page_version( 'rows', 0, DiskFilePlan::PAGE ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'A file of the kept entries is missing or unreadable.' );
		$list->seal( 'apply', 'restore', DiskFilePlan::PAGE, $digest );
	}
}
