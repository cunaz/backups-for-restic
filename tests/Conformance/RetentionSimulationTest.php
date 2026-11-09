<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\ForgetRun;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Milestone 2 acceptance: seven simulated days of scheduled backups, each followed by forget
 * as the heartbeat runs it, and the weekly prune leave exactly the snapshots that restic's
 * forget keeps with the same policy, and a repository that restic checks without errors and
 * in which it finds no unused pack.
 */
final class RetentionSimulationTest extends TestCase {

	private const string PASSWORD = 'retention password';

	private const string TAG = 'backups-for-restic';

	private ?RestServer $server = null;

	/** @var list<string> */
	private array $cleanup = [];

	protected function setUp(): void {
		if ( ! RestServer::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh to install restic and rest-server.' );
		}
		$this->server = new RestServer();
	}

	protected function tearDown(): void {
		$this->server?->stop();
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function work_dir(): string {
		$this->cleanup[] = $path = TempDir::create( 'week' );
		return $path . '/run';
	}

	public function test_seven_days_of_scheduled_runs_keep_what_restic_keeps(): void {
		self::assertNotNull( $this->server );
		$this->cleanup[] = $site = TempDir::create( 'week-site' );
		TempDir::fixture( $site, 1048576 );
		$identity   = new Identity( 'example.com', 'www-data' );
		$random     = new SystemRandom();
		$repository = Repository::initialize( $this->server->backend( 'week' ), self::PASSWORD, $identity, $random, new ScryptParameters( 1024, 8, 1 ) );
		$cli        = new ResticCli( $this->server, 'week', self::PASSWORD );
		$policy     = new RetentionPolicy( 2, 0, 3, 1, 0, 0, Duration::parse( '1d' ) );
		$clock      = new FrozenClock( (int) strtotime( '2026-11-02 03:17:00 UTC' ) );
		$removed    = 0;

		// Twice a day for a week, as the schedule "twice_daily" runs it.
		for ( $run = 0; $run < 14; $run++ ) {
			file_put_contents( $site . '/changing.txt', 'state of run ' . $run );
			$backup = BackupRun::start( $repository, new BackupPlan( [ $site ], [ self::TAG ] ), $this->work_dir(), $identity, $clock, null, 262144 );
			while ( ! $backup->advance( INF ) ) {
				continue;
			}
			$clock->advance( 600 );
			$forget = ForgetRun::start( $repository, $this->work_dir(), $identity, $clock, $random, $policy, [ self::TAG ] );
			while ( ! $forget->advance( INF ) ) {
				continue;
			}
			$removed += $forget->result()['removed'];
			$clock->advance( 12 * 3600 - 600 );
		}
		self::assertGreaterThan( 0, $removed, 'The week was long enough for the policy to remove snapshots.' );

		$prune = PruneRun::start( $repository, $this->work_dir(), $identity, $clock, $random );
		while ( ! $prune->advance( INF ) ) {
			continue;
		}
		self::assertGreaterThan( 0, $prune->result()['packs_deleted'] );

		$ours = array_keys( $repository->snapshots() );
		sort( $ours );
		$groups = json_decode(
			$cli->must( [ 'forget', '--dry-run', '--json', '--host', 'example.com', '--tag', self::TAG, '--group-by', 'host,tags', '--keep-last', '2', '--keep-daily', '3', '--keep-weekly', '1', '--keep-within', '1d' ] ),
			true
		);
		self::assertIsArray( $groups );
		self::assertCount( 1, $groups );
		$keep = array_map( static fn ( array $snapshot ): string => (string) $snapshot['id'], $groups[0]['keep'] ?? [] );
		sort( $keep );
		self::assertSame( $ours, $keep, 'restic keeps exactly the snapshots the plugin kept.' );
		self::assertEmpty( $groups[0]['remove'] ?? [], 'restic would remove nothing more.' );

		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		self::assertMatchesRegularExpression( '/^unused packs:\s+0$/m', $cli->must( [ 'prune', '--dry-run', '--max-unused', 'unlimited', '-v' ] ) );
	}
}
