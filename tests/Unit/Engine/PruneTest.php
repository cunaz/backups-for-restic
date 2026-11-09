<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Prune without repacking deletes exactly the packs that no snapshot uses (ADR 0022).
 */
final class PruneTest extends TestCase {

	private const string PASSWORD = 'prune password';

	private const int PACK_SIZE = 1048576;

	private string $site;

	private InMemoryBackend $backend;

	private Repository $repository;

	private Identity $identity;

	/** JSON of the forgotten middle snapshot. */
	private string $forgotten = '';

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		$this->site      = TempDir::create( 'site' );
		$this->cleanup[] = $this->site;
		TempDir::fixture( $this->site, 1048576 );
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, self::PASSWORD, $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
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

	private function back_up(): Id {
		$run = BackupRun::start( $this->repository, new BackupPlan( [ $this->site ], [ 'backups-for-restic' ] ), $this->work_dir(), $this->identity, new SystemClock(), null, self::PACK_SIZE );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		$id = $run->snapshot_id();
		self::assertNotNull( $id );
		return $id;
	}

	/**
	 * Three snapshots; the middle one alone holds a large unique file, and it is forgotten.
	 *
	 * @return list<Id> Data blobs of the unique file.
	 */
	private function history_with_a_forgotten_snapshot(): array {
		$this->back_up();
		file_put_contents( $this->site . '/unique.bin', Vectors::hash_stream( 'only in the forgotten snapshot', 2621440 ) );
		$middle = $this->back_up();
		unlink( $this->site . '/unique.bin' );
		$this->back_up();

		$unique          = $this->content_of( $middle, 'unique.bin' );
		$this->forgotten = $this->repository->load_unpacked( ObjectType::Snapshots, $middle );
		Forget::delete( $this->repository, [ $middle->hex() ] );
		return $unique;
	}

