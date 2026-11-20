<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\ForgetRun;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Snapshot\Manifest;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Restore\SnapshotBrowser;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

final class SnapshotBrowserTest extends TestCase {

	private const array SITE_TAGS = [ 'backups-for-restic', 'site:0123456789ab' ];

	private InMemoryBackend $backend;
	private Repository $repository;
	private BlobStore $store;
	private FrozenClock $clock;

	protected function setUp(): void {
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, 'test password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->store      = new BlobStore( $this->repository, new IndexSet() );
		$this->clock      = new FrozenClock();
	}

	private function browser( ?RetentionPolicy $policy = null ): SnapshotBrowser {
		return new SnapshotBrowser( new Reader( $this->repository, new Identity( 'example.com' ), $this->clock ), 'example.com', self::SITE_TAGS, $policy, $this->clock );
	}

	/**
	 * The preview of a snapshot list as sorted IDs by decision.
	 *
	 * @param list<array<string, mixed>> $snapshots
	 * @return array{keep: list<string>, remove: list<string>, none: list<string>}
	 */
	private static function preview( array $snapshots ): array {
		$keep   = [];
		$remove = [];
		$none   = [];
		foreach ( $snapshots as $snapshot ) {
			self::assertIsString( $snapshot['id'] );
			match ( $snapshot['retention'] ) {
				SnapshotBrowser::RETENTION_KEEP   => $keep[]   = $snapshot['id'],
				SnapshotBrowser::RETENTION_REMOVE => $remove[] = $snapshot['id'],
				null                              => $none[]   = $snapshot['id'],
			};
		}
		sort( $keep );
		sort( $remove );
		sort( $none );
		return compact( 'keep', 'remove', 'none' );
	}

	/** @param list<Node> $nodes */
	private function tree( array $nodes ): Id {
		usort( $nodes, static fn ( Node $a, Node $b ): int => strcmp( $a->name, $b->name ) );
		return $this->store->save( BlobType::Tree, TreeCodec::encode( $nodes ) );
	}

