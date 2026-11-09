<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Support\StringByteSource;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Backup and restore through the engine against an in-memory rest-server.
 */
final class BackupRestoreTest extends TestCase {

	private const string PASSWORD = 'test password';

	private string $site;

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		$this->site = TempDir::create( 'site' );
		TempDir::fixture( $this->site );
		$this->cleanup[] = $this->site;
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function repository( ?InMemoryBackend $backend = null ): Repository {
		return Repository::initialize( $backend ?? new InMemoryBackend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	}

	private function work_dir(): string {
		$this->cleanup[] = $path = TempDir::create( 'work' );
		return $path;
	}

	private function plan( ?\Closure $exclude = null, ?\Closure $virtual = null ): BackupPlan {
		return new BackupPlan( [ $this->site ], [ 'backups-for-restic' ], $exclude, $virtual );
	}

	private function back_up( Repository $repository, BackupPlan $plan, ?Id $parent_id = null, bool $interrupt = false ): BackupRun {
		$work   = $this->work_dir();
		$run    = BackupRun::start( $repository, $plan, $work, new Identity( 'example.com', 'www-data' ), new SystemClock(), $parent_id, 1048576 );
		$slices = 0;
		while ( ! $run->advance( $interrupt ? 0.0 : INF ) ) {
			++$slices;
			if ( $interrupt ) {
				// A new request: everything in memory is gone, only the checkpoint remains.
				$run = BackupRun::resume( $repository, $plan, $work, new Identity( 'example.com', 'www-data' ), new SystemClock(), 1048576 );
			}
			self::assertLessThan( 10000, $slices );
		}
		return $run;
	}

	private function restore( Repository $repository, Id $snapshot, string $subpath = '/', bool $interrupt = false ): string {
		$this->cleanup[] = $target = TempDir::create( 'target' );
		$work            = $this->work_dir();
		$run             = RestoreRun::start( $repository, $snapshot, $target, $work, $subpath );
		while ( ! $run->advance( $interrupt ? 0.0 : INF ) ) {
			if ( $interrupt ) {
				$run = RestoreRun::resume( $repository, $work );
			}
		}
		return $target;
	}

	private function snapshot( Repository $repository, Id $id ): Snapshot {
		return Snapshot::from_json( $repository->load_unpacked( ObjectType::Snapshots, $id ) );
	}

	/**
	 * Returns the tree of the site directory itself. The leading directories (/tmp) change
	 * whenever a test creates a scratch directory, so whole-snapshot trees are not comparable.
	 */
	private function site_tree( Repository $repository, Id $snapshot ): string {
		$index = $repository->load_index();
		$tree  = $this->snapshot( $repository, $snapshot )->tree;
		foreach ( array_filter( explode( '/', $this->site ) ) as $part ) {
			foreach ( $repository->load_tree( $index, $tree ) as $node ) {
				if ( $node->name === $part && null !== $node->subtree ) {
					$tree = $node->subtree;
				}
			}
		}
		return $tree->hex();
	}

	public function test_backup_and_restore_round_trip(): void {
		$repository = $this->repository();
		$snapshot   = $this->back_up( $repository, $this->plan() )->snapshot_id();
		self::assertNotNull( $snapshot );

		$target = $this->restore( $repository, $snapshot );
		self::assertSame( TempDir::describe( $this->site ), TempDir::describe( $target . $this->site ) );

		$summary = $this->snapshot( $repository, $snapshot )->summary;
		self::assertNotNull( $summary );
		self::assertSame( 9, $summary->files_new );
		self::assertSame( [], $repository->backend()->list( ObjectType::Locks ), 'The lock is released.' );
	}

	public function test_interrupted_backup_produces_the_same_snapshot_tree(): void {
		$repository = $this->repository();
		$whole      = $this->back_up( $repository, $this->plan() )->snapshot_id();
		$sliced     = $this->back_up( $repository, $this->plan(), null, true )->snapshot_id();
		self::assertNotNull( $whole );
		self::assertNotNull( $sliced );

		self::assertSame( $this->site_tree( $repository, $whole ), $this->site_tree( $repository, $sliced ) );
		$restored = $this->restore( $repository, $sliced, '/', true );
		self::assertSame( TempDir::describe( $this->site ), TempDir::describe( $restored . $this->site ) );
	}

	public function test_snapshot_layout_follows_restic_for_absolute_paths(): void {
		$repository = $this->repository();
		$id         = $this->back_up( $repository, $this->plan() )->snapshot_id();
		self::assertNotNull( $id );
		$snapshot = $this->snapshot( $repository, $id );
		self::assertSame( [ $this->site ], $snapshot->paths );
		self::assertSame( 'example.com', $snapshot->hostname );

		// The root tree holds the leading directories of the absolute path.
		$index = $repository->load_index();
		$tree  = $snapshot->tree;
		foreach ( array_filter( explode( '/', $this->site ) ) as $part ) {
			$nodes = $repository->load_tree( $index, $tree );
			self::assertCount( 1, $nodes );
			self::assertSame( $part, $nodes[0]->name );
			self::assertSame( NodeType::Dir, $nodes[0]->type );
			$tree = $nodes[0]->subtree ?? $tree;
		}
	}

	public function test_second_backup_reuses_unchanged_files_and_stores_nothing_new(): void {
		$repository = $this->repository();
		$first      = $this->back_up( $repository, $this->plan() )->snapshot_id();
		$second     = $this->back_up( $repository, $this->plan(), $first )->snapshot_id();
		self::assertNotNull( $second );

		self::assertNotNull( $first );
		$summary = $this->snapshot( $repository, $second )->summary;
		self::assertNotNull( $summary );
		self::assertSame( 9, $summary->files_unmodified );
		self::assertSame( 0, $summary->data_blobs, 'Unchanged files store no data.' );
		self::assertSame( $this->site_tree( $repository, $first ), $this->site_tree( $repository, $second ), 'The site tree deduplicates completely.' );
	}

	public function test_changed_files_are_read_again(): void {
		$repository = $this->repository();
		$first      = $this->back_up( $repository, $this->plan() )->snapshot_id();
		file_put_contents( $this->site . '/index.php', "<?php\n// Changed.\n" );
		$second = $this->back_up( $repository, $this->plan(), $first )->snapshot_id();
		self::assertNotNull( $second );

		$summary = $this->snapshot( $repository, $second )->summary;
		self::assertNotNull( $summary );
		self::assertSame( 1, $summary->files_changed );
		self::assertSame( 1, $summary->data_blobs );
		self::assertSame( TempDir::describe( $this->site ), TempDir::describe( $this->restore( $repository, $second ) . $this->site ) );
	}

	public function test_identical_files_share_their_blobs(): void {
		$repository = $this->repository();
		$id         = $this->back_up( $repository, $this->plan() )->snapshot_id();
		self::assertNotNull( $id );
		$summary = $this->snapshot( $repository, $id )->summary;
		self::assertNotNull( $summary );
		self::assertLessThan( 2 * 3145728, $summary->data_added, 'copy.jpg deduplicates against photo.jpg.' );
	}

	public function test_excludes_and_virtual_files(): void {
		$repository = $this->repository();
		$plan       = $this->plan(
			fn ( string $path, bool $is_dir ): bool => str_ends_with( $path, '/wp-content/plugins' ),
			fn ( string $directory ): array => $directory === $this->site . '/wp-content'
				? [ new VirtualFile( 'database.sql', static fn () => new StringByteSource( "CREATE TABLE t (id INT);\n" ), 1767323045 ) ]
				: []
		);
		$id         = $this->back_up( $repository, $plan )->snapshot_id();
		self::assertNotNull( $id );

		$target = $this->restore( $repository, $id ) . $this->site;
		self::assertDirectoryDoesNotExist( $target . '/wp-content/plugins' );
		self::assertSame( "CREATE TABLE t (id INT);\n", file_get_contents( $target . '/wp-content/database.sql' ) );
	}

	public function test_restore_of_a_single_file(): void {
		$repository = $this->repository();
		$id         = $this->back_up( $repository, $this->plan() )->snapshot_id();
		self::assertNotNull( $id );

		$target = $this->restore( $repository, $id, $this->site . '/wp-config.php' );
		self::assertSame( [ 'wp-config.php' ], array_values( array_diff( (array) scandir( $target ), [ '.', '..' ] ) ) );
		self::assertSame( 0600, fileperms( $target . '/wp-config.php' ) & 0777 );
	}

	public function test_restore_rejects_names_that_escape_the_target(): void {
		$backend    = new InMemoryBackend();
		$repository = $this->repository( $backend );
		$index      = $repository->load_index();
		$store      = new BlobStore( $repository, $index );
		$evil       = $store->save( BlobType::Data, 'owned' );
		$tree       = $store->save( BlobType::Tree, TreeCodec::encode( [ new Node( '..', NodeType::File, 0644, content: [ $evil ] ) ] ) );
		$store->flush();
		$store->write_index();
		$snapshot = $repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-11-09T12:00:00Z', $tree, [ '/' ] ) )->to_json() );

		$this->expectException( FormatException::class );
		$this->restore( $repository, $snapshot );
	}

	public function test_unlock_with_password(): void {
		$backend    = new InMemoryBackend();
		$repository = $this->repository( $backend );
		$master     = Repository::unlock( $backend, self::PASSWORD, new SystemRandom() );
		self::assertSame( $repository->master_key()->to_json(), $master->to_json() );

		$this->expectException( WrongPassword::class );
		Repository::unlock( $backend, 'wrong', new SystemRandom() );
	}

	public function test_append_only_detection_and_refused_deletion(): void {
		$open = new InMemoryBackend();
		self::assertSame( AppendOnlyStatus::DeletionsAllowed, AppendOnlyProbe::detect( $open, new SystemRandom() ) );

		$backend    = new InMemoryBackend( true );
		$repository = $this->repository( $backend );
		self::assertSame( AppendOnlyStatus::AppendOnly, AppendOnlyProbe::detect( $backend, new SystemRandom() ) );

		$id = $this->back_up( $repository, $this->plan() )->snapshot_id();
		self::assertNotNull( $id );
		self::assertSame( [], $backend->list( ObjectType::Locks ), 'Locks can be released in append-only mode.' );

		$this->expectException( AppendOnlyRefused::class );
		Forget::delete( $repository, [ $id->hex() ] );
	}
}
