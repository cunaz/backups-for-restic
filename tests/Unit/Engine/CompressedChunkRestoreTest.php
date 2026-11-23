<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\BlobBatch;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\ResticFrame;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * restic compresses every data blob of a compressed repository, also a chunk that does not
 * compress. A full chunk of 8 MiB is then stored 202 bytes longer than itself, and restore
 * must accept that length.
 */
final class CompressedChunkRestoreTest extends TestCase {

	private InMemoryBackend $backend;
	private CountingBackend $counted;
	private Repository $repository;
	private string $scratch;

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$this->counted    = new CountingBackend( $this->backend );
		$this->repository = Repository::initialize( $this->counted, 'test password', new Identity( 'example.test' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->scratch    = TempDir::create( 'compressed-chunk' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->scratch );
	}

	/**
	 * Stores a chunk in a pack of its own, as restic stores it in a compressed repository.
	 *
	 * @param string $content Chunk.
	 * @return array{Id, PackEntry} The pack and the index entry of the blob.
	 */
	private function store( string $content ): array {
		$sealed = $this->repository->cipher()->seal( ResticFrame::raw( $content ) );
		$pack   = Id::of( $sealed );
		$this->backend->save( ObjectType::Data, $pack->hex(), $sealed );
		return [ $pack, new PackEntry( BlobType::Data, Id::of( $content ), 0, strlen( $sealed ), strlen( $content ) ) ];
	}

	public function test_a_full_chunk_that_restic_stored_compressed_is_read(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$content          = random_bytes( RabinChunker::MAX_SIZE );
		[ $pack, $entry ] = $this->store( $content );
		self::assertSame( RabinChunker::MAX_SIZE + 202 + Cipher::OVERHEAD, $entry->length, 'restic stores the chunk 202 bytes longer than itself.' );
		$index = new IndexSet();
		$index->add_pack( $pack, [ $entry ] );

		$read = iterator_to_array( BlobBatch::read( $this->repository, $index, [ $entry->id ], 0 ) );
		self::assertSame( [ 0 ], array_keys( $read ) );
		self::assertSame( hash( 'sha256', $content ), hash( 'sha256', $read[0] ) );
	}

	public function test_the_limit_of_a_compressed_blob_is_the_largest_frame_of_a_chunk(): void {
		$id = Id::of( 'blob' );
		self::assertSame( Compression::bound( RabinChunker::MAX_SIZE ) + Cipher::OVERHEAD, BlobBatch::max_length( new PackEntry( BlobType::Data, $id, 0, 100, 1 ) ) );
		self::assertSame( RabinChunker::MAX_SIZE + Cipher::OVERHEAD, BlobBatch::max_length( new PackEntry( BlobType::Data, $id, 0, 100 ) ), 'An uncompressed blob keeps the limit of a chunk.' );
	}

	/** @return iterable<string, array{int, string, int}> */
	public static function compressed_lengths(): iterable {
		$limit = Compression::bound( RabinChunker::MAX_SIZE ) + Cipher::OVERHEAD;
		// Within the limit, the blob is asked for and its pack is missing.
		yield 'the largest frame of a chunk' => [ $limit, NotFound::class, 1 ];
		// Beyond it, nothing is asked for.
		yield 'one byte more' => [ $limit + 1, FormatException::class, 0 ];
	}

	/**
	 * @param class-string<\Throwable> $error What reading the blob raises.
	 */
	#[DataProvider( 'compressed_lengths' )]
	public function test_a_compressed_blob_beyond_the_largest_frame_of_a_chunk_is_refused_before_a_download( int $length, string $error, int $requests ): void {
		$id    = Id::of( 'compressed' );
		$index = new IndexSet();
		$index->add_pack( Id::of( 'missing pack' ), [ new PackEntry( BlobType::Data, $id, 0, $length, RabinChunker::MAX_SIZE ) ] );
		try {
			iterator_to_array( BlobBatch::read( $this->repository, $index, [ $id ], 0 ) );
			self::fail( 'The pack does not exist.' );
		} catch ( NotFound | FormatException $raised ) {
			self::assertSame( $error, $raised::class );
			self::assertSame( $requests, $this->counted->loads['data'] ?? 0 );
		}
	}

	public function test_restore_writes_a_file_whose_full_chunk_restic_stored_compressed(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$content          = random_bytes( RabinChunker::MAX_SIZE );
		$digest           = hash( 'sha256', $content );
		[ $pack, $entry ] = $this->store( $content );
		unset( $content );
		$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->hex() => [ $entry ] ] ) );
		$trees = new BlobStore( $this->repository, new IndexSet() );
		$tree  = $trees->save( BlobType::Tree, TreeCodec::encode( [ new Node( 'chunk.bin', NodeType::File, 0644, '2026-01-01T00:00:00Z', size: RabinChunker::MAX_SIZE, content: [ $entry->id ] ) ] ) );
		$trees->flush();
		$trees->write_index();
		$snapshot = $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $tree, [ '/' ] ) )->to_json() );

		mkdir( $this->scratch . '/target', 0700 );
		$run   = RestoreRun::start( $this->repository, $snapshot, $this->scratch . '/target', $this->scratch . '/work' );
		$steps = 0;
		do {
			self::assertLessThan( 100, ++$steps, 'The restore makes bounded progress.' );
		} while ( ! $run->advance( microtime( true ) + 20 ) );
		self::assertSame( $digest, hash_file( 'sha256', $this->scratch . '/target/chunk.bin' ) );
	}
}
