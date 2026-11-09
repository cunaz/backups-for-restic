<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Backup\SitePaths;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobSteps;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Maintenance\MaintenanceServices;
use BackupsForRestic\Jobs\Maintenance\MaintenanceSteps;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\CurlHttpClient;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Forget, prune and check as jobs, without WordPress, in short ticks against a TLS
 * rest-server; and the retention modes of ADR 0010 against an append-only one.
 */
final class MaintenanceJobTest extends TestCase {

	private const string REPOSITORY_PASSWORD = 'maintenance repository password';

	private static ?RestServer $server = null;

	private static ?RestServer $append_only = null;

	/** @var list<string> */
	private array $cleanup = [];

	/** @var list<string> Modes the jobs switched retention to. */
	private array $switched = [];

	private SiteState $state;

	/** Options of the last components, where settings, secrets and the key cache live. */
	private InMemoryOptions $options;

	public static function setUpBeforeClass(): void {
		if ( ! RestServer::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh to install restic and rest-server.' );
		}
		self::$server      = new RestServer();
		self::$append_only = new RestServer( true );
	}

	public static function tearDownAfterClass(): void {
		self::$server?->stop();
		self::$append_only?->stop();
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	/**
	 * Builds the runner as the plugin does, with the given settings.
	 *
	 * @param array<string, mixed> $settings Setting changes.
	 * @param InMemoryOptions|null $options  Options of an earlier request of the same site, or null for a new site.
	 * @return array{Runner, JobStore}
	 */
	private function components( RestServer $server, string $repository, string $site, array $settings, ?InMemoryOptions $options = null ): array {
		$this->options = $options ??= new InMemoryOptions();
		$random        = new SystemRandom();
		$secrets       = new EncryptedOptionStore( $options, new Secret( 'salts' ), $random );
		$secrets->set( SecretName::RepositoryPassword, new Secret( self::REPOSITORY_PASSWORD ) );
		$secrets->set( SecretName::RestPassword, new Secret( RestServer::PASSWORD ) );
		$loaded = ( new SettingsStore( $options ) )->update(
			[
				'repository.server'        => 'rest-server',
				'repository.url'           => $server->url( $repository ),
				'repository.rest_username' => RestServer::USER,
			] + $settings
		);

		$private     = new PrivateDirectory( $site . '/wp-content/' . PrivateDirectory::NAME );
		$store       = new JobStore( $private->subdirectory( 'jobs' ), new Redactor(), $random );
		$this->state = new SiteState( $options );
		$paths       = new SitePaths( $site, $site . '/wp-content', $site . '/wp-content/uploads', 'https://example.com', 'wp_', '7.1.4', '0.1.0-test' );
		$plan        = static fn ( Job $job ): BackupPlan => ( new PlanFactory( $paths, $loaded, $private, [], [], null, $job->id, $job->created_at ) )->plan();
		$access      = new RepositoryAccess( $loaded, $secrets, new CurlHttpClient( $server->certificate ), $random, 'backups-for-restic-tests' );
		$services    = new BackupServices( $loaded, $access, $private, $store, $this->state, new SystemClock(), $random, new Identity( 'example.com', 'www-data' ), $plan );
		$backup      = new BackupSteps( $services );
		$maintenance = new MaintenanceSteps(
			new MaintenanceServices(
				$services,
				function ( string $mode ): void {
					$this->switched[] = $mode;
				}
			),
			$backup
		);
		$private->ensure();
		return [ new Runner( $store, new RunnerLock( $private->path . '/runner.lock' ), new JobSteps( $backup, $maintenance ), new SystemClock(), $random ), $store ];
	}

	/**
	 * Runs one job to its end in short ticks.
	 *
	 * @param array<string, mixed> $data Job data.
	 */
	private function run_job( Runner $runner, JobStore $store, JobType $type, array $data = [] ): Job {
		$job = $store->create( $type, 'manual', time() );
		if ( [] !== $data ) {
			$job->data = $data;
			$store->save( $job );
		}
		for ( $tick = 0; $tick < 5000; $tick++ ) {
			if ( ! $runner->tick( 0.3 )->has_more_work() ) {
				break;
			}
		}
		$finished = $store->get( $job->id );
		self::assertNotNull( $finished );
		self::assertSame( JobState::Succeeded, $finished->state, (string) json_encode( $finished->error?->to_array() ) );
		return $finished;
	}

	/**
	 * Writes snapshots dated days apart; only the middle one holds a large unique file.
	 */
	private function history( Repository $repository, string $site ): void {
		$identity = new Identity( 'example.com', 'www-data' );
		foreach ( [ 10, 5, 0 ] as $days_ago ) {
			if ( 5 === $days_ago ) {
				file_put_contents( $site . '/unique.bin', random_bytes( 6 * 1048576 ) );
			} elseif ( is_file( $site . '/unique.bin' ) ) {
				unlink( $site . '/unique.bin' );
			}
			$this->cleanup[] = $work = TempDir::create( 'history' );
			$run             = BackupRun::start( $repository, new BackupPlan( [ $site ], [ PlanFactory::TAG ] ), $work, $identity, new FrozenClock( time() - $days_ago * 86400 ), null, 4 * 1048576 );
			while ( ! $run->advance( INF ) ) {
				continue;
			}
		}
	}

	private function site(): string {
		$this->cleanup[] = $site = TempDir::create( 'maintenance-site' );
		TempDir::fixture( $site, 1048576 );
		return $site;
	}

	public function test_forget_prune_and_check_jobs_leave_a_repository_restic_accepts(): void {
		self::assertNotNull( self::$server );
		$site       = $this->site();
		$repository = Repository::initialize( self::$server->backend( 'maintenance' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		[ $runner, $store ] = $this->components(
			self::$server,
			'maintenance',
			$site,
			[
				'retention.keep.last'    => 1,
				'retention.keep.daily'   => 0,
				'retention.keep.weekly'  => 0,
				'retention.keep.monthly' => 0,
				'retention.keep.within'  => '1d',
			]
		);

		$forget = $this->run_job( $runner, $store, JobType::Forget );
		self::assertSame( 2, $forget->result['removed'] ?? null );
		self::assertSame( 1, $forget->result['keep'] ?? null );
		self::assertSame( $forget->id, $this->state->get( 'last_forget' )['job'] ?? null );

		$prune = $this->run_job( $runner, $store, JobType::Prune );
		self::assertGreaterThan( 0, $prune->result['packs_deleted'] ?? 0 );
		self::assertGreaterThan( 6 * 1048576, $prune->result['bytes_deleted'] ?? 0, 'The unique file of the forgotten snapshot is gone.' );

		$check = $this->run_job( $runner, $store, JobType::Check, [ 'read_data_subset_percent' => 100 ] );
		self::assertSame( 0, $check->result['errors'] ?? null );
		self::assertGreaterThan( 0, $check->result['packs_read'] ?? 0 );
		self::assertSame( [], $this->state->get( 'check_alert' ) );
		self::assertSame( [], $this->switched );

		$cli = new ResticCli( self::$server, 'maintenance', self::REPOSITORY_PASSWORD );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$snapshots = json_decode( $cli->must( [ 'snapshots', '--json' ] ), true );
		self::assertIsArray( $snapshots );
		self::assertCount( 1, $snapshots );
		self::assertSame( '', trim( $cli->must( [ 'list', 'locks', '--no-lock' ] ) ), 'Every job released its lock.' );
	}

	public function test_a_cancelled_forget_releases_its_lock_after_the_retention_settings_became_unusable(): void {
		self::assertNotNull( self::$server );
		$site       = $this->site();
		$repository = Repository::initialize( self::$server->backend( 'cancelled-forget' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		[ $runner, $store ] = $this->components( self::$server, 'cancelled-forget', $site, [ 'retention.keep.last' => 1 ] );
		$job                = $store->create( JobType::Forget, 'manual', time() );
		$locks              = [];
		for ( $tick = 0; $tick < 200 && [] === $locks; $tick++ ) {
			$runner->tick( 0.0 );
			$locks = $repository->backend()->list( ObjectType::Locks );
		}
		self::assertCount( 1, $locks, 'The forget run holds its lock.' );

		// The administrator clears keep-within, which the policy needs, and cancels the job; the
		// next request builds everything again from the changed settings.
		[ $runner, $store ] = $this->components( self::$server, 'cancelled-forget', $site, [ 'retention.keep.within' => '' ], $this->options );
		$running            = $store->get( $job->id );
		self::assertNotNull( $running );
		$running->cancel_requested = true;
		$store->save( $running );
		$more = true;
		for ( $tick = 0; $tick < 50 && $more; $tick++ ) {
			$more = $runner->tick( 0.3 )->has_more_work();
		}

		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
		self::assertSame( [], $repository->backend()->list( ObjectType::Locks ), 'The lock was released, not left to go stale.' );
	}

	public function test_a_check_with_damage_raises_the_alert(): void {
		self::assertNotNull( self::$server );
		$site       = $this->site();
		$repository = Repository::initialize( self::$server->backend( 'damaged' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		$packs = glob( self::$server->directory . '/data/damaged/data/*/*' );
		self::assertIsArray( $packs );
		unlink( $packs[0] );
		[ $runner, $store ] = $this->components( self::$server, 'damaged', $site, [] );

		$check = $this->run_job( $runner, $store, JobType::Check );

		self::assertGreaterThan( 0, $check->result['errors'] ?? 0 );
		$alert = $this->state->get( 'check_alert' );
		self::assertSame( $check->id, $alert['job'] ?? null );
		self::assertIsArray( $alert['findings'] ?? null );
		self::assertNotSame( [], $alert['findings'] );
	}

	public function test_a_damaged_index_file_does_not_stop_the_check_that_finds_it(): void {
		self::assertNotNull( self::$server );
		$site       = $this->site();
		$repository = Repository::initialize( self::$server->backend( 'damaged-index' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		$indexes = glob( self::$server->directory . '/data/damaged-index/index/*' );
		self::assertIsArray( $indexes );
		self::assertNotSame( [], $indexes );
		file_put_contents( $indexes[0], 'not an encrypted index file' );
		[ $runner, $store ] = $this->components( self::$server, 'damaged-index', $site, [] );

		$check = $this->run_job( $runner, $store, JobType::Check );

		self::assertSame( JobState::Succeeded, $check->state, (string) json_encode( $check->error ) );
		self::assertGreaterThan( 0, $check->result['errors'] ?? 0 );
		$codes = array_column( $this->state->get( 'check_alert' )['findings'] ?? [], 'code' );
		self::assertContains( 'index_unreadable', $codes );
	}

	public function test_an_append_only_server_switches_retention_to_external_without_deleting(): void {
		self::assertNotNull( self::$append_only );
		$site       = $this->site();
		$repository = Repository::initialize( self::$append_only->backend( 'append' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		$before             = $repository->backend()->list( ObjectType::Snapshots );
		[ $runner, $store ] = $this->components( self::$append_only, 'append', $site, [ 'retention.keep.last' => 1 ] );

		$switched = [];
		foreach ( [ JobType::Forget, JobType::Prune ] as $type ) {
			$job = $this->run_job( $runner, $store, $type );
			self::assertSame( 'append_only', $job->result['skipped'] ?? null, $type->value );
			$switched[] = $job->result['retention_switched'] ?? false;
		}
		self::assertSame( [ true, false ], $switched, 'Only the job that switched reports it, for the notification.' );

		self::assertSame( [ 'external' ], $this->switched, 'One switch, however many jobs see the refusal.' );
		self::assertSame( 'external', $this->state->get( 'retention_switch' )['mode'] ?? null );
		self::assertSame( 'append_only', $this->state->get( 'append_only' )['status'] ?? null );
		self::assertSame( $before, $repository->backend()->list( ObjectType::Snapshots ), 'Nothing was deleted.' );
	}

	public function test_external_retention_only_proposes_to_switch_back_when_deletions_work_again(): void {
		self::assertNotNull( self::$server );
		$site       = $this->site();
		$repository = Repository::initialize( self::$server->backend( 'external' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->history( $repository, $site );
		$before             = $repository->backend()->list( ObjectType::Snapshots );
		[ $runner, $store ] = $this->components(
			self::$server,
			'external',
			$site,
			[
				'retention.mode'      => 'external',
				'retention.keep.last' => 1,
			]
		);

		$job = $this->run_job( $runner, $store, JobType::Forget );

		self::assertSame( 'retention_external', $job->result['skipped'] ?? null );
		self::assertSame( 'plugin', $this->state->get( 'retention_proposal' )['mode'] ?? null );
		self::assertSame( [], $this->switched, 'Nothing switches back without an administrator.' );
		self::assertSame( $before, $repository->backend()->list( ObjectType::Snapshots ) );
	}
}
