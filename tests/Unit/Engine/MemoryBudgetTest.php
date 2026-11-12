<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Check\CheckRun;
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
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * PHP's memory limit decides how large an index may be and how large packs are (ADR 0031).
 */
final class MemoryBudgetTest extends TestCase {

	private const int MIB = 1048576;

	/** What the smallest backup needs besides the index. */
	private const int SMALLEST_JOB = MemoryBudget::WORKING_BYTES + MemoryBudget::PACK_COPIES * MemoryBudget::SMALLEST_PACK;

	/** PHP reserves memory in blocks of this size, so the reserved figure moves in such steps. */
	private const int BLOCK = 2 * self::MIB;

	/** @var list<string> */
	private array $cleanup = [];

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	/**
	 * The memory PHP holds against its limit, as the budget measures it.
	 */
	private static function reserved(): int {
		gc_mem_caches();
		return memory_get_usage( true );
	}

	/**
	 * @return iterable<string, array{string, int|null}>
	 */
	public static function limits(): iterable {
		yield 'megabytes' => [ '128M', 134217728 ];
		yield 'lower case' => [ '256m', 268435456 ];
		yield 'gigabytes' => [ '1G', 1073741824 ];
		yield 'kilobytes' => [ '65536K', 67108864 ];
		yield 'bytes' => [ '100663296', 100663296 ];
		yield 'spaces around and inside' => [ ' 128 M ', 134217728 ];
		yield 'no limit' => [ '-1', null ];
		yield 'zero' => [ '0', null ];
		yield 'nothing' => [ '', null ];
		yield 'a word' => [ 'unlimited', null ];
		yield 'an unknown unit' => [ '128X', null ];
		yield 'a fraction' => [ '1.5G', null ];
		yield 'a unit without a number' => [ 'M', null ];
	}

	#[DataProvider( 'limits' )]
	public function test_the_limit_is_read_as_php_writes_it( string $value, ?int $bytes ): void {
		self::assertSame( $bytes, MemoryBudget::from_ini( $value )?->limit );
	}

	public function test_the_smallest_job_is_twenty_megabytes_and_three_packs_of_four(): void {
		self::assertSame( 32 * self::MIB, self::SMALLEST_JOB );
		self::assertSame( 4 * self::MIB, MemoryBudget::SMALLEST_PACK );
	}

	public function test_an_index_may_grow_until_the_smallest_backup_no_longer_fits_beside_it(): void {
		( new MemoryBudget( memory_get_usage() + self::SMALLEST_JOB + self::MIB ) )->check_index();

		$limit = memory_get_usage() + self::SMALLEST_JOB - self::MIB;
		try {
			( new MemoryBudget( $limit ) )->check_index();
			self::fail( 'A megabyte is missing.' );
		} catch ( IndexTooLarge $e ) {
			self::assertSame( 'The index of the repository does not fit into the memory this host allows.', $e->getMessage() );
			self::assertSame( [ 'memory_limit', 'in_use', 'room' ], array_keys( $e->context() ) );
			self::assertSame( $limit, $e->context()['memory_limit'] );
			self::assertSame( $limit - self::SMALLEST_JOB, $e->context()['room'] );
			self::assertGreaterThan( $e->context()['room'], $e->context()['in_use'] );
		}
	}

	public function test_a_loaded_index_is_measured_by_the_memory_php_has_reserved(): void {
		( new MemoryBudget( self::reserved() + self::SMALLEST_JOB + 2 * self::BLOCK ) )->check_loaded_index();

		$limit = self::reserved() + self::SMALLEST_JOB - 2 * self::BLOCK;
		try {
			( new MemoryBudget( $limit ) )->check_loaded_index();
			self::fail( 'Two blocks are missing.' );
		} catch ( IndexTooLarge $e ) {
			self::assertSame( 'The index of the repository does not fit into the memory this host allows.', $e->getMessage() );
			self::assertSame( [ 'memory_limit', 'reserved', 'room' ], array_keys( $e->context() ) );
			self::assertSame( $limit, $e->context()['memory_limit'] );
			self::assertSame( $limit - self::SMALLEST_JOB, $e->context()['room'] );
			self::assertGreaterThan( $e->context()['room'], $e->context()['reserved'] );
			self::assertSame( 0, $e->context()['reserved'] % self::BLOCK, 'Reserved memory, which comes in blocks, not the memory in use.' );
		}
	}

