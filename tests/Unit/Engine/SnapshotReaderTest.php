<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Snapshot\Manifest;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Engine\TreeCache;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

final class SnapshotReaderTest extends TestCase {

	private InMemoryBackend $backend;
	private CountingBackend $counted;
	private Repository $repository;
	private BlobStore $store;
	private FrozenClock $clock;
	private string $cache;
	private bool $reading         = false;
	private int $read_seconds     = 0;
	private int $snapshot_seconds = 0;
	private int $lock_writes      = 0;

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$hooked           = new HookedBackend(
			$this->backend,
			function ( string $method, ?ObjectType $type ): void {
				if ( 'save' === $method && ObjectType::Locks === $type ) {
					++$this->lock_writes;
				}
				if ( $this->reading && in_array( $type, [ ObjectType::Index, ObjectType::Data, ObjectType::Snapshots ], true ) ) {
					self::assertCount( 1, $this->backend->objects['locks'] ?? [], 'Metadata, index and tree access requires the shared lock first.' );
					self::assertContains( $method, [ 'list', 'load' ], 'Browsing must never mutate repository data.' );
					if ( 'load' === $method && ObjectType::Data === $type ) {
						$this->clock->seconds += $this->read_seconds;
					}
					if ( 'load' === $method && ObjectType::Snapshots === $type ) {
						$this->clock->seconds += $this->snapshot_seconds;
					}
				}
			}
		);
		$this->counted    = new CountingBackend( $hooked );
		$this->repository = Repository::initialize( $this->counted, 'test password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->store      = new BlobStore( $this->repository, new IndexSet() );
		$this->clock      = new FrozenClock();
		$this->cache      = TempDir::create( 'snapshot-cache' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->cache );
	}

	private function reader( bool $cached = false ): Reader {
		$this->reading = true;
		return new Reader( $this->repository, new Identity( 'current.example.com' ), $this->clock, $cached ? new IndexCache( $this->cache, 1048576 ) : null, $cached ? new TreeCache( $this->cache, 1048576 ) : null );
	}

	/** @param list<Node> $nodes */
	private function tree( array $nodes ): Id {
		usort( $nodes, static fn ( Node $a, Node $b ): int => strcmp( $a->name, $b->name ) );
		return $this->store->save( BlobType::Tree, TreeCodec::encode( $nodes ) );
	}

