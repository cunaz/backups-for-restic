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
		$inner      = $this->backend;
		$unreliable = new class( $inner ) implements Backend {
			public function __construct( private readonly Backend $inner ) {
			}

			public function create(): void {
				$this->inner->create();
			}

			public function size( ObjectType $type, string $name ): ?int {
				return $this->inner->size( $type, $name );
			}

			public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
				return $this->inner->load( $type, $name, $offset, $length );
			}

			public function save( ObjectType $type, string $name, string $body ): void {
				$this->inner->save( $type, $name, $body );
			}

			public function delete( ObjectType $type, string $name ): void {
				throw new BackendException( 'The server answered 503.' );
			}

			public function list( ObjectType $type ): array {
				return $this->inner->list( $type );
			}
		};
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
		$this->history_with_a_forgotten_snapshot();
		$used       = array_keys( $this->used_packs() );
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

	public function test_starts_over_when_another_client_removed_its_lock(): void {
		$this->history_with_a_forgotten_snapshot();
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = PruneRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom() );
		while ( 'mark' === $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		self::assertSame( 'plan', $run->progress()['phase'] );
		foreach ( array_keys( $this->backend->list( ObjectType::Locks ) ) as $lock ) {
			$this->backend->delete( ObjectType::Locks, $lock );
		}

		$run = PruneRun::resume( $this->repository, $work, $this->identity, $clock );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( 1, $run->result()['restarts'] );
		self::assertGreaterThan( 0, $run->result()['packs_deleted'] );
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
