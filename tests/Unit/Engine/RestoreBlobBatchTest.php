<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\BlobBatch;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\InsufficientDecodingMemory;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RestoreBlobBatchTest extends TestCase {

	private const int MIB = 1048576;

	private InMemoryBackend $backend;
	private CountingBackend $counted;
	private Repository $repository;
	private IndexSet $index;

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$this->counted    = new CountingBackend( $this->backend );
		$this->repository = Repository::initialize( $this->counted, 'test password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->index      = new IndexSet();
	}

	protected function tearDown(): void {
		// PHPUnit keeps each test object to the end of the run; its packs go with the test.
		unset( $this->backend, $this->counted, $this->repository, $this->index );
	}

	/** @param list<string> $values @return list<Id> */
	private function pack( array $values ): array {
		$writer = new PackWriter( $this->repository->cipher() );
		$ids    = [];
		foreach ( $values as $value ) {
			$id    = Id::of( $value );
			$ids[] = $id;
			$writer->add( BlobType::Data, $id, $value );
		}
		$pack = $writer->finish();
		$this->backend->save( ObjectType::Data, $pack->id->hex(), $pack->bytes );
		$this->index->add_pack( $pack->id, $pack->entries );
		return $ids;
	}

	/**
	 * Stores blobs compressed, back to back in one pack, as restic stores them in format 2.
	 *
	 * @param list<string> $values Contents.
	 * @return list<Id>
	 */
	private function compressed_pack( array $values ): array {
		$body    = '';
		$entries = [];
		foreach ( $values as $value ) {
			$sealed    = $this->repository->cipher()->seal( zstd_compress( $value, 3 ) );
			$entries[] = new PackEntry( BlobType::Data, Id::of( $value ), strlen( $body ), strlen( $sealed ), strlen( $value ) );
			$body     .= $sealed;
		}
		$pack = Id::of( $body );
		$this->backend->save( ObjectType::Data, $pack->hex(), $body );
		$this->index->add_pack( $pack, $entries );
		return array_map( static fn ( PackEntry $entry ): Id => $entry->id, $entries );
	}

	/**
	 * The index entries of blobs.
	 *
	 * @param list<Id> $ids Blob IDs.
	 * @return list<PackEntry>
	 */
	private function entries( array $ids ): array {
		return array_map(
			function ( Id $id ): PackEntry {
				$location = $this->index->find( $id, BlobType::Data );
				self::assertNotNull( $location );
				return $location->entry;
			},
			$ids
		);
	}

	/**
	 * Gives the repository a budget that leaves exactly the given bytes for reading blobs:
	 * MemoryBudget::measure() sees the memory reserved now.
	 */
	private function leave( int $bytes ): void {
		do {
			gc_mem_caches();
			$reserved = memory_get_usage( true );
			$this->repository->limit_memory( new MemoryBudget( $reserved + $bytes ) );
			gc_mem_caches();
		} while ( memory_get_usage( true ) !== $reserved );
	}

	/**
	 * Words of a small vocabulary, which zstd compresses about three times.
	 */
	private static function text( int $size, int $seed ): string {
		mt_srand( $seed );
		$words = [];
		for ( $i = 0; $i < 4096; ++$i ) {
			$words[] = bin2hex( random_bytes( 1 + $i % 6 ) );
		}
		$text   = '';
		$length = 0;
		while ( $length < $size ) {
			$word    = $words[ mt_rand( 0, 4095 ) ] . ' ';
			$text   .= $word;
			$length += strlen( $word );
		}
		return substr( $text, 0, $size );
	}

	/**
	 * Reads one group as RestoreRun does and collects what the writer is handed.
	 *
	 * @param list<Id> $content The file's ordered content IDs.
	 * @return array<int, string> Blob ordinal to authenticated bytes.
	 */
	private function read( array $content, int $first ): array {
		$read = [];
		BlobBatch::read(
			$this->repository,
			$this->index,
			$content,
			$first,
			static function ( int $number, string $plain ) use ( &$read ): void {
				$read[ $number ] = $plain;
			}
		);
		return $read;
	}

	/**
	 * Reads one group as RestoreRun does, with a writer that keeps no reference to what it is
	 * handed, as BlobBatch::read() requires, and gives the SHA-256 of each blob.
	 *
	 * @param list<Id> $content The file's ordered content IDs.
	 * @return array<int, string> Blob ordinal to the SHA-256 of its authenticated bytes.
	 */
	private function read_digests( array $content, int $first ): array {
		$read = [];
		BlobBatch::read(
			$this->repository,
			$this->index,
			$content,
			$first,
			static function ( int $number, string $plain ) use ( &$read ): void {
				$read[ $number ] = hash( 'sha256', $plain );
			}
		);
		return $read;
	}

	/**
	 * The SHA-256 of each value, by the same keys.
	 *
	 * @param array<int, string> $values Values.
	 * @return array<int, string>
	 */
	private static function digests( array $values ): array {
		return array_map( static fn ( string $value ): string => hash( 'sha256', $value ), $values );
	}

	public function test_adjacent_blobs_use_one_request_and_keep_file_order(): void {
		$values = [ 'first', 'second', 'third' ];
		$ids    = $this->pack( $values );
		self::assertSame( $values, $this->read( $ids, 0 ) );
		self::assertSame( 1, $this->counted->loads['data'] );
	}

	public function test_repeated_or_nonadjacent_blobs_stop_the_group_without_reordering(): void {
		$ids = $this->pack( [ 'first', 'middle', 'last' ] );
		self::assertSame( [ 'first' ], $this->read( [ $ids[0], $ids[0], $ids[2] ], 0 ) );
		self::assertSame( [ 1 => 'first' ], $this->read( [ $ids[0], $ids[0], $ids[2] ], 1 ) );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_cross_pack_groups_resume_at_the_next_blob(): void {
		$first = $this->pack( [ 'first' ] );
		$next  = $this->pack( [ 'second', 'third' ] );
		$ids   = [ ...$first, ...$next ];
		self::assertSame( [ 'first' ], $this->read( $ids, 0 ) );
		self::assertSame(
			[
				1 => 'second',
				2 => 'third',
			],
			$this->read( $ids, 1 )
		);
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_every_group_member_authenticates_before_it_is_written(): void {
		$ids      = $this->pack( [ 'first', 'second' ] );
		$location = $this->index->find( $ids[1], BlobType::Data );
		self::assertNotNull( $location );
		$pack   = $location->pack->hex();
		$offset = $location->entry->offset + 20;
		$this->backend->objects['data'][ $pack ][ $offset ] = chr( ord( $this->backend->objects['data'][ $pack ][ $offset ] ) ^ 1 );
		$written = [];
		try {
			BlobBatch::read(
				$this->repository,
				$this->index,
				$ids,
				0,
				static function ( int $number, string $plain ) use ( &$written ): void {
					$written[ $number ] = $plain;
				}
			);
			self::fail( 'The second blob does not authenticate.' );
		} catch ( AuthenticationFailed ) {
			self::assertSame( [ 'first' ], $written, 'The first blob was written, the second one not.' );
		}
	}

	public function test_an_absent_blob_is_not_treated_as_an_empty_file(): void {
		$this->expectException( FormatException::class );
		$this->read( [ Id::of( 'absent' ) ], 0 );
	}

	public function test_tiny_blobs_still_yield_after_the_group_work_limit(): void {
		$values = [];
		for ( $i = 0; $i < BlobBatch::MAX_BLOBS + 1; ++$i ) {
			$values[] = 'blob-' . $i;
		}
		$ids   = $this->pack( $values );
		$first = $this->read( $ids, 0 );
		self::assertSame( array_slice( $values, 0, BlobBatch::MAX_BLOBS ), $first );
		self::assertSame( [ BlobBatch::MAX_BLOBS => 'blob-' . BlobBatch::MAX_BLOBS ], $this->read( $ids, BlobBatch::MAX_BLOBS ) );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_oversized_blob_metadata_is_refused_before_a_download(): void {
		$id = Id::of( 'oversized' );
		$this->index->add_pack( Id::of( 'pack' ), [ new PackEntry( BlobType::Data, $id, 0, RabinChunker::MAX_SIZE + Cipher::OVERHEAD + 1 ) ] );
		try {
			$this->read( [ $id ], 0 );
			self::fail( 'An oversized data blob cannot consume remote bytes or memory.' );
		} catch ( FormatException ) {
			self::assertSame( 0, $this->counted->loads['data'] ?? 0 );
		}
	}

	public function test_a_group_ends_before_a_blob_whose_response_would_not_fit_the_memory_left(): void {
		$values  = [ random_bytes( 3 * self::MIB ), random_bytes( 3 * self::MIB ), random_bytes( self::MIB ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		// Two uncompressed blobs of 3 MiB: their response, held three times, takes more than
		// opening the first one beside it. Every string has memory of its own.
		$two = MemoryBudget::range( $entries[0]->length + $entries[1]->length, $entries[0], $entries[1] );
		self::assertSame( MemoryBudget::response( $entries[0]->length + $entries[1]->length ), $two );
		self::assertGreaterThan( MemoryBudget::opening( $entries[0], $entries[0]->length + $entries[1]->length ), $two );

		$this->leave( $two );
		self::assertSame( self::digests( [ $values[0], $values[1] ] ), $this->read_digests( $ids, 0 ), 'Exactly the two blobs fit.' );
		$this->leave( $two - 1 );
		self::assertSame( self::digests( [ $values[0] ] ), $this->read_digests( $ids, 0 ), 'One byte less, and the group ends after the first.' );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_a_group_ends_before_a_blob_that_would_be_opened_beside_more_than_the_memory_left(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// Text that compresses about three times. The frames of 7 MiB of text, and every range
		// that holds one, are larger than a block and take memory of their own: what decides
		// whether a group fits is then counted exactly, and PHP is not asked about the free pages
		// of its blocks (MemoryBudget::placed()).
		$values  = [ self::text( 7 * self::MIB, 1 ), self::text( self::MIB, 2 ), self::text( 7 * self::MIB, 3 ) ];
		$ids     = $this->compressed_pack( $values );
		$entries = $this->entries( $ids );
		self::assertGreaterThan( MemoryBudget::BLOCK, $entries[0]->length );
		self::assertGreaterThan( MemoryBudget::BLOCK, $entries[2]->length );
		// Compressed blobs are decoded into twice their content: the first one, opened beside the
		// whole group of two, is the most that reading the two takes.
		$two = MemoryBudget::range( $entries[0]->length + $entries[1]->length, $entries[0], $entries[1] );
		self::assertSame( MemoryBudget::opening( $entries[0], $entries[0]->length + $entries[1]->length ), $two );
		self::assertGreaterThan( MemoryBudget::response( $entries[0]->length + $entries[1]->length ), $two );

		$this->leave( $two );
		self::assertSame( self::digests( [ $values[0], $values[1] ] ), $this->read_digests( $ids, 0 ) );
		$this->leave( $two - 1 );
		self::assertSame( self::digests( [ $values[0] ] ), $this->read_digests( $ids, 0 ) );
		// The group of the last two: the last blob, opened by itself, takes the most.
		$last = MemoryBudget::range( $entries[1]->length + $entries[2]->length, $entries[1], $entries[2] );
		self::assertSame( MemoryBudget::opening( $entries[2], 0 ), $last );
		$this->leave( $last );
		self::assertSame(
			self::digests(
				[
					1 => $values[1],
					2 => $values[2],
				]
			),
			$this->read_digests( $ids, 1 )
		);
		$this->leave( $last - 1 );
		self::assertSame( self::digests( [ 1 => $values[1] ] ), $this->read_digests( $ids, 1 ) );
		self::assertSame( 4, $this->counted->loads['data'] );
	}

	/**
	 * In a process of its own, whose blocks are filled first so that the range must take a new
	 * block when it arrives: then less than a block is left, and measuring again would count a
	 * new block for the copies of the blob, which find room in the block of the range. The range
	 * arrived alone, so the blob is opened as planned.
	 */
	#[RunInSeparateProcess]
	public function test_a_blob_whose_range_took_the_block_of_its_plan_is_opened_as_planned(): void {
		$values  = [ random_bytes( 600000 ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		$alone   = MemoryBudget::range( $entries[0]->length, null, $entries[0] );
		self::assertSame( MemoryBudget::BLOCK, $alone );
		$expected = self::digests( $values );
		// Strings of the range's 147 pages fill every run of free pages that is as long, until PHP
		// takes a new block for one; a string of the 364 pages left fills that block.
		$fill = array_fill( 0, 4096, '' );
		$made = 0;
		$was  = memory_get_usage( true );
		while ( $made < 4095 && memory_get_usage( true ) === $was ) {
			$fill[ $made ] = str_repeat( 'f', 147 * 4096 - 32 );
			++$made;
		}
		$fill[ $made ] = str_repeat( 'f', 364 * 4096 - 32 );
		$this->leave( $alone );
		self::assertSame( $expected, $this->read_digests( $ids, 0 ), 'With exactly its need left, the blob is read.' );
		self::assertLessThan( 4095, $made, 'PHP took a new block before the room for the strings ran out.' );
	}

	public function test_a_first_blob_that_does_not_fit_the_memory_left_is_refused_before_a_download(): void {
		$values  = [ random_bytes( 600000 ), random_bytes( 3 * self::MIB ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		// A blob of 600,000 bytes: its three copies fit into one new block while it arrives, and
		// again while it is decrypted. The next blob, of 3 MiB, has memory of its own and does
		// not fit beside it.
		$alone = MemoryBudget::range( $entries[0]->length, null, $entries[0] );
		self::assertSame( MemoryBudget::BLOCK, $alone );

		$this->leave( $alone );
		self::assertSame( self::digests( [ $values[0] ] ), $this->read_digests( $ids, 0 ), 'With exactly its need left, the blob is read by itself.' );
		self::assertSame( 1, $this->counted->loads['data'] );
		$this->leave( $alone - 1 );
		$written = [];
		try {
			BlobBatch::read(
				$this->repository,
				$this->index,
				$ids,
				0,
				static function ( int $number ) use ( &$written ): void {
					$written[] = $number;
				}
			);
			self::fail( 'One byte less than the blob needs is not enough.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'The next blob of the restore does not fit beside the memory already in use.', $error->getMessage() );
			self::assertSame( 'restore_blob_memory', $error->context()['code'] );
			self::assertSame( $alone, $error->context()['blob_bytes'] );
			self::assertSame( $alone - 1, $error->context()['available'] );
			self::assertSame( $this->repository->memory()?->limit, $error->context()['memory_limit'] );
		}
		self::assertSame( [], $written );
		self::assertSame( 1, $this->counted->loads['data'], 'Nothing more was downloaded.' );
	}

	/**
	 * Memory that other code keeps while a group arrives, as a plugin that keeps the HTTP API's
	 * answers would, is noticed before a blob is opened: the restore refuses the blob instead of
	 * running into PHP's limit. The blob of 3 MiB and the 4 MiB kept have memory of their own, so
	 * what is left is counted exactly.
	 */
	public function test_memory_that_other_code_keeps_while_a_group_arrives_is_refused_before_a_blob_is_opened(): void {
		$values     = [ random_bytes( 3 * self::MIB ) ];
		$ids        = $this->pack( $values );
		$entries    = $this->entries( $ids );
		$alone      = MemoryBudget::range( $entries[0]->length, null, $entries[0] );
		$kept       = [];
		$repository = Repository::open(
			new HookedBackend(
				$this->backend,
				static function ( string $method, ?ObjectType $type ) use ( &$kept ): void {
					if ( 'load' === $method && ObjectType::Data === $type ) {
						$kept[] = str_repeat( 'k', 4 * self::MIB );
					}
				}
			),
			$this->repository->master_key(),
			new SystemRandom()
		);
		gc_mem_caches();
		$repository->limit_memory( new MemoryBudget( memory_get_usage( true ) + $alone ) );
		$written = [];
		try {
			BlobBatch::read(
				$repository,
				$this->index,
				$ids,
				0,
				static function ( int $number ) use ( &$written ): void {
					$written[] = $number;
				}
			);
			self::fail( 'Opening the blob no longer fits beside what other code kept.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'restore_blob_memory', $error->context()['code'] );
			self::assertSame( [ 'memory_limit', 'available', 'blob_bytes', 'code' ], array_keys( $error->context() ) );
		}
		self::assertSame( [], $written );
		self::assertCount( 1, $kept, 'The group was downloaded.' );
	}

	/**
	 * A frame without a declared size and with a window of 64 MiB, as a streaming encoder may
	 * write it: the decoder counts four times its window (Format\Compression), which the budget
	 * does not count for a blob of 1000 bytes and PHP's limit does not leave. That is a limit of
	 * the host, not damage, and the restore says so.
	 */
	public function test_a_blob_that_the_decoder_finds_too_large_for_the_memory_left_is_refused_as_restore_blob_memory(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$content = random_bytes( 1000 );
		$sealed  = $this->repository->cipher()->seal( "\x28\xb5\x2f\xfd\x00\x80" . substr( pack( 'V', strlen( $content ) << 3 | 1 ), 0, 3 ) . $content );
		$entry   = new PackEntry( BlobType::Data, Id::of( $content ), 0, strlen( $sealed ), strlen( $content ) );
		$pack    = Id::of( $sealed );
		$this->backend->save( ObjectType::Data, $pack->hex(), $sealed );
		$this->index->add_pack( $pack, [ $entry ] );
		$limit = (string) ini_get( 'memory_limit' );
		try {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The decoder reads PHP's own limit; this test without WordPress sets it as a host would.
			self::assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 64 * self::MIB ) ) );
			$this->leave( 16 * self::MIB );
			$this->read( [ $entry->id ], 0 );
			self::fail( 'The decoder refuses the frame.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'The next blob of the restore does not fit beside the memory already in use.', $error->getMessage() );
			self::assertSame( [ 'blob_bytes', 'code' ], array_keys( $error->context() ) );
			self::assertSame( 'restore_blob_memory', $error->context()['code'] );
			self::assertInstanceOf( InsufficientDecodingMemory::class, $error->getPrevious() );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
			ini_set( 'memory_limit', $limit );
		}
		self::assertSame( 1, $this->counted->loads['data'], 'The decoder measures once the blob is downloaded.' );
	}

	public function test_without_zstd_a_compressed_blob_is_refused_as_such_before_its_decoding_is_counted(): void {
		if ( Compression::available() ) {
			self::markTestSkipped( 'The zstd extension is installed.' );
		}
		$entry = new PackEntry( BlobType::Data, Id::of( 'compressed' ), 0, 1000, 4000 );
		$this->index->add_pack( Id::of( 'pack' ), [ $entry ] );
		$this->leave( 0 );
		try {
			$this->read( [ $entry->id ], 0 );
			self::fail( 'PHP cannot decode the blob.' );
		} catch ( UnsupportedRepository $error ) {
			self::assertSame( 'repository_unsupported_version', JobError::from_throwable( $error )->code, 'Not a lack of memory.' );
		}
		self::assertSame( 0, $this->counted->loads['data'] ?? 0 );
	}

	/**
	 * In a process of its own: the small strings below must take blocks of their own, which
	 * they do not in a process that earlier tests left with free pages in many blocks.
	 */
	#[RunInSeparateProcess]
	public function test_php_gives_back_what_it_keeps_only_when_a_group_would_be_cut_otherwise(): void {
		$values  = [ random_bytes( 3 * self::MIB ), random_bytes( 3 * self::MIB ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		$two     = MemoryBudget::range( $entries[0]->length + $entries[1]->length, $entries[0], $entries[1] );
		$churn   = static function (): void {
			$small = [];
			for ( $i = 0; $i < 100000; ++$i ) {
				$small[] = str_repeat( 's', 60 ) . $i;
			}
		};
		// Small strings made and let go: PHP keeps their blocks until it gives them back.
		$churn();
		$kept = memory_get_usage( true );
		$this->repository->limit_memory( new MemoryBudget( $kept + $two ) );
		self::assertSame( self::digests( $values ), $this->read_digests( $ids, 0 ) );
		self::assertSame( $kept, memory_get_usage( true ), 'The group fitted beside what PHP kept: nothing was given back.' );
		// The blocks that giving back frees, as the budget measures it.
		gc_mem_caches();
		$freed = $kept - memory_get_usage( true );
		self::assertGreaterThanOrEqual( 4 * MemoryBudget::BLOCK, $freed, 'The strings took blocks of their own.' );
		$churn();
		$kept = memory_get_usage( true );
		$this->repository->limit_memory( new MemoryBudget( $kept + $two - intdiv( $freed, 2 ) ) );
		self::assertSame( self::digests( $values ), $this->read_digests( $ids, 0 ), 'Without what PHP kept, the group fits whole.' );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_with_memory_enough_a_group_is_as_large_as_without_a_budget(): void {
		$values = [];
		for ( $i = 0; $i < BlobBatch::MAX_BLOBS + 1; ++$i ) {
			$values[] = random_bytes( 1000 + $i );
		}
		$ids = $this->pack( $values );
		$this->repository->limit_memory( new MemoryBudget( 1073741824 ) );
		self::assertSame( array_slice( $values, 0, BlobBatch::MAX_BLOBS ), $this->read( $ids, 0 ) );
		self::assertSame( 1, $this->counted->loads['data'] );
	}

	/**
	 * In a process of its own: the group of 16 MiB and its blobs do not stay beside later tests.
	 */
	#[RunInSeparateProcess]
	public function test_a_group_holds_blobs_up_to_sixteen_mib_and_no_more(): void {
		// Two blobs of exactly 8 MiB stored fill a group; the third one starts the next.
		$chunk  = intdiv( BlobBatch::MAX_BYTES, 2 ) - Cipher::OVERHEAD;
		$values = [ random_bytes( $chunk ), random_bytes( $chunk ), random_bytes( 1000 ) ];
		$ids    = $this->pack( $values );
		self::assertSame( BlobBatch::MAX_BYTES, array_sum( array_map( static fn ( PackEntry $entry ): int => $entry->length, array_slice( $this->entries( $ids ), 0, 2 ) ) ) );
		self::assertSame( [ $values[0], $values[1] ], $this->read( $ids, 0 ) );
		self::assertSame( [ 2 => $values[2] ], $this->read( $ids, 2 ) );
		self::assertSame( 2, $this->counted->loads['data'] );
	}
}