	public function test_packs_are_as_large_as_asked_for_when_three_of_them_fit(): void {
		$budget = new MemoryBudget( self::reserved() + MemoryBudget::WORKING_BYTES + 3 * 16 * self::MIB + 2 * self::BLOCK );
		self::assertSame( 16 * self::MIB, $budget->pack_size( 16 * self::MIB ) );
		self::assertSame( 8 * self::MIB, $budget->pack_size( 8 * self::MIB ), 'Never larger than asked for.' );
	}

	public function test_packs_shrink_to_a_third_of_what_is_left_after_the_working_memory(): void {
		$budget = new MemoryBudget( self::reserved() + MemoryBudget::WORKING_BYTES + 3 * 8 * self::MIB );
		$size   = $budget->pack_size( 16 * self::MIB );
		// Eight megabytes, give or take a third of a block that PHP reserved or gave back since.
		self::assertGreaterThan( 7 * self::MIB, $size );
		self::assertLessThan( 9 * self::MIB, $size );
	}

	public function test_packs_never_shrink_below_the_smallest_size_but_stay_as_small_as_asked_for(): void {
		$tight = new MemoryBudget( self::reserved() + self::MIB );
		self::assertSame( MemoryBudget::SMALLEST_PACK, $tight->pack_size( 16 * self::MIB ) );
		self::assertSame( 2 * self::MIB, $tight->pack_size( 2 * self::MIB ), 'A size below the smallest was asked for on purpose.' );
	}

	public function test_memory_that_is_reserved_but_not_in_use_does_not_count_as_room_for_packs(): void {
		// Many small strings of which every 400th stays: no block of memory becomes free again.
		$kept    = [];
		$dropped = [];
		for ( $i = 0; $i < 300000; $i++ ) {
			$string = str_repeat( 'x', 96 ) . $i;
			if ( 0 === $i % 400 ) {
				$kept[] = $string;
			} else {
				$dropped[] = $string;
			}
		}
		unset( $dropped );
		$reserved = self::reserved();
		$in_use   = memory_get_usage();
		self::assertGreaterThan( $in_use + 16 * self::MIB, $reserved, 'PHP still holds what the dropped strings took.' );
		self::assertCount( 750, $kept );

		// By the memory in use, three packs of at least 13 MiB would fit under this limit.
		$budget = new MemoryBudget( $reserved + MemoryBudget::WORKING_BYTES + 3 * 8 * self::MIB );
		self::assertGreaterThan( 13 * self::MIB, intdiv( $budget->limit - $in_use - MemoryBudget::WORKING_BYTES, 3 ) );
		self::assertLessThan( 9 * self::MIB, $budget->pack_size( 16 * self::MIB ), 'A pack finds no room in blocks that are partly in use.' );

		$tight = new MemoryBudget( $reserved + self::SMALLEST_JOB - 2 * self::BLOCK );
		$tight->check_index();
		$this->expectException( IndexTooLarge::class );
		$tight->check_loaded_index();
	}

