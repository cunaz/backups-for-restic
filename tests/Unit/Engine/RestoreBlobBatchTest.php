<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\BlobBatch;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
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

	public function test_adjacent_blobs_use_one_request_and_keep_file_order(): void {
		$values = [ 'first', 'second', 'third' ];
		$ids    = $this->pack( $values );
		self::assertSame( $values, iterator_to_array( BlobBatch::read( $this->repository, $this->index, $ids, 0 ) ) );
		self::assertSame( 1, $this->counted->loads['data'] );
	}

	public function test_repeated_or_nonadjacent_blobs_stop_the_group_without_reordering(): void {
		$ids = $this->pack( [ 'first', 'middle', 'last' ] );
		self::assertSame( [ 'first' ], iterator_to_array( BlobBatch::read( $this->repository, $this->index, [ $ids[0], $ids[0], $ids[2] ], 0 ) ) );
		self::assertSame( [ 1 => 'first' ], iterator_to_array( BlobBatch::read( $this->repository, $this->index, [ $ids[0], $ids[0], $ids[2] ], 1 ) ) );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_cross_pack_groups_resume_at_the_next_blob(): void {
		$first = $this->pack( [ 'first' ] );
		$next  = $this->pack( [ 'second', 'third' ] );
		$ids   = [ ...$first, ...$next ];
		self::assertSame( [ 'first' ], iterator_to_array( BlobBatch::read( $this->repository, $this->index, $ids, 0 ) ) );
		self::assertSame(
			[
				1 => 'second',
				2 => 'third',
			],
			iterator_to_array( BlobBatch::read( $this->repository, $this->index, $ids, 1 ) )
		);
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_every_group_member_authenticates_before_it_is_yielded(): void {
		$ids      = $this->pack( [ 'first', 'second' ] );
		$location = $this->index->find( $ids[1], BlobType::Data );
		self::assertNotNull( $location );
		$pack   = $location->pack->hex();
		$offset = $location->entry->offset + 20;
		$this->backend->objects['data'][ $pack ][ $offset ] = chr( ord( $this->backend->objects['data'][ $pack ][ $offset ] ) ^ 1 );
		$batch = BlobBatch::read( $this->repository, $this->index, $ids, 0 );
		self::assertSame( 'first', $batch->current() );
		$this->expectException( AuthenticationFailed::class );
		$batch->next();
	}

	public function test_an_absent_blob_is_not_treated_as_an_empty_file(): void {
		$this->expectException( FormatException::class );
		iterator_to_array( BlobBatch::read( $this->repository, $this->index, [ Id::of( 'absent' ) ], 0 ) );
	}

	public function test_tiny_blobs_still_yield_after_the_group_work_limit(): void {
		$values = [];
		for ( $i = 0; $i < BlobBatch::MAX_BLOBS + 1; ++$i ) {
			$values[] = 'blob-' . $i;
		}
		$ids   = $this->pack( $values );
		$first = iterator_to_array( BlobBatch::read( $this->repository, $this->index, $ids, 0 ) );
		self::assertSame( array_slice( $values, 0, BlobBatch::MAX_BLOBS ), $first );
		self::assertSame( [ BlobBatch::MAX_BLOBS => 'blob-' . BlobBatch::MAX_BLOBS ], iterator_to_array( BlobBatch::read( $this->repository, $this->index, $ids, BlobBatch::MAX_BLOBS ) ) );
		self::assertSame( 2, $this->counted->loads['data'] );
	}

	public function test_oversized_blob_metadata_is_refused_before_a_download(): void {
		$id = Id::of( 'oversized' );
		$this->index->add_pack( Id::of( 'pack' ), [ new PackEntry( BlobType::Data, $id, 0, RabinChunker::MAX_SIZE + Cipher::OVERHEAD + 1 ) ] );
		try {
			iterator_to_array( BlobBatch::read( $this->repository, $this->index, [ $id ], 0 ) );
			self::fail( 'An oversized data blob cannot consume remote bytes or memory.' );
		} catch ( FormatException ) {
			self::assertSame( 0, $this->counted->loads['data'] ?? 0 );
		}
	}
}
