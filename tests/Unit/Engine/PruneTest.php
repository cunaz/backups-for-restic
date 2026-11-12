<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
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
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Prune without repacking deletes exactly the packs that no snapshot uses (ADR 0022), and the
 * packs that no index file lists.
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
	 * The forgotten history, then two files that are new in the same backup and so share one
	 * data pack; only the kept one is in the snapshot that is left. That leaves unused data in
	 * a used pack whatever the clock does: tree packs mix used and unused trees only when
	 * timestamps differ between backups.
	 */
	private function history_with_waste_in_a_used_pack(): void {
		$this->history_with_a_forgotten_snapshot();
		file_put_contents( $this->site . '/kept.bin', Vectors::hash_stream( 'kept', 200000 ) );
		file_put_contents( $this->site . '/gone.bin', Vectors::hash_stream( 'gone', 300000 ) );
		$this->back_up();
		unlink( $this->site . '/gone.bin' );
		$latest = $this->back_up();
		foreach ( array_keys( $this->repository->snapshots() ) as $hex ) {
			if ( $hex !== $latest->hex() ) {
				Forget::delete( $this->repository, [ (string) $hex ] );
			}
		}
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
	 * The unused bytes in packs that keep a used blob, computed independently of PruneRun from
	 * a walk of all snapshots and the index files: what only a full prune would reclaim.
	 *
	 * @return int Bytes.
	 */
	private function unused_bytes_in_used_packs(): int {
		$index = $this->repository->load_index();
		$used  = [];
		$trees = [];
		foreach ( $this->repository->snapshots() as $snapshot ) {
			$trees[] = $snapshot->tree;
		}
		while ( [] !== $trees ) {
			$tree = array_pop( $trees );
			if ( isset( $used[ 'tree:' . $tree->hex() ] ) ) {
				continue;
			}
			$used[ 'tree:' . $tree->hex() ] = true;
			foreach ( $this->repository->load_tree( $index, $tree ) as $node ) {
				if ( null !== $node->subtree ) {
					$trees[] = $node->subtree;
				}
				foreach ( $node->content ?? [] as $blob ) {
					$used[ 'data:' . $blob->hex() ] = true;
				}
			}
		}
		$packs = [];
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
				$packs[ $pack ] = $entries;
			}
		}
		$unused = 0;
		foreach ( $packs as $entries ) {
			$counted = [];
			$wasted  = 0;
			$keeps   = false;
			foreach ( $entries as $entry ) {
				$key = ( BlobType::Tree === $entry->type ? 'tree:' : 'data:' ) . $entry->id->hex();
				if ( isset( $used[ $key ] ) && ! isset( $counted[ $key ] ) ) {
					$counted[ $key ] = true;
					$keeps           = true;
				} else {
					$wasted += $entry->length;
				}
			}
			$unused += $keeps ? $wasted : 0;
		}
		return $unused;
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

	/**
	 * Lets a backup upload packs and gives it up: the packs stay on the server, and no index
	 * file lists them.
	 *
	 * @return list<string> Names of the packs it left.
	 */
	private function packs_of_an_interrupted_backup(): array {
		file_put_contents( $this->site . '/abandoned.bin', Vectors::hash_stream( 'never in a snapshot', 3000000 ) );
		$before = $this->repository->names( ObjectType::Data );
		$run    = BackupRun::start( $this->repository, new BackupPlan( [ $this->site ], [ 'backups-for-restic' ] ), $this->work_dir(), $this->identity, new SystemClock(), null, self::PACK_SIZE );
		$calls  = 0;
		do {
			self::assertFalse( $run->advance( 0.0 ), 'The backup must not finish.' );
			$left   = array_values( array_diff( $this->repository->names( ObjectType::Data ), $before ) );
			$enough = count( $left ) >= 2;
			self::assertLessThan( 1000, ++$calls );
		} while ( ! $enough );
		$run->abort();
		unlink( $this->site . '/abandoned.bin' );

		$indexed = array_map( static fn ( Id $pack ): string => $pack->hex(), $this->repository->load_index()->pack_ids() );
		self::assertSame( [], array_values( array_intersect( $left, $indexed ) ), 'No index file lists what the backup left.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		return $left;
	}

	/**
	 * Saves a snapshot again under a new name, as a backup on another computer that found
	 * nothing new would add one: the same trees, one more snapshot file.
	 */
	private function another_snapshot(): Id {
		$names = $this->repository->names( ObjectType::Snapshots );
		return $this->repository->save_unpacked( ObjectType::Snapshots, $this->repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $names[0] ) ) );
	}

	/**
	 * Writes an index file again under a new name and deletes the old one, as a prune on
	 * another computer does with every index file it changes. The content stays the same.
	 */
	private function rewrite_an_index_file(): void {
		$name = $this->repository->names( ObjectType::Index )[0];
		$this->repository->save_unpacked( ObjectType::Index, $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) );
		$this->backend->delete( ObjectType::Index, $name );
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

	public function test_estimates_the_space_only_a_full_prune_reclaims(): void {
		// Files that share packs with files that stay: their packs become partly used.
		foreach ( [ 'gone-1', 'gone-2', 'gone-3' ] as $name ) {
			file_put_contents( $this->site . '/' . $name . '.bin', Vectors::hash_stream( $name, 200000 ) );
		}
		$first = $this->back_up();
		foreach ( [ 'gone-1', 'gone-2', 'gone-3' ] as $name ) {
			unlink( $this->site . '/' . $name . '.bin' );
		}
		$this->back_up();
		Forget::delete( $this->repository, [ $first->hex() ] );
		$expected = $this->unused_bytes_in_used_packs();
		self::assertGreaterThan( 600000, $expected, 'The removed files stay in partly used packs.' );

		self::assertSame( $expected, $this->prune()->result()['reclaimable_bytes'] );
		self::assertSame( $expected, $this->prune()->result()['reclaimable_bytes'], 'A second prune deletes nothing and finds the same.' );
	}

	public function test_an_interrupted_prune_estimates_the_same_space(): void {
		$this->history_with_waste_in_a_used_pack();
		$expected = $this->unused_bytes_in_used_packs();
		self::assertGreaterThan( 0, $expected );
		self::assertSame( $expected, $this->prune( null, true )->result()['reclaimable_bytes'] );
	}

	public function test_a_checkpoint_without_the_filter_finishes_without_an_estimate(): void {
		$this->history_with_a_forgotten_snapshot();
		$work = $this->work_dir();
		$run  = PruneRun::start( $this->repository, $work, $this->identity, new FrozenClock( time() ), new SystemRandom() );
		$run->advance( 0.0 );
		$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
		self::assertIsArray( $state );
		unset( $state['filter'] );
		file_put_contents( $work . '/state.json', json_encode( $state ) );

		$run = PruneRun::resume( $this->repository, $work, $this->identity, new FrozenClock( time() ) );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( -1, $run->result()['reclaimable_bytes'] );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'], 'The prune itself is unaffected.' );
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
		self::assertSame( $objects, $this->backend->objects, 'Nothing was written or deleted.' );
	}

	public function test_refuses_without_deleting_when_a_used_blob_is_missing_from_the_index(): void {
		$this->history_with_a_forgotten_snapshot();
		// Remove every index file that lists the root tree of a remaining snapshot.
		$root = array_values( $this->repository->snapshots() )[0]->tree;
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $entries ) {
				foreach ( $entries as $entry ) {
					if ( $entry->id->equals( $root ) ) {
						$this->backend->delete( ObjectType::Index, $name );
						continue 3;
					}
				}
			}
		}
		self::assertFalse( $this->repository->load_index()->has( $root, BlobType::Tree ) );
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

	public function test_refuses_without_deleting_when_a_tree_is_missing_on_the_server(): void {
		$this->history_with_a_forgotten_snapshot();
		$root = array_values( $this->repository->snapshots() )[0]->tree;
		$pack = $this->repository->load_index()->find( $root, BlobType::Tree )?->pack->hex();
		self::assertNotNull( $pack );
		$this->backend->delete( ObjectType::Data, $pack );
		$data = array_keys( $this->backend->list( ObjectType::Data ) );

		try {
			$this->prune();
			self::fail( 'Prune must stop.' );
		} catch ( FormatException $exception ) {
			self::assertSame( 'repository_damaged', $exception->context()['code'] ?? null );
		}
		self::assertSame( $data, array_keys( $this->backend->list( ObjectType::Data ) ), 'No pack was deleted.' );
	}

	public function test_refuses_an_append_only_repository_before_writing_anything(): void {
		$this->history_with_a_forgotten_snapshot();
		$this->backend->append_only = true;
		$objects                    = $this->backend->objects;

		$this->expectException( AppendOnlyRefused::class );
		try {
			$this->prune();
		} finally {
			self::assertSame( $objects, $this->backend->objects, 'Not even a lock was written.' );
		}
	}

	public function test_refuses_when_it_cannot_tell_whether_deletions_are_allowed(): void {
		$this->history_with_a_forgotten_snapshot();
		$objects    = $this->backend->objects;
		$unreliable = new HookedBackend(
			$this->backend,
			static function ( string $method ): void {
				if ( 'delete' === $method ) {
					throw new BackendException( 'The server answered 503.' );
				}
			}
		);
		$repository = Repository::open( $unreliable, $this->repository->master_key(), new SystemRandom() );

		try {
			PruneRun::start( $repository, $this->work_dir(), $this->identity, new FrozenClock( time() ), new SystemRandom() );
			self::fail( 'Prune must not start.' );
		} catch ( BackendException $exception ) {
			self::assertStringContainsString( 'could not be determined', $exception->getMessage() );
		}
		self::assertSame( $objects, $this->backend->objects, 'Nothing was written, not even a lock.' );
	}

	public function test_a_long_call_refreshes_its_lock_while_it_works(): void {
		$this->history_with_a_forgotten_snapshot();
		$used       = array_keys( $this->used_packs() );
		$clock      = new FrozenClock( time() );
		$locks      = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( $clock, &$locks ): void {
				if ( 'load' === $method && ObjectType::Data === $type ) {
					$clock->advance( 200 ); // Every tree takes a while, as on a slow server.
				}
				if ( 'save' === $method && ObjectType::Locks === $type ) {
					++$locks;
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = PruneRun::start( $repository, $this->work_dir(), $this->identity, $clock, new SystemRandom() );
		self::assertSame( 1, $locks );

		self::assertTrue( $run->advance( INF ), 'One call without a deadline finishes the prune.' );

		self::assertGreaterThan( 1, $locks, 'The lock was refreshed during the call.' );
		self::assertSame( 0, $run->result()['restarts'], 'The lock never went stale, so marking did not start over.' );
		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_tree_that_failed_to_load_is_walked_when_the_same_run_tries_again(): void {
		$this->history_with_waste_in_a_used_pack();
		$used       = array_keys( $this->used_packs() );
		$wasted     = $this->unused_bytes_in_used_packs();
		$failures   = 2;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$failures ): void {
				if ( 'load' === $method && ObjectType::Data === $type && $failures-- > 0 ) {
					throw new BackendException( 'The connection was reset.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = PruneRun::start( $repository, $this->work_dir(), $this->identity, new FrozenClock( time() ), new SystemRandom() );

		$done = false;
		for ( $attempt = 0; $attempt < 5 && ! $done; ++$attempt ) {
			try {
				$done = $run->advance( INF );
			} catch ( BackendException ) {
				continue; // The caller tries again on the same run, without a checkpoint in between.
			}
		}

		self::assertTrue( $done );
		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual, 'Every used pack is still there.' );
		self::assertGreaterThan( 0, $wasted );
		self::assertSame( $wasted, $run->result()['reclaimable_bytes'], 'A tree tried again counts once in the estimate.' );
	}

	public function test_releases_its_lock_when_the_snapshots_cannot_be_read(): void {
		$this->history_with_a_forgotten_snapshot();
		$garbage = 'not an encrypted snapshot';
		$this->backend->save( ObjectType::Snapshots, hash( 'sha256', $garbage ), $garbage );

		try {
			$this->prune();
			self::fail( 'Prune must stop.' );
		} catch ( \BackupsForRestic\Exception\PluginException ) {
			self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The exclusive lock was released.' );
		}
	}

	public function test_a_checkpoint_with_an_unknown_phase_is_refused(): void {
		$this->history_with_a_forgotten_snapshot();
		$work = $this->work_dir();
		$run  = PruneRun::start( $this->repository, $work, $this->identity, new FrozenClock( time() ), new SystemRandom() );
		$run->advance( 0.0 );
		$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
		self::assertIsArray( $state );
		$state['phase'] = 'repack';
		file_put_contents( $work . '/state.json', json_encode( $state ) );

		$this->expectException( FormatException::class );
		PruneRun::resume( $this->repository, $work, $this->identity, new FrozenClock( time() ) );
	}

	public function test_an_out_of_date_index_makes_it_mark_again_instead_of_deleting_used_data(): void {
		$this->history_with_a_forgotten_snapshot();
		// Another client repacked a used pack after this run read its index: the index it got
		// names a pack that no index file lists any more.
		$victim = array_key_first( $this->used_packs() );
		self::assertIsString( $victim );
		$stale = new IndexSet();
		foreach ( array_keys( $this->backend->list( ObjectType::Index ) ) as $name ) {
			foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
				$stale->add_pack( Id::from_hex( $pack === $victim ? str_repeat( 'f', 64 ) : (string) $pack ), $entries );
			}
		}

		$run = PruneRun::start( $this->repository, $this->work_dir(), $this->identity, new FrozenClock( time() ), new SystemRandom(), $stale );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertArrayHasKey( $victim, $this->backend->list( ObjectType::Data ), 'The used pack is still there.' );
		self::assertSame( 1, $run->result()['restarts'] );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'], 'Unused packs were still deleted.' );
		$this->read_everything();
	}

	public function test_goes_on_when_another_client_removed_its_lock_and_changed_nothing_else(): void {
		$this->history_with_a_forgotten_snapshot();
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'mark' === $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		self::assertSame( 'plan', $run->progress()['phase'] );
		$trees = $run->progress()['trees'];
		foreach ( array_keys( $this->backend->list( ObjectType::Locks ) ) as $lock ) {
			$this->backend->delete( ObjectType::Locks, $lock );
		}

		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		self::assertFalse( $run->advance( 0.0 ) );
		self::assertSame( 'plan', $run->progress()['phase'], 'The run went on where it was.' );
		self::assertSame( $trees, $run->progress()['trees'], 'No tree was walked again.' );
		self::assertCount( 1, $this->backend->list( ObjectType::Locks ), 'It took its lock again.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 0, $run->result()['restarts'], 'The same snapshots and the same index files: nothing it found had changed.' );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->read_everything();
	}

	public function test_goes_on_after_a_pause_before_every_step_when_nobody_else_wrote(): void {
		$this->history_with_a_forgotten_snapshot();
		$left   = $this->packs_of_an_interrupted_backup();
		$used   = array_keys( $this->used_packs() );
		$work   = $this->work_dir();
		$clock  = new FrozenClock( time() );
		$run    = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		$phases = [];
		$ticks  = 0;
		do {
			$phases[ $run->progress()['phase'] ] = true;
			// Longer than the lock lasts, before every single step of every phase.
			$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
			$run  = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			$done = $run->advance( 0.0 );
			self::assertLessThan( 100000, ++$ticks );
		} while ( ! $done );

		self::assertSame( [ 'mark', 'plan', 'download', 'parse_listing', 'classify', 'rewrite', 'delete_indexes', 'delete_packs', 'delete_unindexed' ], array_keys( $phases ) );
		self::assertSame( 0, $run->result()['restarts'], 'Nobody else wrote, so marking never started over, although the lock was taken again every time.' );
		self::assertSame( count( $left ), $run->result()['unindexed_packs'] );
		$actual = $this->repository->names( ObjectType::Data );
		sort( $used );
		self::assertSame( $used, $actual, 'The same packs are left as after a prune without pauses.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->read_everything();
	}

	public function test_starts_over_when_an_index_file_was_written_anew_during_a_pause(): void {
		$this->history_with_a_forgotten_snapshot();
		$used  = array_keys( $this->used_packs() );
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'mark' === $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		self::assertSame( 'plan', $run->progress()['phase'] );

		$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
		$this->rewrite_an_index_file();
		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		self::assertFalse( $run->advance( 0.0 ) );
		self::assertSame( 'mark', $run->progress()['phase'], 'Another client may have repacked: what was marked no longer counts.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 1, $run->result()['restarts'] );
		sort( $used );
		self::assertSame( $used, $this->repository->names( ObjectType::Data ) );
		$this->read_everything();
	}

	public function test_a_request_that_ends_before_it_has_looked_does_not_spare_the_next_one_the_look(): void {
		$unique = $this->history_with_a_forgotten_snapshot();
		$work   = $this->work_dir();
		$clock  = new FrozenClock( time() );
		$run    = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( in_array( $run->progress()['phase'], [ 'mark', 'plan' ], true ) ) {
			$run->advance( 0.0 );
		}
		self::assertGreaterThan( 0, $run->progress()['unused_packs'], 'The plan found the unique file unused.' );

		// A long pause in which the forgotten snapshot comes back, as another client could write
		// one that uses what the plan takes for unused.
		$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
		$this->repository->save_unpacked( ObjectType::Snapshots, $this->forgotten );
		$used = array_keys( $this->used_packs() );

		// The first request after the pause takes the lock again and ends at the listing it
		// compares with.
		$ended      = false;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$ended ): void {
				if ( ! $ended && 'list' === $method && ObjectType::Snapshots === $type ) {
					$ended = true;
					throw new \RuntimeException( 'The request ended.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		try {
			PruneRun::resume( $repository, $work, $this->identity, $clock )->advance( INF );
			self::fail( 'The request must end while the run compares.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'The request ended.', $failure->getMessage() );
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The lock it had taken again is released.' );

		// A lock written a moment ago proves nothing about the time before it.
		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 1, $run->result()['restarts'], 'The next request looked, found the new snapshot and marked again.' );
		sort( $used );
		self::assertSame( $used, $this->repository->names( ObjectType::Data ), 'The packs of the new snapshot are kept.' );
		$index = $this->repository->load_index();
		foreach ( $unique as $blob ) {
			self::assertTrue( $index->has( $blob, BlobType::Data ) );
		}
		$this->read_everything();
	}

	public function test_gives_up_when_the_repository_changes_during_every_pause(): void {
		$this->history_with_a_forgotten_snapshot();
		$packs = $this->backend->list( ObjectType::Data );
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		self::assertFalse( $run->advance( 0.0 ) );

		for ( $restart = 1; $restart <= PruneRun::MAX_RESTARTS; $restart++ ) {
			$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
			$this->another_snapshot();
			$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			self::assertFalse( $run->advance( 0.0 ) );
			self::assertSame( $restart, $run->result()['restarts'] );
		}

		$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
		$this->another_snapshot();
		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		try {
			$run->advance( 0.0 );
			self::fail( 'Marking must not start over a fourth time.' );
		} catch ( RepositoryChanged $changed ) {
			self::assertSame( [ 'restarts' => PruneRun::MAX_RESTARTS ], $changed->context() );
		}
		self::assertSame( $packs, $this->backend->list( ObjectType::Data ), 'Nothing was deleted.' );
		PruneRun::resume( $this->repository, $work, $this->identity, $clock )->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertDirectoryDoesNotExist( $work );
	}

	public function test_deletes_the_packs_that_no_index_file_lists(): void {
		$this->back_up();
		$used = array_keys( $this->used_packs() );
		$left = $this->packs_of_an_interrupted_backup();
		$size = 0;
		foreach ( $left as $pack ) {
			$size += (int) $this->backend->size( ObjectType::Data, $pack );
		}
		$index_files = $this->repository->names( ObjectType::Index );

		$result = $this->prune()->result();

		sort( $used );
		self::assertSame( $used, $this->repository->names( ObjectType::Data ), 'Only the packs of the snapshot are left.' );
		self::assertSame( count( $left ), $result['unindexed_packs'] );
		self::assertSame( count( $left ), $result['packs_deleted'] );
		self::assertSame( $size, $result['bytes_deleted'] );
		self::assertSame( 0, $result['index_files_written'] );
		self::assertSame( $index_files, $this->repository->names( ObjectType::Index ), 'No index file listed them, so none had to change.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->read_everything();
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_large_unindexed_listing_is_planned_and_resumed_with_bounded_memory(): void {
		$this->back_up();
		$packs                       = $this->backend->list( ObjectType::Data );
		$this->backend->listing_file = static function ( ObjectType $type, string $path ) use ( $packs ): void {
			self::assertSame( ObjectType::Data, $type );
			$file = new \BackupsForRestic\Support\WorkFile( $path, true );
			$file->write( '[' );
			foreach ( $packs as $name => $size ) {
				$file->write(
					json_encode(
						[
							'name' => (string) $name,
							'size' => $size,
						],
						JSON_THROW_ON_ERROR
					) . ','
				);
			}
			for ( $i = 0; $i < 200000; ++$i ) {
				$file->write( ( $i > 0 ? ',' : '' ) . '{"name":"' . hash( 'sha256', 'unindexed:' . $i ) . '","size":1}' );
			}
			$file->write( ']' );
			$file->close();
		};
		$clock                       = new FrozenClock( time() );
		$work                        = $this->work_dir();
		$run                         = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		$original                    = ini_get( 'memory_limit' );
		$baseline                    = memory_get_usage( true );
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- A real low-memory regression in an isolated PHP process, outside WordPress.
		self::assertNotFalse( ini_set( 'memory_limit', (string) ( $baseline + 12 * 1048576 ) ) );
		try {
			$steps = 0;
			while ( 'rewrite' !== $run->progress()['phase'] ) {
				self::assertLessThan( 10000, ++$steps );
				self::assertFalse( $run->advance( 0.0 ) );
				$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			}
			self::assertGreaterThan( 1000, $steps, 'Parsing and classification yield repeatedly.' );
			self::assertSame( 200000, $run->progress()['unused_packs'] );
			self::assertSame( 200000, $run->result()['unindexed_packs'] );
			self::assertSame( 0, $run->result()['packs_deleted'] );
			self::assertLessThan( 131072, filesize( $work . '/state.json' ), 'Unindexed IDs never enter the checkpoint.' );
			while ( $run->result()['packs_deleted'] < 3 ) {
				$run->advance( 0.0 );
				$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			}
			self::assertSame( 3, $run->result()['packs_deleted'] );
			self::assertSame( $packs, $this->backend->list( ObjectType::Data ), 'Indexed live packs survive the on-disk deletion plan.' );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore the isolated test process's original limit.
			ini_set( 'memory_limit', $original );
			$run->abort();
		}
	}

	public function test_replayed_parse_and_classification_batches_deduplicate_before_deletion(): void {
		$this->back_up();
		$kept    = $this->backend->list( ObjectType::Data );
		$entries = [];
		foreach ( $kept as $name => $size ) {
			$entries[] = [
				'name' => (string) $name,
				'size' => $size,
			];
		}
		$bytes = 0;
		for ( $i = 0; $i < 500; ++$i ) {
			$content = 'left by an interrupted backup ' . $i;
			$name    = hash( 'sha256', $content );
			$this->backend->save( ObjectType::Data, $name, $content );
			$entries[] = [
				'name' => $name,
				'size' => 1,
			];
			$entries[] = [
				'name' => $name,
				'size' => strlen( $content ),
			];
			$bytes    += strlen( $content );
		}
		$this->backend->listing_file = static function ( ObjectType $type, string $path ) use ( $entries ): void {
			\BackupsForRestic\Storage\Files::write_atomic( $path, json_encode( $entries, JSON_THROW_ON_ERROR ) );
		};
		$clock                       = new FrozenClock( time() );
		$work                        = $this->work_dir();
		$run                         = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		foreach ( [ 'parse_listing', 'classify', 'delete_unindexed' ] as $phase ) {
			while ( $phase !== $run->progress()['phase'] ) {
				self::assertFalse( $run->advance( 0.0 ) );
			}
			$before = file_get_contents( $work . '/state.json' );
			$run->advance( 0.0 );
			// The working table (or remote DELETE) persisted, but the request died before
			// saving its checkpoint. The next request replays that exact same batch.
			file_put_contents( $work . '/state.json', $before );
			$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		}
		self::assertTrue( $run->advance( INF ) );
		self::assertSame( 500, $run->result()['unindexed_packs'] );
		self::assertSame( 500, $run->result()['packs_deleted'] );
		self::assertSame( $bytes, $run->result()['bytes_deleted'], 'The last duplicate entry determines the size.' );
		self::assertSame( $kept, $this->backend->list( ObjectType::Data ) );
	}

	public function test_unwritable_pack_table_stops_before_any_deletion(): void {
		$this->history_with_a_forgotten_snapshot();
		$before = $this->backend->objects;
		$clock  = new FrozenClock( time() );
		$work   = $this->work_dir();
		$run    = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'classify' !== $run->progress()['phase'] ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		chmod( $work . '/packs.table', 0400 );
		try {
			if ( is_writable( $work . '/packs.table' ) ) {
				self::markTestSkipped( 'This user bypasses file permissions.' );
			}
			try {
				@$run->advance( 0.0 );
				self::fail( 'A local I/O failure must stop classification.' );
			} catch ( \BackupsForRestic\Exception\StorageFailed $error ) {
				self::assertSame( 'environment', \BackupsForRestic\Jobs\JobError::from_throwable( $error )->code );
				self::assertSame( 0, $run->result()['packs_deleted'] );
				self::assertSame( 0, $run->result()['index_files_written'] );
				foreach ( [ 'data', 'index', 'snapshots' ] as $type ) {
					self::assertSame( $before[ $type ], $this->backend->objects[ $type ] );
				}
			}
		} finally {
			chmod( $work . '/packs.table', 0600 );
			$run->abort();
		}
	}

	public function test_malformed_final_listing_entry_prevents_all_repository_mutations(): void {
		$this->history_with_a_forgotten_snapshot();
		$this->backend->listing_file = static function ( ObjectType $type, string $path ): void {
			$file = new \BackupsForRestic\Support\WorkFile( $path, true );
			$file->write( '[' );
			for ( $i = 0; $i < 300; ++$i ) {
				$file->write( '"' . hash( 'sha256', 'abandoned:' . $i ) . '",' );
			}
			$file->write( '{"name":"incomplete' );
			$file->close();
		};
		$before                      = $this->backend->objects;
		$clock                       = new FrozenClock( time() );
		$work                        = $this->work_dir();
		$run                         = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		try {
			while ( ! $run->advance( 0.0 ) ) {
				$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
			}
			self::fail( 'The malformed tail must be examined before any deletion.' );
		} catch ( BackendException ) {
			self::assertSame( 'parse_listing', $run->progress()['phase'] );
			self::assertSame( 0, $run->result()['packs_deleted'] );
			self::assertSame( 0, $run->result()['index_files_written'] );
			foreach ( [ 'data', 'index', 'snapshots' ] as $type ) {
				self::assertSame( $before[ $type ], $this->backend->objects[ $type ] );
			}
		} finally {
			$run->abort();
		}
	}

	public function test_a_repository_without_waste_keeps_every_pack_and_reports_none_unindexed(): void {
		$this->back_up();
		$packs = $this->backend->list( ObjectType::Data );

		$result = $this->prune()->result();

		self::assertSame( 0, $result['unindexed_packs'] );
		self::assertSame( 0, $result['packs_deleted'] );
		self::assertSame( $packs, $this->backend->list( ObjectType::Data ) );
	}

	public function test_starting_over_while_deleting_leaves_no_pack_behind(): void {
		$this->history_with_a_forgotten_snapshot();
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'delete_packs' !== $run->progress()['phase'] ) {
			self::assertFalse( $run->advance( 0.0 ) );
		}
		self::assertFalse( $run->advance( 0.0 ) );
		$deleted = $run->progress()['packs_deleted'];
		self::assertGreaterThan( 0, $deleted );
		self::assertLessThan( $run->progress()['unused_packs'], $deleted, 'Packs of the plan are still there, and no index file lists them any more.' );

		// A long pause in which another computer adds a snapshot.
		$clock->advance( PruneRun::CONTINUITY_SECONDS + 1 );
		$this->another_snapshot();
		$used = array_keys( $this->used_packs() );
		$run  = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 1, $run->result()['restarts'] );
		self::assertGreaterThan( 0, $run->result()['unindexed_packs'], 'The second plan found the rest of the first as packs that no index file lists.' );
		sort( $used );
		self::assertSame( $used, $this->repository->names( ObjectType::Data ), 'Nothing unused is left behind.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->read_everything();
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

	public function test_refuses_while_another_client_holds_a_lock(): void {
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

	/**
	 * @return array<string, array{ObjectType}> The listing that fails when marking starts over.
	 */
	public static function listings_needed_to_start_over(): array {
		return [
			'the index files' => [ ObjectType::Index ],
			'the snapshots'   => [ ObjectType::Snapshots ],
		];
	}

	#[DataProvider( 'listings_needed_to_start_over' )]
	public function test_a_tree_that_was_missing_once_is_still_walked_when_marking_could_not_start_over( ObjectType $failing ): void {
		$this->history_with_a_forgotten_snapshot();
		// A newer snapshot with a file of its own: its packs are reached through its trees only.
		file_put_contents( $this->site . '/late.bin', Vectors::hash_stream( 'only in the newest snapshot', 2621440 ) );
		$newest = $this->back_up();
		$late   = $this->content_of( $newest, 'late.bin' );
		$root   = Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, $newest ) )->tree;
		$pack   = $this->repository->load_index()->find( $root, BlobType::Tree )?->pack->hex();
		self::assertNotNull( $pack );
		$used = array_keys( $this->used_packs() );

		// The pack of the newest root tree answers "not found" once; prune then wants to mark
		// again with a fresh index, and the listing it needs for that fails once as well.
		$stage      = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type, string $name ) use ( &$stage, $pack, $failing ): void {
				if ( 0 === $stage && 'load' === $method && ObjectType::Data === $type && $name === $pack ) {
					$stage = 1;
					throw new NotFound( 'The pack was not found.' );
				}
				if ( 1 === $stage && 'list' === $method && $failing === $type ) {
					$stage = 2;
					throw new BackendException( 'The connection was reset.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = PruneRun::start( $repository, $this->work_dir(), $this->identity, new FrozenClock( time() ), new SystemRandom() );

		$done = false;
		for ( $attempt = 0; $attempt < 5 && ! $done; ++$attempt ) {
			try {
				$done = $run->advance( INF );
			} catch ( BackendException ) {
				continue; // The caller tries again on the same run, without a checkpoint in between.
			}
		}

		self::assertTrue( $done );
		self::assertSame( 2, $stage, 'Both failures happened.' );
		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual, 'Every used pack is still there.' );
		$index = $this->repository->load_index();
		foreach ( $late as $blob ) {
			self::assertTrue( $index->has( $blob, BlobType::Data ), 'The file of the newest snapshot is still indexed.' );
		}
		self::assertGreaterThan( 0, $run->result()['packs_deleted'], 'Unused packs were still deleted.' );
		$this->read_everything();
	}

	/**
	 * A start whose request ended with the exclusive lock on the server: the snapshots could
	 * not be read, and the lock could not be deleted either.
	 *
	 * @return string Working directory of the run.
	 */
	private function start_that_dies_with_its_lock(): string {
		$this->history_with_a_forgotten_snapshot();
		$work       = $this->work_dir();
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ): void {
				if ( ( 'load' === $method && ObjectType::Snapshots === $type ) || ( 'delete' === $method && ObjectType::Locks === $type ) ) {
					throw new BackendException( 'The connection was reset.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		try {
			PruneRun::start( $repository, $work, $this->identity, new FrozenClock( time() ), new SystemRandom() );
			self::fail( 'The start must fail.' );
		} catch ( BackendException ) {
			self::assertCount( 1, $this->backend->list( ObjectType::Locks ), 'The exclusive lock is still on the server.' );
		}
		return $work;
	}

	public function test_a_start_that_ended_with_its_lock_is_continued_without_waiting_for_that_lock(): void {
		$work = $this->start_that_dies_with_its_lock();
		$used = array_keys( $this->used_packs() );

		$run = PruneRun::resume( $this->repository, $work, $this->identity, new FrozenClock( time() ) );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		self::assertSame( 0, $run->result()['restarts'], 'Beginning for the first time is not starting over.' );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'] );
		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'The lock of the failed start is gone too.' );
	}

	public function test_abandoning_a_start_that_ended_with_its_lock_removes_that_lock(): void {
		$work = $this->start_that_dies_with_its_lock();

		PruneRun::resume( $this->repository, $work, $this->identity, new FrozenClock( time() ) )->abort();

		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertDirectoryDoesNotExist( $work );
	}

	public function test_a_request_that_ends_while_deleting_is_continued_in_that_phase(): void {
		$this->history_with_a_forgotten_snapshot();
		$used  = array_keys( $this->used_packs() );
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		// The request is killed at its first pack deletion, after it wrote the new index files
		// and deleted the old ones. Nothing of the object in memory survives.
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ): void {
				if ( 'delete' === $method && ObjectType::Data === $type ) {
					throw new \RuntimeException( 'The request was killed.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = PruneRun::start( $repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'mark' === $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		self::assertSame( 'plan', $run->progress()['phase'] );
		try {
			$run->advance( INF );
			self::fail( 'The request must end at the first pack deletion.' );
		} catch ( \RuntimeException $killed ) {
			self::assertSame( 'The request was killed.', $killed->getMessage() );
		}

		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		self::assertSame( 'delete_packs', $run->progress()['phase'], 'The checkpoint is from the phase that was reached.' );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		$actual = array_keys( $this->backend->list( ObjectType::Data ) );
		sort( $actual );
		sort( $used );
		self::assertSame( $used, $actual, 'The packs planned before the request ended are deleted, and no other.' );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->read_everything();
	}
}
