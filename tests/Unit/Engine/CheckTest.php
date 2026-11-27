<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\InsufficientDecodingMemory;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The repository check finds what restic check finds, in resumable ticks (ADR 0022).
 */
final class CheckTest extends TestCase {

	private const string PASSWORD = 'check password';

	private string $site;

	private InMemoryBackend $backend;

	private Repository $repository;

	private Identity $identity;

	/** @var list<string> */
	private array $cleanup = [];

	/** PHP's memory limit when the test started; a test that sets it gets it back. */
	private string $memory_limit = '';

	protected function setUp(): void {
		$this->memory_limit = (string) ini_get( 'memory_limit' );
		$this->site         = TempDir::create( 'site' );
		$this->cleanup[]    = $this->site;
		TempDir::fixture( $this->site, 1048576 );
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, self::PASSWORD, $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->back_up();
		file_put_contents( $this->site . '/index.php', "<?php\n// Changed.\n" );
		$this->back_up();
	}

	protected function tearDown(): void {
		self::limit_php( $this->memory_limit );
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	/**
	 * Sets PHP's memory limit, which the decoder reads; tearDown() restores it.
	 */
	private static function limit_php( string $limit ): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The decoder reads PHP's own limit; this test without WordPress sets it as a host would.
		self::assertNotFalse( ini_set( 'memory_limit', $limit ) );
	}

	/**
	 * Leaves 64 MiB beside the memory that PHP has reserved.
	 */
	private static function leave_64_mib(): void {
		self::limit_php( (string) ( memory_get_usage( true ) + 64 * 1048576 ) );
	}

	/**
	 * A zstd frame of one raw block, without a declared size and with a window of 64 MiB, as a
	 * streaming encoder may write it: the decoder counts four times its window
	 * (Format\Compression), 256 MiB, more than PHP's limit leaves.
	 */
	private static function wide_frame( string $content ): string {
		self::assertLessThanOrEqual( 131072, strlen( $content ), 'One raw block holds at most 128 KiB.' );
		return "\x28\xb5\x2f\xfd\x00\x80" . substr( pack( 'V', strlen( $content ) << 3 | 1 ), 0, 3 ) . $content;
	}

