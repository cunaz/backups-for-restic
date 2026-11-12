<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\RepositoryRecord;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
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
 * The unlock step, which every job runs: it gets the key, records which repository the jobs
 * work with, and refuses one that the plugin cannot read.
 */
final class BackupStepsTest extends TestCase {

	private const string PASSWORD = 'repository password';

	private string $directory;

	private InMemoryOptions $options;

	private InMemoryRestServer $server;

	private EncryptedOptionStore $secrets;

	private JobStore $jobs;

	private int $unlocks = 0;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'steps' );
		$this->options   = new InMemoryOptions();
		$this->server    = new InMemoryRestServer();
		$this->secrets   = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		$this->jobs      = new JobStore( $this->directory . '/private/jobs', new Redactor(), new SystemRandom() );
		( new SettingsStore( $this->options ) )->update( [ 'repository.url' => 'https://backup.example.net:8000/site/' ] );
		$this->secrets->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function access(): RepositoryAccess {
		return new RepositoryAccess( ( new SettingsStore( $this->options ) )->load(), $this->secrets, $this->server, new SystemRandom(), 'backups-for-restic-tests' );
	}

	private function repository(): Repository {
		return Repository::initialize( $this->access()->backend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	}

	/** @param (\Closure(Job): BackupPlan)|null $plan */
	private function steps( ?\Closure $plan = null ): BackupSteps {
		$private = new PrivateDirectory( $this->directory . '/private' );
		$private->ensure();
		$record = new RepositoryRecord( new SettingsStore( $this->options ), new SiteState( $this->options ), new FrozenClock() );
		return new BackupSteps(
			new BackupServices(
				( new SettingsStore( $this->options ) )->load(),
				$this->access(),
				$private,
				$this->jobs,
				new SiteState( $this->options ),
				new FrozenClock(),
				new SystemRandom(),
				new Identity( 'example.com', 'www-data' ),
				$plan ?? static function ( Job $job ): BackupPlan {
					self::fail( 'Unlocking needs no plan.' );
				},
				null,
				static function ( Config $config ) use ( $record ): void {
					$record->opened( $config );
				}
			)
		);
	}

	/**
	 * Runs the unlock step until it is done.
	 */
	private function unlock( JobType $type = JobType::Unlock ): Job {
		$job = new Job( sprintf( '20270115T0300%02dZ-abcdef', ++$this->unlocks ), $type, 'setup', 1_800_000_000 );
		for ( $tick = 0; $tick < 100; $tick++ ) {
			if ( $this->steps()->unlock( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) ) {
				return $job;
			}
		}
		self::fail( 'The key was not derived.' );
	}

	/**
	 * @return array{string, int} Repository ID and format version in the settings.
	 */
	private function recorded(): array {
		$settings = ( new SettingsStore( $this->options ) )->load();
		return [ $settings->string( 'repository.id' ), $settings->int( 'repository.format_version' ) ];
	}

	/**
	 * Turns the repository on the server into one of format version 2, as
	 * `restic migrate upgrade_repo_v2` does.
	 */
	private function upgrade( Repository $repository ): void {
		$config                                = new Config( Config::VERSION_2, $repository->config()->id, $repository->config()->chunker_polynomial );
		$this->server->objects['/site/config'] = ( new Cipher( $repository->master_key(), new SystemRandom() ) )->seal( $config->to_json() );
	}

	public function test_connecting_to_an_existing_repository_records_it(): void {
		$repository = $this->repository();
		self::assertSame( [ '', 1 ], $this->recorded() );

		$this->unlock();

		self::assertSame( [ $repository->config()->id, 1 ], $this->recorded() );
		self::assertSame( $repository->config()->id, $this->access()->cached_repository_id() );
		self::assertSame( $repository->config()->id, ( new SiteState( $this->options ) )->get( 'repository' )['id'] ?? null );
	}

	/** @return iterable<string, array{bool}> */
	public static function cleanup_plans(): iterable {
		yield 'plan cannot be rebuilt' => [ false ];
		yield 'plan exists, saved node entries are missing' => [ true ];
	}

	#[DataProvider( 'cleanup_plans' )]
	#[RunInSeparateProcess]
	public function test_cleanup_after_an_index_memory_refusal_needs_no_plan_nodes_or_index( bool $resume_plan ): void {
		$repository = $this->repository();
		$site       = $this->directory . '/site';
		mkdir( $site );
		file_put_contents( $site . '/hello.txt', 'The next backup must load a real index under its lock.' );
		$backup = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $this->directory . '/first-backup', new Identity( 'example.com', 'www-data' ), new FrozenClock() );
		while ( ! $backup->advance( INF ) ) {
			continue;
		}
		$indexes = array_keys( $repository->backend()->list( ObjectType::Index ) );
		self::assertCount( 1, $indexes );
		$job                    = $this->unlock( JobType::Backup );
		$plan_available         = true;
		$steps                  = $this->steps(
			static function ( Job $job ) use ( $site, &$plan_available ): BackupPlan {
				self::assertTrue( $plan_available, 'Cleanup cannot depend on rebuilding a backup plan.' );
				return new BackupPlan( [ $site ] );
			}
		);
		$log                    = $this->jobs->log( $job );
		$this->server->requests = [];
		gc_mem_caches();
		$previous = ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 8 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This isolated process exercises cleanup under the same low limit as the failed job.
		self::assertNotFalse( $previous );
		try {
			try {
				$steps->back_up( $job, $log, INF );
				self::fail( 'Loading the index must refuse before running out of memory.' );
			} catch ( IndexTooLarge $error ) {
				self::assertSame( 'index_too_large', JobError::from_throwable( $error )->code );
			}
			self::assertCount( 1, $repository->backend()->list( ObjectType::Locks ) );
			$index_request = 'GET /site/index/' . $indexes[0];
			self::assertCount( 1, array_filter( $this->server->requests, static fn ( string $request ): bool => $request === $index_request ) );

			// The failure leaves ownership in the checkpoint. Neither the rest of that
			// checkpoint nor unreadable repository data should be needed to release it.
			$work  = $this->jobs->directory( $job ) . '/backup';
			$state = json_decode( (string) file_get_contents( $work . '/state.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::assertIsArray( $state );
			$state['stack'] = [
				[
					'sequence'    => 0,
					'nodes_bytes' => 1,
				],
			];
			file_put_contents( $work . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
			file_put_contents( $work . '/nodes-0.jsonl', '' );
			$this->server->objects[ '/site/index/' . $indexes[0] ] = 'damaged index';
			$plan_available                                        = $resume_plan;

			self::assertTrue( $steps->release( $job, $log, INF ) );
			self::assertTrue( $steps->clean_up( $job, $log, INF ) );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
			self::assertDirectoryDoesNotExist( $work );
			self::assertCount( 1, array_filter( $this->server->requests, static fn ( string $request ): bool => $request === $index_request ), 'Cleanup must not read the index again.' );
		} finally {
			ini_set( 'memory_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restore the process limit after checking cleanup.
		}
	}

	public function test_cleanup_needs_no_plan_index_or_supported_checkpoint_version(): void {
		$repository = $this->repository();
		$job        = $this->unlock( JobType::Backup );
		$identity   = new Identity( 'example.com', 'www-data' );
		$clock      = new FrozenClock();
		( new Locking( $repository, $identity, $clock, 424242 ) )->acquire( false );
		$foreign = ( new Locking( $repository, $identity, $clock, 787878 ) )->acquire( false );
		$work    = $this->jobs->directory( $job ) . '/backup';
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
		$this->server->objects[ '/site/index/' . hash( 'sha256', 'unreadable index' ) ] = 'unreadable index';

		self::assertTrue( $this->steps()->release( $job, $this->jobs->log( $job ), INF ) );

		self::assertSame( [ $foreign->hex() ], array_keys( $repository->backend()->list( ObjectType::Locks ) ) );
		self::assertFileDoesNotExist( $work . '/state.json' );
	}

	public function test_cleanup_reports_refused_file_removal_after_releasing_its_lock(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse removal.' );
		}
		$repository = $this->repository();
		$job        = $this->unlock( JobType::Backup );
		$identity   = new Identity( 'example.com', 'www-data' );
		( new Locking( $repository, $identity, new FrozenClock(), 424242 ) )->acquire( false );
		$work = $this->jobs->directory( $job ) . '/backup';
		mkdir( $work );
		file_put_contents( $work . '/state.json', '{"pid":424242}' );
		chmod( $work, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$this->steps()->release( $job, $this->jobs->log( $job ), INF );
			self::fail( 'Refused local cleanup must not pass silently.' );
		} catch ( StorageFailed $error ) {
			self::assertSame( 'environment', JobError::from_throwable( $error )->code );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ), 'Local removal happens after the repository lock is released.' );
			self::assertFileExists( $work . '/state.json' );
		} finally {
			restore_error_handler();
			chmod( $work, 0700 );
		}
	}

	/** @return iterable<string, array{bool}> */
	public static function refused_cleanup_directories(): iterable {
		yield 'unlock' => [ true ];
		yield 'backup' => [ false ];
	}

	#[DataProvider( 'refused_cleanup_directories' )]
	public function test_a_refused_removal_does_not_skip_other_cleanup_actions( bool $refuse_unlock ): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse removal.' );
		}
		$this->repository();
		$job = $this->unlock( JobType::Backup );
		( new SettingsStore( $this->options ) )->update( [ 'advanced.keep_jobs' => 5 ] );
		$this->secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( 'a cancelled attempt' ) );
		$unlock = $this->directory . '/private/kdf/' . $job->id;
		$work   = $this->jobs->directory( $job ) . '/backup';
		$cache  = $this->directory . '/private/cache/' . str_repeat( 'a', 64 );
		foreach ( [ $unlock, $work, $cache ] as $path ) {
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0700, true );
			}
			file_put_contents( $path . '/leftover', 'private working data' );
		}
		$oldest = '';
		for ( $number = 0; $number < 6; ++$number ) {
			$old        = $this->jobs->create( JobType::Check, 'manual', 1_700_000_000 + $number );
			$old->state = JobState::Succeeded;
			$this->jobs->save( $old );
			if ( 0 === $number ) {
				$oldest = $this->jobs->directory( $old );
			}
		}
		$blocked = $refuse_unlock ? $unlock : $work;
		chmod( $blocked, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$this->steps()->clean_up( $job, $this->jobs->log( $job ), INF );
			self::fail( 'Refused cleanup must still be reported.' );
		} catch ( StorageFailed ) {
			self::assertNull( $this->secrets->get( SecretName::RepositoryPasswordCandidate ) );
			self::assertDirectoryDoesNotExist( $refuse_unlock ? $work : $unlock );
			self::assertDirectoryDoesNotExist( $oldest, 'Job pruning still runs.' );
			self::assertDirectoryDoesNotExist( $cache, 'Cache cleanup still runs.' );
			self::assertFileExists( $blocked . '/leftover' );
		} finally {
			restore_error_handler();
			chmod( $blocked, 0700 );
		}
	}

	public function test_an_entered_password_that_opens_the_repository_becomes_the_repository_password(): void {
		$repository = $this->repository();
		// The stored password is the one from before somebody changed it with restic.
		$this->secrets->set( SecretName::RepositoryPassword, new Secret( 'the password before' ) );
		$this->secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( self::PASSWORD ) );

		$this->unlock();

		self::assertSame( self::PASSWORD, $this->secrets->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertNull( $this->secrets->get( SecretName::RepositoryPasswordCandidate ) );
		self::assertSame( $repository->config()->id, $this->access()->cached_repository_id() );
	}

	public function test_an_entered_password_is_tried_even_though_a_key_is_cached(): void {
		$this->repository();
		$this->unlock();
		$cached = $this->secrets->get( SecretName::MasterKey )?->reveal();
		$this->secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( 'not the password of this repository' ) );
		$job = new Job( '20270115T030100Z-abcdef', JobType::Unlock, 'setup', 1_800_000_000 );

		try {
			$this->steps()->unlock( $job, $this->jobs->log( $job ), microtime( true ) + 5 );
			self::fail( 'The cached key says nothing about the entered password.' );
		} catch ( WrongPassword ) {
			self::assertSame( self::PASSWORD, $this->secrets->get( SecretName::RepositoryPassword )?->reveal(), 'The backups go on with the password that works.' );
			self::assertSame( $cached, $this->secrets->get( SecretName::MasterKey )?->reveal() );
		}

		self::assertTrue( $this->steps()->clean_up( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) );
		self::assertNull( $this->secrets->get( SecretName::RepositoryPasswordCandidate ), 'The wrong password is not kept.' );
		self::assertSame( $cached, $this->secrets->get( SecretName::MasterKey )?->reveal() );
	}

	public function test_an_entered_password_that_cannot_be_read_fails_the_unlock_job(): void {
		$this->repository();
		$this->unlock();
		$this->secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( 'entered before the security keys changed' ) );
		$key           = $this->secrets->get( SecretName::MasterKey );
		$this->secrets = new EncryptedOptionStore( $this->options, new Secret( 'other salts' ), new SystemRandom() );
		// Everything else can be read: only the entered password stands in the way.
		$this->secrets->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );
		self::assertNotNull( $key );
		$this->secrets->set( SecretName::MasterKey, $key );
		$job = new Job( '20270115T030100Z-abcdef', JobType::Unlock, 'setup', 1_800_000_000 );

		$this->expectException( SecretsUndecryptable::class );

		// Going on with the cached key would report a password as confirmed that nobody tried.
		$this->steps()->unlock( $job, $this->jobs->log( $job ), microtime( true ) + 5 );
	}

	public function test_only_the_unlock_job_tries_an_entered_password(): void {
		$this->repository();
		$this->secrets->set( SecretName::RepositoryPasswordCandidate, new Secret( 'left behind by an unlock that never ended' ) );

		$job = $this->unlock( JobType::Backup );

		self::assertNotSame( '', $this->access()->cached_repository_id(), 'The backup unlocked with the stored password.' );
		self::assertSame( self::PASSWORD, $this->secrets->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertTrue( $this->steps()->clean_up( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) );
		self::assertNull( $this->secrets->get( SecretName::RepositoryPasswordCandidate ), 'Nothing unconfirmed stays stored.' );
	}

	public function test_a_job_with_a_cached_key_notices_another_repository_at_the_address(): void {
		$first = $this->repository();
		$this->unlock();
		$state = new SiteState( $this->options );
		$state->set( 'check_alert', [ 'errors' => 1 ] );

		// Somebody emptied the bucket and created a repository with the same password.
		$this->server->objects = [];
		$second                = $this->repository();
		$this->unlock();

		self::assertNotSame( $first->config()->id, $second->config()->id );
		self::assertSame( [ $second->config()->id, 1 ], $this->recorded() );
		self::assertSame( [], $state->get( 'check_alert' ), 'The damage was in the repository that is gone.' );
	}

	public function test_recording_a_backup_measures_what_the_repository_holds(): void {
		$this->repository();
		$this->unlock();
		$state = new SiteState( $this->options );
		foreach ( [ 'a pack', 'another, larger pack' ] as $content ) {
			$this->server->objects[ '/site/data/' . hash( 'sha256', $content ) ] = $content;
		}
		$job = new Job( '20270115T030000Z-abcdef', JobType::Backup, 'manual', 1_800_000_000 );

		self::assertTrue( $this->steps()->record( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) );

		self::assertSame( $job->id, $state->get( 'last_backup' )['job'] ?? null );
		self::assertSame(
			[
				'bytes' => strlen( 'a pack' ) + strlen( 'another, larger pack' ),
				'packs' => 2,
				'at'    => ( new FrozenClock() )->seconds,
			],
			$state->get( 'usage' )
		);
	}

	public function test_a_job_whose_listing_never_came_back_does_not_ask_again(): void {
		$this->repository();
		$this->unlock();
		$state = new SiteState( $this->options );
		$job   = new Job( '20270115T030000Z-abcdef', JobType::Backup, 'manual', 1_800_000_000 );
		// The host ended the request in the middle of the listing; nothing caught that.
		$this->server->before = static function ( string $method, string $path ): ?HttpResponse {
			if ( str_ends_with( $path, '/data/' ) ) {
				throw new \Error( 'Maximum execution time exceeded' );
			}
			return null;
		};
		try {
			$this->steps()->record( $job, $this->jobs->log( $job ), microtime( true ) + 5 );
			self::fail( 'The request was ended.' );
		} catch ( \Error ) {
			self::assertSame( [], $state->get( 'usage' ) );
		}

		// The next tick repeats the step.
		$listings = count( array_filter( $this->server->requests, static fn ( string $request ): bool => str_ends_with( $request, '/data/' ) ) );
		self::assertTrue( $this->steps()->record( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) );

		self::assertCount( $listings, array_filter( $this->server->requests, static fn ( string $request ): bool => str_ends_with( $request, '/data/' ) ), 'No second listing.' );
		self::assertSame( $job->id, $state->get( 'last_backup' )['job'] ?? null );

		// The next job asks again.
		$this->server->before = null;
		$next                 = new Job( '20270116T030000Z-abcdef', JobType::Backup, 'manual', 1_800_086_400 );
		self::assertTrue( $this->steps()->record( $next, $this->jobs->log( $next ), microtime( true ) + 5 ) );
		self::assertSame( 0, $state->get( 'usage' )['packs'] ?? null );
	}

	public function test_a_listing_that_fails_does_not_fail_the_backup_and_keeps_the_last_measure(): void {
		$this->repository();
		$this->unlock();
		$state = new SiteState( $this->options );
		$state->set( 'usage', [ 'bytes' => 42 ] );
		$this->server->before = static fn ( string $method, string $path ): ?HttpResponse => str_ends_with( $path, '/data/' ) ? new HttpResponse( 403, [], '' ) : null;
		$job                  = new Job( '20270115T030000Z-abcdef', JobType::Backup, 'manual', 1_800_000_000 );

		self::assertTrue( $this->steps()->record( $job, $this->jobs->log( $job ), microtime( true ) + 5 ) );

		self::assertSame( $job->id, $state->get( 'last_backup' )['job'] ?? null, 'The backup is recorded.' );
		self::assertSame( [ 'bytes' => 42 ], $state->get( 'usage' ) );
		self::assertStringContainsString( 'The size of the repository could not be read.', (string) file_get_contents( $this->directory . '/private/jobs/' . $job->id . '/events.log' ) );
	}

	public function test_a_restart_refuses_to_reuse_database_state_that_cannot_be_removed(): void {
		if ( 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root can remove files from a directory without write permission.' );
		}
		$repository = $this->repository();
		$index      = $repository->save_unpacked( ObjectType::Index, IndexFile::encode( [] ) );
		$this->unlock();
		$site = $this->directory . '/site';
		mkdir( $site );
		file_put_contents( $site . '/example.txt', 'The backup has work left after its first tick.' );
		$steps = $this->steps( static fn (): BackupPlan => new BackupPlan( [ $site ] ) );
		$job   = new Job( '20270115T030100Z-abcdef', JobType::Backup, 'manual', 1_800_000_000 );
		$log   = $this->jobs->log( $job );
		self::assertFalse( $steps->back_up( $job, $log, 0.0 ) );
		$repository->backend()->delete( ObjectType::Index, $index->hex() );

		$directory = $this->jobs->directory( $job );
		$dump      = BackupServices::dump_state( $this->jobs, $job );
		$old       = '{"tables":["old_table"]}';
		file_put_contents( $dump, $old );
		chmod( $directory, 0500 );
		// unlink() reports the permission failure before the step can classify it.
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$steps->back_up( $job, $log, 0.0 );
			self::fail( 'A fresh backup must not reuse the previous database state.' );
		} catch ( StorageFailed $failure ) {
			self::assertSame( 'database.json', $failure->context()['file'] ?? null );
			self::assertSame( $old, file_get_contents( $dump ) );
			self::assertFileDoesNotExist( $directory . '/backup/state.json', 'The invalid engine run was already abandoned.' );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
		} finally {
			restore_error_handler();
			chmod( $directory, 0700 );
		}
	}

	public function test_a_repository_of_format_2_is_refused_without_zstd_and_recorded_for_the_doctor(): void {
		if ( Compression::available() ) {
			self::markTestSkipped( 'With the zstd extension the plugin reads format 2.' );
		}
		$repository = $this->repository();
		$this->unlock();
		$this->upgrade( $repository );

		try {
			$this->unlock();
			self::fail( 'restic compresses the index and the snapshots of such a repository.' );
		} catch ( UnsupportedRepository $e ) {
			self::assertSame( 2, $e->context()['version'] ?? null );
		}
		self::assertSame( [ $repository->config()->id, 2 ], $this->recorded(), 'The Doctor names the missing extension from this.' );
	}

	public function test_a_repository_of_format_2_is_accepted_with_zstd(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$repository = $this->repository();
		$this->upgrade( $repository );

		$this->unlock();

		self::assertSame( [ $repository->config()->id, 2 ], $this->recorded() );
	}
}
