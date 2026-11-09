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
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Support\SystemClock;
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

	public function test_backups_may_run_while_a_check_holds_its_shared_lock(): void {
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), 0 );

		$other = ( new Locking( $this->repository, new Identity( 'laptop', 'admin' ), new SystemClock(), 4711 ) )->acquire( false );

		self::assertCount( 2, $this->backend->list( ObjectType::Locks ) );
		$run->abort();
		self::assertSame( [ $other->hex() ], array_keys( $this->backend->list( ObjectType::Locks ) ) );
	}
}
