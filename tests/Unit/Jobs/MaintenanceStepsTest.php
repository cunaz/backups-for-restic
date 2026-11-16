<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\StorageFailed;
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

	/** @param (\Closure(string): void)|null $switch_retention */
	private function steps( ?\Closure $switch_retention = null ): MaintenanceSteps {
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
				$switch_retention ?? static function ( string $mode ): void {
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
			if ( $this->steps()->step( $job->type, $name )->advance( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) ) {
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
	public function test_an_index_memory_refusal_before_download_still_allows_the_check_to_release_its_lock(): void {
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		$repository = Repository::initialize( $this->access()->backend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$site       = $this->directory . '/site';
		mkdir( $site );
		file_put_contents( $site . '/hello.txt', 'A real snapshot makes the check load its index under the lock.' );
		$backup = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $this->directory . '/backup', new Identity( 'example.com', 'www-data' ), new FrozenClock() );
		while ( ! $backup->advance( INF ) ) {
			continue;
		}
		$indexes = array_keys( $repository->backend()->list( ObjectType::Index ) );
		self::assertCount( 1, $indexes );
		$index_request = 'GET /site/index/' . $indexes[0];
		$job           = new Job( '20270115T030000Z-abcdef', JobType::Check, 'manual', 1_800_000_000 );
		$this->run_step( $job, 'unlocking' );
		$this->server->requests = [];
		gc_mem_caches();
		$previous = ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 8 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This isolated test simulates a host's limit without WordPress.
		self::assertNotFalse( $previous );
		try {
			$this->run_step( $job, 'checking' );
			self::fail( 'The memory refusal must stop the check before it reads the snapshot trees.' );
		} catch ( IndexTooLarge ) {
			self::assertCount( 0, array_filter( $this->server->requests, static fn ( string $request ): bool => $request === $index_request ), 'The budget refuses before the first index body is downloaded.' );
			self::assertCount( 1, $repository->backend()->list( ObjectType::Locks ) );
			$this->run_step( $job, 'releasing' );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
		} finally {
			ini_set( 'memory_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore the test process's original limit.
		}
	}

	public function test_a_refused_maintenance_removal_does_not_skip_common_cleanup(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse removal.' );
		}
		$secrets = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		$secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( 'a cancelled attempt' ) );
		$job    = new Job( '20270115T030000Z-abcdef', JobType::Check, 'manual', 1_800_000_000 );
		$work   = $this->jobs->directory( $job ) . '/maintenance';
		$unlock = $this->directory . '/private/kdf/' . $job->id;
		foreach ( [ $work, $unlock ] as $path ) {
			mkdir( $path, 0700, true );
			file_put_contents( $path . '/leftover', 'private working data' );
		}
		chmod( $work, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$this->run_step( $job, 'cleaning_up' );
			self::fail( 'Refused cleanup must still be reported.' );
		} catch ( StorageFailed ) {
			self::assertNull( $secrets->get( SecretName::RepositoryPasswordCandidate ) );
			self::assertDirectoryDoesNotExist( $unlock );
			self::assertFileExists( $work . '/leftover' );
		} finally {
			restore_error_handler();
			chmod( $work, 0700 );
		}
	}

	/** @return iterable<string, array{JobType}> */
	public static function deleting_jobs(): iterable {
		yield 'forget' => [ JobType::Forget ];
		yield 'prune' => [ JobType::Prune ];
	}

	#[DataProvider( 'deleting_jobs' )]
	public function test_append_only_refusal_switches_retention_even_when_cleanup_fails( JobType $type ): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse removal.' );
		}
		$settings = new SettingsStore( $this->options );
		$settings->update(
			[
				'retention.keep.last'    => 1,
				'retention.keep.daily'   => 0,
				'retention.keep.weekly'  => 0,
				'retention.keep.monthly' => 0,
				'retention.keep.within'  => '1d',
			]
		);
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		$identity   = new Identity( 'example.com', 'www-data' );
		$clock      = new FrozenClock();
		$repository = Repository::initialize( $this->access()->backend(), self::PASSWORD, $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$site       = $this->directory . '/site';
		mkdir( $site );
		foreach ( [ 864000, 60 ] as $age ) {
			file_put_contents( $site . '/file.txt', 'Content from ' . $age . ' seconds ago.' );
			$backup = BackupRun::start( $repository, new BackupPlan( [ $site ], [ 'backups-for-restic', 'site-tag' ] ), $this->directory . '/backup-' . $age, $identity, new FrozenClock( $clock->seconds - $age ) );
			while ( ! $backup->advance( INF ) ) {
				continue;
			}
			if ( JobType::Prune === $type && 864000 === $age ) {
				foreach ( $repository->names( ObjectType::Snapshots ) as $name ) {
					$repository->backend()->delete( ObjectType::Snapshots, $name );
				}
			}
		}
		$job = new Job( '20270115T030000Z-abcdef', $type, 'manual', 1_800_000_000 );
		$this->run_step( $job, 'unlocking' );
		$work                 = $this->jobs->directory( $job ) . '/maintenance';
		$delete_type          = JobType::Forget === $type ? ObjectType::Snapshots : ObjectType::Data;
		$deleting             = array_map( static fn ( string $name ): string => '/site/' . $delete_type->value . '/' . $name, $repository->names( $delete_type ) );
		$this->server->before = static function ( string $method, string $path ) use ( $work, $deleting ): ?HttpResponse {
			if ( 'DELETE' === $method && in_array( $path, $deleting, true ) ) {
				chmod( $work, 0500 );
				return new HttpResponse( 403, [], '' );
			}
			return null;
		};
		$steps                = $this->steps(
			static function ( string $mode ) use ( $settings ): void {
				$settings->update( [ 'retention.mode' => $mode ] );
			}
		);
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$steps->step( $type, JobType::Forget === $type ? 'forgetting' : 'pruning' )->advance( $job, $this->jobs->log( $job ), INF );
			self::fail( 'Refused local cleanup must still be reported.' );
		} catch ( StorageFailed ) {
			self::assertSame( 'external', $settings->load()->string( 'retention.mode' ) );
			self::assertTrue( $job->result['retention_switched'] ?? false );
			self::assertSame( 'append_only', $job->result['skipped'] ?? null );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
		} finally {
			restore_error_handler();
			if ( is_dir( $work ) ) {
				chmod( $work, 0700 );
			}
		}
	}

	/** @return iterable<string, array{JobType}> */
	public static function maintenance_jobs(): iterable {
		yield 'forget' => [ JobType::Forget ];
		yield 'prune' => [ JobType::Prune ];
		yield 'check' => [ JobType::Check ];
	}

	#[DataProvider( 'maintenance_jobs' )]
	public function test_cleanup_releases_only_its_run_when_the_checkpoint_version_is_unknown( JobType $type ): void {
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		$identity   = new Identity( 'example.com', 'www-data' );
		$clock      = new FrozenClock();
		$repository = Repository::initialize( $this->access()->backend(), self::PASSWORD, $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$job        = new Job( '20270115T030000Z-abcdef', $type, 'manual', 1_800_000_000 );
		$this->run_step( $job, 'unlocking' );
		( new Locking( $repository, $identity, $clock, 424242 ) )->acquire( false );
		$foreign = ( new Locking( $repository, $identity, $clock, 787878 ) )->acquire( false );
		$work    = $this->jobs->directory( $job ) . '/maintenance';
		mkdir( $work );
		file_put_contents(
			$work . '/state.json',
			(string) json_encode(
				[
					'version' => 99999,
					'pid'     => 424242,
					'lock'    => $foreign->hex(),
				]
			)
		);

		$this->run_step( $job, 'releasing' );

		self::assertSame( [ $foreign->hex() ], array_keys( $repository->backend()->list( ObjectType::Locks ) ), 'The run number identifies ownership; an unfamiliar lock field is not trusted.' );
		self::assertFileDoesNotExist( $work . '/state.json' );
	}
}