	/** @param list<string> $tags */
	private function snapshot( Id $tree, array $tags = self::SITE_TAGS, string $host = 'example.com', string $time = '2026-01-01T00:00:00Z' ): string {
		$this->store->flush();
		$this->store->write_index();
		return $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( $time, $tree, [ '/old/site' ], $host, tags: $tags ) )->to_json() )->hex();
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

	private function manifest_tree( ?string $json = null ): Id {
		$json ??= GoJson::encode( self::manifest_data() );
		return $this->tree( [ new Node( 'manifest.json', NodeType::File, size: strlen( $json ), content: [ $this->store->save( BlobType::Data, $json ) ] ) ] );
	}

	/** @return array{0: string, 1: int} */
	private static function refused( \Closure $read ): array {
		try {
			$read();
		} catch ( PluginException $error ) {
			return SnapshotBrowser::failure( $error );
		}
		self::fail( 'The read must be refused.' );
	}

	public function test_the_site_scope_lists_this_sites_snapshots_and_the_repository_scope_all_of_them(): void {
		$tree  = $this->tree( [] );
		$older = $this->snapshot( $tree );
		$newer = $this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:two' ], time: '2026-01-02T00:00:00Z' );
		$other = $this->snapshot( $tree, self::SITE_TAGS, 'elsewhere.example.com', '2026-01-03T00:00:00Z' );
		$plain = $this->snapshot( $tree, [], time: '2026-01-04T00:00:00Z' );

		$site = $this->browser()->snapshots();
		self::assertSame( [ $newer, $older ], array_column( $site, 'id' ) );
		self::assertSame( [ $plain, $other, $newer, $older ], array_column( $this->browser()->snapshots( SnapshotBrowser::SCOPE_REPOSITORY ), 'id' ) );
		self::assertSame(
			[
				'id'         => $newer,
				'short_id'   => substr( $newer, 0, 8 ),
				'time'       => '2026-01-02T00:00:00Z',
				'hostname'   => 'example.com',
				'tags'       => [ ...self::SITE_TAGS, 'run:two' ],
				'data_added' => null,
				'files'      => null,
				'bytes'      => null,
				'retention'  => null,
			],
			$site[0],
			'The admin page reads these fields.'
		);
		self::assertSame( [], $this->backend->objects['locks'], 'A list leaves no lock behind.' );
	}

	public function test_the_site_scope_previews_what_the_next_forget_keeps_and_removes(): void {
		$tree   = $this->tree( [] );
		$oldest = $this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:one' ], time: '2025-12-29T00:00:00Z' );
		$middle = $this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:two' ], time: '2025-12-30T00:00:00Z' );
		$newest = $this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:three' ], time: '2025-12-31T00:00:00Z' );
		$other  = $this->snapshot( $tree, self::SITE_TAGS, 'elsewhere.example.com', '2025-12-01T00:00:00Z' );
		$plain  = $this->snapshot( $tree, [], time: '2025-12-02T00:00:00Z' );
		$before = $this->backend->objects['snapshots'];

		// The tag of the job that wrote a snapshot does not make it a group of its own: if
		// it did, each would be the newest of its group and the policy would keep them all.
		$site = $this->browser( new RetentionPolicy( 2 ) )->snapshots();

		self::assertSame( [ $newest, $middle, $oldest ], array_column( $site, 'id' ) );
		self::assertSame( [ 'keep', 'keep', 'remove' ], array_column( $site, 'retention' ) );
		// No forget of this site decides about the snapshots of others.
		$all = $this->browser( new RetentionPolicy( 2 ) )->snapshots( SnapshotBrowser::SCOPE_REPOSITORY );
		self::assertSame( [ $newest, $middle, $oldest, $plain, $other ], array_column( $all, 'id' ) );
		self::assertSame( [ null, null, null, null, null ], array_column( $all, 'retention' ) );
		// A preview only reads: every snapshot is still there, and no lock is left.
		self::assertSame( $before, $this->backend->objects['snapshots'] );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_a_site_without_a_policy_gets_no_preview(): void {
		$tree = $this->tree( [] );
		$this->snapshot( $tree );
		$this->snapshot( $tree, time: '2026-01-02T00:00:00Z' );

		self::assertSame( [ null, null ], array_column( $this->browser()->snapshots(), 'retention' ) );
		// A policy without a rule would remove everything, so it is never applied and previews nothing.
		self::assertSame( [ null, null ], array_column( $this->browser( new RetentionPolicy() )->snapshots(), 'retention' ) );
	}

	public function test_the_preview_counts_keep_within_back_from_the_browsers_clock(): void {
		$tree   = $this->tree( [] );
		$oldest = $this->snapshot( $tree, time: '2025-12-20T00:00:00Z' );
		$recent = $this->snapshot( $tree, time: '2025-12-31T12:00:00Z' );
		// For the clock, at the start of 2026, this snapshot lies in the future: the day is
		// counted back from the one before it, which is kept with it.
		$future = $this->snapshot( $tree, time: '2026-06-01T00:00:00Z' );

		$preview = self::preview( $this->browser( new RetentionPolicy( within: Duration::parse( '1d' ) ) )->snapshots() );

		$kept = [ $recent, $future ];
		sort( $kept );
		self::assertSame( $kept, $preview['keep'] );
		self::assertSame( [ $oldest ], $preview['remove'] );
	}

	public function test_a_forget_removes_exactly_what_the_preview_announced(): void {
		$tree = $this->tree( [] );
		for ( $day = 1; $day <= 12; $day++ ) {
			$this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:' . $day ], time: sprintf( '2025-12-%02dT03:00:00Z', $day ) );
			$this->snapshot( $tree, [ ...self::SITE_TAGS, 'run:late-' . $day ], time: sprintf( '2025-12-%02dT15:00:00Z', $day ) );
		}
		$foreign = [
			$this->snapshot( $tree, self::SITE_TAGS, 'elsewhere.example.com', '2025-11-01T00:00:00Z' ),
			$this->snapshot( $tree, [ 'backups-for-restic', 'site:ffffffffffff' ], time: '2025-11-02T00:00:00Z' ),
			$this->snapshot( $tree, [], time: '2025-11-03T00:00:00Z' ),
		];
		sort( $foreign );
		$policy  = new RetentionPolicy( 1, 0, 3, 2, within: Duration::parse( '1d' ) );
		$preview = self::preview( $this->browser( $policy )->snapshots() );
		self::assertNotSame( [], $preview['keep'] );
		self::assertNotSame( [], $preview['remove'] );
		self::assertSame( [], $preview['none'] );

		$work = TempDir::create( 'preview' );
		try {
			$run = ForgetRun::start( $this->repository, $work . '/run', new Identity( 'example.com' ), $this->clock, new SystemRandom(), $policy, self::SITE_TAGS );
			while ( ! $run->advance( INF ) ) {
				continue;
			}
		} finally {
			TempDir::remove( $work );
		}

		$left = array_keys( $this->backend->objects['snapshots'] );
		sort( $left );
		$expected = [ ...$preview['keep'], ...$foreign ];
		sort( $expected );
		self::assertSame( $expected, $left );
		self::assertSame( count( $preview['remove'] ), $run->result()['removed'] );
	}

	public function test_an_unknown_scope_is_refused_before_the_repository_is_asked(): void {
		$requests = $this->backend->requests;

		self::assertSame( [ 'invalid_snapshot_query', 400 ], self::refused( fn () => $this->browser()->snapshots( 'everything' ) ) );
		self::assertSame( $requests, $this->backend->requests );
	}

	public function test_a_snapshot_is_answered_with_its_manifest(): void {
		$root = $this->tree( [ new Node( 'spool', NodeType::Dir, subtree: $this->manifest_tree() ) ] );
		$id   = $this->snapshot( $root, [ ...self::SITE_TAGS, Manifest::tag( '/spool/manifest.json' ) ] );

		$answer = $this->browser()->snapshot( $id );

		self::assertSame( $id, $answer['id'] );
		self::assertSame( '2026-01-01T00:00:00Z', $answer['time'] );
		self::assertSame( 'example.com', $answer['hostname'] );
		self::assertSame( [ '/old/site' ], $answer['paths'] );
		self::assertSame( $root->hex(), $answer['tree'] );
		self::assertSame( 'found', $answer['manifest_state'] );
		self::assertSame(
			[
				'path'  => '/spool/manifest.json',
				'token' => SnapshotBrowser::token( '/spool/manifest.json' ),
				'data'  => self::manifest_data(),
			],
			$answer['manifest']
		);
	}

	public function test_a_snapshot_without_a_manifest_is_answered_with_null(): void {
		$id = $this->snapshot( $this->tree( [ new Node( 'file', NodeType::File, content: [] ) ] ), [] );

		$answer = $this->browser()->snapshot( $id );

		self::assertNull( $answer['manifest'] );
		self::assertSame( 'none', $answer['manifest_state'] );
		self::assertSame( [], $answer['tags'] );
	}

	public function test_a_manifest_that_cannot_be_read_does_not_hide_the_snapshot(): void {
		$root = $this->tree( [ new Node( 'spool', NodeType::Dir, subtree: $this->manifest_tree( '{"format":"something-else/9"}' ) ) ] );
		$id   = $this->snapshot( $root, [ Manifest::tag( '/spool/manifest.json' ) ] );

		$answer = $this->browser()->snapshot( $id );

		self::assertSame( $id, $answer['id'] );
		self::assertNull( $answer['manifest'] );
		self::assertSame( 'unreadable', $answer['manifest_state'] );
	}

	public function test_a_directory_lists_files_directories_links_and_special_files(): void {
		$leaf = $this->tree( [] );
		$root = $this->tree(
			[
				new Node( 'uploads', NodeType::Dir, FileMode::DIR | 0755, '2026-01-02T03:04:05.123456789Z', subtree: $leaf ),
				new Node( 'index.php', NodeType::File, 0644, '2026-01-02T03:04:05Z', size: 405, content: [] ),
				new Node( 'current', NodeType::Symlink, FileMode::SYMLINK | 0777, link_target: 'releases/42' ),
				new Node( 'queue', NodeType::Fifo, FileMode::NAMED_PIPE | 0600 ),
				new Node( 'socket', NodeType::Socket ),
				new Node( 'tool', NodeType::File, FileMode::SETUID | 0755, 'yesterday', size: 7, content: [], error: 'read failed' ),
			]
		);

		$page    = $this->browser()->directory( $this->snapshot( $root ) );
		$entries = array_column( $page['entries'], null, 'name' );

		self::assertSame( [ 'current', 'index.php', 'queue', 'socket', 'tool', 'uploads' ], array_keys( $entries ), 'The order of the snapshot is kept.' );
		self::assertSame(
			[
				'name'       => 'index.php',
				'exact'      => true,
				'token'      => SnapshotBrowser::token( '/index.php' ),
				'type'       => 'file',
				'size'       => 405,
				'mtime'      => '2026-01-02T03:04:05Z',
				'mode'       => '0644',
				'target'     => null,
				'incomplete' => false,
			],
			$entries['index.php']
		);
		self::assertSame( [ 'dir', null, '0755', '2026-01-02T03:04:05.123456789Z' ], [ $entries['uploads']['type'], $entries['uploads']['size'], $entries['uploads']['mode'], $entries['uploads']['mtime'] ] );
		self::assertSame( [ 'symlink', 'releases/42', null ], [ $entries['current']['type'], $entries['current']['target'], $entries['current']['size'] ] );
		self::assertSame( [ 'other', null, '0600' ], [ $entries['queue']['type'], $entries['queue']['target'], $entries['queue']['mode'] ] );
		self::assertSame( 'other', $entries['socket']['type'] );
		self::assertSame( [ 7, null, '4755', true ], [ $entries['tool']['size'], $entries['tool']['mtime'], $entries['tool']['mode'], $entries['tool']['incomplete'] ], 'A time that is none is left out, the setuid bit is kept and a read error is marked.' );
		self::assertSame( [ '/', true, SnapshotBrowser::token( '/' ), null ], [ $page['path'], $page['exact'], $page['token'], $page['parent'] ] );
	}

	public function test_a_name_that_is_not_utf8_is_shown_lossy_and_addressed_by_its_token(): void {
		$inner = $this->tree( [ new Node( 'inside.txt', NodeType::File, content: [] ) ] );
		$root  = $this->tree(
			[
				new Node( "caf\xe9", NodeType::Dir, subtree: $inner ),
				new Node( "caf\u{e9}", NodeType::File, content: [] ),
				new Node( "\xff\xfe", NodeType::Symlink, link_target: "target\xc0" ),
			]
		);
		$id    = $this->snapshot( $root );

		$page    = $this->browser()->directory( $id, '/' );
		$entries = $page['entries'];

		self::assertNotFalse( json_encode( $page ), 'Every field is valid UTF-8.' );
		self::assertSame( [ "caf\u{e9}", "caf\u{fffd}", "\u{fffd}\u{fffd}" ], array_column( $entries, 'name' ) );
		self::assertSame( [ true, false, false ], array_column( $entries, 'exact' ) );
		self::assertSame( "target\u{fffd}", $entries[2]['target'] );
		self::assertSame( "/caf\xe9", SnapshotBrowser::path( $entries[1]['token'] ), 'The token holds the stored bytes.' );

		$below = $this->browser()->directory( $id, $entries[1]['token'] );
		self::assertSame( [ 'inside.txt' ], array_column( $below['entries'], 'name' ) );
		self::assertSame( [ "/caf\u{fffd}", false, $entries[1]['token'], SnapshotBrowser::token( '/' ) ], [ $below['path'], $below['exact'], $below['token'], $below['parent'] ] );
		self::assertSame( "/caf\xe9/inside.txt", SnapshotBrowser::path( $below['entries'][0]['token'] ) );
	}

	public function test_a_long_name_with_one_invalid_byte_gets_one_replacement_character(): void {
		$ascii = str_repeat( 'a', 100000 );
		$wide  = str_repeat( "\u{e9}\u{20ac}", 20000 );
		$root  = $this->tree(
			[
				new Node( $ascii . "\xff", NodeType::Symlink, link_target: $wide . "\xff" . $wide ),
			]
		);

		$entry = $this->browser()->directory( $this->snapshot( $root ), '/' )['entries'][0];

		self::assertSame( $ascii . "\u{fffd}", $entry['name'], 'Only the byte that belongs to no valid sequence is replaced.' );
		self::assertSame( $wide . "\u{fffd}" . $wide, $entry['target'] );
	}

	public function test_a_line_is_printed_for_an_entry_beyond_the_limits_of_a_path(): void {
		$entry = [
			'name'       => 'child',
			'exact'      => true,
			'token'      => SnapshotBrowser::token( str_repeat( '/d', 256 ) . '/child' ),
			'type'       => 'file',
			'size'       => 1,
			'mtime'      => null,
			'mode'       => '0644',
			'target'     => null,
			'incomplete' => false,
		];
		$long  = [ 'token' => SnapshotBrowser::token( '/' . str_repeat( 'x', 4090 ) . '/child-name' ) ] + $entry;

		self::assertSame( [ 'invalid_snapshot_path', 400 ], self::refused( static fn () => SnapshotBrowser::path( $entry['token'] ) ), 'The entry itself cannot be opened.' );
		self::assertStringEndsWith( '  child', SnapshotBrowser::line( $entry ) );
		self::assertStringEndsWith( '  child-name', SnapshotBrowser::line( $long ) );
	}

	public function test_a_full_id_is_checked_without_a_repository(): void {
		$id = str_repeat( 'ab', 32 );
		self::assertSame( $id, SnapshotBrowser::full_id( $id ) );
		foreach ( [ '', 'latest', substr( $id, 1 ), strtoupper( $id ), $id . 'a' ] as $given ) {
			self::assertSame( [ 'invalid_snapshot_id', 400 ], self::refused( static fn () => SnapshotBrowser::full_id( $given ) ), $given );
		}
	}

	public function test_a_path_and_its_token_name_the_same_directory(): void {
		$deep = $this->tree( [ new Node( 'file', NodeType::File, content: [] ) ] );
		$mid  = $this->tree( [ new Node( 'b', NodeType::Dir, subtree: $deep ) ] );
		$id   = $this->snapshot( $this->tree( [ new Node( 'a', NodeType::Dir, subtree: $mid ) ] ) );

		$by_path  = $this->browser()->directory( $id, '/a/b/' );
		$by_token = $this->browser()->directory( $id, SnapshotBrowser::token( '/a/b' ) );

		self::assertSame( $by_path, $by_token );
		self::assertSame( [ '/a/b', SnapshotBrowser::token( '/a/b' ), SnapshotBrowser::token( '/a' ) ], [ $by_path['path'], $by_path['token'], $by_path['parent'] ] );
		self::assertSame( SnapshotBrowser::token( '/' ), $this->browser()->directory( $id, '/a' )['parent'] );
	}

	/** @return iterable<string, array{int, int|null, list<string>, int, int|null}> */
	public static function pages(): iterable {
		yield 'the default page' => [ 0, SnapshotBrowser::DEFAULT_LIMIT, [ 'n0', 'n1', 'n2', 'n3', 'n4' ], SnapshotBrowser::DEFAULT_LIMIT, null ];
		yield 'the first of several' => [ 0, 2, [ 'n0', 'n1' ], 2, 2 ];
		yield 'the middle' => [ 2, 2, [ 'n2', 'n3' ], 2, 4 ];
		yield 'the last, partly filled' => [ 4, 2, [ 'n4' ], 2, null ];
		yield 'exactly to the end' => [ 3, 2, [ 'n3', 'n4' ], 2, null ];
		yield 'at the end' => [ 5, 2, [], 2, null ];
		yield 'behind the end' => [ 99, 2, [], 2, null ];
		yield 'a negative offset' => [ -3, 1, [ 'n0' ], 1, 1 ];
		yield 'a limit of zero' => [ 0, 0, [ 'n0' ], 1, 1 ];
		yield 'a limit above the maximum' => [ 0, SnapshotBrowser::MAX_LIMIT + 1, [ 'n0', 'n1', 'n2', 'n3', 'n4' ], SnapshotBrowser::MAX_LIMIT, null ];
		yield 'everything for the command line' => [ 0, null, [ 'n0', 'n1', 'n2', 'n3', 'n4' ], 5, null ];
	}

	/** @param list<string> $names */
	#[DataProvider( 'pages' )]
	public function test_a_directory_is_answered_in_pages( int $offset, ?int $limit, array $names, int $applied, ?int $next ): void {
		$nodes = [];
		for ( $i = 0; $i < 5; ++$i ) {
			$nodes[] = new Node( 'n' . $i, NodeType::File, content: [] );
		}
		$id = $this->snapshot( $this->tree( $nodes ) );

		$page = $this->browser()->directory( $id, '/', $offset, $limit );

		self::assertSame( $names, array_column( $page['entries'], 'name' ) );
		self::assertSame( [ 5, max( 0, $offset ), $applied, $next ], [ $page['total'], $page['offset'], $page['limit'], $page['next'] ] );
	}

	public function test_a_page_never_holds_more_than_the_maximum(): void {
		$nodes = [];
		for ( $i = 0; $i < SnapshotBrowser::MAX_LIMIT + 2; ++$i ) {
			$nodes[] = new Node( sprintf( 'n%05d', $i ), NodeType::File, content: [] );
		}
		$id = $this->snapshot( $this->tree( $nodes ) );

		$page = $this->browser()->directory( $id, '/', 0, PHP_INT_MAX );
		$rest = $this->browser()->directory( $id, '/', (int) $page['next'], PHP_INT_MAX );

		self::assertCount( SnapshotBrowser::MAX_LIMIT, $page['entries'] );
		self::assertSame( [ SnapshotBrowser::MAX_LIMIT + 2, SnapshotBrowser::MAX_LIMIT ], [ $page['total'], $page['next'] ] );
		self::assertSame( [ 'n01000', 'n01001' ], array_column( $rest['entries'], 'name' ) );
		self::assertNull( $rest['next'] );
	}

	public function test_the_paging_of_a_request_is_parsed_and_bounded(): void {
		self::assertSame( [ 0, SnapshotBrowser::DEFAULT_LIMIT ], SnapshotBrowser::paging( null, null ) );
		self::assertSame( [ 0, SnapshotBrowser::DEFAULT_LIMIT ], SnapshotBrowser::paging( '', '' ) );
		self::assertSame( [ 40, 20 ], SnapshotBrowser::paging( '40', '20' ) );
		self::assertSame( [ 7, SnapshotBrowser::MAX_LIMIT ], SnapshotBrowser::paging( 7, '999999999' ) );
		foreach ( [ [ '-1', '5' ], [ '1.5', '5' ], [ 'x', '5' ], [ '0', '0' ], [ '0', 'many' ], [ [ 1 ], '5' ], [ '0', '1234567890' ], [ -1, 5 ] ] as [ $offset, $limit ] ) {
			self::assertSame( [ 'invalid_snapshot_query', 400 ], self::refused( static fn () => SnapshotBrowser::paging( $offset, $limit ) ) );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function malformed_paths(): iterable {
		$paths = [
			'empty'               => '',
			'relative'            => 'wp-content',
			'a parent component'  => '/a/../b',
			'an empty component'  => '/a//b',
			'a NUL byte'          => "/a\0b",
			'too long'            => '/' . str_repeat( 'x', 4096 ),
			'a token of nonsense' => 'b64:!!!',
			'a token of relative' => SnapshotBrowser::token( 'relative' ),
			'a token with dots'   => SnapshotBrowser::token( '/a/./b' ),
			'a token too long'    => 'b64:' . str_repeat( 'A', 6000 ),
		];
		foreach ( $paths as $name => $path ) {
			yield $name => [ $path ];
		}
	}

	#[DataProvider( 'malformed_paths' )]
	public function test_a_malformed_path_is_refused_before_the_repository_is_asked( string $path ): void {
		$id       = $this->snapshot( $this->tree( [] ) );
		$requests = $this->backend->requests;

		self::assertSame( [ 'invalid_snapshot_path', 400 ], self::refused( fn () => $this->browser()->directory( $id, $path ) ) );
		self::assertSame( $requests, $this->backend->requests );
	}

	/** @return iterable<string, array{string}> */
	public static function malformed_ids(): iterable {
		foreach ( [ '', 'latest', str_repeat( 'a', 63 ), str_repeat( 'a', 65 ), str_repeat( 'A', 64 ), str_repeat( 'g', 64 ), '../' . str_repeat( 'a', 61 ) ] as $i => $id ) {
			yield (string) $i => [ $id ];
		}
	}

	#[DataProvider( 'malformed_ids' )]
	public function test_a_malformed_snapshot_id_is_refused_before_the_repository_is_asked( string $id ): void {
		$requests = $this->backend->requests;

		self::assertSame( [ 'invalid_snapshot_id', 400 ], self::refused( fn () => $this->browser()->snapshot( $id ) ) );
		self::assertSame( [ 'invalid_snapshot_id', 400 ], self::refused( fn () => $this->browser()->directory( $id ) ) );
		self::assertSame( $requests, $this->backend->requests );
	}

	public function test_an_unknown_snapshot_is_not_found(): void {
		$this->snapshot( $this->tree( [] ) );
		$unknown = str_repeat( 'ab', 32 );

		self::assertSame( [ 'snapshot_not_found', 404 ], self::refused( fn () => $this->browser()->snapshot( $unknown ) ) );
		self::assertSame( [ 'snapshot_not_found', 404 ], self::refused( fn () => $this->browser()->directory( $unknown ) ) );
		self::assertSame( [], $this->backend->objects['locks'] );
	}

	public function test_a_path_that_is_absent_or_no_directory_is_not_found(): void {
		$root = $this->tree(
			[
				new Node( 'dir', NodeType::Dir, subtree: $this->tree( [] ) ),
				new Node( 'file', NodeType::File, content: [] ),
				new Node( 'link', NodeType::Symlink, link_target: '/dir' ),
			]
		);
		$id   = $this->snapshot( $root );

		foreach ( [ '/absent', '/file', '/link', '/dir/absent', SnapshotBrowser::token( '/file' ) ] as $path ) {
			self::assertSame( [ 'snapshot_path_not_found', 404 ], self::refused( fn () => $this->browser()->directory( $id, $path ) ), $path );
		}
		self::assertSame( [], $this->browser()->directory( $id, '/dir' )['entries'] );
	}

	public function test_an_exclusively_locked_repository_is_a_conflict(): void {
		$id      = $this->snapshot( $this->tree( [] ) );
		$locking = new Locking( $this->repository, new Identity( 'maintenance.example.com' ), $this->clock, 7 );
		$held    = $locking->acquire( true );
		try {
			self::assertSame( [ 'repository_locked', 409 ], self::refused( fn () => $this->browser()->snapshots() ) );
			self::assertSame( [ 'repository_locked', 409 ], self::refused( fn () => $this->browser()->snapshot( $id ) ) );
			self::assertSame( [ 'repository_locked', 409 ], self::refused( fn () => $this->browser()->directory( $id ) ) );
		} finally {
			$locking->release( $held );
		}
	}

	public function test_other_errors_keep_their_code_and_are_answers_of_the_repository(): void {
		self::assertSame( [ 'repository_locked', 409 ], SnapshotBrowser::failure( new LockConflict( 'locked' ) ) );
		self::assertSame( [ 'repository_missing', 502 ], SnapshotBrowser::failure( new NotFound( 'gone' ) ) );
		self::assertSame( [ 'repository_unreachable', 502 ], SnapshotBrowser::failure( new BackendException( 'down' ) ) );
		self::assertSame( [ 'repository_damaged', 502 ], SnapshotBrowser::failure( new FormatException( 'broken', [ 'code' => 'snapshot_not_found' ] ) ), 'Only a refusal of the browser carries one of its codes.' );
		self::assertSame( [ 'configuration', 502 ], SnapshotBrowser::failure( new InvalidArgument( 'other', [ 'code' => 'something_else' ] ) ) );
	}

	public function test_a_snapshot_whose_tree_is_missing_is_not_reported_as_an_unknown_snapshot(): void {
		$id = $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', Id::of( 'a tree that no pack holds' ), [ '/' ] ) )->to_json() )->hex();

		[ $code, $status ] = self::refused( fn () => $this->browser()->directory( $id ) );

		self::assertNotSame( 'snapshot_not_found', $code );
		self::assertSame( 502, $status );
	}

	public function test_a_shortened_id_is_resolved_when_exactly_one_snapshot_starts_with_it(): void {
		$tree = $this->tree( [] );
		$ids  = [];
		for ( $i = 0; $i < 3; ++$i ) {
			$ids[] = $this->snapshot( $tree, [ 'run:' . $i ] );
		}

		self::assertSame( $ids[0], $this->browser()->resolve( $ids[0] ) );
		self::assertSame( $ids[1], $this->browser()->resolve( substr( $ids[1], 0, 8 ) ) );
		self::assertSame( $ids[2], $this->browser()->resolve( substr( $ids[2], 0, 40 ) ) );
		self::assertSame( [ 'snapshot_not_found', 404 ], self::refused( fn () => $this->browser()->resolve( '0123456789abcdef' === substr( $ids[0], 0, 16 ) ? 'fedcba9876543210' : '0123456789abcdef' ) ) );
		foreach ( [ '', substr( $ids[0], 0, 7 ), 'latest', strtoupper( substr( $ids[0], 0, 12 ) ), str_repeat( 'a', 65 ) ] as $given ) {
			self::assertSame( [ 'invalid_snapshot_id', 400 ], self::refused( fn () => $this->browser()->resolve( $given ) ), $given );
		}
	}

	public function test_a_line_for_the_terminal_quotes_names_that_could_control_it(): void {
		$inner = $this->tree( [] );
		$root  = $this->tree(
			[
				new Node( 'uploads', NodeType::Dir, FileMode::DIR | 0755, '2026-01-02T03:04:05Z', subtree: $inner ),
				new Node( 'index.php', NodeType::File, 0644, '2026-01-02T03:04:05Z', size: 405, content: [] ),
				new Node( 'current', NodeType::Symlink, 0777, '2026-01-02T03:04:05Z', link_target: "releases/\x1b[2J" ),
				new Node( "evil\x1b[31m\xff", NodeType::File, 0600, 'never', content: [] ),
				new Node( 'queue', NodeType::Fifo, 0600, '2026-01-02T03:04:05Z' ),
				new Node( "break\n", NodeType::File, 0640, '2026-01-02T03:04:05Z', content: [] ),
			]
		);
		$lines = [];
		foreach ( $this->browser()->directory( $this->snapshot( $root ), '/', 0, null )['entries'] as $entry ) {
			$lines[ $entry['type'] . ' ' . $entry['mode'] ] = SnapshotBrowser::line( $entry );
		}

		self::assertSame( 'd 0755            -  2026-01-02T03:04:05Z  uploads/', $lines['dir 0755'] );
		self::assertSame( '- 0644          405  2026-01-02T03:04:05Z  index.php', $lines['file 0644'] );
		self::assertSame( 'l 0777            -  2026-01-02T03:04:05Z  current -> "releases/\x1b[2J"', $lines['symlink 0777'] );
		self::assertSame( '- 0600            0  -                     "evil\x1b[31m\xff"', $lines['file 0600'] );
		self::assertSame( '? 0600            -  2026-01-02T03:04:05Z  queue', $lines['other 0600'] );
		self::assertSame( '- 0640            0  2026-01-02T03:04:05Z  "break\n"', $lines['file 0640'], 'A line break at the end of a name is escaped too.' );
		foreach ( $lines as $line ) {
			self::assertSame( 0, preg_match( '/[\x00-\x1f\x7f-\xff]/', $line ), 'No control character and no raw byte reaches the terminal.' );
		}
	}
}