	/**
	 * Data blobs of a file in the site directory of a snapshot.
	 *
	 * @param Id     $snapshot Snapshot.
	 * @param string $name     File name.
	 * @return list<Id> Blobs.
	 */
	private function content_of( Id $snapshot, string $name ): array {
		$index = $this->repository->load_index();
		$tree  = Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, $snapshot ) )->tree;
		foreach ( array_filter( explode( '/', $this->site ) ) as $part ) {
			foreach ( $this->repository->load_tree( $index, $tree ) as $node ) {
				if ( $node->name === $part && null !== $node->subtree ) {
					$tree = $node->subtree;
				}
			}
		}
		foreach ( $this->repository->load_tree( $index, $tree ) as $node ) {
			if ( $node->name === $name ) {
				return $node->content ?? [];
			}
		}
		self::fail( "No file $name in the snapshot." );
	}

	/**
	 * Packs that hold a blob some snapshot uses, computed independently of PruneRun.
	 *
	 * @return array<string, true> Hex pack IDs.
	 */
	private function used_packs(): array {
		$index = $this->repository->load_index();
		$used  = [];
		$trees = [];
		foreach ( $this->repository->snapshots() as $snapshot ) {
			$trees[] = $snapshot->tree;
		}
		$seen = [];
		while ( [] !== $trees ) {
			$tree = array_pop( $trees );
			if ( isset( $seen[ $tree->hex() ] ) ) {
				continue;
			}
			$seen[ $tree->hex() ] = true;
			$used[ $index->find( $tree, BlobType::Tree )?->pack->hex() ?? '' ] = true;
			foreach ( $this->repository->load_tree( $index, $tree ) as $node ) {
				if ( null !== $node->subtree ) {
					$trees[] = $node->subtree;
				}
				foreach ( $node->content ?? [] as $blob ) {
					$used[ $index->find( $blob, BlobType::Data )?->pack->hex() ?? '' ] = true;
				}
			}
		}
		unset( $used[''] );
		return $used;
	}

	/**
	 * Loads every blob of every snapshot, as restic check --read-data would.
	 *
	 * @return int Number of data blobs read.
	 */
	private function read_everything(): int {
		$index = $this->repository->load_index();
		$count = 0;
		foreach ( $this->repository->snapshots() as $snapshot ) {
			$trees = [ $snapshot->tree ];
			while ( [] !== $trees ) {
				foreach ( $this->repository->load_tree( $index, array_pop( $trees ) ) as $node ) {
					if ( null !== $node->subtree ) {
						$trees[] = $node->subtree;
					}
					foreach ( $node->content ?? [] as $blob ) {
						$this->repository->load_blob( $index, BlobType::Data, $blob );
						++$count;
					}
				}
			}
		}
		return $count;
	}

	private function prune( ?FrozenClock $clock = null, bool $interrupt = false, ?string $work = null ): PruneRun {
		$work  ??= $this->work_dir();
		$clock ??= new FrozenClock( time() );
		$run     = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		$ticks   = 0;
		while ( ! $run->advance( $interrupt ? 0.0 : INF ) ) {
			if ( $interrupt ) {
				$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			}
			self::assertLessThan( 100000, ++$ticks );
		}
		return $run;
	}

	public function test_deletes_exactly_the_packs_no_snapshot_uses(): void {
		$unique  = $this->history_with_a_forgotten_snapshot();
		$indexed = $this->repository->load_index()->pack_ids();
		$used    = $this->used_packs();
		$before  = $this->read_everything();

		$result = $this->prune()->result();

		$expected = array_values( array_filter( array_map( static fn ( Id $pack ): string => $pack->hex(), $indexed ), static fn ( string $pack ): bool => isset( $used[ $pack ] ) ) );
		$actual   = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $expected );
		sort( $actual );
		self::assertSame( $expected, $actual, 'The used packs stay, every other pack is gone.' );
		self::assertSame( count( $indexed ) - count( $used ), $result['packs_deleted'] );
		self::assertGreaterThan( 0, $result['packs_deleted'] );
		self::assertGreaterThan( 2000000, $result['bytes_deleted'], 'The unique file is gone.' );
		self::assertSame( count( $used ), $result['packs_kept'] );

		$index = $this->repository->load_index();
		foreach ( $unique as $blob ) {
			self::assertFalse( $index->has( $blob, BlobType::Data ), 'No index file lists a deleted pack.' );
		}
		foreach ( $index->pack_ids() as $pack ) {
			self::assertNotNull( $this->backend->size( ObjectType::Data, $pack->hex() ), 'Every indexed pack exists.' );
		}
		self::assertSame( $before, $this->read_everything(), 'Every remaining snapshot reads completely.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The lock is released.' );
	}

	public function test_an_interrupted_prune_after_every_tick_ends_in_the_same_state(): void {
		$this->history_with_a_forgotten_snapshot();
		$used = $this->used_packs();

		$result = $this->prune( null, true )->result();

		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		$used   = array_keys( $used );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual );
		self::assertGreaterThan( 0, $result['packs_deleted'] );
		$this->read_everything();
	}

	public function test_a_second_prune_finds_nothing_to_delete(): void {
		$this->history_with_a_forgotten_snapshot();
		$this->prune();
		$objects = $this->backend->objects;

		$result = $this->prune()->result();

		self::assertSame( 0, $result['packs_deleted'] );
		self::assertSame( 0, $result['index_files_written'] );
		self::assertSame( array_keys( $objects ), array_keys( $this->backend->objects ), 'Nothing was written or deleted.' );
	}

	public function test_refuses_without_deleting_when_a_used_blob_is_missing_from_the_index(): void {
		$this->history_with_a_forgotten_snapshot();
		$this->back_up();
		$indexes = array_keys( $this->backend->list( ObjectType::Index ) );
		$this->backend->delete( ObjectType::Index, end( $indexes ) );
		$data = array_keys( $this->backend->list( ObjectType::Data ) );

		$work = $this->work_dir();
		try {
			$this->prune( null, false, $work );
			self::fail( 'Prune must stop.' );
		} catch ( FormatException $exception ) {
			self::assertSame( 'repository_damaged', $exception->context()['code'] ?? null );
			self::assertGreaterThan( 0, $exception->context()['missing'] ?? 0 );
		}
		self::assertSame( $data, array_keys( $this->backend->list( ObjectType::Data ) ), 'No pack was deleted.' );
		PruneRun::resume( $this->repository, $work, $this->identity, new SystemClock() )->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertDirectoryDoesNotExist( $work );
	}

	public function test_refuses_an_append_only_repository_before_writing_anything(): void {
		$this->history_with_a_forgotten_snapshot();
		$this->backend->append_only = true;
		$objects                    = array_keys( $this->backend->objects );

		$this->expectException( AppendOnlyRefused::class );
		try {
			$this->prune();
		} finally {
			self::assertSame( $objects, array_keys( $this->backend->objects ) );
		}
	}

	public function test_refuses_a_repository_without_snapshots(): void {
		$this->back_up();
		foreach ( array_keys( $this->backend->list( ObjectType::Snapshots ) ) as $snapshot ) {
			$this->backend->delete( ObjectType::Snapshots, $snapshot );
		}
		$data = $this->backend->list( ObjectType::Data );

		try {
			$this->prune();
			self::fail( 'Prune must refuse.' );
		} catch ( InvalidArgument $exception ) {
			self::assertSame( 'no_snapshots', $exception->context()['code'] ?? null );
		}
		self::assertSame( $data, $this->backend->list( ObjectType::Data ) );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_waits_for_another_clients_lock(): void {
		$this->history_with_a_forgotten_snapshot();
		( new Locking( $this->repository, new Identity( 'laptop', 'admin' ), new SystemClock(), 4711 ) )->acquire( false );

		$this->expectException( LockConflict::class );
		$this->prune();
	}

	public function test_starts_over_when_the_lock_was_not_refreshed_in_time(): void {
		$unique = $this->history_with_a_forgotten_snapshot();
		$clock  = new FrozenClock( time() );
		$work   = $this->work_dir();
		$run    = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( in_array( $run->progress()['phase'], [ 'mark', 'plan' ], true ) ) {
			$run->advance( 0.0 );
		}
		self::assertGreaterThan( 0, $run->progress()['unused_packs'], 'The first plan found the unique file unused.' );

		// A long pause: other clients may now treat the lock as stale and write a snapshot that
		// uses blobs the first marking found unused. Here: the forgotten middle snapshot again.
		$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
		$revived = $this->repository->save_unpacked( ObjectType::Snapshots, $this->forgotten );
		$used    = $this->used_packs();

		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 1, $run->result()['restarts'] );
		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		$used   = array_keys( $used );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual, 'Marking started again and kept the packs of the new snapshot.' );
		self::assertArrayHasKey( $revived->hex(), $this->backend->list( ObjectType::Snapshots ) );
		$index = $this->repository->load_index();
		foreach ( $unique as $blob ) {
			self::assertTrue( $index->has( $blob, BlobType::Data ), 'The unique file of the revived snapshot is still there.' );
		}
		$this->read_everything();
	}

	public function test_refreshes_the_lock_during_a_long_prune(): void {
		$this->history_with_a_forgotten_snapshot();
		$clock = new FrozenClock( time() );
		$work  = $this->work_dir();
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		$first = array_keys( $this->backend->list( ObjectType::Locks ) );

		$clock->advance( Locking::REFRESH_AFTER_SECONDS );
		$run->advance( 0.0 );

		$second = array_keys( $this->backend->list( ObjectType::Locks ) );
		self::assertCount( 1, $second );
		self::assertNotSame( $first, $second, 'A fresh lock replaced the old one.' );
		self::assertSame( 0, $run->result()['restarts'] );
	}
}
