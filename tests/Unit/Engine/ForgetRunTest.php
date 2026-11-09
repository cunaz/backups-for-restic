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
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Forget removes only this site's snapshots, exactly as the policy decides (ADR 0010).
 */
final class ForgetRunTest extends TestCase {

	private const array TAGS = [ 'backups-for-restic' ];

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
	 * Writes a snapshot directly; forget reads only its metadata.
	 *
	 * @param list<string> $tags Tags.
	 */
	private function snapshot( int $days_ago, string $hostname = 'example.com', array $tags = self::TAGS ): string {
		$time = Rfc3339::format( 1767225600 - $days_ago * 86400 + 3600 );
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
		$foreign = [ $this->snapshot( 20, 'laptop' ), $this->snapshot( 21, 'example.com', [ 'manual' ] ) ];
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
			self::assertContains( $id, $left, 'Snapshots of other hosts or without the tag stay.' );
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
		self::assertCount( 12 - count( $expected ), $left );
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

	public function test_waits_for_another_clients_lock(): void {
		$this->history();
		( new Locking( $this->repository, new Identity( 'laptop', 'admin' ), new SystemClock(), 4711 ) )->acquire( false );

		$this->expectException( LockConflict::class );
		$this->forget( new RetentionPolicy( 1 ) );
	}
}
