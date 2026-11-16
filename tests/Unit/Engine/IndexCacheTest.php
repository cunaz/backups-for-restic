<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The index cache as a file of sealed pieces (ADR 0031): what it looks like, and that a
 * file someone changed is thrown away instead of believed.
 */
final class IndexCacheTest extends TestCase {

	private const string MAGIC = "backups-for-restic index 2\n";

	private CountingBackend $backend;

	private Repository $repository;

	private string $directory;

	protected function setUp(): void {
		$this->backend    = new CountingBackend();
		$this->repository = Repository::initialize( $this->backend, 'cache password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->directory  = TempDir::create( 'index-cache' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * Stores an index file with made-up packs and returns its name.
	 */
	private function index_file( string $seed, int $packs, int $blobs_per_pack ): string {
		$content = [];
		for ( $pack = 0; $pack < $packs; $pack++ ) {
			$entries = [];
			for ( $i = 0; $i < $blobs_per_pack; $i++ ) {
				$entries[] = new PackEntry( 0 === $i % 9 ? BlobType::Tree : BlobType::Data, Id::from_bytes( hash( 'sha256', $seed . $pack . ':' . $i, true ) ), 1000 * $i, 1000, $i % 3 );
			}
			$content[ hash( 'sha256', $seed . 'pack' . $pack ) ] = $entries;
		}
		return $this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( $content ) )->hex();
	}

	/**
	 * Every blob of an index as one line, sorted.
	 *
	 * @return list<string>
	 */
	private static function blobs( IndexSet $index ): array {
		$lines = [];
		foreach ( $index->locations() as $location ) {
			$entry   = $location->entry;
			$lines[] = implode( ' ', [ $entry->type->value, $entry->id->hex(), $location->pack->hex(), $entry->offset, $entry->length, $entry->uncompressed_length ] );
		}
		sort( $lines );
		return $lines;
	}

	/**
	 * The sealed pieces of the cache file, without their length fields.
	 *
	 * @return list<string>
	 */
	private function sealed_pieces(): array {
		$file   = (string) file_get_contents( $this->directory . '/index.bin' );
		$pieces = [];
		$offset = 0;
		$length = strlen( $file );
		while ( $offset < $length ) {
			$fields = unpack( 'Vlength', $file, $offset );
			self::assertIsArray( $fields );
			$pieces[] = substr( $file, $offset + 4, $fields['length'] );
			$offset  += 4 + $fields['length'];
		}
		self::assertSame( $length, $offset, 'The file ends with its last piece.' );
		return $pieces;
	}

	/**
	 * Writes a cache file from sealed pieces.
	 *
	 * @param list<string> $pieces Sealed pieces.
	 */
	private function write_pieces( array $pieces ): void {
		file_put_contents( $this->directory . '/index.bin', implode( '', array_map( static fn ( string $piece ): string => pack( 'V', strlen( $piece ) ) . $piece, $pieces ) ) );
	}

	public function test_the_file_is_a_row_of_sealed_pieces_that_carry_one_mark_and_their_number(): void {
		$names = [ $this->index_file( 'a', 3, 40 ), $this->index_file( 'b', 2, 10 ) ];
		sort( $names );
		( new IndexCache( $this->directory ) )->load( $this->repository );

		$plain = array_map( $this->repository->cipher()->open( ... ), $this->sealed_pieces() );
		self::assertGreaterThan( 3, count( $plain ) );
		$mark = substr( $plain[0], 0, 16 );
		foreach ( $plain as $number => $piece ) {
			self::assertSame( $mark, substr( $piece, 0, 16 ), 'Every piece carries the mark of its file.' );
			self::assertSame( pack( 'V', $number ), substr( $piece, 16, 4 ), 'Pieces are numbered from zero.' );
		}
		self::assertSame( 'N' . implode( '', array_map( 'hex2bin', $names ) ), substr( $plain[0], 20 ), 'The first piece names the index files the cache covers, sorted.' );
		self::assertSame( self::MAGIC, substr( $plain[1], 20, strlen( self::MAGIC ) ), 'The index follows as IndexSet exports it.' );
		self::assertSame( 'P', $plain[2][20] );
		self::assertSame( 0600, fileperms( $this->directory . '/index.bin' ) & 0777 );
		self::assertSame( [ $this->directory . '/index.bin' ], glob( $this->directory . '/*' ), 'No temporary file is left.' );
	}

	public function test_two_files_of_the_same_index_have_different_marks_and_nonces(): void {
		$this->index_file( 'a', 2, 10 );
		( new IndexCache( $this->directory ) )->load( $this->repository );
		$first = $this->sealed_pieces();
		unlink( $this->directory . '/index.bin' );
		( new IndexCache( $this->directory ) )->load( $this->repository );
		$second = $this->sealed_pieces();

		self::assertNotSame( $first[0], $second[0] );
		self::assertNotSame( substr( $this->repository->cipher()->open( $first[0] ), 0, 16 ), substr( $this->repository->cipher()->open( $second[0] ), 0, 16 ) );
	}

	public function test_an_unchanged_repository_reads_the_cache_and_writes_nothing(): void {
		$this->index_file( 'a', 4, 25 );
		$cache = new IndexCache( $this->directory );
		$first = $cache->load( $this->repository );
		$bytes = file_get_contents( $this->directory . '/index.bin' );

		$this->backend->loads = [];
		$second               = ( new IndexCache( $this->directory ) )->load( $this->repository );

		self::assertSame( self::blobs( $first ), self::blobs( $second ) );
		self::assertSame( 100, $second->count() );
		self::assertArrayNotHasKey( 'index', $this->backend->loads, 'No index file was downloaded.' );
		self::assertSame( $bytes, file_get_contents( $this->directory . '/index.bin' ), 'The file was not written again.' );
	}

	/**
	 * Ways to change a cache file without the key.
	 *
	 * @return iterable<string, array{\Closure(list<string>, list<string>): (list<string>|string)}>
	 */
	public static function manipulations(): iterable {
		yield 'two pieces swapped' => [ static fn ( array $pieces ): array => [ $pieces[0], $pieces[1], $pieces[3], $pieces[2], ...array_slice( $pieces, 4 ) ] ];
		yield 'a piece left out' => [ static fn ( array $pieces ): array => [ ...array_slice( $pieces, 0, 3 ), ...array_slice( $pieces, 4 ) ] ];
		yield 'the last piece left out' => [ static fn ( array $pieces ): array => array_slice( $pieces, 0, -1 ) ];
		yield 'a piece twice' => [ static fn ( array $pieces ): array => [ ...array_slice( $pieces, 0, 4 ), $pieces[3], ...array_slice( $pieces, 4 ) ] ];
		yield 'the last piece twice' => [ static fn ( array $pieces ): array => [ ...$pieces, $pieces[ count( $pieces ) - 1 ] ] ];
		yield 'only the names' => [ static fn ( array $pieces ): array => [ $pieces[0] ] ];
		yield 'no names' => [ static fn ( array $pieces ): array => array_slice( $pieces, 1 ) ];
		yield 'a piece of another file of the same repository' => [ static fn ( array $pieces, array $other ): array => [ ...array_slice( $pieces, 0, 3 ), $other[3], ...array_slice( $pieces, 4 ) ] ];
		yield 'the names of another file' => [ static fn ( array $pieces, array $other ): array => [ $other[0], ...array_slice( $pieces, 1 ) ] ];
		yield 'one bit changed' => [
			static function ( array $pieces ): array {
				$pieces[2][40] = $pieces[2][40] ^ "\x01";
				return $pieces;
			},
		];
		yield 'a file cut off inside a piece' => [ static fn ( array $pieces ): string => substr( implode( '', array_map( static fn ( string $piece ): string => pack( 'V', strlen( $piece ) ) . $piece, $pieces ) ), 0, -9 ) ];
		yield 'a file cut off inside a length' => [ static fn ( array $pieces ): string => implode( '', array_map( static fn ( string $piece ): string => pack( 'V', strlen( $piece ) ) . $piece, $pieces ) ) . "\x10\0" ];
		yield 'a length of nothing' => [ static fn ( array $pieces ): string => pack( 'V', 0 ) . implode( '', array_map( static fn ( string $piece ): string => pack( 'V', strlen( $piece ) ) . $piece, $pieces ) ) ];
		yield 'a length beyond any piece' => [ static fn ( array $pieces ): string => pack( 'V', 0xFFFFFFF0 ) . $pieces[0] ];
		yield 'an empty file' => [ static fn (): string => '' ];
		yield 'the format before this one: one sealed block' => [ static fn ( array $pieces ): string => $pieces[1] ];
	}

	/**
	 * @param \Closure(list<string>, list<string>): (list<string>|string) $change
	 */
	#[DataProvider( 'manipulations' )]
	public function test_a_changed_file_is_thrown_away_and_the_index_read_from_the_server( \Closure $change ): void {
		$this->index_file( 'a', 3, 30 );
		$this->index_file( 'b', 3, 30 );
		$cache    = new IndexCache( $this->directory );
		$expected = self::blobs( $cache->load( $this->repository ) );
		$pieces   = $this->sealed_pieces();
		self::assertGreaterThan( 4, count( $pieces ), 'Enough pieces to rearrange.' );
		unlink( $this->directory . '/index.bin' );
		$cache->load( $this->repository );
		$other = $this->sealed_pieces();

		$changed = $change( $pieces, $other );
		if ( is_string( $changed ) ) {
			file_put_contents( $this->directory . '/index.bin', $changed );
		} else {
			$this->write_pieces( $changed );
		}
		$this->backend->loads = [];
		$index                = ( new IndexCache( $this->directory ) )->load( $this->repository );

		self::assertSame( $expected, self::blobs( $index ) );
		self::assertSame( 2, $this->backend->loads['index'] ?? 0, 'Both index files were read again.' );
		$this->backend->loads = [];
		self::assertSame( $expected, self::blobs( ( new IndexCache( $this->directory ) )->load( $this->repository ) ) );
		self::assertArrayNotHasKey( 'index', $this->backend->loads, 'The cache was written anew and is used again.' );
	}

	public function test_a_file_of_another_repository_is_not_read(): void {
		$this->index_file( 'a', 2, 10 );
		$expected = self::blobs( ( new IndexCache( $this->directory ) )->load( $this->repository ) );
		$stranger = Repository::initialize( new CountingBackend(), 'another password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$foreign  = TempDir::create( 'foreign-cache' );
		try {
			$stranger->save_unpacked( ObjectType::Index, IndexFile::encode( [ hash( 'sha256', 'x' ) => [ new PackEntry( BlobType::Data, Id::of( 'x' ), 0, 40 ) ] ] ) );
			( new IndexCache( $foreign ) )->load( $stranger );
			copy( $foreign . '/index.bin', $this->directory . '/index.bin' );
		} finally {
			TempDir::remove( $foreign );
		}

		self::assertSame( $expected, self::blobs( ( new IndexCache( $this->directory ) )->load( $this->repository ) ), 'Sealed with another key, the file counts for nothing.' );
	}

	#[RunInSeparateProcess]
	public function test_a_truncated_large_piece_is_rebuilt_under_the_memory_limit(): void {
		self::assertSame( '128M', ini_get( 'memory_limit' ) );
		$this->index_file( 'a', 2, 10 );
		$cache    = new IndexCache( $this->directory );
		$expected = self::blobs( $cache->load( $this->repository ) );
		$file     = $this->directory . '/index.bin';
		file_put_contents( $file, pack( 'V', 64 * 1048576 ) . 'short body' );
		$this->repository->limit_memory( new MemoryBudget( 128 * 1048576 ) );
		$this->backend->loads = [];

		self::assertSame( $expected, self::blobs( $cache->load( $this->repository ) ) );
		self::assertSame( 1, $this->backend->loads['index'] ?? 0, 'A truncated cache is rebuilt from the repository.' );
		$this->backend->loads = [];
		self::assertSame( $expected, self::blobs( $cache->load( $this->repository ) ) );
		self::assertArrayNotHasKey( 'index', $this->backend->loads, 'The repaired cache is reused on the next load.' );
	}

	#[RunInSeparateProcess]
	public function test_a_complete_large_piece_still_refuses_the_memory_budget_before_reading(): void {
		$file   = $this->directory . '/index.bin';
		$handle = fopen( $file, 'wb' );
		self::assertNotFalse( $handle );
		try {
			self::assertSame( 4, fwrite( $handle, pack( 'V', 64 * 1048576 ) ) );
			self::assertTrue( ftruncate( $handle, 4 + 64 * 1048576 ) );
		} finally {
			fclose( $handle );
		}
		$this->repository->limit_memory( new MemoryBudget( 128 * 1048576 ) );
		try {
			( new IndexCache( $this->directory ) )->load( $this->repository );
			self::fail( 'A complete piece must pass the budget before it can be read or authenticated.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 64 * 1048576, $error->context()['input_bytes'] );
			self::assertSame( 4 + 64 * 1048576, filesize( $file ), 'A capacity refusal does not discard the cache.' );
		}
	}

	public function test_a_replacement_over_the_limit_keeps_the_smaller_cache(): void {
		$this->index_file( 'old', 2, 20 );
		( new IndexCache( $this->directory ) )->load( $this->repository );
		$file = $this->directory . '/index.bin';
		$old  = file_get_contents( $file );
		self::assertIsString( $old );
		$cache = new IndexCache( $this->directory, strlen( $old ) );
		$this->index_file( 'new', 2, 20 );
		self::assertSame( 80, $cache->load( $this->repository )->count() );
		self::assertFileExists( $file, 'Refusing a replacement must preserve the useful cache.' );
		self::assertSame( $old, file_get_contents( $file ) );
		self::assertSame( [ $file ], glob( $this->directory . '/*' ), 'No refused temporary file remains.' );
		$this->backend->loads = [];

		self::assertSame( 80, $cache->load( $this->repository )->count() );
		self::assertSame( 1, $this->backend->loads['index'] ?? 0, 'Only the new index file is downloaded again.' );
	}

	public function test_a_cache_beyond_its_limit_leaves_no_file_behind(): void {
		$this->index_file( 'a', 10, 300 );
		$limited = $this->directory . '/limited';

		$index = ( new IndexCache( $limited, 100000 ) )->load( $this->repository );

		self::assertSame( 3000, $index->count(), 'The index itself is complete.' );
		self::assertDirectoryDoesNotExist( $limited, 'Neither the file nor the temporary file it was written to is kept.' );

		( new IndexCache( $limited, 1000000 ) )->load( $this->repository );
		self::assertFileExists( $limited . '/index.bin' );
		self::assertLessThan( 1000000, filesize( $limited . '/index.bin' ) );
		self::assertGreaterThan( 100000, filesize( $limited . '/index.bin' ) );
	}

	/**
	 * In a process of its own: what the tests before it left reserved would count against the
	 * suite's memory limit, and against the figures measured here.
	 */
	#[RunInSeparateProcess]
	public function test_reading_the_cache_needs_little_more_memory_than_the_index(): void {
		for ( $file = 0; $file < 10; $file++ ) {
			$this->index_file( 'file' . $file, 10, 1000 );
		}
		( new IndexCache( $this->directory ) )->load( $this->repository );
		$size = (int) filesize( $this->directory . '/index.bin' );

		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$index  = ( new IndexCache( $this->directory ) )->load( $this->repository );
		$peak   = memory_get_peak_usage() - $before;
		$held   = memory_get_usage() - $before;

		self::assertSame( 100000, $index->count() );
		self::assertGreaterThan( 4500000, $size );
		self::assertLessThan( $held + 6000000, $peak, 'A piece or two besides the index, not the file again.' );
		self::assertLessThan( 160, $peak / $index->count(), 'Before, reading took about 316 bytes per blob: the file three times and an index three times this size.' );
	}
}
