<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Locks that a dying request leaves behind never outlive the run that wrote them.
 */
final class LockingTest extends TestCase {

	private const int RUN = 424242;

	private InMemoryBackend $backend;

	private Repository $repository;

	private Identity $identity;

	private FrozenClock $clock;

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->repository = Repository::initialize( $this->backend, 'locking password', $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->clock      = new FrozenClock( time() );
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function locking( int $run = self::RUN, ?Identity $identity = null ): Locking {
		return new Locking( $this->repository, $identity ?? $this->identity, $this->clock, $run );
	}

	/**
	 * @return list<string> Lock names.
	 */
	private function locks(): array {
		return array_keys( $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_leftover_of_the_same_run_never_blocks_it_and_is_removed(): void {
		$leftover = $this->locking()->acquire( true );
		$held     = $this->locking()->acquire( true );
		self::assertSame( [ $held->hex() ], $this->locks(), 'The leftover went away when the run locked again.' );
	}

	public function test_another_run_on_the_same_host_still_conflicts(): void {
		$this->locking( 7 )->acquire( true );
		$this->expectException( LockConflict::class );
		$this->locking()->acquire( true );
	}

	public function test_another_host_with_the_same_number_still_conflicts(): void {
		$this->locking( self::RUN, new Identity( 'other.example.com', 'www-data' ) )->acquire( false );
		$this->expectException( LockConflict::class );
		$this->locking()->acquire( true );
	}

	public function test_refresh_and_release_remove_leftovers_and_keep_other_runs_locks(): void {
		$other    = $this->locking( 7 )->acquire( false );
		$held     = $this->locking()->acquire( false );
		$leftover = $this->locking()->acquire( false );
		$fresh    = $this->locking()->refresh( $held, false );
		self::assertEqualsCanonicalizing( [ $other->hex(), $fresh->hex() ], $this->locks() );

		$again = $this->locking()->acquire( false );
		$this->locking()->release( $fresh );
		self::assertSame( [ $other->hex() ], $this->locks(), 'Release removed the held lock and the leftover.' );
		self::assertNotSame( $again->hex(), $leftover->hex() );
	}

	public function test_without_a_run_number_nothing_counts_as_a_leftover(): void {
		$this->locking( 0 )->acquire( false );
		$this->expectException( LockConflict::class );
		$this->locking( 0 )->acquire( true );
	}

	public function test_a_backup_whose_refresh_died_leaves_no_lock(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$plan            = new BackupPlan( [ $site ], [ 'backups-for-restic' ] );
		$run             = BackupRun::start( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, null, 262144 );
		$run->advance( 0.0 );
		$state = json_decode( (string) file_get_contents( $work . '/run/state.json' ), true );
		self::assertIsArray( $state );
		self::assertIsInt( $state['pid'] );
		$recorded = Id::from_hex( $state['lock'] );

		// A refresh wrote a new lock and deleted the recorded one, then the request died
		// before the checkpoint named the new lock.
		$this->clock->advance( 360 );
		$orphan = $this->repository->save_unpacked( ObjectType::Locks, ( new Lock( '2026-01-01T00:00:00Z', false, 'example.com', 'www-data', $state['pid'] ) )->to_json() );
		$this->backend->delete( ObjectType::Locks, $recorded->hex() );

		$run = BackupRun::resume( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, 262144 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertNotNull( $run->snapshot_id() );
		self::assertNotContains( $orphan->hex(), $this->locks() );
		self::assertSame( [], $this->locks(), 'No lock outlives the backup.' );
	}
}
