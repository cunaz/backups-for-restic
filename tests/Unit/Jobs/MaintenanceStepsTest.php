<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Maintenance\MaintenanceServices;
use BackupsForRestic\Jobs\Maintenance\MaintenanceSteps;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\InMemoryRestServer;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * What a maintenance job remembers once its work is done.
 */
final class MaintenanceStepsTest extends TestCase {

	private const string PASSWORD = 'repository password';

	private string $directory;

	private InMemoryOptions $options;

	private InMemoryRestServer $server;

	private JobStore $jobs;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'maintenance' );
		$this->options   = new InMemoryOptions();
		$this->server    = new InMemoryRestServer();
		$this->jobs      = new JobStore( $this->directory . '/private/jobs', new Redactor(), new SystemRandom() );
		( new SettingsStore( $this->options ) )->update( [ 'repository.url' => 'https://backup.example.net:8000/site/' ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function access(): RepositoryAccess {
		$secrets = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		return new RepositoryAccess( ( new SettingsStore( $this->options ) )->load(), $secrets, $this->server, new SystemRandom(), 'backups-for-restic-tests' );
	}

	private function steps(): MaintenanceSteps {
		$private = new PrivateDirectory( $this->directory . '/private' );
		$private->ensure();
		$services = new BackupServices(
			( new SettingsStore( $this->options ) )->load(),
			$this->access(),
			$private,
			$this->jobs,
			new SiteState( $this->options ),
			new FrozenClock(),
			new SystemRandom(),
			new Identity( 'example.com', 'www-data' ),
			static function ( Job $job ): BackupPlan {
				self::fail( 'A check needs no plan.' );
			}
		);
		return new MaintenanceSteps(
			new MaintenanceServices(
				$services,
				static function ( string $mode ): void {
					self::fail( 'A check does not switch the retention mode.' );
				},
				'site-tag'
			),
			new BackupSteps( $services )
		);
	}

	/**
	 * Runs a step of a check job until it is done.
	 */
	private function run_step( Job $job, string $name ): void {
		for ( $tick = 0; $tick < 100; $tick++ ) {
			if ( $this->steps()->step( JobType::Check, $name )->advance( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) ) {
				return;
			}
		}
		self::fail( 'The step did not end: ' . $name );
	}

	public function test_a_finished_check_is_recorded_without_the_server(): void {
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		Repository::initialize( $this->access()->backend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		// A pack that no index lists: something for the check to report.
		$stray = str_repeat( 'not a pack', 10 );
		$this->server->objects[ '/site/data/' . hash( 'sha256', $stray ) ] = $stray;
		$job = new Job( '20270115T030000Z-abcdef', JobType::Check, 'manual', 1_800_000_000 );
		$this->run_step( $job, 'unlocking' );
		$this->run_step( $job, 'checking' );
		$found = (int) ( $job->result['errors'] ?? 0 ) + (int) ( $job->result['warnings'] ?? 0 );
		self::assertGreaterThan( 0, $found );

		// The plugin is being switched off, and the server does not answer any more. The job
		// is what the next tick reads from its file.
		$this->server->before = static fn (): HttpResponse => self::fail( 'Recording must not need the server.' );
		$job                  = Job::from_array( $job->to_array() );
		$this->run_step( $job, 'recording' );

		$last = ( new SiteState( $this->options ) )->get( 'last_check' );
		self::assertSame( $job->id, $last['job'] ?? null );
		self::assertSame( $found, array_sum( (array) ( $last['counts'] ?? [] ) ), 'The dashboard shows what the check found.' );
		self::assertCount( $found, (array) ( $last['findings'] ?? [] ) );
	}

	#[RunInSeparateProcess]
	public function test_a_memory_refusal_does_not_fall_back_to_a_check_without_the_cache(): void {
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		$repository = Repository::initialize( $this->access()->backend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$job        = new Job( '20270115T030000Z-abcdef', JobType::Check, 'manual', 1_800_000_000 );
		$this->run_step( $job, 'unlocking' );
		gc_mem_caches();
		$previous = ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 8 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This isolated test simulates a host's limit without WordPress.
		self::assertNotFalse( $previous );
		try {
			$this->run_step( $job, 'checking' );
			self::fail( 'The memory refusal must stop the job before it starts a check.' );
		} catch ( IndexTooLarge ) {
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
		} finally {
			ini_set( 'memory_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore the test process's original limit.
		}
	}
}
