<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\Files;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Engine\Restore\State;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Throttle;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestoreRunTest extends TestCase {

	private InMemoryBackend $backend;
	private Repository $repository;
	private BlobStore $store;
	private FrozenClock $clock;
	private string $scratch;
	private string $target;
	private string $work;

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, 'test password', new Identity( 'example.test' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->store      = new BlobStore( $this->repository, new IndexSet() );
		$this->clock      = new FrozenClock();
		$this->scratch    = TempDir::create( 'restore-run' );
		$this->target     = $this->scratch . '/target';
		$this->work       = $this->scratch . '/work';
		mkdir( $this->target, 0700 );
		mkdir( $this->work, 0700 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->scratch );
	}

	private function file( string $name, string $body, int $repeat = 1, int $mode = 0644 ): Node {
		$blob = $this->store->save( BlobType::Data, $body );
		return new Node( $name, NodeType::File, $mode, '2026-01-01T00:00:00Z', size: strlen( $body ) * $repeat, content: array_fill( 0, $repeat, $blob ) );
	}

	/** @param list<Node> $nodes */
	private function directory( string $name, array $nodes ): Node {
		usort( $nodes, static fn ( Node $a, Node $b ): int => strcmp( $a->name, $b->name ) );
		return new Node( $name, NodeType::Dir, 0755, '2026-01-01T00:00:00Z', subtree: $this->store->save( BlobType::Tree, TreeCodec::encode( $nodes ) ) );
	}

	/** @param list<Node> $nodes */
	private function snapshot( array $nodes ): Id {
		$root = $this->directory( 'root', $nodes );
		self::assertNotNull( $root->subtree );
		$this->store->flush();
		$this->store->write_index();
		return $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $root->subtree, [ '/' ] ) )->to_json() );
	}

	/** @param list<string> $paths */
	private function start( Id $snapshot, array $paths = [ '/' ], ?Repository $repository = null, ?\Closure $space = null, ?Throttle $throttle = null ): RestoreRun {
		return RestoreRun::start( $repository ?? $this->repository, $snapshot, $this->target, $this->work, $paths, new Identity( 'example.test' ), $this->clock, $throttle, $space );
	}

	private function finish( RestoreRun $run, bool $resume = true ): RestoreRun {
		for ( $steps = 0; $steps < 1000; ++$steps ) {
			if ( $run->advance( 0.0 ) ) {
				return $run;
			}
			if ( $resume ) {
				$run = RestoreRun::resume( $this->repository, $this->work, $this->clock );
			}
		}
		self::fail( 'The restore did not make bounded forward progress.' );
	}

	private function plan( RestoreRun $run ): void {
		for ( $steps = 0; $steps < 1000; ++$steps ) {
			if ( 'plan' !== $run->progress()['phase'] ) {
				break;
			}
			self::assertFalse( $run->advance( 0.0 ) );
			self::assertSame( [], TempDir::describe( $this->target ), 'Planning must not write payload entries.' );
		}
		self::assertSame( 'restore', $run->progress()['phase'] );
	}

	private function repeated_file(): Id {
		return $this->snapshot( [ $this->file( 'repeated.txt', 'abc', 3 ) ] );
	}

	public function test_start_and_resume_do_not_read_the_repository_before_taking_a_lock(): void {
		$snapshot = $this->repeated_file();
		$requests = $this->backend->requests;
		$run      = $this->start( $snapshot );
		$again    = RestoreRun::resume( $this->repository, $this->work, $this->clock );
		self::assertSame( $requests, $this->backend->requests );
		self::assertSame( $run->progress(), $again->progress() );
		self::assertSame( [], TempDir::describe( $this->target ) );
	}

	public function test_multiple_overlapping_selections_preserve_their_original_paths(): void {
		$snapshot = $this->snapshot(
			[
				$this->directory( 'alpha', [ $this->file( 'same.txt', 'first' ), $this->file( 'unselected', 'omit' ) ] ),
				$this->directory( 'beta', [ $this->file( 'same.txt', 'second' ) ] ),
				$this->file( 'unselected', 'omit' ),
			]
		);
		$run      = $this->start( $snapshot, [ '/alpha/same.txt', '/beta/same.txt', '/beta', '/alpha/same.txt' ] );
		$this->plan( $run );
		self::assertSame( 2, $run->progress()['total_files'] );
		self::assertSame( 11, $run->progress()['total_bytes'] );
		$run = $this->finish( $run );
		self::assertSame( 'first', file_get_contents( $this->target . '/alpha/same.txt' ) );
		self::assertSame( 'second', file_get_contents( $this->target . '/beta/same.txt' ) );
		self::assertFileDoesNotExist( $this->target . '/alpha/unselected' );
		self::assertFileDoesNotExist( $this->target . '/unselected' );
		self::assertSame( 11, $run->progress()['bytes'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertTrue( RestoreRun::resume( $this->repository, $this->work, $this->clock )->advance( 0.0 ) );
	}

	public function test_a_large_file_is_planned_in_bounded_ticks_without_publishing_partial_totals(): void {
		$name     = "large-\xe9";
		$snapshot = $this->snapshot( [ $this->file( $name, 'abc', 300 ) ] );
		$pauses   = 0;
		$throttle = new Throttle(
			0.0,
			0.0,
			static fn (): float => 1.0,
			static function () use ( &$pauses ): void {
				if ( ++$pauses > 129 ) {
					throw new \RuntimeException( 'A planning tick exceeded its bounded work.' );
				}
			}
		);
		$run      = $this->start( $snapshot, [ '/' . $name ], throttle: $throttle );
		foreach ( [ 128, 256 ] as $blobs ) {
			$pauses = 0;
			self::assertFalse( $run->advance( 0.0 ) );
			self::assertSame( 0, $run->progress()['total_files'] );
			self::assertSame( 0, $run->progress()['total_bytes'] );
			self::assertSame( [], TempDir::describe( $this->target ) );
			$state = Files::read( $this->work );
			self::assertSame(
				[
					'path'  => $name,
					'blob'  => $blobs,
					'bytes' => $blobs * 3,
				],
				$state->planning
			);
			self::assertSame( 0, $state->planned_entries );
			self::assertSame( [], $state->found );
			$run = RestoreRun::resume( $this->repository, $this->work, $this->clock, $throttle );
		}
		$pauses = 0;
		self::assertFalse( $run->advance( 0.0 ) );
		self::assertNull( Files::read( $this->work )->planning );
		self::assertSame( 1, $run->progress()['total_files'] );
		self::assertSame( 900, $run->progress()['total_bytes'] );
		$run = RestoreRun::resume( $this->repository, $this->work, $this->clock );
		self::assertTrue( $run->advance( INF ) );
		self::assertSame( str_repeat( 'abc', 300 ), file_get_contents( $this->target . '/' . $name ) );
		self::assertSame( 1, $run->progress()['files'] );
		self::assertSame( 900, $run->progress()['bytes'] );
	}

	public function test_interrupted_planning_replays_only_the_uncommitted_blob_batch(): void {
		$run = $this->start( $this->snapshot( [ $this->file( 'large', 'abc', 300 ) ] ) );
		$run->advance( 0.0 );
		$before = Files::read( $this->work )->planning;
		self::assertNotNull( $before );
		$pauses   = 0;
		$throttle = new Throttle(
			0.0,
			0.0,
			static fn (): float => 1.0,
			function () use ( &$pauses ): void {
				if ( 1 === ++$pauses ) {
					$this->clock->advance( Locking::REFRESH_AFTER_SECONDS );
				}
				if ( 20 === $pauses ) {
					throw new \RuntimeException( 'Interrupted a later planning batch.' );
				}
			}
		);
		$run      = RestoreRun::resume( $this->repository, $this->work, $this->clock, $throttle );
		try {
			$run->advance( 0.0 );
			self::fail( 'The interruption must reach the caller.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Interrupted a later planning batch.', $error->getMessage() );
		}
		self::assertSame( $before, Files::read( $this->work )->planning );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$run = RestoreRun::resume( $this->repository, $this->work, $this->clock );
		self::assertTrue( $run->advance( INF ) );
		self::assertSame( 1, $run->progress()['total_files'] );
		self::assertSame( 900, $run->progress()['total_bytes'] );
		self::assertSame( str_repeat( 'abc', 300 ), file_get_contents( $this->target . '/large' ) );
	}

	public function test_missing_later_selection_refuses_all_payload_writes(): void {
		$run = $this->start( $this->repeated_file(), [ '/repeated.txt', '/z-missing' ] );
		try {
			$run->advance( INF );
			self::fail( 'Missing selections must not silently restore a subset.' );
		} catch ( InvalidArgument $error ) {
			self::assertStringContainsString( 'does not exist', $error->getMessage() );
		}
		self::assertSame( [], TempDir::describe( $this->target ) );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	/** @return iterable<string, array{float|false}> */
	public static function insufficient_space(): iterable {
		yield 'full' => [ 0.0 ];
		yield 'unknown' => [ false ];
		yield 'infinity' => [ INF ];
		yield 'not-a-number' => [ NAN ];
		yield 'data-only' => [ 9.0 ];
	}

	#[DataProvider( 'insufficient_space' )]
	public function test_disk_space_preflight_prevents_payload_writes( float|false $free ): void {
		$run = $this->start( $this->repeated_file(), space: static fn (): float|false => $free );
		try {
			$run->advance( INF );
			self::fail( 'Staging must have a known reserve beyond the logical file size.' );
		} catch ( FormatException $error ) {
			self::assertStringContainsString( 'free space', $error->getMessage() );
		}
		self::assertSame( [], TempDir::describe( $this->target ) );
	}

	public function test_working_filesystem_space_is_checked_separately(): void {
		$run = $this->start( $this->repeated_file(), space: fn ( string $path ): float => $this->work === $path ? 0.0 : 1073741824.0 );
		$this->expectExceptionMessage( 'working filesystem' );
		$run->advance( INF );
	}

	public function test_nonempty_target_is_refused_without_changing_existing_content(): void {
		$snapshot = $this->repeated_file();
		file_put_contents( $this->target . '/owned-by-someone-else', 'keep' );
		$requests = $this->backend->requests;
		try {
			$this->start( $snapshot );
			self::fail( 'An occupied target is not staging.' );
		} catch ( InvalidArgument ) {
			self::assertSame( 'keep', file_get_contents( $this->target . '/owned-by-someone-else' ) );
		}
		self::assertSame( $requests, $this->backend->requests );
	}

	public function test_working_directory_must_not_be_inside_the_payload(): void {
		$this->work = $this->target . '/work';
		$this->expectException( InvalidArgument::class );
		$this->start( $this->repeated_file() );
	}

	public function test_late_unsafe_tree_name_is_rejected_during_planning(): void {
		$snapshot = $this->snapshot( [ $this->file( 'a-good', 'abc' ), $this->directory( 'z-bad', [ $this->file( '..', 'bad' ) ] ) ] );
		$run      = $this->start( $snapshot );
		try {
			$run->advance( INF );
			self::fail( 'All selected paths must validate before writing any of them.' );
		} catch ( FormatException ) {
			self::assertSame( [], TempDir::describe( $this->target ) );
		}
	}

	public function test_links_and_special_files_are_recorded_exactly_once_without_being_created(): void {
		$snapshot = $this->snapshot(
			[
				$this->file( "raw-\xe9", 'raw' ),
				new Node( 'link', NodeType::Symlink, link_target: "../../outside-\xe9" ),
				new Node( 'pipe', NodeType::Fifo ),
			]
		);
		$run      = $this->finish( $this->start( $snapshot ) );
		self::assertSame( 2, $run->progress()['skipped'] );
		self::assertSame( 'raw', file_get_contents( $this->target . "/raw-\xe9" ) );
		self::assertFalse( file_exists( $this->target . '/link' ) || is_link( $this->target . '/link' ) );
		self::assertFalse( file_exists( $this->target . '/pipe' ) );
		$lines = file( $this->work . '/entries.jsonl', FILE_IGNORE_NEW_LINES );
		self::assertIsArray( $lines );
		self::assertCount( 3, $lines );
		$entries = array_map( GoJson::decode_object( ... ), $lines );
		self::assertSame( '/link', GoQuote::unquote( $entries[0]['path'] ) );
		self::assertSame( "../../outside-\xe9", GoQuote::unquote( $entries[0]['link_target'] ) );
		self::assertSame( 'fifo', $entries[1]['type'] );
	}

	public function test_uncommitted_file_and_report_suffixes_are_discarded_after_an_interrupted_tick(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		self::assertFalse( $run->advance( 0.0 ) );
		self::assertSame( 3, $run->progress()['bytes'] );
		file_put_contents( $this->target . '/repeated.txt', 'uncommitted bytes', FILE_APPEND );
		file_put_contents( $this->work . '/entries.jsonl', "uncommitted report\n", FILE_APPEND );
		$run = $this->finish( RestoreRun::resume( $this->repository, $this->work, $this->clock ) );
		self::assertSame( 'abcabcabc', file_get_contents( $this->target . '/repeated.txt' ) );
		self::assertSame( 9, $run->progress()['bytes'] );
		self::assertCount( 1, file( $this->work . '/entries.jsonl' ) );
	}

	public function test_missing_committed_file_bytes_are_not_replaced_with_sparse_zeroes(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		$run->advance( 0.0 );
		file_put_contents( $this->target . '/repeated.txt', '' );
		$this->expectExceptionMessage( 'committed offset' );
		$run->advance( INF );
	}

	public function test_replacing_a_partial_file_with_a_new_inode_is_refused(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		$run->advance( 0.0 );
		rename( $this->target . '/repeated.txt', $this->scratch . '/original' );
		file_put_contents( $this->target . '/repeated.txt', 'foreign' );
		try {
			$run->advance( INF );
			self::fail( 'A new file is not the committed partial file.' );
		} catch ( FormatException ) {
			self::assertSame( 'foreign', file_get_contents( $this->target . '/repeated.txt' ) );
		}
	}

	/** @return iterable<string, array{bool}> */
	public static function link_types(): iterable {
		yield 'symbolic' => [ true ];
		yield 'hard' => [ false ];
	}

	#[DataProvider( 'link_types' )]
	public function test_links_cannot_replace_partial_files( bool $symbolic ): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		$run->advance( 0.0 );
		file_put_contents( $this->scratch . '/outside', 'must stay unchanged' );
		unlink( $this->target . '/repeated.txt' );
		if ( $symbolic ) {
			symlink( $this->scratch . '/outside', $this->target . '/repeated.txt' );
		} else {
			link( $this->scratch . '/outside', $this->target . '/repeated.txt' );
		}
		try {
			$run->advance( INF );
			self::fail( 'A restore must not follow a link.' );
		} catch ( FormatException ) {
			self::assertSame( 'must stay unchanged', file_get_contents( $this->scratch . '/outside' ) );
		}
	}

	public function test_a_replaced_target_directory_is_refused_but_abort_still_releases_the_lock(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		rename( $this->target, $this->scratch . '/original' );
		mkdir( $this->target, 0700 );
		try {
			$run->advance( INF );
			self::fail( 'A fresh directory at the same path is not the bound staging directory.' );
		} catch ( FormatException ) {
			$run->abort();
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertSame( [], TempDir::describe( $this->target ) );
	}

	public function test_a_second_request_cannot_enter_while_the_local_lock_is_held(): void {
		$run    = $this->start( $this->repeated_file() );
		$handle = fopen( $this->work . '/restore.lock', 'r+b' );
		self::assertIsResource( $handle );
		self::assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		$requests = $this->backend->requests;
		try {
			$run->advance( INF );
			self::fail( 'Concurrent restore ticks must be serialized.' );
		} catch ( LockConflict ) {
			self::assertSame( $requests, $this->backend->requests );
		} finally {
			fclose( $handle );
		}
	}

	public function test_an_older_object_rereads_the_latest_progress_after_taking_the_local_lock(): void {
		$run = $this->start( $this->repeated_file() );
		$old = RestoreRun::resume( $this->repository, $this->work, $this->clock );
		$this->plan( $run );
		$run->advance( 0.0 );
		self::assertSame( 3, $run->progress()['bytes'] );
		$old->advance( 0.0 );
		self::assertSame( 6, $old->progress()['bytes'] );
		$run = $this->finish( $run, false );
		self::assertSame( 9, $run->progress()['bytes'] );
		self::assertSame( 'abcabcabc', file_get_contents( $this->target . '/repeated.txt' ) );
	}

	/** @return iterable<string, array{bool}> */
	public static function lease_gaps(): iterable {
		yield 'elapsed' => [ true ];
		yield 'deleted' => [ false ];
	}

	#[DataProvider( 'lease_gaps' )]
	public function test_index_locations_are_reloaded_after_repacking_during_a_lease_gap( bool $elapsed ): void {
		$node     = $this->file( 'file.txt', 'content' );
		$snapshot = $this->snapshot( [ $node ] );
		$run      = $this->start( $snapshot );
		$run->advance( 0.0 );
		$packs       = array_keys( $this->backend->list( ObjectType::Data ) );
		$indexes     = array_keys( $this->backend->list( ObjectType::Index ) );
		$this->store = new BlobStore( $this->repository, new IndexSet() );
		$this->file( 'file.txt', 'content' );
		$this->directory( 'root', [ $node ] );
		$this->store->flush();
		$this->store->write_index();
		foreach ( $packs as $pack ) {
			$this->backend->delete( ObjectType::Data, $pack );
		}
		foreach ( $indexes as $index ) {
			$this->backend->delete( ObjectType::Index, $index );
		}
		if ( $elapsed ) {
			$this->clock->advance( Locking::TRUSTED_SECONDS + 1 );
		} else {
			$this->backend->objects['locks'] = [];
		}
		self::assertTrue( $run->advance( INF ) );
		self::assertSame( 'content', file_get_contents( $this->target . '/file.txt' ) );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_snapshot_removed_during_a_gap_is_not_restored_from_cached_trees(): void {
		$snapshot = $this->repeated_file();
		$run      = $this->start( $snapshot );
		$run->advance( 0.0 );
		$this->backend->delete( ObjectType::Snapshots, $snapshot->hex() );
		$this->clock->advance( Locking::TRUSTED_SECONDS + 1 );
		$this->expectExceptionMessage( 'snapshot no longer exists' );
		$run->advance( INF );
	}

	public function test_a_lease_lost_during_a_data_download_is_refused_before_writing_the_download(): void {
		$snapshot   = $this->repeated_file();
		$expire     = false;
		$backend    = new HookedBackend(
			$this->backend,
			function ( string $method, ?ObjectType $type, string $name, int $offset, ?int $length ) use ( &$expire ): void {
				if ( $expire && 'load' === $method && ObjectType::Data === $type && Cipher::OVERHEAD + 3 === $length ) {
					$expire = false;
					$this->clock->advance( Locking::TRUSTED_SECONDS + 1 );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = $this->start( $snapshot, repository: $repository );
		$this->plan( $run );
		$expire = true;
		try {
			$run->advance( 0.0 );
			self::fail( 'An untrusted lease must discard its download.' );
		} catch ( RepositoryChanged ) {
			self::assertSame( '', file_get_contents( $this->target . '/repeated.txt' ) );
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		$this->finish( RestoreRun::resume( $this->repository, $this->work, $this->clock ) );
		self::assertSame( 'abcabcabc', file_get_contents( $this->target . '/repeated.txt' ) );
	}

	public function test_a_slow_lock_head_cannot_extend_the_trusted_lease(): void {
		$snapshot   = $this->repeated_file();
		$delay      = true;
		$backend    = new HookedBackend(
			$this->backend,
			function ( string $method, ?ObjectType $type ) use ( &$delay ): void {
				if ( $delay && 'size' === $method && ObjectType::Locks === $type ) {
					$delay = false;
					$this->clock->advance( Locking::TRUSTED_SECONDS + 1 );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = $this->start( $snapshot, repository: $repository );
		$this->expectException( RepositoryChanged::class );
		$run->advance( INF );
	}

	public function test_abort_releases_an_unrecorded_lock_without_reading_an_index(): void {
		$run     = $this->start( $this->repeated_file() );
		$state   = Files::read( $this->work );
		$locking = new Locking( $this->repository, new Identity( $state->hostname ), $this->clock, $state->pid );
		$locking->acquire( false );
		$this->backend->objects['index'] = [];
		$run->abort();
		$run->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertSame( 'aborted', Files::read( $this->work )->phase );
		$this->expectException( InvalidArgument::class );
		$run->advance( INF );
	}

	/** @return iterable<string, array{bool}> */
	public static function abort_cleanup(): iterable {
		yield 'backend available' => [ false ];
		yield 'backend fails too' => [ true ];
	}

	#[DataProvider( 'abort_cleanup' )]
	public function test_abort_attempts_lock_cleanup_when_its_checkpoint_cannot_be_published( bool $fail ): void {
		$snapshot   = $this->repeated_file();
		$deletes    = 0;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$fail, &$deletes ): void {
				if ( 'delete' === $method && ObjectType::Locks === $type ) {
					++$deletes;
					if ( $fail ) {
						throw new \RuntimeException( 'Lock cleanup failed too.' );
					}
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run        = $this->start( $snapshot, repository: $repository );
		$run->advance( 0.0 );
		mkdir( $this->work . '/restore.json.tmp' );
		try {
			$run->abort();
			self::fail( 'The checkpoint failure must reach the caller.' );
		} catch ( FormatException $error ) {
			self::assertStringContainsString( 'not a regular private file', $error->getMessage() );
		}
		self::assertGreaterThan( 0, $deletes );
		self::assertCount( $fail ? 1 : 0, $this->backend->list( ObjectType::Locks ) );
		rmdir( $this->work . '/restore.json.tmp' );
		$fail = false;
		$run->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
		self::assertSame( 'aborted', Files::read( $this->work )->phase );
	}

	public function test_an_exclusive_repository_lock_blocks_restore_and_remains_owned_by_its_client(): void {
		$snapshot = $this->repeated_file();
		$foreign  = ( new Locking( $this->repository, new Identity( 'foreign.test' ), $this->clock, 1234 ) )->acquire( true );
		$run      = $this->start( $snapshot );
		try {
			$run->advance( INF );
			self::fail( 'A restore must cooperate with maintenance.' );
		} catch ( LockConflict ) {
			self::assertSame( [ $foreign->hex() ], array_keys( $this->backend->list( ObjectType::Locks ) ) );
		}
		self::assertSame( [], TempDir::describe( $this->target ) );
	}

	public function test_checkpoint_is_bound_to_its_repository(): void {
		$this->start( $this->repeated_file() );
		$other = Repository::initialize( new InMemoryBackend(), 'other', new Identity( 'other.test' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->expectExceptionMessage( 'another repository' );
		RestoreRun::resume( $other, $this->work, $this->clock );
	}

	public function test_future_checkpoint_versions_are_refused_before_repository_reads(): void {
		$this->start( $this->repeated_file() );
		$data            = GoJson::decode_object( (string) file_get_contents( $this->work . '/restore.json' ) );
		$data['version'] = State::VERSION + 1;
		file_put_contents( $this->work . '/restore.json', GoJson::encode( $data ) );
		$this->expectException( FormatException::class );
		RestoreRun::resume( $this->repository, $this->work, $this->clock );
	}


	public function test_an_existing_target_symlink_with_a_trailing_slash_is_refused(): void {
		$snapshot = $this->repeated_file();
		$link     = $this->scratch . '/linked-target';
		symlink( $this->target, $link );
		$this->target = $link . '/';
		$this->expectException( InvalidArgument::class );
		$this->start( $snapshot );
	}

	public function test_directory_creation_intent_can_be_replayed_after_mkdir_but_before_checkpoint(): void {
		$snapshot = $this->snapshot( [ $this->directory( 'folder', [ $this->file( 'child', 'abc' ) ] ) ] );
		$run      = $this->start( $snapshot );
		$this->plan( $run );
		$state           = Files::read( $this->work );
		$state->creating = 'folder';
		Files::persist( $state );
		mkdir( $this->target . '/folder', 0700 );
		$this->finish( RestoreRun::resume( $this->repository, $this->work, $this->clock ) );
		self::assertSame( 'abc', file_get_contents( $this->target . '/folder/child' ) );
		self::assertCount( 2, file( $this->work . '/entries.jsonl' ) );
	}

	public function test_file_creation_intent_can_be_replayed_after_open_but_before_identity_checkpoint(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		$state       = Files::read( $this->work );
		$state->file = [
			'path'    => 'repeated.txt',
			'blob'    => 0,
			'written' => 0,
			'dev'     => null,
			'ino'     => null,
		];
		Files::persist( $state );
		$handle = fopen( $this->target . '/repeated.txt', 'x+b' );
		self::assertIsResource( $handle );
		fclose( $handle );
		$this->finish( RestoreRun::resume( $this->repository, $this->work, $this->clock ) );
		self::assertSame( 'abcabcabc', file_get_contents( $this->target . '/repeated.txt' ) );
	}

	public function test_new_destination_collisions_are_refused_even_for_equal_content(): void {
		$run = $this->start( $this->repeated_file() );
		$this->plan( $run );
		file_put_contents( $this->target . '/repeated.txt', 'abcabcabc' );
		$this->expectExceptionMessage( 'unexpected entry' );
		$run->advance( INF );
	}

	public function test_restoring_through_a_replaced_parent_directory_is_refused(): void {
		$snapshot = $this->snapshot( [ $this->directory( 'folder', [ $this->file( 'child', 'abc' ) ] ) ] );
		$run      = $this->start( $snapshot );
		$this->plan( $run );
		$run->advance( 0.0 );
		rmdir( $this->target . '/folder' );
		mkdir( $this->scratch . '/outside' );
		symlink( $this->scratch . '/outside', $this->target . '/folder' );
		try {
			$run->advance( INF );
			self::fail( 'A parent link must never redirect staged writes.' );
		} catch ( FormatException ) {
			self::assertSame( [], TempDir::describe( $this->scratch . '/outside' ) );
		}
	}

	public function test_inconsistent_file_metadata_is_refused_before_staging(): void {
		$blob     = $this->store->save( BlobType::Data, 'short' );
		$snapshot = $this->snapshot( [ new Node( 'file', NodeType::File, size: 100, content: [ $blob ] ) ] );
		$run      = $this->start( $snapshot );
		try {
			$run->advance( INF );
			self::fail( 'Preflight must compare logical sizes with indexed blobs.' );
		} catch ( FormatException $error ) {
			self::assertStringContainsString( 'size does not match', $error->getMessage() );
		}
		self::assertSame( [], TempDir::describe( $this->target ) );
	}

	public function test_refreshes_between_slow_index_reads_do_not_duplicate_planning_counters(): void {
		$nodes = [];
		for ( $number = 0; $number < 12; ++$number ) {
			$nodes[] = $this->file( 'file-' . $number, 'content-' . $number );
			$this->store->flush();
			$this->store->write_index();
		}
		$snapshot    = $this->snapshot( $nodes );
		$locks_saved = 0;
		$backend     = new HookedBackend(
			$this->backend,
			function ( string $method, ?ObjectType $type ) use ( &$locks_saved ): void {
				if ( 'load' === $method && ObjectType::Index === $type ) {
					$this->clock->advance( 180 );
				}
				if ( 'save' === $method && ObjectType::Locks === $type ) {
					++$locks_saved;
				}
			}
		);
		$repository  = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$run         = $this->start( $snapshot, repository: $repository );
		self::assertTrue( $run->advance( INF ) );
		self::assertGreaterThan( 5, $locks_saved );
		self::assertSame( 12, $run->progress()['total_files'] );
		self::assertSame( 12, $run->progress()['files'] );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_slow_planning_can_refresh_without_checkpointing_a_half_counted_file(): void {
		$snapshot = $this->repeated_file();
		$pauses   = 0;
		$throttle = new Throttle(
			0.0,
			0.0,
			static fn (): float => 1.0,
			function () use ( &$pauses ): void {
				$this->clock->advance( Locking::REFRESH_AFTER_SECONDS );
				if ( 2 === ++$pauses ) {
					throw new \RuntimeException( 'Interrupted planning after a lease refresh.' );
				}
			}
		);
		$run      = $this->start( $snapshot, [ '/repeated.txt' ], throttle: $throttle );
		try {
			$run->advance( 0.0 );
			self::fail( 'The injected interruption must reach the caller.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Interrupted planning after a lease refresh.', $error->getMessage() );
		}
		$state = Files::read( $this->work );
		self::assertSame( 0, $state->planned_entries );
		self::assertSame( [], $state->found );
		$this->finish( RestoreRun::resume( $this->repository, $this->work, $this->clock ) );
		$state = Files::read( $this->work );
		self::assertSame( 1, $state->planned_entries );
		self::assertSame( 1, $state->planned_files );
		self::assertSame( 1, $state->files );
		self::assertSame( 9, $state->bytes );
	}

	public function test_cleanup_recognizes_a_refresh_that_died_before_recording_its_new_lock(): void {
		$run = $this->start( $this->repeated_file() );
		$run->advance( 0.0 );
		$state = Files::read( $this->work );
		self::assertNotNull( $state->lock );
		$locking = new Locking( $this->repository, new Identity( $state->hostname ), $this->clock, $state->pid );
		$locking->refresh( Id::from_hex( $state->lock ), false );
		$run->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_readonly_snapshot_permissions_do_not_prevent_resume_or_later_downloads(): void {
		$snapshot = $this->snapshot( [ $this->file( 'readonly', 'abc', 3, 0400 ) ] );
		$this->finish( $this->start( $snapshot ) );
		self::assertSame( 0600, fileperms( $this->target . '/readonly' ) & 0777 );
		$line = trim( (string) file_get_contents( $this->work . '/entries.jsonl' ) );
		self::assertSame( 0400, GoJson::decode_object( $line )['mode'] );
		self::assertSame( 'abcabcabc', file_get_contents( $this->target . '/readonly' ) );
	}

	public function test_cpu_throttle_runs_during_planning_and_data_restore(): void {
		$pauses   = 0;
		$throttle = new Throttle(
			0.0,
			0.0,
			static fn (): float => 1.0,
			static function () use ( &$pauses ): void {
				++$pauses;
			}
		);
		$run      = $this->start( $this->repeated_file(), throttle: $throttle );
		$this->plan( $run );
		self::assertGreaterThan( 3, $pauses );
		$before = $pauses;
		$run->advance( INF );
		self::assertGreaterThan( $before + 3, $pauses );
	}
}
