<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\ResticIndexFile;
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


	/**
	 * What WordPress 7.1.5 on PHP 8.3 had reserved once it was loaded, in every tick of the CI
	 * memory runs (ADR 0019).
	 */
	private const int WORDPRESS = 34 * self::MIB;

	/** @var list<string> */
	private array $cleanup = [];

	/**
	 * PHP's memory limit when the test started.
	 *
	 * @var string
	 */
	private string $memory_limit = '';

	protected function setUp(): void {
		$this->memory_limit = (string) ini_get( 'memory_limit' );
	}

	protected function tearDown(): void {
		self::limit_memory( $this->memory_limit );
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	/**
	 * Sets PHP's memory limit; tearDown() restores it.
	 *
	 * @param int|string $value Bytes.
	 */
	private static function limit_memory( int|string $value ): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The decoder reads PHP's own limit; this test without WordPress sets it as a host would.
		if ( false === ini_set( 'memory_limit', (string) $value ) ) {
			throw new \LogicException( 'PHP did not accept the memory limit.' );
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

	public function test_encrypted_index_input_needs_room_for_transport_and_decryption_copies(): void {
		$budget = new MemoryBudget( self::reserved() + 20 * self::MIB );
		$budget->check_index_input( 4 * self::MIB );
		$this->expectException( IndexTooLarge::class );
		$budget->check_index_input( 6 * self::MIB );
	}

	public function test_decoding_a_compressed_index_file_needs_room_beside_the_memory_in_use(): void {
		$budget = new MemoryBudget( self::reserved() + 2 * self::MIB + 20 * self::MIB );
		$budget->check_index_decoding( 20 * self::MIB - self::BLOCK );
		try {
			$budget->check_index_decoding( 20 * self::MIB + self::BLOCK );
			self::fail( 'Decoding takes more than is left.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'The next index file does not fit beside the memory already in use.', $error->getMessage() );
			self::assertSame( [ 'memory_limit', 'reserved', 'decoding_bytes' ], array_keys( $error->context() ) );
			self::assertSame( 20 * self::MIB + self::BLOCK, $error->context()['decoding_bytes'] );
		}
	}

	public function test_an_oversized_index_is_refused_before_the_backend_loads_its_body(): void {
		$backend    = new CountingBackend();
		$repository = Repository::initialize( $backend, 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$body       = str_repeat( 'x', 10 * self::MIB );
		$id         = Id::of( $body );
		$backend->save( ObjectType::Index, $id->hex(), $body );
		unset( $body );
		$repository->limit_memory( new MemoryBudget( self::reserved() + self::SMALLEST_JOB + self::MIB ) );
		try {
			$repository->load_unpacked( ObjectType::Index, $id );
			self::fail( 'The input and its decryption copies cannot fit.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 10 * self::MIB, $error->context()['input_bytes'] );
			self::assertSame( 0, $backend->loads['index'] ?? 0 );
		}
	}

	public function test_an_index_advertised_as_empty_is_refused_without_an_unbounded_load(): void {
		$backend    = new CountingBackend();
		$repository = Repository::initialize( $backend, 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$id         = Id::of( '' );
		$backend->save( ObjectType::Index, $id->hex(), '' );
		$repository->limit_memory( new MemoryBudget( self::reserved() + self::SMALLEST_JOB + self::MIB ) );

		try {
			$repository->load_unpacked( ObjectType::Index, $id );
			self::fail( 'An empty encrypted index is invalid.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'The index file is empty.', $error->getMessage() );
			self::assertSame( 0, $backend->loads['index'] ?? 0, 'A zero size must not select the whole-object download.' );
		}
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

	/**
	 * A format 2 repository on files with one index file that restic 0.14 to 0.16 could have
	 * written: 150,000 blobs compressed with zstd. Every load from files makes a copy of its
	 * own, as a response from a server does.
	 *
	 * @return array{Repository, int, int} The repository, the length of the JSON and of the stored file.
	 */
	private function compressed_index_repository(): array {
		$directory       = TempDir::create( 'compressed-index' );
		$this->cleanup[] = $directory;
		$backend         = new FileBackend( $directory );
		$repository      = Repository::initialize( $backend, 'budget password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$config          = $repository->config();
		unlink( $directory . '/config' );
		$backend->save( ObjectType::Config, 'config', $repository->cipher()->seal( ( new Config( Config::VERSION_2, $config->id, $config->chunker_polynomial ) )->to_json() ) );
		$repository = Repository::open( $backend, $repository->master_key(), new SystemRandom() );
		$json       = ResticIndexFile::json( ResticIndexFile::COMPRESSED_BLOBS );
		$id         = $repository->save_unpacked( ObjectType::Index, "\x02" . zstd_compress( $json, 3 ) );
		return [ $repository, strlen( $json ), (int) $backend->size( ObjectType::Index, $id->hex() ) ];
	}

	public function test_a_compressed_index_file_of_150000_blobs_loads_in_what_wordpress_leaves_of_128_mib(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// Building the file needs more than the suite's limit leaves; loading it does not.
		self::limit_memory( self::reserved() + 128 * self::MIB );
		[ $repository, $json, $stored ] = $this->compressed_index_repository();
		$limit                          = self::reserved() + 128 * self::MIB - self::WORDPRESS;
		self::limit_memory( $limit );
		$repository->limit_memory( new MemoryBudget( $limit ) );

		memory_reset_peak_usage();
		$before = memory_get_usage();
		$index  = $repository->load_index();
		$peak   = memory_get_peak_usage() - $before;
		self::assertSame( ResticIndexFile::COMPRESSED_BLOBS, $index->count() );
		// The decoder's two buffers beside one copy of the stored file: the sealed bytes and the
		// plaintext are let go before decoding starts.
		self::assertLessThan( 2 * $json + 1.5 * $stored, $peak );
	}

	public function test_a_compressed_index_file_that_cannot_be_decoded_beside_the_memory_in_use_is_an_index_too_large(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		self::limit_memory( self::reserved() + 128 * self::MIB );
		[ $repository, $json, $stored ] = $this->compressed_index_repository();
		// Room for the smallest job and for the stored file with its decryption copies, which the
		// checks before the download ask for, but not for decoding twice the JSON.
		$limit = self::reserved() + max( self::SMALLEST_JOB, 4 * $stored + 2 * self::MIB ) + self::BLOCK;
		self::limit_memory( $limit );
		$repository->limit_memory( new MemoryBudget( $limit ) );

		try {
			$repository->load_index();
			self::fail( 'Decoding the file takes twice its JSON.' );
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'The next index file does not fit beside the memory already in use.', $error->getMessage() );
			self::assertSame( 2 * $json, $error->context()['decoding_bytes'] );
		}
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

	/**
	 * What PHP reserves for a string with memory of its own: its size in the page that
	 * MemoryBudget uses (MemoryBudget::page()).
	 */
	private static function pages( int $bytes ): int {
		$page = MemoryBudget::page();
		return intdiv( MemoryBudget::string_size( $bytes ) + $page - 1, $page ) * $page;
	}

	/**
	 * The longest string that takes no more than the given pages of 4 KiB inside a block.
	 */
	private static function filling( int $pages ): int {
		return $pages * 4096 - 32;
	}

	/**
	 * Makes once what the questions to PHP and the assertions below make, so that the slots of
	 * their small allocations are free when they are asked again: they then take no page.
	 */
	private static function warm(): void {
		MemoryBudget::placed( [ 1 ] );
		MemoryBudget::response( self::filling( 300 ), 3 * self::BLOCK - 1 );
		MemoryBudget::opening( new PackEntry( BlobType::Data, Id::of( 'warm' ), 0, self::filling( 300 ), self::MIB ), 3 * self::MIB, self::BLOCK );
		self::assertTrue( true );
		self::assertFalse( false );
		self::assertSame( 0, 0 );
	}

	/**
	 * Fills every run of 16 free pages in PHP's blocks with strings of 16 pages, until PHP
	 * reserves a new block for one: that block is then the only one with such a run, of the 495
	 * pages after the string. PHP takes a new block from the system only when it keeps none for
	 * reuse, so it keeps none then.
	 *
	 * @param array<int, string> $fill Room for the strings, made before, so that keeping them takes no page.
	 * @return bool Whether PHP reserved the new block before the room ran out.
	 */
	private static function fill_runs( array &$fill ): bool {
		$before = memory_get_usage( true );
		$made   = 0;
		while ( $made < 4096 && memory_get_usage( true ) === $before ) {
			$fill[ $made ] = str_repeat( 'f', self::filling( 16 ) );
			++$made;
		}
		return memory_get_usage( true ) !== $before;
	}

	public function test_the_page_is_what_php_rounds_memory_of_its_own_up_to(): void {
		$page = MemoryBudget::page();
		self::assertSame( 0, $page & ( $page - 1 ), 'A power of two.' );
		if ( function_exists( 'posix_sysconf' ) && defined( 'POSIX_SC_PAGESIZE' ) && 'Windows' !== PHP_OS_FAMILY ) {
			self::assertSame( posix_sysconf( POSIX_SC_PAGESIZE ), $page );
		}
		gc_mem_caches();
		$before = memory_get_usage( true );
		$string = str_repeat( 'p', 3 * self::MIB + 1 );
		self::assertSame( self::pages( 3 * self::MIB + 1 ), memory_get_usage( true ) - $before, 'What PHP reserved for the string.' );
		unset( $string );
	}

	public function test_without_the_posix_extension_the_page_is_measured_while_two_blocks_are_left(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'PHP on Windows rounds memory of its own to whole blocks.' );
		}
		$measure = new \ReflectionMethod( MemoryBudget::class, 'measure_page' );
		self::limit_memory( -1 );
		self::assertSame( MemoryBudget::page(), $measure->invoke( null ), 'By what a string of a block reserves.' );
		self::limit_memory( memory_get_usage( true ) + 2 * self::BLOCK );
		self::assertSame( MemoryBudget::page(), $measure->invoke( null ), 'Two blocks left take the string.' );
		self::limit_memory( memory_get_usage( true ) + 2 * self::BLOCK - 4096 );
		self::assertSame( 65536, $measure->invoke( null ), 'With less, the largest page that Linux uses is assumed.' );
	}

	public function test_a_string_takes_a_header_and_a_closing_byte_aligned_to_eight(): void {
		self::assertSame( 32, MemoryBudget::string_size( 0 ) );
		self::assertSame( 32, MemoryBudget::string_size( 7 ) );
		self::assertSame( 40, MemoryBudget::string_size( 8 ) );
		self::assertSame( 8388640, MemoryBudget::string_size( 8388608 ) );
	}

	public function test_strings_that_exist_at_once_take_pages_of_their_own_or_new_blocks_together(): void {
		self::assertSame( self::pages( 8 * self::MIB ), MemoryBudget::reserves( [ 8 * self::MIB ] ), 'Too large for a block: memory of its own.' );
		self::assertSame( 3 * self::pages( 8 * self::MIB ), MemoryBudget::reserves( [ 8 * self::MIB, 8 * self::MIB, 8 * self::MIB ] ) );
		self::assertSame( self::BLOCK, MemoryBudget::reserves( [ self::filling( 511 ) ] ), 'The largest string that PHP puts into a block.' );
		self::assertSame( self::pages( self::filling( 511 ) + 8 ), MemoryBudget::reserves( [ self::filling( 511 ) + 8 ] ), 'Eight bytes more, and it has memory of its own.' );
		// The smaller strings go into new blocks in the order they are made, together where they
		// fit into the 511 pages of one.
		self::assertSame( self::BLOCK, MemoryBudget::reserves( [ 40000, 40000, 40000 ] ) );
		self::assertSame( self::BLOCK, MemoryBudget::reserves( [ self::filling( 255 ), self::filling( 256 ) ] ), 'Together they fill a block.' );
		self::assertSame( 2 * self::BLOCK, MemoryBudget::reserves( [ self::filling( 256 ), self::filling( 256 ) ] ), 'One page more does not fit.' );
		self::assertSame( 2 * self::BLOCK, MemoryBudget::reserves( [ self::filling( 400 ), self::filling( 200 ), self::filling( 95 ) ] ), 'The third one fits beside the first.' );
		self::assertSame( 3 * self::BLOCK, MemoryBudget::reserves( [ self::MIB, self::MIB, self::MIB ] ), 'Strings of 257 pages: one in each block.' );
		self::assertSame( 2 * self::BLOCK, MemoryBudget::reserves( [ self::filling( 511 ), 1 ] ), 'A full block takes nothing more.' );
		self::assertSame( self::pages( 3 * self::MIB ) + self::BLOCK, MemoryBudget::reserves( [ 40000, 3 * self::MIB, 40000 ] ) );
	}

	/**
	 * In a process of its own, whose blocks hold no strings that other tests let go.
	 */
	#[RunInSeparateProcess]
	public function test_php_is_asked_whether_the_smaller_strings_of_a_step_find_free_pages_in_its_blocks(): void {
		self::warm();
		$fill = array_fill( 0, 4096, '' );
		$new  = self::fill_runs( $fill );
		// One block has 495 free pages in a run, no other has a run of 16.
		self::assertTrue( MemoryBudget::placed( [ self::filling( 495 ) ] ), 'A string of the 495 pages.' );
		self::assertTrue( MemoryBudget::placed( [ self::filling( 300 ), self::filling( 195 ) ] ), 'Two strings, one after the other.' );
		self::assertFalse( MemoryBudget::placed( [ self::filling( 300 ), self::filling( 196 ) ] ), 'One page more: PHP reserves a new block for the second.' );
		self::assertFalse( MemoryBudget::placed( [ self::filling( 496 ) ] ) );
		self::assertTrue( $new, 'PHP reserved a new block before the room for the strings ran out.' );
	}

	/**
	 * In a process of its own, whose blocks hold no strings that other tests let go.
	 */
	#[RunInSeparateProcess]
	public function test_when_the_most_that_a_step_can_take_does_not_fit_php_is_asked_with_a_block_left(): void {
		self::warm();
		$entry  = new PackEntry( BlobType::Data, Id::of( 'blob' ), 0, self::filling( 300 ) );
		$packed = new PackEntry( BlobType::Data, Id::of( 'packed' ), 0, self::filling( 300 ), self::MIB );
		$fill   = array_fill( 0, 4096, '' );
		$new    = self::fill_runs( $fill );
		// Three blocks with 495 free pages each: the first, filled with a string of 495 pages, then
		// a string of 16 pages that takes the second, and so on; the strings of 495 pages go again.
		$first  = str_repeat( 'x', self::filling( 495 ) );
		$taken  = str_repeat( 'f', self::filling( 16 ) );
		$second = str_repeat( 'x', self::filling( 495 ) );
		$third  = str_repeat( 'f', self::filling( 16 ) );
		unset( $first, $second );

		$range = self::filling( 300 );
		// Three copies of 300 or 301 pages: one new block each, at most.
		self::assertSame( 3 * self::BLOCK, MemoryBudget::response( $range ) );
		self::assertSame( 3 * self::BLOCK, MemoryBudget::response( $range, 3 * self::BLOCK ), 'The most fits: PHP is not asked.' );
		self::assertSame( 0, MemoryBudget::response( $range, 3 * self::BLOCK - 1 ), 'It does not: PHP finds them free pages.' );
		self::assertSame( 0, MemoryBudget::response( $range, self::BLOCK ) );
		self::assertSame( 3 * self::BLOCK, MemoryBudget::response( $range, self::BLOCK - 1 ), 'Asking may take a block: with less left PHP is not asked.' );
		// Beside a range of 3 MiB, which has memory of its own, and a decoder.
		$room = self::pages( 3 * self::MIB ) + 3 * self::BLOCK - 1;
		self::assertSame( self::pages( 3 * self::MIB ), MemoryBudget::opening( $entry, 3 * self::MIB, $room ) );
		self::assertSame( self::pages( 3 * self::MIB ) + MemoryBudget::decoding( self::MIB ), MemoryBudget::opening( $packed, 3 * self::MIB, $room ) );
		// No string smaller than a block: nothing to ask, and asking makes a string of 16 pages.
		memory_reset_peak_usage();
		$used = memory_get_usage();
		self::assertSame( MemoryBudget::response( 8 * self::MIB ), MemoryBudget::response( 8 * self::MIB, self::BLOCK ) );
		self::assertLessThan( $used + self::filling( 16 ), memory_get_peak_usage() );
		self::assertTrue( $new, 'PHP reserved a new block before the room for the strings ran out.' );
		// Last, since PHP may keep the block it took when asked: copies of 500 pages find no free run.
		self::assertSame( 3 * self::BLOCK, MemoryBudget::response( self::filling( 500 ), 3 * self::BLOCK - 1 ), 'Copies that find no free pages count as new blocks.' );
	}

	public function test_a_response_is_held_three_times_with_its_headers_in_one_copy(): void {
		self::assertSame( 2 * self::pages( 8 * self::MIB ) + self::pages( 8 * self::MIB + MemoryBudget::RESPONSE_HEAD ), MemoryBudget::response( 8 * self::MIB ) );
		self::assertSame( self::BLOCK, MemoryBudget::response( 40000 ), 'Three small copies fill one new block together.' );
	}

	public function test_decoding_takes_twice_the_content_or_a_small_frames_window_and_the_decoders_reserve(): void {
		self::assertSame( 2 * 8 * self::MIB + Compression::RESERVE, MemoryBudget::decoding( 8 * self::MIB ) );
		// restic writes content below 256 bytes without a declared size and with a window of 1 KiB.
		self::assertSame( 4096 + Compression::RESERVE, MemoryBudget::decoding( 200 ) );
		self::assertSame( 4096 + Compression::RESERVE, MemoryBudget::decoding( 2048 ) );
		self::assertSame( 4098 + Compression::RESERVE, MemoryBudget::decoding( 2049 ) );
	}

	/**
	 * What opening a blob takes at most: three strings while it is decrypted, the frame and what
	 * the decoder requires while a compressed one is decoded.
	 *
	 * @return iterable<string, array{PackEntry, \Closure(): int}>
	 */
	public static function openings(): iterable {
		$id = Id::of( 'blob' );
		yield 'a full chunk, uncompressed' => [ new PackEntry( BlobType::Data, $id, 0, 8388640 ), static fn (): int => self::pages( 8388640 ) + 2 * self::pages( 8388608 ) ];
		// restic stores a full chunk that does not compress 202 bytes longer than the chunk.
		yield 'a full chunk in raw zstd blocks' => [ new PackEntry( BlobType::Data, $id, 0, 8388842, 8388608 ), static fn (): int => self::pages( 8388810 ) + MemoryBudget::decoding( 8388608 ) ];
		yield 'a full chunk that compresses' => [ new PackEntry( BlobType::Data, $id, 0, 2654000, 8388608 ), static fn (): int => self::pages( 2653968 ) + MemoryBudget::decoding( 8388608 ) ];
		yield 'a small blob, its copies in a new block' => [ new PackEntry( BlobType::Data, $id, 0, 40032 ), static fn (): int => self::BLOCK ];
		yield 'a small compressed blob, its frame in a new block' => [ new PackEntry( BlobType::Data, $id, 0, 20032, 40000 ), static fn (): int => self::BLOCK + MemoryBudget::decoding( 40000 ) ];
		yield 'a blob that claims more than Compression decodes' => [ new PackEntry( BlobType::Tree, $id, 0, 1000, Compression::MAX_DECOMPRESSED_SIZE + 1 ), static fn (): int => self::BLOCK ];
		yield 'a blob that claims exactly what Compression decodes' => [ new PackEntry( BlobType::Tree, $id, 0, 1000, Compression::MAX_DECOMPRESSED_SIZE ), static fn (): int => self::BLOCK + MemoryBudget::decoding( Compression::MAX_DECOMPRESSED_SIZE ) ];
	}

	/**
	 * @param \Closure(): int $bytes What it takes.
	 */
	#[DataProvider( 'openings' )]
	public function test_opening_a_blob_takes_three_copies_of_it_or_what_decoding_it_takes( PackEntry $entry, \Closure $bytes ): void {
		self::assertSame( $bytes(), MemoryBudget::opening( $entry, 0 ) );
	}

	public function test_reading_a_range_takes_its_response_then_each_blob_beside_the_range_and_the_last_alone(): void {
		$id    = Id::of( 'blob' );
		$chunk = new PackEntry( BlobType::Data, $id, 0, 8388640 );
		$text  = new PackEntry( BlobType::Data, $id, 0, 2654000, 8388608 );
		self::assertSame( MemoryBudget::response( 2 * 8388640 ), MemoryBudget::range( 2 * 8388640, $chunk, $chunk ), 'Two full chunks: the response is held three times while it arrives.' );
		self::assertSame( self::pages( 2 * 2654000 ) + MemoryBudget::opening( $text, 0 ), MemoryBudget::range( 2 * 2654000, $text, $text ), 'A blob before the last is opened beside the whole range.' );
		self::assertSame( MemoryBudget::opening( $text, 0 ), MemoryBudget::range( 2654000, null, $text ), 'The last blob is opened by itself.' );
		self::assertSame( MemoryBudget::response( 1000 ), MemoryBudget::range( 1000, null, null ), 'A range without a blob to open takes its response.' );
	}

	public function test_once_a_range_has_arrived_the_rest_of_opening_a_blob_is_counted_beside_it(): void {
		$id    = Id::of( 'blob' );
		$chunk = new PackEntry( BlobType::Data, $id, 0, 8388640 );
		$text  = new PackEntry( BlobType::Data, $id, 0, 8388842, 8388608 );
		self::assertSame( self::pages( 8388640 ) + 2 * self::pages( 8388608 ), MemoryBudget::rest( $chunk, 3 * 8388640, false ), 'Before the last blob the range stays.' );
		self::assertSame( 2 * self::pages( 8388608 ), MemoryBudget::rest( $chunk, 8388640, true ), 'A blob that is the whole range: its stored bytes are held already.' );
		self::assertSame( self::pages( 8388640 ) + 2 * self::pages( 8388608 ) - self::pages( 2 * 8388640 ), MemoryBudget::rest( $chunk, 2 * 8388640, true ), 'The last blob gives back the range once it is taken out.' );
		self::assertSame( self::pages( 8388640 ), MemoryBudget::rest( $chunk, 3 * 8388640, true ), 'At the least the copy that takes it out, beside the range.' );
		self::assertSame( self::pages( 8388810 ) + MemoryBudget::decoding( 8388608 ) - self::pages( 8388842 ), MemoryBudget::rest( $text, 8388842, true ), 'A compressed blob is decoded once the range is let go.' );
		$small = new PackEntry( BlobType::Data, $id, 0, self::filling( 225 ) );
		self::assertSame( 2 * self::BLOCK, MemoryBudget::rest( $small, self::filling( 225 ) + 100000, true ), 'A range in a block gives back nothing to count on: its pages stay with PHP.' );
		$filled = new PackEntry( BlobType::Data, $id, 0, self::filling( 200 ) );
		self::assertSame( 2 * self::BLOCK, MemoryBudget::rest( $filled, self::filling( 511 ), true ), 'Nor does a range that fills a block.' );
		self::assertSame( self::BLOCK, MemoryBudget::rest( $filled, self::filling( 200 ), true ), 'A small blob that is the whole range: its stored bytes are the range, two copies follow.' );
		$third = new PackEntry( BlobType::Data, $id, 0, self::filling( 170 ) );
		self::assertSame( self::BLOCK, MemoryBudget::rest( $third, self::filling( 170 ) + 100000, false ), 'The stored bytes and two copies without nonce and tag share a block.' );
	}

	public function test_of_two_blobs_the_one_whose_opening_takes_the_most_counts(): void {
		$id    = Id::of( 'blob' );
		$small = new PackEntry( BlobType::Data, $id, 0, 40032 );
		$chunk = new PackEntry( BlobType::Data, $id, 0, 8388640 );
		self::assertSame( $chunk, MemoryBudget::larger( $small, $chunk ) );
		self::assertSame( $chunk, MemoryBudget::larger( $chunk, $small ) );
		self::assertSame( $small, MemoryBudget::larger( null, $small ) );
		self::assertSame( $small, MemoryBudget::larger( $small, null ) );
		self::assertNull( MemoryBudget::larger( null, null ) );
	}

	public function test_a_blob_is_taken_out_of_its_range_and_the_last_takes_the_range_with_it(): void {
		$range = 'firstsecond';
		self::assertSame( 'first', MemoryBudget::slice( $range, 0, 5, false ) );
		self::assertSame( 'firstsecond', $range, 'A blob that is followed by another keeps the range.' );
		self::assertSame( 'second', MemoryBudget::slice( $range, 5, 6, true ) );
		self::assertSame( '', $range, 'The last blob lets the range go.' );
	}

	public function test_a_range_arrived_alone_when_php_reserved_and_holds_no_more_than_the_range(): void {
		$reserved = memory_get_usage( true );
		$used     = memory_get_usage();
		$range    = str_repeat( 'r', 3 * self::MIB );
		self::assertTrue( MemoryBudget::arrived_alone( $reserved, $used, 3 * self::MIB ), 'A range with memory of its own, and nothing else.' );
		$kept = str_repeat( 'k', 100000 );
		self::assertFalse( MemoryBudget::arrived_alone( $reserved, $used, 3 * self::MIB ), 'Other code keeps a string beside it.' );
		unset( $range, $kept );

		$reserved = memory_get_usage( true );
		$used     = memory_get_usage();
		$range    = str_repeat( 'r', 600000 );
		self::assertTrue( MemoryBudget::arrived_alone( $reserved, $used, 600000 ), 'A range smaller than a block, in a new block or in free pages.' );
		$kept = str_repeat( 'k', 100000 );
		self::assertFalse( MemoryBudget::arrived_alone( $reserved, $used, 600000 ), 'Beside it, other code keeps a string that the same block may hold.' );
		unset( $kept );
		$kept = str_repeat( 'k', 3 * self::MIB );
		self::assertFalse( MemoryBudget::arrived_alone( $reserved, $used, 600000 ), 'Or one with memory of its own.' );
		unset( $range, $kept );
	}

	public function test_the_room_for_blobs_is_what_the_limit_leaves_beside_the_reserved_memory(): void {
		$reserved = self::reserved();
		$budget   = new MemoryBudget( $reserved + 30 * self::MIB );
		$room     = $budget->measure();
		self::assertSame( 30 * self::MIB, $room );
		self::assertSame( 0, ( new MemoryBudget( $reserved - self::BLOCK ) )->measure(), 'Never less than nothing.' );
		try {
			MemoryBudget::refuse_blob(
				[
					'memory_limit' => $budget->limit,
					'available'    => $room,
					'blob_bytes'   => $room + 1,
				]
			);
		} catch ( IndexTooLarge $error ) {
			self::assertSame( 'The next blob of the restore does not fit beside the memory already in use.', $error->getMessage() );
			self::assertSame(
				[
					'memory_limit' => $reserved + 30 * self::MIB,
					'available'    => 30 * self::MIB,
					'blob_bytes'   => 30 * self::MIB + 1,
					'code'         => 'restore_blob_memory',
				],
				$error->context()
			);
		}
	}

	/**
	 * In a process of its own: the let-go strings must take blocks of their own.
	 */
	#[RunInSeparateProcess]
	public function test_php_gives_back_what_it_keeps_only_when_asked_to(): void {
		$budget = new MemoryBudget( PHP_INT_MAX );
		gc_mem_caches();
		$small = [];
		for ( $i = 0; $i < 100000; ++$i ) {
			$small[] = str_repeat( 's', 60 ) . $i;
		}
		unset( $small );
		$kept = memory_get_usage( true );
		$budget->measure();
		self::assertSame( $kept, memory_get_usage( true ), 'Measuring alone gives nothing back.' );
		$budget->measure( true );
		self::assertLessThan( $kept, memory_get_usage( true ), 'Asked to, PHP gives back the blocks that the let-go strings left.' );
	}
}
