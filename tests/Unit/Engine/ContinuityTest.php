<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * What a run does after a time in which other clients did not honour its lock (ADR 0030): it
 * goes on if everything it relies on is still there, and starts over otherwise.
 */
final class ContinuityTest extends TestCase {

	private const int PACK_SIZE = 262144;

	private string $site;

	private InMemoryBackend $backend;

	private Repository $repository;

	private Identity $identity;

	private FrozenClock $clock;

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		$this->site      = TempDir::create( 'site' );
		$this->cleanup[] = $this->site;
		TempDir::fixture( $this->site, 1048576 );
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, 'continuity password', $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->clock      = new FrozenClock( time() );
		// A first snapshot, so that the repository has index files and something to keep.
		$this->finish( $this->start() );
		// What the next backup has to upload.
		for ( $i = 0; $i < 6; $i++ ) {
			file_put_contents( $this->site . '/new-' . $i . '.bin', random_bytes( 200000 ) );
		}
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

	private function plan(): BackupPlan {
		return new BackupPlan( [ $this->site ], [ 'backups-for-restic' ] );
	}

	private function start( ?string $work = null, ?Repository $repository = null ): BackupRun {
		return BackupRun::start( $repository ?? $this->repository, $this->plan(), $work ?? $this->work_dir(), $this->identity, $this->clock, null, self::PACK_SIZE );
	}

	private function resume( string $work, ?Repository $repository = null ): BackupRun {
		return BackupRun::resume( $repository ?? $this->repository, $this->plan(), $work, $this->identity, $this->clock, self::PACK_SIZE );
	}

	private function finish( BackupRun $run ): Id {
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		$id = $run->snapshot_id();
		self::assertNotNull( $id );
		return $id;
	}

	/**
	 * The checkpoint of a run.
	 *
	 * @return array<string, mixed>
	 */
	private static function state( string $work ): array {
		$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
		self::assertIsArray( $state );
		return $state;
	}

	/**
	 * Starts a backup and runs short calls until it has uploaded a pack that no index file
	 * lists yet. With $indexed, ten minutes pass first, so that the next call writes an index
	 * file for the packs so far.
	 *
	 * @return string Working directory of the unfinished run.
	 */
	private function unfinished_backup( bool $indexed = false ): string {
		$work  = $this->work_dir();
		$run   = $this->start( $work );
		$calls = 0;
		while ( [] === ( self::state( $work )['unindexed'] ?? [] ) ) {
			self::assertFalse( $run->advance( 0.0 ), 'The backup must not be finished yet.' );
			self::assertLessThan( 1000, ++$calls );
		}
		if ( $indexed ) {
			$before = count( $this->backend->list( ObjectType::Index ) );
			$this->clock->advance( 601 );
			self::assertFalse( $this->resume( $work )->advance( 0.0 ) );
			self::assertGreaterThan( $before, count( $this->backend->list( ObjectType::Index ) ), 'The run wrote an index file of its own.' );
		}
		return $work;
	}

	/**
	 * Lets enough time pass for the run to distrust its lock: other clients with a clock that
	 * runs ahead may call it stale by now. With $stale, every client does.
	 */
	private function gap( bool $stale = false ): void {
		$this->clock->advance( ( $stale ? Lock::STALE_AFTER_SECONDS : Locking::TRUSTED_SECONDS ) + 1 );
	}

	/**
	 * Checks the whole repository, every pack read.
	 *
	 * @return array{errors: int, warnings: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, stats: array<string, int>, restarts: int}
	 */
	private function check(): array {
		$run = CheckRun::start( $this->repository, $this->work_dir(), $this->identity, $this->clock, new SystemRandom(), 100 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		return $run->result();
	}

	/**
	 * Writes an index file again under a new name and deletes the old one, as a prune on
	 * another computer does with every index file it changes. The content stays the same.
	 */
	private function rewrite_index_file( string $name ): void {
		$this->repository->save_unpacked( ObjectType::Index, $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) );
		$this->backend->delete( ObjectType::Index, $name );
	}