	/**
	 * Stores a pack of one blob in a wide frame (wide_frame()), indexed, and gives its name.
	 */
	private function wide_pack( BlobType $type, string $content ): string {
		$cipher                                  = $this->repository->cipher();
		$id                                      = Id::of( $content );
		$sealed                                  = $cipher->seal( self::wide_frame( $content ) );
		$header                                  = $cipher->seal( chr( BlobType::Tree === $type ? 3 : 2 ) . pack( 'VV', strlen( $sealed ), strlen( $content ) ) . $id->bytes() );
		$body                                    = $sealed . $header . pack( 'V', strlen( $header ) );
		$name                                    = hash( 'sha256', $body );
		$this->backend->objects['data'][ $name ] = $body;
		$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $name => [ new PackEntry( $type, $id, 0, strlen( $sealed ), strlen( $content ) ) ] ] ) );
		return $name;
	}

	/**
	 * The repository of the fixture as one of format 2, whose files may be compressed.
	 */
	private function format_2( ?Backend $backend = null ): Repository {
		$config = $this->repository->config();
		$this->backend->objects[ ObjectType::Config->value ]['config'] = $this->repository->cipher()->seal( ( new Config( Config::VERSION_2, $config->id, $config->chunker_polynomial ) )->to_json() );
		return Repository::open( $backend ?? $this->backend, $this->repository->master_key(), new SystemRandom() );
	}

	private function work_dir(): string {
		$this->cleanup[] = $path = TempDir::create( 'work' );
		return $path . '/run';
	}

	private function back_up(): void {
		$run = BackupRun::start( $this->repository, new BackupPlan( [ $this->site ], [ 'backups-for-restic' ] ), $this->work_dir(), $this->identity, new SystemClock(), null, 1048576 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
	}

	/**
	 * Runs a check to the end.
	 *
	 * @return array{errors: int, warnings: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, stats: array<string, int>}
	 */
	private function check( int $percent = 100, bool $interrupt = false ): array {
		$work  = $this->work_dir();
		$run   = CheckRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), $percent );
		$ticks = 0;
		while ( ! $run->advance( $interrupt ? 0.0 : INF ) ) {
			if ( $interrupt ) {
				$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
			}
			self::assertLessThan( 100000, ++$ticks );
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The lock is released.' );
		return $run->result();
	}

	/**
	 * Codes of the findings.
	 *
	 * @param array{findings: list<array{severity: string, code: string, object: string, message: string}>} $result Result.
	 * @return list<string> severity:code per finding.
	 */
	private static function codes( array $result ): array {
		return array_values( array_unique( array_map( static fn ( array $finding ): string => $finding['severity'] . ':' . $finding['code'], $result['findings'] ) ) );
	}

	private function any_pack(): string {
		$packs = array_keys( $this->backend->list( ObjectType::Data ) );
		self::assertNotSame( [], $packs );
		return $packs[0];
	}

	public function test_a_healthy_repository_has_no_findings_and_every_pack_is_read(): void {
		$result = $this->check();

		self::assertSame( 0, $result['errors'], (string) json_encode( $result['findings'] ) );
		self::assertSame( 0, $result['warnings'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ), $result['stats']['packs_read'] );
		self::assertSame( 2, $result['stats']['snapshots'] );
		self::assertGreaterThan( 10, $result['stats']['blobs_verified'] ?? 0 );
	}

	public function test_rebuilding_a_readable_index_obeys_the_memory_budget(): void {
		$this->backend->objects['index'][ hash( 'sha256', 'damaged index' ) ] = 'damaged index';
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		while ( 'trees' !== $run->progress()['phase'] ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		$this->repository->limit_memory( new MemoryBudget( 1 ) );
		try {
			$run->advance( INF );
			self::fail( 'A check must refuse an index that does not fit.' );
		} catch ( IndexTooLarge ) {
			self::assertArrayNotHasKey( 'error:tree_unreadable', $run->result()['counts'], 'Insufficient memory is not repository damage.' );
		} finally {
			$run->abort();
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_cache_memory_refusal_does_not_start_an_uncached_fallback(): void {
		$listings   = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$listings ): void {
				if ( 'list' === $method && ObjectType::Index === $type ) {
					++$listings;
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$cache      = new IndexCache( $this->work_dir() );
		$cache->load( $repository );
		$run = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0, $cache );
		while ( 'trees' !== $run->progress()['phase'] ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		$before = $listings;
		$repository->limit_memory( new MemoryBudget( 1 ) );
		try {
			$run->advance( INF );
			self::fail( 'The cache cannot fit.' );
		} catch ( IndexTooLarge ) {
			self::assertSame( $before + 1, $listings, 'Only the cache lists the indexes; a fallback would list them again.' );
		} finally {
			$run->abort();
		}
	}

	public function test_an_empty_rebuilt_index_also_needs_working_memory(): void {
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			$this->backend->delete( ObjectType::Index, $name );
		}
		$this->repository->limit_memory( new MemoryBudget( 1 ) );
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		try {
			$this->expectException( IndexTooLarge::class );
			$run->advance( INF );
		} finally {
			$run->abort();
		}
	}

	public function test_an_interrupted_check_after_every_tick_gives_the_same_result(): void {
		$whole       = $this->check();
		$interrupted = $this->check( 100, true );

		self::assertSame( $whole['counts'], $interrupted['counts'] );
		self::assertSame( $whole['stats'], $interrupted['stats'] );
	}

	public function test_reads_only_the_requested_share_of_packs(): void {
		self::assertArrayNotHasKey( 'packs_read', $this->check( 0 )['stats'] );
		$one = $this->check( 1 )['stats']['packs_read'] ?? 0;
		self::assertSame( 1, $one, 'Any share above zero reads at least one pack.' );
	}

	public function test_rejects_a_share_outside_0_to_100(): void {
		$this->expectException( InvalidArgument::class );
		CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 101 );
	}

	public function test_finds_a_missing_pack(): void {
		$pack = $this->any_pack();
		$this->backend->delete( ObjectType::Data, $pack );

		$result = $this->check();

		self::assertContains( 'error:pack_missing', self::codes( $result ) );
		self::assertSame( $pack, $result['findings'][0]['object'] );
	}

	public function test_a_missing_pack_with_trees_is_a_finding_and_the_check_goes_on(): void {
		$root = array_values( $this->repository->snapshots() )[0]->tree;
		$pack = $this->repository->load_index()->find( $root, BlobType::Tree )?->pack->hex();
		self::assertNotNull( $pack );
		$this->backend->delete( ObjectType::Data, $pack );

		$codes = self::codes( $this->check() );

		self::assertContains( 'error:pack_missing', $codes );
		self::assertContains( 'error:tree_unreadable', $codes );
	}

	public function test_finds_a_truncated_pack(): void {
		$pack                                    = $this->any_pack();
		$this->backend->objects['data'][ $pack ] = substr( $this->backend->objects['data'][ $pack ], 0, -100 );

		$codes = self::codes( $this->check() );

		self::assertContains( 'error:pack_size', $codes );
		self::assertContains( 'error:pack_hash', $codes );
	}

	public function test_finds_a_blob_whose_content_does_not_match_its_id(): void {
		$claimed = Id::of( 'what the index claims' );
		$writer  = new PackWriter( $this->repository->cipher() );
		$writer->add( BlobType::Data, $claimed, 'something else' );
		$pack = $writer->finish();
		$this->backend->save( ObjectType::Data, $pack->id->hex(), $pack->bytes );
		$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->id->hex() => $pack->entries ] ) );

		$result = $this->check();

		self::assertSame( [ 'error:blob_corrupt' ], self::codes( $result ) );
		self::assertSame( $claimed->hex(), $result['findings'][0]['object'] );
	}

	public function test_a_tree_with_unordered_names_is_reported_as_damaged(): void {
		$tree   = '{"nodes":[{"name":"b","type":"file"},{"name":"a","type":"file"}]}';
		$id     = Id::of( $tree );
		$writer = new PackWriter( $this->repository->cipher() );
		$writer->add( BlobType::Tree, $id, $tree );
		$pack = $writer->finish();
		$this->backend->save( ObjectType::Data, $pack->id->hex(), $pack->bytes );
		$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->id->hex() => $pack->entries ] ) );
		$this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-11-12T00:00:00Z', $id, [ '/site' ] ) )->to_json() );

		$result = $this->check();

		self::assertContains( 'error:tree_unreadable', self::codes( $result ) );
		$findings = array_values( array_filter( $result['findings'], static fn ( array $finding ): bool => 'tree_unreadable' === $finding['code'] ) );
		self::assertSame( $id->hex(), $findings[0]['object'] );
		self::assertSame( 'Tree nodes must be sorted by name and unique.', $findings[0]['message'] );
	}

	public function test_finds_blobs_missing_from_the_index_and_unindexed_packs(): void {
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $index ) {
			$this->backend->delete( ObjectType::Index, $index );
		}

		$result = $this->check();

		self::assertContains( 'error:blob_missing', self::codes( $result ) );
		self::assertContains( 'warning:pack_unindexed', self::codes( $result ) );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ), $result['counts']['warning:pack_unindexed'] );
	}

	public function test_finds_an_unreadable_snapshot(): void {
		$snapshot = array_key_first( $this->backend->list( ObjectType::Snapshots ) );
		$this->backend->objects['snapshots'][ $snapshot ] = 'not an encrypted snapshot';

		$result = $this->check();

		self::assertSame( [ 'error:snapshot_unreadable' ], self::codes( $result ) );
		self::assertSame( 1, $result['counts']['error:snapshot_unreadable'] );
	}

	public function test_an_unindexed_pack_is_only_a_warning(): void {
		$this->backend->save( ObjectType::Data, Id::of( 'stray' )->hex(), 'stray' );

		$result = $this->check();

		self::assertSame( 0, $result['errors'] );
		self::assertSame( [ 'warning:pack_unindexed' ], self::codes( $result ) );
	}

	public function test_keeps_details_of_the_first_findings_and_counts_all(): void {
		for ( $i = 0; $i < CheckRun::MAX_FINDINGS + 10; $i++ ) {
			$this->backend->save( ObjectType::Data, Id::of( 'stray ' . $i )->hex(), 'stray ' . $i );
		}

		$result = $this->check( 0 );

		self::assertCount( CheckRun::MAX_FINDINGS, $result['findings'] );
		self::assertSame( CheckRun::MAX_FINDINGS + 10, $result['warnings'] );
	}

	public function test_errors_are_kept_when_earlier_warnings_filled_the_list(): void {
		for ( $i = 0; $i < CheckRun::MAX_FINDINGS + 10; $i++ ) {
			$this->backend->save( ObjectType::Data, Id::of( 'stray ' . $i )->hex(), 'stray ' . $i );
		}
		$snapshots = array_keys( $this->backend->list( ObjectType::Snapshots ) );
		sort( $snapshots, SORT_STRING );
		foreach ( $snapshots as $snapshot ) {
			$this->backend->objects['snapshots'][ $snapshot ] = 'not an encrypted snapshot';
		}

		$result = $this->check( 0 );

		self::assertSame( CheckRun::MAX_FINDINGS + 10, $result['warnings'], 'The packs are compared before the snapshots are read.' );
		self::assertSame( count( $snapshots ), $result['errors'] );
		self::assertCount( CheckRun::MAX_FINDINGS, $result['findings'] );
		$errors = array_values( array_filter( $result['findings'], static fn ( array $finding ): bool => 'error' === $finding['severity'] ) );
		self::assertSame( $snapshots, array_column( $errors, 'object' ), 'Every error kept its details, in the order found.' );
		self::assertSame( 'warning', $result['findings'][0]['severity'], 'The oldest warnings stay; the newest made room.' );
	}

	public function test_a_damaged_index_file_is_a_finding_and_the_check_goes_on(): void {
		$name                                     = array_key_first( $this->backend->list( ObjectType::Index ) );
		$this->backend->objects['index'][ $name ] = 'not an encrypted index file';

		$result = $this->check();

		self::assertContains( 'error:index_unreadable', self::codes( $result ) );
		self::assertSame( 1, $result['counts']['error:index_unreadable'] );
		self::assertGreaterThan( 0, $result['stats']['packs_read'] ?? 0, 'The packs listed in readable index files were still read.' );
	}

	public function test_a_damaged_index_file_that_the_cache_meets_is_a_finding_and_the_check_goes_on(): void {
		$name                                     = array_key_first( $this->backend->list( ObjectType::Index ) );
		$this->backend->objects['index'][ $name ] = 'not an encrypted index file';

		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 100, new IndexCache( $this->work_dir() ) );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		$result = $run->result();
		self::assertSame( 1, $result['counts']['error:index_unreadable'] );
		self::assertGreaterThan( 0, $result['stats']['packs_read'] ?? 0, 'The cache gave way to the readable index files.' );
	}

	/**
	 * A file that disappears from the server while the check runs, at the given load of it.
	 *
	 * @return iterable<string, array{ObjectType, int, list<string>}>
	 */
	public static function disappearing_files(): iterable {
		yield 'an index file, before the check reads it' => [ ObjectType::Index, 1, [ 'error:index_unreadable' ] ];
		yield 'a snapshot, before the check reads it' => [ ObjectType::Snapshots, 1, [ 'error:snapshot_unreadable' ] ];
		// Read and checked once, the file is left out of the index for the trees.
		yield 'an index file, before the index for the trees is loaded' => [ ObjectType::Index, 2, [] ];
	}

	/**
	 * @param list<string> $codes Findings that the file's loss leaves.
	 */
	#[DataProvider( 'disappearing_files' )]
	public function test_a_file_that_disappears_during_the_check_does_not_stop_it( ObjectType $type, int $load, array $codes ): void {
		$name    = (string) array_key_first( $this->backend->list( $type ) );
		$loads   = 0;
		$backend = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $kind, string $requested = '' ) use ( $type, $name, $load, &$loads ): void {
				if ( 'load' === $method && $type === $kind && $name === $requested && $load === ++$loads ) {
					throw new NotFound( 'The file is gone.' );
				}
			}
		);
		$run     = CheckRun::start( Repository::open( $backend, $this->repository->master_key(), new SystemRandom() ), $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertGreaterThanOrEqual( $load, $loads );
		foreach ( $codes as $code ) {
			self::assertContains( $code, self::codes( $run->result() ) );
		}
	}

	/**
	 * A server that fails while the check reads a file, at the given load of it.
	 *
	 * @return iterable<string, array{ObjectType, int, bool}>
	 */
	public static function failing_loads(): iterable {
		yield 'a snapshot' => [ ObjectType::Snapshots, 1, false ];
		yield 'an index file, for the trees' => [ ObjectType::Index, 2, false ];
		yield 'an index file, for the trees through the cache' => [ ObjectType::Index, 2, true ];
	}

	#[DataProvider( 'failing_loads' )]
	public function test_a_server_failure_while_a_file_is_read_stops_the_check_and_is_no_damage( ObjectType $type, int $load, bool $cached ): void {
		$name    = (string) array_key_first( $this->backend->list( $type ) );
		$loads   = 0;
		$backend = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $kind, string $requested = '' ) use ( $type, $name, $load, &$loads ): void {
				if ( 'load' === $method && $type === $kind && $name === $requested && $load === ++$loads ) {
					throw new BackendException( 'The server failed.' );
				}
			}
		);
		$run     = CheckRun::start( Repository::open( $backend, $this->repository->master_key(), new SystemRandom() ), $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0, $cached ? new IndexCache( $this->work_dir() ) : null );
		try {
			while ( ! $run->advance( INF ) ) {
				continue;
			}
			self::fail( 'A transport error stops the check.' );
		} catch ( BackendException $error ) {
			self::assertSame( 'The server failed.', $error->getMessage() );
			self::assertSame( [], $run->result()['findings'], 'A transport error is no damage.' );
		} finally {
			$run->abort();
		}
	}

	public function test_an_index_file_that_does_not_fit_the_memory_budget_is_no_damage(): void {
		$this->repository->limit_memory( new MemoryBudget( 1 ) );
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		try {
			while ( ! $run->advance( INF ) ) {
				continue;
			}
			self::fail( 'A check must refuse an index that does not fit.' );
		} catch ( IndexTooLarge ) {
			self::assertSame( 'index', $run->progress()['phase'] );
			self::assertSame( [], $run->result()['findings'], 'Insufficient memory is not repository damage.' );
		} finally {
			$run->abort();
		}
	}

	public function test_reads_one_snapshot_per_unit_of_work(): void {
		$this->back_up();
		$this->back_up();
		$snapshots = count( $this->backend->list( ObjectType::Snapshots ) );
		self::assertSame( 4, $snapshots );

		$run  = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		$read = 0;
		while ( ! $run->advance( 0.0 ) ) {
			$now = $run->result()['stats']['snapshots'] ?? 0;
			self::assertLessThanOrEqual( $read + 1, $now, 'At most one snapshot per unit of work.' );
			$read = $now;
		}
		self::assertSame( $snapshots, $run->result()['stats']['snapshots'] ?? 0 );
	}

	public function test_repeated_pack_entries_in_one_index_keep_their_combined_size_and_count(): void {
		$before = $this->check();
		foreach ( $this->repository->names( ObjectType::Index ) as $name ) {
			$document = json_decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ), true, flags: JSON_THROW_ON_ERROR );
			$split    = [];
			foreach ( $document['packs'] as $pack ) {
				foreach ( $pack['blobs'] as $blob ) {
					$split[] = [
						'id'    => $pack['id'],
						'blobs' => [ $blob ],
					];
				}
			}
			$this->repository->save_unpacked( ObjectType::Index, json_encode( [ 'packs' => $split ], JSON_THROW_ON_ERROR ) );
			$this->backend->delete( ObjectType::Index, $name );
		}
		$after = $this->check();
		self::assertSame( 0, $after['errors'] );
		self::assertSame( 0, $after['warnings'] );
		self::assertSame( $before['stats'], $after['stats'] );
	}

	public function test_a_late_invalid_pack_discards_the_entire_index_in_both_check_phases(): void {
		$packs = [];
		foreach ( $this->repository->names( ObjectType::Index ) as $name ) {
			$document = json_decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ), true, flags: JSON_THROW_ON_ERROR );
			array_push( $packs, ...$document['packs'] );
			$this->backend->delete( ObjectType::Index, $name );
		}
		$packs[] = [
			'id'    => Id::of( 'invalid tail' )->hex(),
			'blobs' => [ [ 'invalid' => true ] ],
		];
		$this->repository->save_unpacked( ObjectType::Index, json_encode( [ 'packs' => $packs ], JSON_THROW_ON_ERROR ) );
		$result = $this->check();
		self::assertSame( 1, $result['counts']['error:index_unreadable'] );
		self::assertSame( 0, $result['stats']['packs'], 'The earlier valid packs were not committed.' );
		self::assertSame( count( $this->repository->snapshots() ), $result['counts']['error:blob_missing'], 'The tree walk must not find roots in the earlier valid part either.' );
	}

	public function test_a_transport_error_retries_the_same_index_on_the_same_run(): void {
		$before     = $this->check();
		$failed     = false;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$failed ): void {
				if ( ! $failed && 'load' === $method && ObjectType::Index === $type ) {
					$failed = true;
					throw new BackendException( 'Temporary index transport failure.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 100 );
		try {
			$run->advance( INF );
			self::fail( 'The first index read must fail.' );
		} catch ( BackendException $error ) {
			self::assertSame( 'Temporary index transport failure.', $error->getMessage() );
		}
		self::assertTrue( $run->advance( INF ) );
		self::assertSame( $before['stats'], $run->result()['stats'] );
		self::assertSame( 0, $run->result()['errors'] );
		self::assertSame( 0, $run->result()['warnings'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_large_pack_listings_are_resumed_in_bounded_batches_and_duplicates_count_once(): void {
		$packs                       = $this->backend->list( ObjectType::Data );
		$this->backend->listing_file = static function ( ObjectType $type, string $path ) use ( $packs ): void {
			self::assertSame( ObjectType::Data, $type );
			$file = new \BackupsForRestic\Support\WorkFile( $path, true );
			$file->write( '[' );
			foreach ( $packs as $name => $size ) {
				// A later duplicate supplies the actual size.
				$file->write(
					json_encode(
						[
							'name' => $name,
							'size' => 0,
						],
						JSON_THROW_ON_ERROR
					) . ','
				);
				$file->write(
					json_encode(
						[
							'name' => $name,
							'size' => $size,
						],
						JSON_THROW_ON_ERROR
					) . ','
				);
			}
			for ( $i = 0; $i < 60000; ++$i ) {
				$entry = '{"name":"' . hash( 'sha256', 'check-unindexed:' . $i ) . '","size":1}';
				$file->write( ( $i > 0 ? ',' : '' ) . $entry . ',' . $entry );
			}
			$file->write( ']' );
			$file->close();
		};
		$work                        = $this->work_dir();
		$clock                       = new FrozenClock( time() );
		$run                         = CheckRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom(), 0 );
		$original                    = ini_get( 'memory_limit' );
		$baseline                    = memory_get_usage( true );
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Isolated regression: repository bytes are not part of the downloaded listing.
		self::assertNotFalse( ini_set( 'memory_limit', (string) ( $baseline + 12 * 1048576 ) ) );
		try {
			$steps = 0;
			while ( ! $run->advance( 0.0 ) ) {
				self::assertLessThan( 10000, ++$steps );
				$run = CheckRun::resume( $this->repository, $work, $this->identity, $clock );
			}
			self::assertGreaterThan( 1000, $steps );
			self::assertSame( 0, $run->result()['errors'] );
			self::assertSame( 60000, $run->result()['counts']['warning:pack_unindexed'] );
			self::assertSame( count( $packs ), $run->result()['stats']['packs'] );
			self::assertLessThan( 131072, filesize( $work . '/state.json' ) );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore the isolated process's original setting.
			ini_set( 'memory_limit', $original );
			$run->abort();
		}
	}

	/**
	 * Answers that are stored as the pack listing although they are not one.
	 *
	 * @return array<string, array{string}> The stored answer.
	 */
	public static function invalid_listings(): array {
		$names = '';
		for ( $i = 0; $i < 300; ++$i ) {
			$names .= '"' . hash( 'sha256', 'listed only once:' . $i ) . '",';
		}
		return [
			'an error page instead of a listing'    => [ '<html><body>502 Bad Gateway</body></html>' ],
			'a negative size after the first batch' => [ '[' . $names . '{"name":"' . hash( 'sha256', 'negative' ) . '","size":-1}]' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_listings' )]
	public function test_an_invalid_stored_pack_listing_is_downloaded_again_and_the_check_completes( string $invalid ): void {
		$entries = [];
		foreach ( $this->backend->list( ObjectType::Data ) as $name => $size ) {
			$entries[] = [
				'name' => (string) $name,
				'size' => $size,
			];
		}
		$downloads                   = 0;
		$this->backend->listing_file = static function ( ObjectType $type, string $path ) use ( &$downloads, $invalid, $entries ): void {
			self::assertSame( ObjectType::Data, $type );
			file_put_contents( $path, 1 === ++$downloads ? $invalid : json_encode( $entries, JSON_THROW_ON_ERROR ) );
		};
		$work                        = $this->work_dir();
		$run                         = CheckRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 0 );
		$failures                    = 0;
		$ticks                       = 0;
		do {
			try {
				$done = $run->advance( 0.0 );
			} catch ( BackendException ) {
				// The job treats this as a transport error and resumes from the checkpoint.
				self::assertSame( 1, ++$failures, 'Only the tick that parsed the invalid answer fails.' );
				$done = false;
			}
			if ( ! $done ) {
				$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
			}
			self::assertLessThan( 100000, ++$ticks );
		} while ( ! $done );

		self::assertSame( 1, $failures );
		self::assertSame( 2, $downloads, 'The next tick asks the server again instead of parsing the stored answer.' );
		self::assertSame( [], $run->result()['findings'], 'Nothing of the invalid answer reaches the comparison.' );
		self::assertSame( count( $entries ), $run->result()['stats']['packs'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The lock is released.' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_listings' )]
	public function test_a_pack_listing_that_stays_invalid_is_downloaded_a_bounded_number_of_times( string $invalid ): void {
		$downloads                   = 0;
		$this->backend->listing_file = static function ( ObjectType $type, string $path ) use ( &$downloads, $invalid ): void {
			self::assertSame( ObjectType::Data, $type );
			++$downloads;
			file_put_contents( $path, $invalid );
		};
		$work                        = $this->work_dir();
		$run                         = CheckRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 0 );
		// The job gives up after failures that follow each other; a call that ends normally,
		// as the one that only downloads does, starts its count again.
		$consecutive = 0;
		$ticks       = 0;
		while ( $consecutive < 5 ) {
			try {
				self::assertFalse( $run->advance( 0.0 ), 'A check without a valid listing never completes.' );
				$consecutive = 0;
			} catch ( BackendException ) {
				++$consecutive;
			}
			$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
			self::assertLessThan( 2000, ++$ticks, 'The failures must follow each other once the run stops asking again.' );
		}

		self::assertSame( 1 + CheckRun::MAX_LISTING_RETRIES, $downloads );
		self::assertSame( 'parse_packs', $run->progress()['phase'], 'The checkpoint stays at the batch that fails.' );
	}

	public function test_a_missing_pack_is_not_read_again_when_the_findings_list_is_full(): void {
		for ( $i = 0; $i <= CheckRun::MAX_FINDINGS; $i++ ) {
			$this->backend->objects['index'][ hash( 'sha256', 'damaged ' . $i ) ] = 'damaged ' . $i;
		}
		$this->backend->delete( ObjectType::Data, $this->any_pack() );

		$result = $this->check();

		self::assertSame( CheckRun::MAX_FINDINGS + 1, $result['counts']['error:index_unreadable'] );
		self::assertSame( 1, $result['counts']['error:pack_missing'], 'Counted once, not again when the sample is read.' );
	}

	public function test_a_transport_error_ends_the_tick_and_the_check_resumes(): void {
		$flaky = new class( $this->backend ) implements Backend {
			private bool $failed = false;

			public function __construct( private readonly Backend $inner ) {
			}

			public function create(): void {
				$this->inner->create();
			}

			public function size( ObjectType $type, string $name ): ?int {
				return $this->inner->size( $type, $name );
			}

			public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
				if ( ObjectType::Data === $type && ! $this->failed ) {
					$this->failed = true;
					throw new BackendException( 'Connection reset by peer.' );
				}
				return $this->inner->load( $type, $name, $offset, $length );
			}

			public function save( ObjectType $type, string $name, string $body ): void {
				$this->inner->save( $type, $name, $body );
			}

			public function delete( ObjectType $type, string $name ): void {
				$this->inner->delete( $type, $name );
			}

			public function list( ObjectType $type ): array {
				return $this->inner->list( $type );
			}

			/** Downloads the listing to a file. */
			public function list_to_file( ObjectType $type, string $file ): void {
				$this->inner->list_to_file( $type, $file );
			}
		};
		$work  = $this->work_dir();
		$run   = CheckRun::start( Repository::open( $flaky, $this->repository->master_key(), new SystemRandom() ), $work, $this->identity, new SystemClock(), new SystemRandom(), 100 );
		try {
			$run->advance( INF );
			self::fail( 'The transport error ends the tick.' );
		} catch ( BackendException ) {
			self::assertCount( 1, $this->backend->list( ObjectType::Locks ), 'The lock stays for the next tick.' );
		}

		$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		$result = $run->result();
		self::assertSame( 0, $result['errors'] );
		self::assertSame( [], $result['findings'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ), $result['stats']['packs_read'] ?? 0 );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_first_call_that_cannot_list_the_index_files_keeps_a_lock_the_run_knows(): void {
		$fail       = true;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$fail ): void {
				if ( $fail && 'list' === $method && ObjectType::Index === $type ) {
					throw new BackendException( 'The server answered 503.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$work       = $this->work_dir();

		$run = CheckRun::start( $repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 100 );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'Starting sends nothing to the server.' );
		try {
			$run->advance( 0.0 );
			self::fail( 'The index files cannot be listed.' );
		} catch ( BackendException ) {
			$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
			self::assertIsArray( $state );
			self::assertSame( [ $state['lock'] ], array_keys( $this->backend->list( ObjectType::Locks ) ), 'The checkpoint names the lock, so the next call or abort() finds it.' );
		}

		$fail = false;
		$run  = CheckRun::resume( $repository, $work, $this->identity, new SystemClock() );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 0, $run->result()['errors'] );
		self::assertGreaterThan( 0, $run->result()['stats']['index_files'] ?? 0, 'The check began with the listing it could not get before.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_reads_packs_in_small_pieces_with_the_same_result(): void {
		$whole   = $this->check();
		$longest = 0;
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $entries ) {
				foreach ( $entries as $entry ) {
					$longest = max( $longest, $entry->length );
				}
			}
		}
		$largest = 0;
		$backend = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type, string $name, int $offset = 0, ?int $length = null ) use ( &$largest ): void {
				if ( 'load' === $method && ObjectType::Data === $type ) {
					self::assertNotNull( $length, 'Packs are only read in ranges.' );
					$largest = max( $largest, $length );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 100, null, 4096 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( $whole['counts'], $run->result()['counts'] );
		self::assertSame( $whole['stats'], $run->result()['stats'] );
		self::assertLessThanOrEqual( max( 4096, $longest ), $largest );
		self::assertGreaterThan( 4096, $longest, 'The fixture has blobs larger than a piece.' );
	}

	public function test_a_pack_whose_reads_keep_ending_the_request_becomes_a_warning(): void {
		$work = $this->work_dir();
		$run  = CheckRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 100 );
		while ( 'read' !== $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
		self::assertIsArray( $state );
		self::assertIsArray( $state['sample'] );
		$pack = $state['sample'][0];
		self::assertIsString( $pack );
		// Requests that the host killed while they read this pack left only the marker behind.
		file_put_contents(
			$work . '/reading.json',
			json_encode(
				[
					'pack'     => $pack,
					'attempts' => CheckRun::READ_ATTEMPTS,
				]
			)
		);

		$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		$result = $run->result();
		self::assertSame( [ 'warning:pack_unverified' ], self::codes( $result ) );
		self::assertSame( $pack, $result['findings'][0]['object'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ) - 1, $result['stats']['packs_read'] ?? 0 );
		self::assertFileDoesNotExist( $work . '/reading.json' );
	}

	public function test_transport_errors_while_reading_a_pack_do_not_count_as_attempts(): void {
		$failures = CheckRun::READ_ATTEMPTS + 2;
		$backend  = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type, string $name, int $offset = 0, ?int $length = null ) use ( &$failures ): void {
				// The first request for a pack header: only the read phase asks for four bytes.
				if ( 'load' === $method && ObjectType::Data === $type && 4 === $length && $failures-- > 0 ) {
					throw new BackendException( 'Connection reset by peer.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$work       = $this->work_dir();
		$run        = CheckRun::start( $repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 100 );
		$done       = false;
		for ( $tick = 0; $tick < 20 && ! $done; ++$tick ) {
			try {
				$done = $run->advance( INF );
			} catch ( BackendException ) {
				$run = CheckRun::resume( $repository, $work, $this->identity, new SystemClock() );
			}
		}

		self::assertTrue( $done );
		self::assertSame( [], $run->result()['findings'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ), $run->result()['stats']['packs_read'] ?? 0 );
	}

	public function test_backups_may_run_while_a_check_holds_its_shared_lock(): void {
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		$run->advance( 0.0 );

		$other = ( new Locking( $this->repository, new Identity( 'laptop', 'admin' ), new SystemClock(), 4711 ) )->acquire( false );

		self::assertCount( 2, $this->backend->list( ObjectType::Locks ) );
		$run->abort();
		self::assertSame( [ $other->hex() ], array_keys( $this->backend->list( ObjectType::Locks ) ) );
	}

	public function test_a_call_that_fails_while_reading_packs_keeps_the_phases_before_it(): void {
		$work    = $this->work_dir();
		$run     = null;
		$backend = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$run ): void {
				if ( 'load' === $method && ObjectType::Data === $type && 'read' === $run?->progress()['phase'] ) {
					throw new BackendException( 'Connection reset by peer.' );
				}
			}
		);
		$run     = CheckRun::start( Repository::open( $backend, $this->repository->master_key(), new SystemRandom() ), $work, $this->identity, new SystemClock(), new SystemRandom(), 100 );
		try {
			// One call does every phase up to reading the packs, where the first request fails.
			$run->advance( INF );
			self::fail( 'The transport error ends the call.' );
		} catch ( BackendException ) {
			$trees = $run->progress()['trees'];
		}
		self::assertGreaterThan( 0, $trees );

		$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
		self::assertSame( 'read', $run->progress()['phase'], 'The checkpoint is from the phase that was reached.' );
		self::assertSame( $trees, $run->progress()['trees'], 'The walked trees are not walked again.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 0, $run->result()['errors'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ), $run->result()['stats']['packs_read'] ?? 0 );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_long_call_writes_checkpoints_while_it_works(): void {
		$work    = $this->work_dir();
		$clock   = new FrozenClock( time() );
		$loads   = 0;
		$backend = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( $clock, &$loads ): void {
				if ( 'load' === $method && ObjectType::Data === $type ) {
					$clock->advance( 2 ); // Every tree takes a while, as on a slow server.
					if ( 8 === ++$loads ) {
						throw new BackendException( 'Connection reset by peer.' );
					}
				}
			}
		);
		$run     = CheckRun::start( Repository::open( $backend, $this->repository->master_key(), new SystemRandom() ), $work, $this->identity, $clock, new SystemRandom(), 0 );
		try {
			$run->advance( INF );
			self::fail( 'The transport error ends the call.' );
		} catch ( BackendException ) {
			self::assertSame( 'trees', $run->progress()['phase'] );
		}

		$resumed = CheckRun::resume( $this->repository, $work, $this->identity, $clock );
		self::assertSame( 'trees', $resumed->progress()['phase'] );
		self::assertGreaterThan( 0, $resumed->progress()['trees'], 'Trees walked before the failure are in the checkpoint.' );
		$resumed->abort();
	}

	public function test_a_repository_this_php_cannot_read_is_not_reported_as_damaged(): void {
		if ( Compression::available() ) {
			self::markTestSkipped( 'The zstd extension is installed.' );
		}
		// A format 2 repository with a compressed index file, on a PHP without zstd.
		$config = $this->repository->config();
		$this->backend->objects[ ObjectType::Config->value ]['config'] = $this->repository->cipher()->seal( ( new Config( Config::VERSION_2, $config->id, $config->chunker_polynomial ) )->to_json() );
		$repository = Repository::open( $this->backend, $this->repository->master_key(), new SystemRandom() );
		$repository->save_unpacked( ObjectType::Index, "\x02" . 'compressed with zstd' );

		$run = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		try {
			while ( ! $run->advance( INF ) ) {
				continue;
			}
			self::fail( 'The check cannot read this repository.' );
		} catch ( UnsupportedRepository ) {
			self::assertSame( 0, $run->result()['errors'], 'Nothing was reported as damage.' );
			self::assertSame( [], $run->result()['findings'] );
		}
		$run->abort();
	}

	public function test_refreshes_its_lock_during_a_long_check(): void {
		$clock = new FrozenClock( time() );
		$run   = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, $clock, new SystemRandom(), 0 );
		$run->advance( 0.0 );
		$first = array_keys( $this->backend->list( ObjectType::Locks ) );
		self::assertCount( 1, $first, 'The first call took the lock.' );

		$clock->advance( Locking::REFRESH_AFTER_SECONDS );
		$run->advance( 0.0 );

		$second = array_keys( $this->backend->list( ObjectType::Locks ) );
		self::assertCount( 1, $second );
		self::assertNotSame( $first, $second, 'A fresh lock replaced the old one.' );
		$run->abort();
	}

	public function test_takes_a_new_lock_when_its_lock_was_removed(): void {
		$work = $this->work_dir();
		$run  = CheckRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), 0 );
		$run->advance( 0.0 );
		foreach ( array_keys( $this->backend->list( ObjectType::Locks ) ) as $lock ) {
			$this->backend->delete( ObjectType::Locks, $lock );
		}

		$run = CheckRun::resume( $this->repository, $work, $this->identity, new SystemClock() );
		$run->advance( 0.0 );

		self::assertCount( 1, $this->backend->list( ObjectType::Locks ) );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 0, $run->result()['errors'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_check_paused_for_too_long_waits_for_an_exclusive_lock(): void {
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = CheckRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom(), 0 );
		$run->advance( 0.0 );

		// The lock is stale by now, so another client's maintenance ignored it.
		$clock->advance( Lock::STALE_AFTER_SECONDS + 1 );
		$other     = new Locking( $this->repository, new Identity( 'laptop', 'admin' ), $clock, 4711 );
		$exclusive = $other->acquire( true );

		try {
			CheckRun::resume( $this->repository, $work, $this->identity, $clock )->advance( 0.0 );
			self::fail( 'The check must wait for the exclusive lock.' );
		} catch ( LockConflict ) {
			self::assertSame( [ $exclusive->hex() ], array_keys( $this->backend->list( ObjectType::Locks ) ) );
		}
		$other->release( $exclusive );
		CheckRun::resume( $this->repository, $work, $this->identity, $clock )->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_requests_that_end_in_a_later_pack_of_the_same_call_still_count_as_attempts(): void {
		$work            = $this->work_dir();
		$this->cleanup[] = $aside = TempDir::create( 'killed' );
		$run             = null;
		$first           = null;
		$victim          = null;
		$kills           = 0;
		$backend         = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type, string $name ) use ( &$run, &$first, &$victim, &$kills, $work, $aside ): void {
				if ( 'load' !== $method || ObjectType::Data !== $type || 'read' !== $run?->progress()['phase'] ) {
					return;
				}
				$first ??= $name;
				if ( $name === $first ) {
					return;
				}
				$victim ??= $name;
				if ( $name === $victim && $kills < CheckRun::READ_ATTEMPTS ) {
					// The host kills the request while it reads the second pack of this call. What is
					// on disk at this moment is what the next request finds.
					++$kills;
					copy( $work . '/state.json', $aside . '/state.json' );
					copy( $work . '/reading.json', $aside . '/reading.json' );
					throw new \RuntimeException( 'The request was killed.' );
				}
			}
		);
		$repository      = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run             = CheckRun::start( $repository, $work, $this->identity, new FrozenClock( time() ), new SystemRandom(), 100 );

		for ( $request = 0; $request < CheckRun::READ_ATTEMPTS; $request++ ) {
			try {
				$run->advance( INF );
				self::fail( 'The request must end while it reads the pack.' );
			} catch ( \RuntimeException $killed ) {
				self::assertSame( 'The request was killed.', $killed->getMessage() );
			}
			// A killed request runs no handler: put back what was on disk when it ended.
			copy( $aside . '/state.json', $work . '/state.json' );
			copy( $aside . '/reading.json', $work . '/reading.json' );
			$run = CheckRun::resume( $repository, $work, $this->identity, new FrozenClock( time() ) );
		}
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( CheckRun::READ_ATTEMPTS, $kills );
		self::assertNotNull( $victim );
		$result = $run->result();
		self::assertSame( 0, $result['errors'] );
		self::assertSame( [ 'warning:pack_unverified' ], self::codes( $result ) );
		self::assertSame( $victim, $result['findings'][0]['object'] );
		self::assertSame( count( $this->backend->list( ObjectType::Data ) ) - 1, $result['stats']['packs_read'] ?? 0, 'Every other pack was read.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	/**
	 * Runs a check whose repository gets a memory budget before each pack it reads, and counts
	 * the ranges it reads from packs.
	 *
	 * @param \Closure(): ?MemoryBudget $budget Gives the budget when the size of a pack is asked for in the read phase.
	 * @return array{array{errors: int, warnings: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, stats: array<string, int>}, int} Result and ranges read.
	 */
	private function check_with_budget( \Closure $budget ): array {
		$reading    = false;
		$ranges     = 0;
		$repository = null;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$reading, &$ranges, &$repository, $budget ): void {
				if ( ! $reading || ObjectType::Data !== $type ) {
					return;
				}
				if ( 'size' === $method ) {
					// Right before the pack is read, so that the budget sees the memory as it is then.
					$repository?->limit_memory( $budget() );
				} elseif ( 'load' === $method ) {
					++$ranges;
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 100 );
		while ( 'read' !== $run->progress()['phase'] ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		$reading = true;
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		return [ $run->result(), $ranges ];
	}

	/**
	 * What reading the largest blob of the repository takes by itself (MemoryBudget::range()).
	 */
	private function largest_blob(): int {
		$largest = 0;
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $entries ) {
				foreach ( $entries as $entry ) {
					$largest = max( $largest, MemoryBudget::range( $entry->length, 0, MemoryBudget::opening( $entry ) ) );
				}
			}
		}
		return $largest;
	}

	/**
	 * A budget that leaves the given bytes for reading packs: MemoryBudget::available() sees the
	 * memory reserved now, less its two MiB.
	 */
	private static function leaving( int $bytes ): MemoryBudget {
		gc_mem_caches();
		return new MemoryBudget( memory_get_usage( true ) + 2097152 + $bytes );
	}

	public function test_with_memory_enough_the_packs_are_read_in_the_pieces_they_were_read_in_before(): void {
		[ $unlimited, $before ] = $this->check_with_budget( static fn (): ?MemoryBudget => null );
		[ $result, $ranges ]    = $this->check_with_budget( static fn (): MemoryBudget => new MemoryBudget( 1073741824 ) );
		self::assertSame( $before, $ranges );
		self::assertSame( $unlimited['stats'], $result['stats'] );
		self::assertSame( [], $result['findings'] );
	}

	public function test_packs_are_read_in_pieces_that_the_memory_left_can_take_with_the_same_result(): void {
		// A pack of three blobs of 600 KiB: two of them take more than the largest blob of the
		// fixture, of at most 1 MiB, by itself. The pack stays below 2 MiB, as the others, so that
		// what the backend keeps of it does not change how PHP reserves memory for later tests.
		$store = new BlobStore( $this->repository, new IndexSet(), 8388608 );
		for ( $blob = 0; $blob < 3; ++$blob ) {
			$store->save( BlobType::Data, random_bytes( 614400 ) );
		}
		$store->flush();
		$store->write_index();
		[ $unlimited, $before ] = $this->check_with_budget( static fn (): ?MemoryBudget => null );
		$largest                = $this->largest_blob();
		[ $result, $ranges ]    = $this->check_with_budget( static fn (): MemoryBudget => self::leaving( $largest ) );
		self::assertGreaterThan( $before, $ranges, 'A piece holds no more than the largest blob takes by itself.' );
		self::assertSame( $unlimited['counts'], $result['counts'] );
		self::assertSame( $unlimited['stats'], $result['stats'] );
		self::assertSame( [], $result['findings'] );
	}

	public function test_a_pack_whose_largest_blob_does_not_fit_the_memory_left_is_a_warning_before_it_is_read(): void {
		// The blobs of the fixture, of at most 1 MiB, each take what a block of PHP's takes; a
		// blob of 3 MiB takes more.
		$large = $this->add_pack( [ 3 * 1048576 ] );
		try {
			$packs               = count( $this->backend->list( ObjectType::Data ) );
			$largest             = $this->largest_blob();
			[ $result, $ranges ] = $this->check_with_budget( static fn (): MemoryBudget => self::leaving( $largest - 1 ) );
			$unverified          = array_values( array_filter( $result['findings'], static fn ( array $finding ): bool => 'pack_memory' === $finding['code'] ) );
			self::assertSame( 0, $result['errors'], 'Memory that is short is no damage.' );
			self::assertSame( [ $large ], array_column( $unverified, 'object' ), 'The pack of the largest blob is not verified.' );
			self::assertSame( 1, $result['warnings'] );
			self::assertSame( 'warning', $unverified[0]['severity'] );
			self::assertSame( 'A blob of the pack does not fit into the memory that PHP has left; run restic check --read-data from another computer to verify it.', $unverified[0]['message'] );
			self::assertSame( $packs - 1, $result['stats']['packs_read'], 'The other packs are read and verified.' );

			[ $none, $headers ] = $this->check_with_budget( static fn (): MemoryBudget => self::leaving( 0 ) );
			self::assertSame( $packs, $none['warnings'] );
			self::assertSame( 0, $none['stats']['packs_read'] ?? 0 );
			self::assertSame( 2 * $packs, $headers, 'Of each pack only the length of its header and the header are read.' );
		} finally {
			unset( $this->backend->objects['data'][ $large ] );
		}
	}

	/**
	 * Stores a pack whose blobs the index lists, and gives its name. The blobs are random bytes
	 * of the given sizes, or, with $text, words that zstd compresses, stored compressed. Blobs
	 * after the first $indexed ones are in the pack's header but not in the index.
	 *
	 * @param list<int> $sizes Blob sizes.
	 */
	private function add_pack( array $sizes, bool $text = false, ?int $indexed = null ): string {
		$cipher  = $this->repository->cipher();
		$body    = '';
		$header  = '';
		$entries = [];
		foreach ( $sizes as $number => $size ) {
			$content = $text ? substr( str_repeat( 'blob ' . $number . ' of a text that compresses well ', intdiv( $size, 20 ) + 1 ), 0, $size ) : random_bytes( $size );
			$id      = Id::of( $content );
			$sealed  = $cipher->seal( $text ? (string) zstd_compress( $content, 3 ) : $content );
			$header .= $text ? chr( 2 ) . pack( 'VV', strlen( $sealed ), $size ) . $id->bytes() : chr( 0 ) . pack( 'V', strlen( $sealed ) ) . $id->bytes();
			$entry   = new PackEntry( BlobType::Data, $id, strlen( $body ), strlen( $sealed ), $text ? $size : 0 );
			if ( null === $indexed || $number < $indexed ) {
				$entries[] = $entry;
			}
			$body .= $sealed;
		}
		$header                                  = $cipher->seal( $header );
		$body                                   .= $header . pack( 'V', strlen( $header ) );
		$name                                    = hash( 'sha256', $body );
		$this->backend->objects['data'][ $name ] = $body;
		$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $name => $entries ] ) );
		return $name;
	}

	/**
	 * Runs a whole check and looks at one pack: what was found about it, how many ranges were
	 * read from it, and the most memory in use while it was read, above the memory in use when
	 * its size was asked for. The budget, if any, is given right then, as check_with_budget()
	 * gives it.
	 *
	 * @param (\Closure(): MemoryBudget)|null $budget Budget for the pack.
	 * @return array{list<string>, int, int} Severity:code of its findings, ranges read, peak.
	 */
	private function read_one_pack( string $name, ?\Closure $budget = null, int $piece = CheckRun::READ_PIECE ): array {
		$before     = null;
		$peak       = null;
		$ranges     = 0;
		$within     = false;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type, string $requested = '' ) use ( $name, $budget, &$repository, &$before, &$peak, &$ranges, &$within ): void {
				if ( ObjectType::Data !== $type ) {
					return;
				}
				if ( 'load' === $method && $within ) {
					++$ranges;
				}
				if ( 'size' !== $method ) {
					return;
				}
				if ( $within ) {
					$peak   = memory_get_peak_usage() - (int) $before;
					$within = false;
				}
				if ( $name === $requested ) {
					$repository?->limit_memory( null === $budget ? null : $budget() );
					memory_reset_peak_usage();
					$before = memory_get_usage();
					$within = true;
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 100, null, $piece );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		if ( $within ) {
			$peak = memory_get_peak_usage() - (int) $before;
		}
		self::assertNotNull( $peak, 'The pack was read.' );
		$findings = array_values(
			array_map(
				static fn ( array $finding ): string => $finding['severity'] . ':' . $finding['code'],
				array_filter( $run->result()['findings'], static fn ( array $finding ): bool => $name === $finding['object'] )
			)
		);
		return [ $findings, $ranges, $peak ];
	}

	/**
	 * A pack whose header cannot be read is read in pieces without a blob to open, and so is the
	 * rest of every pack after its blobs. Each piece is let go before the next one arrives: the
	 * check held the last one beside the next response before.
	 */
	public function test_a_piece_without_a_blob_to_open_is_let_go_before_the_next_one_arrives(): void {
		$piece                                   = 262144;
		$name                                    = $this->add_pack( array_fill( 0, 6, $piece ) );
		$this->backend->objects['data'][ $name ] = substr( $this->backend->objects['data'][ $name ], 0, -4 ) . "\xff\xff\xff\xff";
		try {
			// The pack's name is no longer the hash of its content, and its header length is wrong.
			[ $findings, , $peak ] = $this->read_one_pack( $name, null, $piece );
			self::assertSame( [ 'error:pack_hash' ], $findings );
			// One piece of 256 KiB, read by the backend as a copy of the stored pack, and little else.
			self::assertLessThan( 1.5 * $piece, $peak );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	public function test_a_pack_whose_header_cannot_be_read_needs_room_for_its_response_three_times(): void {
		$name                                    = $this->add_pack( array_fill( 0, 6, 200000 ) );
		$damaged                                 = substr( $this->backend->objects['data'][ $name ], 0, -4 ) . "\xff\xff\xff\xff";
		$this->backend->objects['data'][ $name ] = $damaged;
		try {
			// Without a header it is read in pieces of 8 MiB after its blobs, of which it has none.
			$whole        = MemoryBudget::range( strlen( $damaged ), 0, 0 );
			[ $findings ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $whole ) );
			self::assertSame( [ 'error:pack_hash' ], $findings );
			[ $findings, $ranges ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $whole - 1 ) );
			self::assertSame( [ 'warning:pack_memory' ], $findings );
			self::assertSame( 1, $ranges, 'Only the length of the header was read.' );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	public function test_the_last_blob_of_a_piece_to_be_opened_is_opened_without_the_piece(): void {
		// A small blob, a large one, and a small one that the index does not list here.
		$name = $this->add_pack( [ 65536, 1048576, 65536 ], false, 2 );
		try {
			[ $findings, , $peak ] = $this->read_one_pack( $name );
			self::assertContains( 'error:pack_header', $findings );
			// The large blob takes three times its size by itself, and the piece of 1.13 MiB on top
			// if it were opened beside it.
			self::assertLessThan( 3.5 * 1048576, $peak );
			self::assertGreaterThan( 3 * 1048576, $peak );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	public function test_a_piece_holds_whole_blobs_up_to_its_size_and_up_to_what_the_memory_left_takes(): void {
		$name   = $this->add_pack( array_fill( 0, 4, 1048576 ) );
		$stored = 1048576 + 32;
		try {
			// Two header reads, the blobs in pieces, and the header as the rest of the pack.
			[ , $ranges ] = $this->read_one_pack( $name, null, 2 * $stored );
			self::assertSame( 2 + 2 + 1, $ranges, 'Two blobs fill a piece exactly.' );
			[ , $ranges ] = $this->read_one_pack( $name, null, 2 * $stored - 1 );
			self::assertSame( 2 + 4 + 1, $ranges );
			// Each blob of 1 MiB takes four blocks to be opened, beside the piece of two, of 33 pages
			// of 64 KiB, for the first.
			$opening = MemoryBudget::opening( new PackEntry( BlobType::Data, Id::of( 'blob' ), 0, $stored ) );
			$two     = MemoryBudget::range( 2 * $stored, $opening, $opening );
			self::assertSame( 33 * 65536 + 4 * MemoryBudget::BLOCK, $two );
			[ $findings, $ranges ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $two ) );
			self::assertSame( [], $findings );
			self::assertSame( 2 + 2 + 1, $ranges, 'Exactly what two blobs take is left.' );
			[ $findings, $ranges ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $two - 1 ) );
			self::assertSame( [], $findings );
			self::assertSame( 2 + 4 + 1, $ranges );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	public function test_a_compressed_blob_needs_room_for_twice_its_content_before_the_check_reads_it(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$name = $this->add_pack( [ 3 * 1048576, 3 * 1048576 ], true );
		try {
			$entries = PackReader::read_header( $this->repository->cipher(), substr( $this->backend->objects['data'][ $name ], -4 - unpack( 'V', substr( $this->backend->objects['data'][ $name ], -4 ) )[1], -4 ), strlen( $this->backend->objects['data'][ $name ] ) );
			$largest = max( array_map( static fn ( PackEntry $entry ): int => MemoryBudget::range( $entry->length, 0, MemoryBudget::opening( $entry ) ), $entries ) );
			// The frame of a few KiB in a block, the decoder's buffer and its output of 49 pages
			// of 64 KiB each, and a block for the small allocations; far more than the stored bytes.
			self::assertSame( 2 * MemoryBudget::footprint( 3 * 1048576 ) + 2 * MemoryBudget::BLOCK, $largest, 'Decoding takes twice the content.' );
			[ $findings ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $largest ) );
			self::assertSame( [], $findings );
			[ $findings, $ranges ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( $largest - 1 ) );
			self::assertSame( [ 'warning:pack_memory' ], $findings );
			self::assertSame( 2, $ranges, 'Only the header was read.' );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	public function test_a_blob_that_the_decoder_cannot_decode_in_the_memory_left_is_a_warning_and_no_damage(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$name = $this->wide_pack( BlobType::Data, random_bytes( 1000 ) );
		try {
			self::leave_64_mib();
			// The budget does not count the window of a frame; the decoder refuses it after the download.
			[ $findings, $ranges ] = $this->read_one_pack( $name, static fn (): MemoryBudget => self::leaving( 16 * 1048576 ) );
			self::assertSame( [ 'warning:pack_memory' ], $findings, 'A refusal for memory is no damage.' );
			self::assertSame( 3, $ranges, 'The length of the header, the header and the blob were read.' );
		} finally {
			unset( $this->backend->objects['data'][ $name ] );
		}
	}

	/**
	 * What the check cannot go on without.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function undecodable(): iterable {
		yield 'an index file' => [ 'index' ];
		yield 'a snapshot' => [ 'snapshot' ];
		yield 'a tree' => [ 'tree' ];
	}

	/**
	 * An index file, a snapshot or a tree that the decoder cannot decode in the memory that PHP's
	 * limit leaves ends the check, as an index that does not fit does: it is no damage, and a
	 * job reports the decoder's refusal as decompression_limit.
	 */
	#[DataProvider( 'undecodable' )]
	public function test_what_the_check_cannot_decode_in_the_memory_left_ends_it_and_is_no_damage( string $kind ): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$repository = $this->format_2();
		$snapshot   = static fn ( Id $tree ): string => ( new Snapshot( '2026-01-01T00:00:00Z', $tree, [ '/' ] ) )->to_json();
		// A tree that the fixture does not have, so that the check walks it.
		$tree = TreeCodec::encode( [ new Node( 'wide.txt', NodeType::File, 0644, '2026-01-01T00:00:00Z', size: 0, content: [] ) ] );
		$pack = '';
		if ( 'index' === $kind ) {
			$repository->save_unpacked( ObjectType::Index, "\x02" . self::wide_frame( IndexFile::encode( [] ) ) );
		} elseif ( 'snapshot' === $kind ) {
			$repository->save_unpacked( ObjectType::Snapshots, "\x02" . self::wide_frame( $snapshot( Id::of( $tree ) ) ) );
		} else {
			$pack = $this->wide_pack( BlobType::Tree, $tree );
			$repository->save_unpacked( ObjectType::Snapshots, $snapshot( Id::of( $tree ) ) );
		}
		self::leave_64_mib();
		$run = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );
		try {
			while ( ! $run->advance( INF ) ) {
				continue;
			}
			self::fail( 'The check cannot go on without it.' );
		} catch ( InsufficientDecodingMemory $refusal ) {
			self::assertSame( 'decompression_limit', JobError::from_throwable( $refusal )->code );
			self::assertSame( [], $run->result()['findings'], 'Nothing was reported as damage.' );
		} finally {
			unset( $this->backend->objects['data'][ $pack ] );
		}
		$run->abort();
	}

	/**
	 * @return iterable<string, array{bool}>
	 */
	public static function index_loads(): iterable {
		yield 'from the index files' => [ false ];
		yield 'through the cache' => [ true ];
	}

	/**
	 * The check loads the index once more for the trees, from the index files or through the
	 * cache. An index file that decodes when the check reads it, but not then, ends the check as
	 * well: left out, its blobs would be reported as missing.
	 */
	#[DataProvider( 'index_loads' )]
	public function test_an_index_file_that_cannot_be_decoded_when_the_index_is_loaded_ends_the_check( bool $cached ): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// The whole index of the fixture in one compressed index file.
		$packs = [];
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			$packs += IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( (string) $name ) ) );
			$this->backend->delete( ObjectType::Index, (string) $name );
		}
		$loads      = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$loads ): void {
				if ( 'load' === $method && ObjectType::Index === $type ) {
					// The check of the file reads it first; the index for the trees reads it next.
					2 === ++$loads ? self::leave_64_mib() : self::limit_php( '-1' );
				}
			}
		);
		$repository = $this->format_2( $backend );
		$repository->save_unpacked( ObjectType::Index, "\x02" . self::wide_frame( IndexFile::encode( $packs ) ) );
		$this->cleanup[] = $cache = TempDir::create( 'cache' );
		$run             = CheckRun::start( $repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0, $cached ? new IndexCache( $cache ) : null );
		try {
			while ( ! $run->advance( INF ) ) {
				continue;
			}
			self::fail( 'The check cannot go on without the index.' );
		} catch ( InsufficientDecodingMemory ) {
			self::assertSame( 2, $loads );
			self::assertSame( [], $run->result()['findings'], 'No blob was reported as missing.' );
		}
		$run->abort();
	}
}