	/**
	 * A repository with 1000 blobs per pack and ten packs per index file.
	 */
	private function repository( int $pack_count = 60 ): Repository {
		$repository = Repository::initialize( new InMemoryBackend(), 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$packs      = [];
		for ( $pack = 0; $pack < $pack_count; $pack++ ) {
			$entries = [];
			for ( $i = 0; $i < 1000; $i++ ) {
				$entries[] = new PackEntry( BlobType::Data, Id::from_bytes( hash( 'sha256', $pack . ':' . $i, true ) ), 1000 * $i, 1000 );
			}
			$packs[ hash( 'sha256', 'pack' . $pack ) ] = $entries;
			if ( 9 === $pack % 10 ) {
				$repository->save_unpacked( ObjectType::Index, IndexFile::encode( $packs ) );
				$packs = [];
			}
		}
		return $repository;
	}

	public function test_an_index_that_does_not_fit_stops_loading_from_the_index_files(): void {
		$repository = $this->repository();
		gc_collect_cycles();
		$repository->limit_memory( new MemoryBudget( memory_get_usage() + self::SMALLEST_JOB + self::MIB ) );

		$this->expectException( IndexTooLarge::class );
		$repository->load_index();
	}

	public function test_an_index_that_does_not_fit_stops_loading_through_the_cache(): void {
		$repository      = $this->repository();
		$this->cleanup[] = $directory = TempDir::create( 'budget-cache' );
		gc_collect_cycles();
		$repository->limit_memory( new MemoryBudget( memory_get_usage() + self::SMALLEST_JOB + self::MIB ) );

		try {
			( new IndexCache( $directory ) )->load( $repository );
			self::fail( 'The index needs more than a megabyte.' );
		} catch ( IndexTooLarge ) {
			self::assertFileDoesNotExist( $directory . '/index.bin', 'Nothing is cached of an index that was not loaded.' );
		}
	}

	public function test_an_index_that_does_not_fit_stops_loading_from_the_cache_file(): void {
		$repository      = $this->repository();
		$this->cleanup[] = $directory = TempDir::create( 'budget-cache' );
		self::assertSame( 60000, ( new IndexCache( $directory ) )->load( $repository )->count() );
		$bytes = file_get_contents( $directory . '/index.bin' );
		gc_collect_cycles();
		$repository->limit_memory( new MemoryBudget( memory_get_usage() + self::SMALLEST_JOB + self::MIB ) );

		try {
			( new IndexCache( $directory ) )->load( $repository );
			self::fail( 'The index needs more than a megabyte.' );
		} catch ( IndexTooLarge ) {
			self::assertSame( $bytes, file_get_contents( $directory . '/index.bin' ), 'The cache file is fine and stays.' );
		}

		$repository->limit_memory( new MemoryBudget( self::reserved() + self::SMALLEST_JOB + 64 * self::MIB ) );
		self::assertSame( 60000, ( new IndexCache( $directory ) )->load( $repository )->count(), 'With enough memory the same file loads.' );
	}

	public function test_a_backup_with_little_memory_left_writes_smaller_packs(): void {
		$this->cleanup[] = $site = TempDir::create( 'budget-site' );
		file_put_contents( $site . '/large.bin', random_bytes( 12 * self::MIB ) );
		$backend    = new InMemoryBackend();
		$identity   = new Identity( 'example.com', 'www-data' );
		$repository = Repository::initialize( $backend, 'budget password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		gc_collect_cycles();
		$repository->limit_memory( new MemoryBudget( self::reserved() + MemoryBudget::WORKING_BYTES + 3 * 6 * self::MIB ) );

		$this->cleanup[] = $work = TempDir::create( 'budget-work' );
		$run             = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $work . '/run', $identity, new SystemClock(), null, 16 * self::MIB );
		self::assertFalse( $run->advance( 0.0 ), 'The first tick takes the lock and then chooses the pack size.' );
		self::assertLessThan( 7 * self::MIB, $run->pack_size() );
		self::assertGreaterThan( MemoryBudget::SMALLEST_PACK - 1, $run->pack_size() );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		$data_sizes = [];
		foreach ( $backend->objects['data'] ?? [] as $bytes ) {
			$length  = PackReader::header_length( substr( $bytes, -4 ), strlen( $bytes ) );
			$entries = PackReader::read_header( $repository->cipher(), substr( $bytes, -4 - $length, $length ), strlen( $bytes ) );
			if ( BlobType::Data === $entries[0]->type ) {
				$data_sizes[] = strlen( $bytes );
			}
		}
		self::assertGreaterThanOrEqual( 2, count( $data_sizes ), 'A data pack was uploaded before the final flush.' );
		self::assertLessThan( 16 * self::MIB, max( $data_sizes ), 'The backend received smaller packs, not just a smaller reported target.' );

		$repository->limit_memory( null );
		$check = CheckRun::start( $repository, $work . '/check', $identity, new SystemClock(), new SystemRandom(), 100 );
		while ( ! $check->advance( INF ) ) {
			continue;
		}
		self::assertSame( 0, $check->result()['errors'] );

		$this->cleanup[] = $other = TempDir::create( 'budget-work' );
		$plenty          = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $other . '/run', $identity, new SystemClock(), null, 16 * self::MIB );
		self::assertSame( 16 * self::MIB, $plenty->pack_size(), 'Without a limit the pack size is the one asked for.' );
		$plenty->abort();
	}