	public function test_a_backup_goes_on_after_a_gap_in_which_nothing_it_relies_on_was_removed(): void {
		$work = $this->unfinished_backup();
		$lock = self::state( $work )['lock'];

		$this->gap( true );
		// Another computer backed up to the same repository meanwhile: more index files and packs.
		$this->cleanup[] = $other_site = TempDir::create( 'other-site' );
		file_put_contents( $other_site . '/notes.txt', random_bytes( 50000 ) );
		$other = BackupRun::start( $this->repository, new BackupPlan( [ $other_site ] ), $this->work_dir(), new Identity( 'laptop', 'admin' ), $this->clock, null, self::PACK_SIZE );
		$this->finish( $other );

		$run = $this->resume( $work );
		self::assertFalse( $run->advance( 0.0 ) );
		self::assertNotSame( $lock, self::state( $work )['lock'], 'The lock was taken again, not refreshed.' );
		$this->finish( $run );

		$result = $this->check();
		self::assertSame( 0, $result['errors'], (string) json_encode( $result['findings'] ) );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_backup_is_given_up_when_another_site_pruned_its_packs_during_a_gap(): void {
		$work  = $this->unfinished_backup( true );
		$state = self::state( $work );

		$this->gap( true );
		// Another site that shares the repository prunes. No snapshot uses what the paused
		// backup uploaded, so its indexed packs go, after the index file that listed them.
		$prune = PruneRun::start( $this->repository, $this->work_dir(), new Identity( 'other.example', 'www-data' ), $this->clock, new SystemRandom() );
		while ( ! $prune->advance( INF ) ) {
			continue;
		}
		self::assertGreaterThan( 0, $prune->result()['packs_deleted'] );

		try {
			$this->resume( $work )->advance( 0.0 );
			self::fail( 'A snapshot built on this run would refer to packs that are gone.' );
		} catch ( RepositoryChanged $changed ) {
			self::assertGreaterThan( 0, $changed->context()['index_files'] );
		}
		self::assertFileDoesNotExist( $work . '/state.json', 'The run is given up.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'Neither the stale lock nor the new one is left.' );
		self::assertIsInt( $state['pid'] );

		// A new run in the same directory uploads again what is missing.
		$this->finish( $this->start( $work ) );
		$result = $this->check();
		self::assertSame( 0, $result['errors'], (string) json_encode( $result['findings'] ) );
		self::assertSame( 2, $result['stats']['snapshots'] ?? 0 );
	}

	public function test_a_backup_is_given_up_when_one_of_its_unindexed_packs_is_gone(): void {
		$work      = $this->unfinished_backup();
		$unindexed = array_map( 'strval', array_keys( (array) self::state( $work )['unindexed'] ) );

		$this->gap();
		// restic prune deletes packs that no index lists before anything else.
		$this->backend->delete( ObjectType::Data, $unindexed[0] );

		try {
			$this->resume( $work )->advance( 0.0 );
			self::fail( 'The run relies on a pack that is gone.' );
		} catch ( RepositoryChanged $changed ) {
			self::assertSame(
				[
					'index_files' => 0,
					'packs'       => 1,
				],
				$changed->context()
			);
		}
		self::assertFileDoesNotExist( $work . '/state.json' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_lock_that_someone_removed_counts_like_a_gap(): void {
		$work  = $this->unfinished_backup();
		$state = self::state( $work );

		// No time has passed, but `restic unlock --remove-all` ran on another computer.
		$this->backend->delete( ObjectType::Locks, (string) $state['lock'] );
		$run = $this->resume( $work );
		self::assertFalse( $run->advance( 0.0 ), 'Nothing was removed besides the lock: the run goes on.' );

		$state = self::state( $work );
		$this->backend->delete( ObjectType::Locks, (string) $state['lock'] );
		$this->rewrite_index_file( (string) ( (array) $state['index_files'] )[0] );
		$this->expectException( RepositoryChanged::class );
		$this->resume( $work )->advance( 0.0 );
	}

	public function test_a_refresh_within_the_trusted_time_checks_nothing(): void {
		$work  = $this->unfinished_backup();
		$state = self::state( $work );
		// Deleted behind the run's back while its lock holds: no client does that, and the run
		// does not look for it. This only shows that a refresh does not start the comparison.
		$this->rewrite_index_file( (string) ( (array) $state['index_files'] )[0] );

		$this->clock->advance( Locking::REFRESH_AFTER_SECONDS );
		self::assertFalse( $this->resume( $work )->advance( 0.0 ) );
		self::assertNotSame( $state['lock'], self::state( $work )['lock'], 'The lock was refreshed.' );
	}

	public function test_every_call_deals_with_the_lock_before_it_reads_the_index(): void {
		$calls      = [];
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$calls ): void {
				$calls[] = $method . ' ' . ( $type->value ?? '' );
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$work       = $this->work_dir();
		$position   = static function ( array $calls, string $call ): int {
			$found = array_search( $call, $calls, true );
			self::assertIsInt( $found, $call . ' was never called.' );
			return $found;
		};

		$calls = [];
		$run   = $this->start( $work, $repository );
		self::assertSame( [], $calls, 'Starting sends nothing to the server.' );
		$run->advance( 0.0 );
		self::assertLessThan( $position( $calls, 'list index' ), $position( $calls, 'save locks' ), 'First call: the lock is written before the index is listed.' );

		$calls = [];
		$run   = $this->resume( $work, $repository );
		self::assertSame( [], $calls, 'Resuming sends nothing to the server.' );
		$run->advance( 0.0 );
		self::assertLessThan( $position( $calls, 'list index' ), $position( $calls, 'size locks' ), 'Later calls: the lock is looked at before the index is listed.' );

		$this->gap();
		$calls = [];
		$this->resume( $work, $repository )->advance( 0.0 );
		self::assertLessThan( $position( $calls, 'list index' ), $position( $calls, 'save locks' ), 'After a gap: the lock is taken again first.' );
	}

	public function test_one_long_call_refreshes_its_lock_between_packs(): void {
		$clock      = $this->clock;
		$written    = [];
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( $clock, &$written ): void {
				if ( 'save' === $method && ObjectType::Data === $type ) {
					$clock->advance( 200 ); // A slow upload: the large file alone takes longer than a lock lasts.
				}
				if ( 'save' === $method && ObjectType::Locks === $type ) {
					$written[] = $clock->now()[0];
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		// About twelve chunks, each a pack of its own at this pack size.
		file_put_contents( $this->site . '/large.bin', random_bytes( 12000000 ) );

		$run = $this->start( null, $repository );
		self::assertTrue( $run->advance( INF ), 'One call without a deadline does the whole backup.' );

		self::assertGreaterThan( 2, count( $written ), 'The lock was written again while the call ran.' );
		$ages = [];
		foreach ( array_slice( $written, 1 ) as $position => $time ) {
			$ages[] = $time - $written[ $position ];
		}
		self::assertLessThan( Locking::TRUSTED_SECONDS, max( $ages ), 'No lock grew old enough for other clients to ignore it, not even inside the large file.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$result = $this->check();
		self::assertSame( 0, $result['errors'], (string) json_encode( $result['findings'] ) );
	}

	public function test_asking_for_missing_files_lists_once_and_not_at_all_for_nothing(): void {
		$lists      = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method ) use ( &$lists ): void {
				$lists += 'list' === $method ? 1 : 0;
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$present    = array_map( 'strval', array_keys( $this->backend->list( ObjectType::Index ) ) );
		$gone       = str_repeat( 'ab', 32 );

		self::assertSame( [], $repository->missing( ObjectType::Index, [] ) );
		self::assertSame( 0, $lists );
		self::assertSame( [ $gone ], $repository->missing( ObjectType::Index, array_merge( $present, [ $gone ] ) ) );
		self::assertSame( 1, $lists );
		self::assertSame( [], $repository->missing( ObjectType::Index, $present ) );
	}

	public function test_a_check_goes_on_after_a_gap_without_changes(): void {
		$work = $this->work_dir();
		$run  = CheckRun::start( $this->repository, $work, $this->identity, $this->clock, new SystemRandom(), 100 );
		for ( $calls = 0; $calls < 4; $calls++ ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		$lock = self::state( $work )['lock'];

		$this->gap();
		$run = CheckRun::resume( $this->repository, $work, $this->identity, $this->clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertNotSame( $lock, '' );
		self::assertSame( 0, $run->result()['restarts'] );
		self::assertSame( [], $run->result()['findings'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_check_starts_over_when_an_index_file_it_listed_is_gone(): void {
		$work = $this->work_dir();
		$run  = CheckRun::start( $this->repository, $work, $this->identity, $this->clock, new SystemRandom(), 100 );
		self::assertFalse( $run->advance( 0.0 ) );
		$listed = (array) self::state( $work )['index_files'];

		$this->gap();
		$this->rewrite_index_file( (string) $listed[0] );
		$run = CheckRun::resume( $this->repository, $work, $this->identity, $this->clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 1, $run->result()['restarts'] );
		self::assertSame( [], $run->result()['findings'], 'The index file that went away is not reported as damage: the check read the new one.' );
		self::assertSame( count( $this->backend->list( ObjectType::Index ) ), $run->result()['stats']['index_files'] ?? 0 );
	}

	public function test_a_check_starts_over_when_a_snapshot_it_listed_is_gone(): void {
		$second = $this->finish( $this->start() );
		$work   = $this->work_dir();
		$run    = CheckRun::start( $this->repository, $work, $this->identity, $this->clock, new SystemRandom(), 0 );
		$calls  = 0;
		while ( [] === ( self::state( $work )['snapshot_names'] ?? [] ) ) {
			self::assertFalse( $run->advance( 0.0 ) );
			self::assertLessThan( 1000, ++$calls );
		}

		$this->gap();
		// A forget on another computer removed a snapshot.
		$this->backend->delete( ObjectType::Snapshots, $second->hex() );
		$run = CheckRun::resume( $this->repository, $work, $this->identity, $this->clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 1, $run->result()['restarts'] );
		self::assertSame( 0, $run->result()['errors'], 'A snapshot that was forgotten is not a snapshot that disappeared.' );
		self::assertSame( 1, $run->result()['stats']['snapshots'] ?? 0 );
	}

	public function test_a_check_gives_up_when_the_repository_keeps_changing(): void {
		$work = $this->work_dir();
		$run  = CheckRun::start( $this->repository, $work, $this->identity, $this->clock, new SystemRandom(), 0 );
		self::assertFalse( $run->advance( 0.0 ) );

		for ( $restart = 1; $restart <= CheckRun::MAX_RESTARTS; $restart++ ) {
			$this->gap();
			$this->rewrite_index_file( (string) ( (array) self::state( $work )['index_files'] )[0] );
			$run = CheckRun::resume( $this->repository, $work, $this->identity, $this->clock );
			self::assertFalse( $run->advance( 0.0 ) );
			self::assertSame( $restart, $run->result()['restarts'] );
		}

		$this->gap();
		$this->rewrite_index_file( (string) ( (array) self::state( $work )['index_files'] )[0] );
		$run = CheckRun::resume( $this->repository, $work, $this->identity, $this->clock );
		try {
			$run->advance( 0.0 );
			self::fail( 'The check must not start over a fourth time.' );
		} catch ( RepositoryChanged $changed ) {
			self::assertSame( [ 'restarts' => CheckRun::MAX_RESTARTS ], $changed->context() );
		}
		$run->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}
}
