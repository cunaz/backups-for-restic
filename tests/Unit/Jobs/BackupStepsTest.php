<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\RepositoryRecord;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
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

	private function steps(): BackupSteps {
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
				static function ( Job $job ): BackupPlan {
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
	private function unlock(): Job {
		$job = new Job( sprintf( '20270115T0300%02dZ-abcdef', ++$this->unlocks ), JobType::Unlock, 'setup', 1_800_000_000 );
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