	/** @param list<string> $tags */
	private function snapshot( Id $tree, array $tags = [], string $host = 'example.com', string $time = '2026-01-01T00:00:00Z' ): Id {
		$this->reading = false;
		$this->store->flush();
		$this->store->write_index();
		return $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( $time, $tree, [ '/old/site' ], $host, tags: $tags ) )->to_json() );
	}

	/** @return array<string, mixed> */
	private static function manifest_data(): array {
		return [
			'format'     => Manifest::FORMAT,
			'created'    => '2026-01-01T00:00:00Z',
			'home_url'   => 'https://old.example.com',
			'abspath'    => '/old/site',
			'content'    => '/old/site/wp-content',
			'uploads'    => '/old/site/wp-content/uploads',
			'prefix'     => 'wp_',
			'roots'      => [ '/old/site' ],
			'excludes'   => [],
			'database'   => true,
			'versions'   => [
				'plugin'    => '0.3.0',
				'wordpress' => '6.7',
				'php'       => '8.3',
			],
			'repository' => [ 'format' => 1 ],
		];
	}

	private function manifest_tree( ?string $json = null, ?int $size = null ): Id {
		$json ??= GoJson::encode( self::manifest_data() );
		$blob   = $this->store->save( BlobType::Data, $json );
		return $this->tree( [ new Node( 'manifest.json', NodeType::File, size: $size ?? strlen( $json ), content: [ $blob ] ) ] );
	}

	public function test_site_filter_requires_host_and_every_tag_and_preserves_newest_first(): void {
		$tree  = $this->tree( [] );
		$tags  = [ 'backups-for-restic', 'site:0123456789ab' ];
		$older = $this->snapshot( $tree, [ ...$tags, 'run:one' ] );
		$newer = $this->snapshot( $tree, [ ...$tags, 'run:two' ], time: '2026-01-02T00:00:00Z' );
		$this->snapshot( $tree, $tags, 'elsewhere.example.com' );
		$this->snapshot( $tree, [ 'backups-for-restic', 'site:fedcba987654' ] );
		$this->snapshot( $tree, [ 'site:0123456789ab' ] );
		$reader = $this->reader();

		self::assertSame( [ $newer->hex(), $older->hex() ], array_keys( $reader->snapshots( 'example.com', $tags ) ) );
		self::assertCount( 5, $reader->snapshots() );
		self::assertSame( 0, $this->counted->loads['index'] ?? 0 );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_snapshot_enumeration_refreshes_its_lock_even_for_excluded_sites(): void {
		$tree = $this->tree( [] );
		$tags = [ 'backups-for-restic', 'site:0123456789ab' ];
		for ( $i = 0; $i < 12; ++$i ) {
			$this->snapshot( $tree, $tags, 'other.example.com' );
		}
		$wanted                 = $this->snapshot( $tree, $tags );
		$this->snapshot_seconds = 180;

		self::assertSame( [ $wanted->hex() ], array_keys( $this->reader()->snapshots( 'example.com', $tags ) ) );
		self::assertGreaterThan( 1, $this->lock_writes, 'A read longer than the lease refreshes the lock between snapshots, including those filtered out.' );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_a_single_snapshot_read_that_outlasts_the_lease_is_refused(): void {
		$this->snapshot( $this->tree( [] ) );
		$this->snapshot_seconds = Locking::TRUSTED_SECONDS + 1;
		try {
			$this->reader()->snapshots();
			self::fail( 'A lapse during one snapshot read must not return a stale result.' );
		} catch ( LockConflict ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_directory_browsing_preserves_raw_names_and_displays_links(): void {
		$leaf     = $this->tree( [ new Node( '0', NodeType::File, content: [] ), new Node( "raw\xff", NodeType::File, content: [] ) ] );
		$root     = $this->tree( [ new Node( 'dir', NodeType::Dir, subtree: $leaf ), new Node( 'link', NodeType::Symlink, link_target: '/dir' ) ] );
		$snapshot = $this->snapshot( $root );
		$reader   = $this->reader();

		self::assertSame( [ '0', "raw\xff" ], array_map( static fn ( Node $node ): string => $node->name, $reader->directory( $snapshot, '/dir/' ) ) );
		self::assertSame( NodeType::Symlink, $reader->directory( $snapshot )[1]->type );
	}

	public function test_directory_browsing_refuses_to_follow_a_link(): void {
		$leaf     = $this->tree( [] );
		$root     = $this->tree( [ new Node( 'dir', NodeType::Dir, subtree: $leaf ), new Node( 'link', NodeType::Symlink, link_target: '/dir' ) ] );
		$snapshot = $this->snapshot( $root );
		$reader   = $this->reader();
		try {
			$reader->directory( $snapshot, '/link' );
			self::fail( 'A link must not be followed.' );
		} catch ( InvalidArgument ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function invalid_paths(): iterable {
		foreach ( [ '', 'relative', '//', '/a//b', '/./a', '/a/..', "/nul\0", '/' . str_repeat( 'x', 4096 ), '/' . implode( '/', array_fill( 0, 257, 'x' ) ) ] as $i => $path ) {
			yield (string) $i => [ $path ];
		}
	}

	#[DataProvider( 'invalid_paths' )]
	public function test_invalid_paths_are_rejected_before_any_repository_request( string $path ): void {
		$reader   = $this->reader();
		$requests = $this->backend->requests;
		try {
			$reader->directory( Id::of( 'absent' ), $path );
			self::fail( 'The path must be rejected.' );
		} catch ( InvalidArgument ) {
			self::assertSame( $requests, $this->backend->requests );
		}
	}

	public function test_a_warm_tree_cache_avoids_loading_the_index_and_packs(): void {
		$leaf = $this->tree( [ new Node( 'file', NodeType::File, content: [] ) ] );
		$root = $this->tree( [ new Node( 'dir', NodeType::Dir, subtree: $leaf ) ] );
		$id   = $this->snapshot( $root );
		self::assertSame( 'file', $this->reader( true )->directory( $id, '/dir' )[0]->name );
		$index_reads = $this->counted->loads['index'];
		$pack_reads  = $this->counted->loads['data'];

		self::assertSame( 'file', $this->reader( true )->directory( $id, '/dir' )[0]->name );
		self::assertSame( $index_reads, $this->counted->loads['index'] );
		self::assertSame( $pack_reads, $this->counted->loads['data'] );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_an_index_is_not_reused_after_the_readers_lock_has_ended(): void {
		$id     = $this->snapshot( $this->tree( [] ) );
		$reader = $this->reader();
		self::assertSame( [], $reader->directory( $id ) );
		$this->backend->objects['index'] = [];

		try {
			$reader->directory( $id );
			self::fail( 'The second operation must read the current index.' );
		} catch ( FormatException ) {
			self::assertSame( [], $this->backend->objects['locks'], 'Even index failure releases the lock.' );
		}
	}

	public function test_a_conflicting_exclusive_lock_prevents_metadata_or_index_reads(): void {
		$id      = $this->snapshot( $this->tree( [] ) );
		$locking = new Locking( $this->repository, new Identity( 'maintenance.example.com' ), $this->clock, 7 );
		$held    = $locking->acquire( true );
		try {
			$this->reader()->directory( $id );
			self::fail( 'Maintenance must exclude browsing.' );
		} catch ( LockConflict ) {
			self::assertSame( 0, $this->counted->loads['snapshots'] ?? 0 );
			self::assertSame( 0, $this->counted->loads['index'] ?? 0 );
			self::assertArrayHasKey( $held->hex(), $this->backend->objects['locks'] );
		} finally {
			$locking->release( $held );
		}
	}

	public function test_manifest_locator_uses_the_original_path_and_does_not_walk_unrelated_directories(): void {
		$empty = $this->tree( [] );
		$wide  = [];
		for ( $i = 0; $i < 4096; ++$i ) {
			$wide[] = new Node( sprintf( '%05d', $i ), NodeType::Dir, subtree: $empty );
		}
		$forest = $this->tree( $wide );
		$spool  = $this->manifest_tree();
		$root   = $this->tree( [ new Node( 'forest', NodeType::Dir, subtree: $forest ), new Node( 'spool', NodeType::Dir, subtree: $spool ) ] );
		$id     = $this->snapshot( $root, [ Manifest::tag( '/spool/manifest.json' ) ] );

		$manifest = $this->reader()->manifest( $id );
		self::assertSame( '/spool/manifest.json', $manifest['path'] ?? null );
		self::assertSame( self::manifest_data(), $manifest['data'] ?? null );
		self::assertSame( 3, $this->counted->loads['data'] );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_legacy_manifest_search_works_after_a_site_moves(): void {
		$spool   = $this->manifest_tree();
		$private = $this->tree( [ new Node( 'spool', NodeType::Dir, subtree: $spool ) ] );
		$root    = $this->tree( [ new Node( 'old-private', NodeType::Dir, subtree: $private ) ] );
		$id      = $this->snapshot( $root );

		self::assertSame( '/old-private/spool/manifest.json', $this->reader()->manifest( $id )['path'] ?? null );
	}

	public function test_an_external_snapshot_without_a_site_manifest_returns_none(): void {
		$id = $this->snapshot( $this->tree( [ new Node( 'ordinary', NodeType::File, content: [] ) ] ) );
		self::assertNull( $this->reader()->manifest( $id ) );
	}

	public function test_ambiguous_legacy_manifests_require_an_explicit_choice(): void {
		$spool  = $this->manifest_tree();
		$site   = $this->tree( [ new Node( 'spool', NodeType::Dir, subtree: $spool ) ] );
		$id     = $this->snapshot( $this->tree( [ new Node( 'a', NodeType::Dir, subtree: $site ), new Node( 'b', NodeType::Dir, subtree: $site ) ] ) );
		$reader = $this->reader();
		try {
			$reader->manifest( $id );
			self::fail( 'Shared subtrees at two paths still require an explicit choice.' );
		} catch ( InvalidArgument ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
		self::assertSame( self::manifest_data(), $reader->manifest( $id, '/b/spool/manifest.json' )['data'] ?? null );
	}

	public function test_an_incomplete_manifest_is_refused_and_releases_its_lock(): void {
		$spool = $this->manifest_tree( size: 1 );
		$id    = $this->snapshot( $spool, [ Manifest::tag( '/manifest.json' ) ] );
		try {
			$this->reader()->manifest( $id );
			self::fail( 'The declared file length must match every verified blob.' );
		} catch ( FormatException ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_paths_and_manifest_locators_preserve_non_utf8_name_bytes(): void {
		$path = "/raw\xff/spool/manifest.json";
		self::assertSame( [ "raw\xff", 'spool', 'manifest.json' ], Path::parts( $path ) );
		self::assertSame( $path, Manifest::path( [ 'unrelated', Manifest::tag( $path ) ] ) );
	}

	public function test_excessive_manifest_search_fails_instead_of_reporting_the_manifest_absent(): void {
		$empty = $this->tree( [] );
		$nodes = [];
		for ( $i = 0; $i < 4096; ++$i ) {
			$nodes[] = new Node( sprintf( '%05d', $i ), NodeType::Dir, subtree: $empty );
		}
		$id = $this->snapshot( $this->tree( $nodes ) );
		try {
			$this->reader()->manifest( $id );
			self::fail( 'An incomplete search must never be reported as absent.' );
		} catch ( InvalidArgument $error ) {
			self::assertStringContainsString( 'select the manifest explicitly', $error->getMessage() );
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_a_manifest_cannot_amplify_reads_through_many_empty_blobs(): void {
		$blob = $this->store->save( BlobType::Data, '' );
		$tree = $this->tree( [ new Node( 'manifest.json', NodeType::File, content: array_fill( 0, Manifest::MAX_BLOBS + 1, $blob ) ) ] );
		$id   = $this->snapshot( $tree, [ Manifest::tag( '/manifest.json' ) ] );
		try {
			$this->reader()->manifest( $id );
			self::fail( 'The content-list limit must precede every data-blob request.' );
		} catch ( FormatException ) {
			self::assertSame( 1, $this->counted->loads['data'], 'Only the root tree was fetched.' );
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_a_directory_with_a_recorded_read_error_is_not_presented_as_complete(): void {
		$empty = $this->tree( [] );
		$id    = $this->snapshot( $this->tree( [ new Node( 'unreadable', NodeType::Dir, subtree: $empty, error: 'read refused' ) ] ) );
		try {
			$this->reader()->directory( $id, '/unreadable' );
			self::fail( 'A recorded read error must remain visible.' );
		} catch ( FormatException ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_legacy_discovery_refuses_an_incomplete_search_even_with_one_manifest_found(): void {
		$empty = $this->tree( [] );
		$root  = $this->tree(
			[
				new Node( 'spool', NodeType::Dir, subtree: $this->manifest_tree() ),
				new Node( 'unreadable', NodeType::Dir, subtree: $empty, error: 'read refused' ),
			]
		);
		$id    = $this->snapshot( $root );
		try {
			$this->reader()->manifest( $id );
			self::fail( 'The unreadable directory could hide another manifest.' );
		} catch ( FormatException ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
	}

	public function test_a_slow_directory_read_refreshes_its_shared_lock(): void {
		$id                 = $this->snapshot( $this->tree( [] ) );
		$this->read_seconds = Locking::REFRESH_AFTER_SECONDS + 1;
		$saves              = $this->lock_writes;
		self::assertSame( [], $this->reader()->directory( $id ) );
		self::assertSame( 2, $this->lock_writes - $saves, 'The read must refresh before returning its result.' );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_a_lapsed_lock_refuses_the_read_and_allows_a_fresh_attempt(): void {
		$id                 = $this->snapshot( $this->tree( [] ) );
		$this->read_seconds = Locking::TRUSTED_SECONDS + 1;
		$reader             = $this->reader();
		try {
			$reader->directory( $id );
			self::fail( 'A read across a lock gap must be retried under a new shared lock.' );
		} catch ( LockConflict ) {
			self::assertSame( [], $this->backend->objects['locks'] );
		}
		$this->read_seconds = 0;
		self::assertSame( [], $reader->directory( $id ) );
		self::assertSame( [], $this->backend->objects['locks'] );
	}
}