	public function test_a_stale_cache_is_discarded_before_its_entries_use_the_memory_budget(): void {
		$repository      = $this->repository( 200 );
		$this->cleanup[] = $directory = TempDir::create( 'budget-cache' );
		$cache           = new IndexCache( $directory );
		self::assertSame( 200000, $cache->load( $repository )->count() );
		foreach ( array_keys( $repository->backend()->list( ObjectType::Index ) ) as $name ) {
			$repository->backend()->delete( ObjectType::Index, $name );
		}
		gc_collect_cycles();
		$repository->limit_memory( new MemoryBudget( self::reserved() + self::SMALLEST_JOB ) );

		self::assertSame( 0, $cache->load( $repository )->count(), 'Only the current, empty index must fit.' );
		self::assertSame( 0, $cache->load( $repository )->count(), 'The stale cache was replaced.' );
	}

	public function test_a_new_blob_does_not_make_an_open_pack_exceed_the_reduced_target(): void {
		$backend    = new InMemoryBackend();
		$repository = Repository::initialize( $backend, 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$index      = new IndexSet();
		$repository->limit_memory( new MemoryBudget( self::reserved() + self::SMALLEST_JOB ) );
		$store = new BlobStore( $repository, $index );
		self::assertLessThan( 5 * self::MIB, $store->pack_size() );
		$store->save( BlobType::Data, str_repeat( 'a', 3 * self::MIB ) );
		self::assertSame( [], $backend->list( ObjectType::Data ) );
		$store->save( BlobType::Data, str_repeat( 'b', 3 * self::MIB ) );
		$sizes = array_values( $backend->list( ObjectType::Data ) );
		self::assertCount( 1, $sizes, 'The first pack is uploaded before the second blob is appended.' );
		self::assertLessThanOrEqual( $store->pack_size(), $sizes[0] );
		$store->flush();
		self::assertCount( 2, $backend->list( ObjectType::Data ) );
	}

	public function test_the_pack_projection_counts_the_sealed_blob_and_header_entry(): void {
		$backend    = new InMemoryBackend();
		$repository = Repository::initialize( $backend, 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		// Two one-byte blobs would exceed this target by one byte, including their header.
		$target = 2 * ( 1 + Cipher::OVERHEAD + 37 ) + Cipher::OVERHEAD + 4 - 1;
		$store  = new BlobStore( $repository, new IndexSet(), $target );
		$store->save( BlobType::Data, 'a' );
		$store->save( BlobType::Data, 'b' );
		$sizes = array_values( $backend->list( ObjectType::Data ) );
		self::assertCount( 1, $sizes );
		self::assertLessThan( $target, $sizes[0] );
		$store->flush();
		self::assertCount( 2, $backend->list( ObjectType::Data ) );
	}
}
