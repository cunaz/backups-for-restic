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
 * as the heartbeat runs it, and the weekly prune. Before every forget, restic's forget is asked
 * (dry run, same policy) which snapshots it would keep; the plugin must keep exactly those. At
 * the end restic checks the repository without errors and finds no unused pack.
 *
 * The snapshots carry the tags a backup job gives them: the plugin's tag, the site's tag and
 * the tag of the job, which is different on every snapshot.
 */
final class RetentionSimulationTest extends TestCase {

	private const string PASSWORD = 'retention password';

	/** Tags every snapshot of the site carries; retention selects by them. */
	private const array TAGS = [ 'backups-for-restic', 'site:1a2b3c4d5e6f' ];

	private const string HOST = 'example.com';

	/** The policy, used for the plugin and for restic's flags alike. */
	private const array POLICY = [
		'last'   => 2,
		'daily'  => 3,
		'weekly' => 1,
		'within' => '1d',
	];

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

	private static function policy(): RetentionPolicy {
		return new RetentionPolicy( self::POLICY['last'], 0, self::POLICY['daily'], self::POLICY['weekly'], 0, 0, Duration::parse( self::POLICY['within'] ) );
	}

	/**
	 * The snapshots that restic's forget would keep, with the same scope and policy.
	 *
	 * @return list<string> Snapshot IDs, sorted.
	 */
	private static function restic_keeps( ResticCli $cli ): array {
		$groups = json_decode(
			$cli->must(
				[
					'forget',
					'--dry-run',
					'--json',
					'--host',
					self::HOST,
					'--tag',
					implode( ',', self::TAGS ),
					'--group-by',
					'host',
					'--keep-last',
					(string) self::POLICY['last'],
					'--keep-daily',
					(string) self::POLICY['daily'],
					'--keep-weekly',
					(string) self::POLICY['weekly'],
					'--keep-within',
					self::POLICY['within'],
				]
			),
			true
		);
		self::assertIsArray( $groups );
		self::assertCount( 1, $groups );
		$keep = array_map( static fn ( array $snapshot ): string => (string) $snapshot['id'], $groups[0]['keep'] ?? [] );
		sort( $keep );
		return $keep;
	}

	public function test_seven_days_of_scheduled_runs_keep_what_restic_keeps(): void {
		self::assertNotNull( $this->server );
		$this->cleanup[] = $site = TempDir::create( 'week-site' );
		TempDir::fixture( $site, 1048576 );
		$identity   = new Identity( self::HOST, 'www-data' );
		$random     = new SystemRandom();
		$repository = Repository::initialize( $this->server->backend( 'week' ), self::PASSWORD, $identity, $random, new ScryptParameters( 1024, 8, 1 ) );
		$cli        = new ResticCli( $this->server, 'week', self::PASSWORD );
		$policy     = self::policy();
		$clock      = new FrozenClock( (int) strtotime( '2026-11-02 03:17:00 UTC' ) );
		$removed    = 0;

		// Twice a day for a week, as the schedule "twice_daily" runs it.
		for ( $run = 0; $run < 14; $run++ ) {
			file_put_contents( $site . '/changing.txt', 'state of run ' . $run );
			$tags   = [ ...self::TAGS, sprintf( 'run:20261102T031700Z-%06x', $run ) ];
			$backup = BackupRun::start( $repository, new BackupPlan( [ $site ], $tags ), $this->work_dir(), $identity, $clock, null, 262144 );
			while ( ! $backup->advance( INF ) ) {
				continue;
			}
			$clock->advance( 600 );
			$expected = self::restic_keeps( $cli );
			$forget   = ForgetRun::start( $repository, $this->work_dir(), $identity, $clock, $random, $policy, self::TAGS );
			while ( ! $forget->advance( INF ) ) {
				continue;
			}
			$removed += $forget->result()['removed'];
			$kept     = array_keys( $repository->snapshots() );
			sort( $kept );
			self::assertSame( $expected, $kept, 'Run ' . $run . ': the plugin keeps what restic keeps.' );
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
		self::assertSame( $ours, self::restic_keeps( $cli ), 'After the prune, restic would remove nothing more.' );

		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		self::assertMatchesRegularExpression( '/^unused packs:\s+0$/m', $cli->must( [ 'prune', '--dry-run', '--max-unused', 'unlimited', '-v' ] ) );
	}
}
