<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Forget\ForgetRun;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Forget removes only this site's snapshots, exactly as the policy decides (ADR 0010).
 */
final class ForgetRunTest extends TestCase {

	/** Tags every snapshot of this site carries; forget selects by them. */
	private const array TAGS = [ 'backups-for-restic', 'site:1a2b3c4d5e6f' ];

	private InMemoryBackend $backend;

	private Repository $repository;

	private Identity $identity;

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->backend    = new InMemoryBackend();
		$this->repository = Repository::initialize( $this->backend, 'forget password', $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function work_dir(): string {
		$this->cleanup[] = $path = TempDir::create( 'forget' );
		return $path . '/run';
	}

	/**
	 * Writes a snapshot directly; forget reads only its metadata. Like a real backup, the
	 * snapshot carries the site's tags and the tag of the job that wrote it, which no other
	 * snapshot has.
	 *
	 * @param list<string>|null $tags Tags, or null for this site's.
	 */
	private function snapshot( int $days_ago, string $hostname = 'example.com', ?array $tags = null ): string {
		$tags ??= [ ...self::TAGS, sprintf( 'run:20260101T000000Z-%06x', $days_ago ) ];
		$time   = Rfc3339::format( 1767225600 - $days_ago * 86400 + 3600 );
		return $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( $time, Id::of( 'tree ' . $days_ago . $hostname ), [ '/var/www' ], $hostname, 'www-data', 0, 0, [], $tags ) )->to_json() )->hex();
	}

	private function forget( RetentionPolicy $policy, bool $interrupt = false ): ForgetRun {
		$work  = $this->work_dir();
		$run   = ForgetRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), $policy, self::TAGS );
		$ticks = 0;
		while ( ! $run->advance( $interrupt ? 0.0 : INF ) ) {
			if ( $interrupt ) {
				$run = ForgetRun::resume( $this->repository, $work, $this->identity, new SystemClock(), $policy, self::TAGS );
			}
			self::assertLessThan( 1000, ++$ticks );
		}
		return $run;
	}

	/**
	 * @return array{ours: list<string>, foreign: list<string>}
	 */
	private function history(): array {
		$ours = [];
		for ( $day = 0; $day < 10; $day++ ) {
			$ours[] = $this->snapshot( $day );
		}
		$foreign = [
			$this->snapshot( 20, 'laptop' ),
			$this->snapshot( 21, 'example.com', [ 'manual' ] ),
			// Another site with the same host name in the same repository.
			$this->snapshot( 22, 'example.com', [ 'backups-for-restic', 'site:ffffffffffff', 'run:20260101T000000Z-ffffff' ] ),
		];
		return [
			'ours'    => $ours,
			'foreign' => $foreign,
		];
	}

	public function test_keep_within_counts_back_from_the_newest_snapshot_before_the_runs_clock(): void {
		$oldest = $this->snapshot( 3 );
		$middle = $this->snapshot( 1 );
		$newest = $this->snapshot( 0 );
		// For the run's clock, a minute after the middle snapshot, the newest one lies in the
		// future: restic counts the day back from the middle one and keeps both of them.
		$clock = new FrozenClock( 1767225600 - 86400 + 3600 + 60 );
		$run   = ForgetRun::start( $this->repository, $this->work_dir(), $this->identity, $clock, new SystemRandom(), new RetentionPolicy( within: Duration::parse( '1d' ) ), self::TAGS );
		while ( ! $run->advance( INF ) ) {
			continue;
		}

		$left = array_keys( $this->repository->snapshots() );
		sort( $left );
		$kept = [ $middle, $newest ];
		sort( $kept );
		self::assertSame( $kept, $left );
		self::assertNotContains( $oldest, $left );
	}

	public function test_removes_exactly_what_the_policy_decides_for_this_site(): void {
		$history = $this->history();
		$policy  = new RetentionPolicy( 2, 0, 3 );
		$ours    = [];
		foreach ( $history['ours'] as $id ) {
			$ours[ $id ] = Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $id ) ) );
		}
		$expected = Forget::apply( $ours, $policy )['remove'];
		sort( $expected );

		$result = $this->forget( $policy )->result();

		$left = array_keys( $this->backend->list( ObjectType::Snapshots ) );
		self::assertSame( 10, $result['snapshots'] );
		self::assertSame( count( $expected ), $result['removed'] );
		self::assertNotSame( [], $expected );
		foreach ( $expected as $id ) {
			self::assertNotContains( $id, $left );
		}
		foreach ( $history['foreign'] as $id ) {
			self::assertContains( $id, $left, 'Snapshots of other hosts, of other sites or without the tags stay.' );
		}
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_an_interrupted_forget_removes_the_same_snapshots(): void {
		$history = $this->history();
		$policy  = new RetentionPolicy( 1, 0, 0, 0, 0, 0, Duration::parse( '3d' ) );
		$ours    = [];
		foreach ( $history['ours'] as $id ) {
			$ours[ $id ] = Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $id ) ) );
		}
		$expected = Forget::apply( $ours, $policy )['remove'];
		sort( $expected );

		$result = $this->forget( $policy, true )->result();

		self::assertSame( 10, $result['snapshots'] );
		self::assertSame( count( $expected ), $result['removed'] );
		$left = array_keys( $this->backend->list( ObjectType::Snapshots ) );
		self::assertSame( [], array_values( array_intersect( $expected, $left ) ) );
		self::assertCount( 10 + count( $history['foreign'] ) - count( $expected ), $left );
	}

	public function test_refuses_an_empty_policy(): void {
		$this->expectException( InvalidArgument::class );
		ForgetRun::start( $this->repository, $this->work_dir(), $this->identity, new SystemClock(), new SystemRandom(), new RetentionPolicy(), self::TAGS );
	}

	public function test_refuses_an_append_only_repository(): void {
		$this->history();
		$this->backend->append_only = true;
		$objects                    = array_keys( $this->backend->objects );

		try {
			$this->forget( new RetentionPolicy( 1 ) );
			self::fail( 'Forget must refuse.' );
		} catch ( AppendOnlyRefused ) {
			self::assertSame( $objects, array_keys( $this->backend->objects ) );
		}
	}

	public function test_refuses_while_another_client_holds_a_lock(): void {
		$this->history();
		( new Locking( $this->repository, new Identity( 'laptop', 'admin' ), new SystemClock(), 4711 ) )->acquire( false );

		$this->expectException( LockConflict::class );
		$this->forget( new RetentionPolicy( 1 ) );
	}

	public function test_the_tag_of_the_job_that_wrote_a_snapshot_does_not_keep_it(): void {
		// Every snapshot has a tag no other snapshot has. Grouped by all their tags, each would
		// be the newest of its own group and the policy would keep them all.
		$history = $this->history();

		$result = $this->forget( new RetentionPolicy( 2 ) )->result();

		self::assertSame( 8, $result['removed'] );
		$left = array_keys( $this->backend->list( ObjectType::Snapshots ) );
		self::assertCount( 2 + count( $history['foreign'] ), $left );
		self::assertContains( $history['ours'][0], $left, 'The newest snapshot stays.' );
		self::assertContains( $history['ours'][1], $left );
	}

	public function test_a_first_call_that_cannot_list_the_snapshots_keeps_a_lock_the_run_knows(): void {
		$history    = $this->history();
		$fail       = true;
		$backend    = new HookedBackend(
			$this->backend,
			static function ( string $method, ?ObjectType $type ) use ( &$fail ): void {
				if ( $fail && 'list' === $method && ObjectType::Snapshots === $type ) {
					throw new BackendException( 'The connection was reset.' );
				}
			}
		);
		$repository = Repository::open( $backend, $this->repository->master_key(), new SystemRandom() );
		$work       = $this->work_dir();

		$run = ForgetRun::start( $repository, $work, $this->identity, new SystemClock(), new SystemRandom(), new RetentionPolicy( 1 ), self::TAGS );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ), 'Starting takes no lock yet.' );
		try {
			$run->advance( 0.0 );
			self::fail( 'The snapshots cannot be listed.' );
		} catch ( BackendException ) {
			$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
			self::assertIsArray( $state );
			self::assertSame( [ $state['lock'] ], array_keys( $this->backend->list( ObjectType::Locks ) ), 'The checkpoint names the exclusive lock.' );
			self::assertCount( count( $history['ours'] ) + count( $history['foreign'] ), $this->backend->list( ObjectType::Snapshots ), 'Nothing was removed.' );
		}

		$fail = false;
		$run  = ForgetRun::resume( $repository, $work, $this->identity, new SystemClock(), new RetentionPolicy( 1 ), self::TAGS );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		self::assertSame( count( $history['ours'] ) - 1, $run->result()['removed'], 'The run listed the snapshots in its second call and applied the policy.' );
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_forget_recognises_a_lock_it_wrote_but_never_recorded(): void {
		$this->history();
		$work = $this->work_dir();
		ForgetRun::start( $this->repository, $work, $this->identity, new SystemClock(), new SystemRandom(), new RetentionPolicy( 1 ), self::TAGS );
		$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true );
		self::assertIsArray( $state );
		self::assertIsInt( $state['pid'] );
		// The first request wrote its exclusive lock and ended before it recorded the name.
		$orphan = ( new Locking( $this->repository, $this->identity, new SystemClock(), $state['pid'] ) )->acquire( true );

		$run = ForgetRun::resume( $this->repository, $work, $this->identity, new SystemClock(), new RetentionPolicy( 1 ), self::TAGS );
		$run->advance( 0.0 );

		$locks = array_keys( $this->backend->list( ObjectType::Locks ) );
		self::assertCount( 1, $locks, 'Its own exclusive lock did not block the run.' );
		self::assertNotContains( $orphan->hex(), $locks );
		$run->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}

	public function test_a_forget_paused_for_too_long_waits_for_another_clients_lock(): void {
		$this->history();
		$work  = $this->work_dir();
		$clock = new FrozenClock( time() );
		$run   = ForgetRun::start( $this->repository, $work, $this->identity, $clock, new SystemRandom(), new RetentionPolicy( 1 ), self::TAGS );
		$run->advance( 0.0 );

		// The exclusive lock is stale by now, so another client's backup ignored it.
		$clock->advance( Lock::STALE_AFTER_SECONDS + 1 );
		$other  = new Locking( $this->repository, new Identity( 'laptop', 'admin' ), $clock, 4711 );
		$shared = $other->acquire( false );

		try {
			ForgetRun::resume( $this->repository, $work, $this->identity, $clock, new RetentionPolicy( 1 ), self::TAGS )->advance( 0.0 );
			self::fail( 'The forget must wait for the other lock.' );
		} catch ( LockConflict ) {
			self::assertSame( [ $shared->hex() ], array_keys( $this->backend->list( ObjectType::Locks ) ) );
		}
		$other->release( $shared );
		ForgetRun::resume( $this->repository, $work, $this->identity, $clock, new RetentionPolicy( 1 ), self::TAGS )->abort();
		self::assertSame( [], $this->backend->list( ObjectType::Locks ) );
	}
}
