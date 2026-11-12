<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Format\Pack\PackWriter;
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

	protected function setUp(): void {
		$this->site      = TempDir::create( 'site' );
		$this->cleanup[] = $this->site;
		TempDir::fixture( $this->site, 1048576 );
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, self::PASSWORD, $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->back_up();
		file_put_contents( $this->site . '/index.php', "<?php\n// Changed.\n" );
		$this->back_up();
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
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
		sort( $snapshots );
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
}
