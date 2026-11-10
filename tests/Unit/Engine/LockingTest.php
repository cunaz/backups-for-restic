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
use BackupsForRestic\Format\Snapshot;
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

	public function test_a_lock_is_trusted_for_less_time_than_other_clients_honour_it(): void {
		$locking   = $this->locking();
		$locked_at = $this->clock->now()[0];
		self::assertSame( 300, Lock::STALE_AFTER_SECONDS - Locking::TRUSTED_SECONDS, 'Five minutes are left for clocks that differ.' );

		$this->clock->advance( Locking::TRUSTED_SECONDS );
		self::assertFalse( $locking->lapsed( $locked_at ) );
		$this->clock->advance( 1 );
		self::assertTrue( $locking->lapsed( $locked_at ), 'A client whose clock runs ahead may call the lock stale from now on.' );
	}

	public function test_a_backup_writes_its_run_number_before_it_takes_a_lock(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$plan            = new BackupPlan( [ $site ], [ 'backups-for-restic' ] );

		BackupRun::start( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, null, 262144 );

		$state = json_decode( (string) file_get_contents( $work . '/run/state.json' ), true );
		self::assertIsArray( $state );
		self::assertIsInt( $state['pid'] );
		self::assertSame( '', $state['lock'] );
		self::assertSame( [], $this->locks(), 'Starting sends nothing to the server.' );

		// The first request wrote its lock and ended before it could record the lock's name.
		$orphan = $this->repository->save_unpacked( ObjectType::Locks, ( new Lock( '2026-01-01T00:00:00Z', false, 'example.com', 'www-data', $state['pid'] ) )->to_json() );

		$run = BackupRun::resume( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, 262144 );
		$run->advance( 0.0 );
		self::assertCount( 1, $this->locks() );
		self::assertNotContains( $orphan->hex(), $this->locks(), 'The run recognised the lock by its number and replaced it.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertNotNull( $run->snapshot_id() );
		self::assertSame( [], $this->locks() );
	}

	public function test_giving_up_a_backup_removes_a_lock_that_was_never_recorded(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$plan            = new BackupPlan( [ $site ], [ 'backups-for-restic' ] );
		BackupRun::start( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, null, 262144 );
		$state = json_decode( (string) file_get_contents( $work . '/run/state.json' ), true );
		self::assertIsArray( $state );
		$this->repository->save_unpacked( ObjectType::Locks, ( new Lock( '2026-01-01T00:00:00Z', false, 'example.com', 'www-data', $state['pid'] ) )->to_json() );
		$foreign = $this->locking( 4711, new Identity( 'laptop', 'admin' ) )->acquire( false );

		BackupRun::resume( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, 262144 )->abort();

		self::assertSame( [ $foreign->hex() ], $this->locks(), 'Only the lock of the other client is left.' );
		self::assertFileDoesNotExist( $work . '/run/state.json' );
	}

	public function test_a_backup_does_without_a_parent_snapshot_that_is_gone(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$plan            = new BackupPlan( [ $site ], [ 'backups-for-restic' ] );
		$missing_parent  = Id::from_hex( str_repeat( 'a', 64 ) );

		$run = BackupRun::start( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, $missing_parent, 262144 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		$id = $run->snapshot_id();
		self::assertNotNull( $id );
		self::assertNull( Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, $id ) )->parent_id, 'A snapshot names no parent that does not exist.' );
		self::assertSame( [ 'The previous snapshot is gone; every file is read again.' ], $run->warnings() );
		self::assertSame( [], $this->locks() );
	}

	public function test_a_backup_paused_for_too_long_takes_its_lock_again_and_notices_a_conflict(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$plan            = new BackupPlan( [ $site ], [ 'backups-for-restic' ] );
		$run             = BackupRun::start( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, null, 262144 );
		$run->advance( 0.0 );
		$first = $this->locks();

		// No request for more than 30 minutes: the lock is stale, and another client, which
		// ignores it, starts maintenance with an exclusive lock.
		$this->clock->advance( Lock::STALE_AFTER_SECONDS + 1 );
		$other     = $this->locking( 4711, new Identity( 'laptop', 'admin' ) );
		$exclusive = $other->acquire( true );

		try {
			BackupRun::resume( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, 262144 )->advance( 0.0 );
			self::fail( 'The backup must wait for the exclusive lock.' );
		} catch ( LockConflict ) {
			self::assertSame( [ $exclusive->hex() ], $this->locks(), 'Only the other client holds a lock.' );
		}

		$other->release( $exclusive );
		$run = BackupRun::resume( $this->repository, $plan, $work . '/run', $this->identity, $this->clock, 262144 );
		$run->advance( 0.0 );
		self::assertCount( 1, $this->locks() );
		self::assertNotSame( $first, $this->locks(), 'A new lock replaced the stale one.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertNotNull( $run->snapshot_id() );
		self::assertSame( [], $this->locks() );
	}
}
