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
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RestoreBlobBatchTest extends TestCase {

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
	 * MemoryBudget::blob_room() sees the memory reserved now, less its two MiB.
	 */
	private function leave( int $bytes ): void {
		do {
			gc_mem_caches();
			$reserved = memory_get_usage( true );
			$this->repository->limit_memory( new MemoryBudget( $reserved + 2097152 + $bytes ) );
			gc_mem_caches();
		} while ( memory_get_usage( true ) !== $reserved );
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
		$values  = [ random_bytes( 70000 ), random_bytes( 50000 ), random_bytes( 60000 ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		// Two uncompressed blobs: their response, three times their stored bytes, is the most.
		$two = MemoryBudget::range( $entries[0]->length + $entries[1]->length, MemoryBudget::opening( $entries[0] ), MemoryBudget::opening( $entries[1] ) );
		self::assertSame( 3 * ( $entries[0]->length + $entries[1]->length ), $two );

		$this->leave( $two );
		self::assertSame( [ $values[0], $values[1] ], $this->read( $ids, 0 ), 'Exactly the two blobs fit.' );
		$this->leave( $two - 1 );
		self::assertSame( [ $values[0] ], $this->read( $ids, 0 ), 'One byte less, and the group ends after the first.' );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_a_group_ends_before_a_blob_that_would_be_opened_beside_more_than_the_memory_left(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$values  = [ str_repeat( 'first ', 50000 ), str_repeat( 'second ', 40000 ), str_repeat( 'third ', 30000 ) ];
		$ids     = $this->compressed_pack( $values );
		$entries = $this->entries( $ids );
		// Compressed blobs open into twice their content: the first one, opened beside the whole
		// group of two, is the most that reading the two takes.
		$first = $entries[0]->length - Cipher::OVERHEAD + 2 * strlen( $values[0] );
		self::assertSame( $first, MemoryBudget::opening( $entries[0] ) );
		$two = MemoryBudget::range( $entries[0]->length + $entries[1]->length, $first, MemoryBudget::opening( $entries[1] ) );
		self::assertSame( $entries[0]->length + $entries[1]->length + $first, $two );

		$this->leave( $two );
		self::assertSame( [ $values[0], $values[1] ], $this->read( $ids, 0 ) );
		$this->leave( $two - 1 );
		self::assertSame( [ $values[0] ], $this->read( $ids, 0 ) );
		// The group of the last two: the second blob is now the one opened beside the group.
		$last = MemoryBudget::range( $entries[1]->length + $entries[2]->length, MemoryBudget::opening( $entries[1] ), MemoryBudget::opening( $entries[2] ) );
		$this->leave( $last );
		self::assertSame(
			[
				1 => $values[1],
				2 => $values[2],
			],
			$this->read( $ids, 1 )
		);
		$this->leave( $last - 1 );
		self::assertSame( [ 1 => $values[1] ], $this->read( $ids, 1 ) );
		self::assertSame( 4, $this->counted->loads['data'] );
	}

	public function test_a_first_blob_that_does_not_fit_the_memory_left_is_refused_before_a_download(): void {
		$values  = [ random_bytes( 70000 ), random_bytes( 50000 ) ];
		$ids     = $this->pack( $values );
		$entries = $this->entries( $ids );
		$alone   = MemoryBudget::range( $entries[0]->length, 0, MemoryBudget::opening( $entries[0] ) );
		self::assertSame( 3 * $entries[0]->length, $alone );

		$this->leave( $alone );
		self::assertSame( [ $values[0] ], $this->read( $ids, 0 ), 'With exactly its need left, the blob is read by itself.' );
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
		}
		self::assertSame( [], $written );
		self::assertSame( 1, $this->counted->loads['data'], 'Nothing more was downloaded.' );
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
