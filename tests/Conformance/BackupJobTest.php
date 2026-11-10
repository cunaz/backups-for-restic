<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Backup\SitePaths;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\CurlHttpClient;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The backup job end to end without WordPress: settings, encrypted secrets, preflight, a
 * resumable unlock, the plan with the streamed database dump, and many short ticks against a
 * TLS rest-server. restic checks and restores the result; the dump imports into a fresh
 * database with identical tables. Needs restic, rest-server and MariaDB or MySQL.
 */
final class BackupJobTest extends TestCase {

	private const string REPOSITORY_PASSWORD = 'job repository password';

	private static ?RestServer $server = null;

	private static ?MysqliConnection $admin = null;

	private static string $database = '';

	/** @var list<string> */
	private array $cleanup = [];

	public static function setUpBeforeClass(): void {
		if ( ! RestServer::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh to install restic and rest-server.' );
		}
		self::$admin = MysqliConnection::connect();
		if ( null === self::$admin ) {
			self::markTestSkipped( 'No MySQL or MariaDB server is reachable.' );
		}
		self::$server   = new RestServer();
		self::$database = 'bfr_job_' . bin2hex( random_bytes( 4 ) );
		self::$admin->execute( 'CREATE DATABASE `' . self::$database . '` CHARACTER SET utf8mb4' );
		$db = MysqliConnection::connect( self::$database );
		self::assertNotNull( $db );
		$db->execute( 'CREATE TABLE wp_options (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT, autoload VARCHAR(20))' );
		$db->execute( 'CREATE TABLE wp_posts (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_title TEXT, post_content LONGTEXT, post_date DATETIME)' );
		$db->execute( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'https://example.com', 'yes'), ('blogname', 'Job \\'test\\'', 'yes')" );
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$rows = [];
			for ( $i = 0; $i < 1000; $i++ ) {
				$n      = $batch * 1000 + $i;
				$rows[] = sprintf( "('Post %d', '%s', '2026-11-09 10:%02d:00')", $n, str_repeat( hash( 'sha256', (string) $n ), 8 ), $n % 60 );
			}
			$db->execute( 'INSERT INTO wp_posts (post_title, post_content, post_date) VALUES ' . implode( ',', $rows ) );
		}
		$db->execute( 'CREATE TABLE other_plugin_table (id INT PRIMARY KEY)' );
	}

	public static function tearDownAfterClass(): void {
		self::$server?->stop();
		self::$admin?->execute( 'DROP DATABASE IF EXISTS `' . self::$database . '`' );
		self::$admin?->execute( 'DROP DATABASE IF EXISTS `' . self::$database . '_restored`' );
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function server(): RestServer {
		self::assertNotNull( self::$server );
		return self::$server;
	}

	public function test_backup_job_through_many_short_ticks_restores_with_restic(): void {
		$this->cleanup[] = $site = TempDir::create( 'job-site' );
		TempDir::fixture( $site, 4 * 1048576 );
		mkdir( $site . '/wp-content/cache/page', 0755, true );
		file_put_contents( $site . '/wp-content/cache/page/index.html', 'cached page' );

		$repository         = Repository::initialize( $this->server()->backend( 'job' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 16384, 8, 1 ) );
		[ $runner, $store ] = $this->components( $site, 'job' );
		// The cache of a repository this site used before. The site was connected to the
		// existing repository, so no setting names its ID.
		$caches = $site . '/wp-content/' . PrivateDirectory::NAME . '/cache';
		mkdir( $caches . '/' . str_repeat( 'a', 64 ), 0700, true );

		$first = $this->run_job( $runner, $store, 0.4 );
		self::assertSame( JobState::Succeeded, $first->state, (string) json_encode( $first->error?->to_array() ) );
		self::assertDirectoryDoesNotExist( $caches . '/' . str_repeat( 'a', 64 ), 'The cache of the other repository is removed.' );
		self::assertDirectoryExists( $caches . '/' . $repository->config()->id, 'The cache of the repository in use stays.' );
		self::assertGreaterThan( 5, $first->ticks, 'Unlock and dump spanned many ticks.' );

		$cli = new ResticCli( $this->server(), 'job', self::REPOSITORY_PASSWORD );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$snapshots = json_decode( $cli->must( [ 'snapshots', '--json' ] ), true );
		self::assertIsArray( $snapshots );
		self::assertSame( 'example.com', $snapshots[0]['hostname'] );
		self::assertContains( 'backups-for-restic', $snapshots[0]['tags'] );
		self::assertContains( 'run:' . $first->id, $snapshots[0]['tags'] );

		$this->cleanup[] = $target = TempDir::create( 'job-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		$private = $site . '/wp-content/' . PrivateDirectory::NAME;
		self::assertFileExists( $target . $site . '/wp-content/uploads/2026/11/photo.jpg' );
		self::assertFileDoesNotExist( $target . $site . '/wp-content/cache/page/index.html', 'Default excludes apply.' );
		self::assertDirectoryDoesNotExist( $target . $private . '/jobs', 'Only spool/ of the private directory is backed up.' );
		self::assertFileExists( $target . $private . '/spool/manifest.json' );
		self::assertSame( 'backups-for-restic-site/1', json_decode( (string) file_get_contents( $target . $private . '/spool/manifest.json' ), true )['format'] ?? null );

		$sql = (array) glob( $target . $private . '/spool/database/*.sql' );
		self::assertCount( 4, $sql, 'Header, two tables, views; other_plugin_table lacks the prefix.' );
		$this->import( $sql );
		$source   = MysqliConnection::connect( self::$database );
		$restored = MysqliConnection::connect( self::$database . '_restored' );
		self::assertNotNull( $source );
		self::assertNotNull( $restored );
		foreach ( [ 'wp_options', 'wp_posts' ] as $table ) {
			self::assertSame( $source->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $restored->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $table );
		}

		// Nothing under jobs/ contains a secret.
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $private . '/jobs', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$content = (string) file_get_contents( (string) $file );
			self::assertStringNotContainsString( self::REPOSITORY_PASSWORD, $content );
			self::assertStringNotContainsString( RestServer::PASSWORD, $content );
		}
		self::assertSame( [], (array) glob( $private . '/kdf/*' ), 'The scrypt table is gone.' );

		// The second backup unlocks from the cache, reuses unchanged data and finishes quickly.
		file_put_contents( $site . '/index.php', "<?php\n// Changed.\n" );
		$second = $this->run_job( $runner, $store, 5 );
		self::assertSame( JobState::Succeeded, $second->state );
		// Unchanged files are reused and add nothing. The dump deduplicates except around the
		// places where the first run's tiny ticks cut the chunker at a page boundary; how many
		// cuts that were depends on the machine's speed, so the bound is the dump itself plus a
		// margin for the changed file and the trees.
		self::assertGreaterThan( 0, (int) ( $second->progress['files_unmodified'] ?? 0 ) );
		$dump_bytes = array_sum( array_map( static fn ( mixed $file ): int => (int) filesize( (string) $file ), $sql ) );
		self::assertLessThan( $dump_bytes + 1048576, (int) ( $second->progress['data_added'] ?? PHP_INT_MAX ) );
		self::assertSame( '', trim( $cli->must( [ 'list', 'locks', '--no-lock' ] ) ), 'No lock is left behind.' );
	}

	/**
	 * ADR 0030 against the real restic: a backup job pauses for longer than its lock lasts,
	 * and an administrator runs `restic unlock` and `restic prune` on another computer in the
	 * meantime. No snapshot uses what the paused backup uploaded, so restic removes it.
	 */
	public function test_a_backup_starts_over_when_restic_pruned_during_a_long_pause(): void {
		$this->cleanup[] = $site = TempDir::create( 'job-site' );
		TempDir::fixture( $site, 1048576 );
		$clock = new FrozenClock( time() );
		Repository::initialize( $this->server()->backend( 'paused' ), self::REPOSITORY_PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 16384, 8, 1 ) );
		[ $runner, $store ] = $this->components( $site, 'paused', $clock );
		$cli                = new ResticCli( $this->server(), 'paused', self::REPOSITORY_PASSWORD );
		self::assertSame( JobState::Succeeded, $this->run_job( $runner, $store, 5 )->state, 'A first snapshot, so that restic has something to keep.' );

		// The second backup has new files to upload. Its engine clock is 31 minutes behind, so
		// its lock is as old for restic as after a pause of that length.
		mkdir( $site . '/wp-content/uploads/2026/12', 0755, true );
		for ( $i = 0; $i < 12; $i++ ) {
			file_put_contents( $site . '/wp-content/uploads/2026/12/photo-' . $i . '.jpg', random_bytes( 700000 ) );
		}
		$clock->seconds = time() - Lock::STALE_AFTER_SECONDS - 60;
		$job            = $store->create( JobType::Backup, 'manual', time() );
		$checkpoint     = $site . '/wp-content/' . PrivateDirectory::NAME . '/jobs/' . $job->id . '/backup/state.json';
		$uploaded       = static function () use ( $checkpoint ): array {
			$state = is_file( $checkpoint ) ? json_decode( (string) file_get_contents( $checkpoint ), true ) : null;
			return is_array( $state ) && is_array( $state['unindexed'] ?? null ) ? array_keys( $state['unindexed'] ) : [];
		};
		$ticks          = 0;
		while ( [] === $uploaded() ) {
			self::assertTrue( $runner->tick( 0.05 )->has_more_work(), 'The backup must still be running when it pauses.' );
			self::assertLessThan( 2000, ++$ticks );
		}
		$packs = array_map( 'strval', $uploaded() );

		self::assertStringContainsString( 'successfully removed 1 locks', $cli->must( [ 'unlock' ] ), 'The lock of the paused backup is stale for restic.' );
		$cli->must( [ 'prune' ] );
		$left = (string) $cli->must( [ 'list', 'packs' ] );
		foreach ( $packs as $pack ) {
			self::assertStringNotContainsString( $pack, $left, 'restic removed the pack that no index and no snapshot knew.' );
		}

		// The pause is over.
		$clock->seconds = time();
		$ticks          = 0;
		while ( $runner->tick( 0.3 )->has_more_work() ) {
			self::assertLessThan( 2000, ++$ticks );
		}
		$finished = $store->get( $job->id );
		self::assertNotNull( $finished );
		self::assertSame( JobState::Succeeded, $finished->state, (string) json_encode( $finished->error?->to_array() ) );
		self::assertSame( 1, $finished->data['restarts'] ?? null, 'The backup noticed the missing packs and started over once.' );
		$messages = array_column( $store->log( $finished )->read(), 'message' );
		self::assertContains( 'Another program removed data that the backup relied on; the backup starts over.', $messages );
		self::assertSame( 2, count( array_keys( $messages, 'Backup started.', true ) ) );

		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ), 'The snapshot refers to nothing that is gone.' );
		$snapshots = json_decode( $cli->must( [ 'snapshots', '--json' ] ), true );
		self::assertIsArray( $snapshots );
		self::assertCount( 2, $snapshots );
		$this->cleanup[] = $target = TempDir::create( 'job-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		self::assertSame( TempDir::describe( $site . '/wp-content/uploads' ), TempDir::describe( $target . $site . '/wp-content/uploads' ) );
		self::assertSame( '', trim( $cli->must( [ 'list', 'locks', '--no-lock' ] ) ), 'No lock is left behind.' );
	}

	/**
	 * Builds runner and store like the composition root does.
	 *
	 * @param Clock|null $clock Time of the engine: what it writes into locks and compares them with.
	 * @return array{Runner, JobStore}
	 */
	private function components( string $site, string $repository, ?Clock $clock = null ): array {
		$options = new InMemoryOptions();
		$random  = new SystemRandom();
		$secrets = new EncryptedOptionStore( $options, new Secret( 'salts' ), $random );
		$secrets->set( SecretName::RepositoryPassword, new Secret( self::REPOSITORY_PASSWORD ) );
		$secrets->set( SecretName::RestPassword, new Secret( RestServer::PASSWORD ) );
		$settings = ( new SettingsStore( $options ) )->update(
			[
				'repository.server'        => 'rest-server',
				'repository.url'           => $this->server()->url( $repository ),
				'repository.rest_username' => RestServer::USER,
				'advanced.pack_size_mib'   => 4,
			]
		);

		$private  = new PrivateDirectory( $site . '/wp-content/' . PrivateDirectory::NAME );
		$redactor = new Redactor();
		$redactor->add( new Secret( self::REPOSITORY_PASSWORD ) );
		$redactor->add( new Secret( RestServer::PASSWORD ) );
		$store    = new JobStore( $private->subdirectory( 'jobs' ), $redactor, $random );
		$paths    = new SitePaths( $site, $site . '/wp-content', $site . '/wp-content/uploads', 'https://example.com', 'wp_', '7.1.4', '0.1.0-test' );
		$database = self::$database;
		$plan     = static function ( Job $job ) use ( $paths, $settings, $private, $store, $database ): BackupPlan {
			$connection = MysqliConnection::connect( $database );
			self::assertNotNull( $connection );
			$dump = new DatabaseDump( $connection, BackupServices::dump_state( $store, $job ), 'wp_', [], $job->created_at );
			return ( new PlanFactory( $paths, $settings, $private, [], [], $dump->files( ... ), $job->id, $job->created_at ) )->plan();
		};
		$access   = new RepositoryAccess( $settings, $secrets, new CurlHttpClient( $this->server()->certificate ), $random, 'backups-for-restic-tests' );
		$services = new BackupServices( $settings, $access, $private, $store, new SiteState( $options ), $clock ?? new SystemClock(), $random, new Identity( 'example.com', 'www-data' ), $plan );
		$private->ensure();
		return [ new Runner( $store, new RunnerLock( $private->path . '/runner.lock' ), new BackupSteps( $services ), new SystemClock(), $random ), $store ];
	}

	private function run_job( Runner $runner, JobStore $store, float $budget ): Job {
		$job = $store->create( JobType::Backup, 'manual', time() );
		for ( $tick = 0; $tick < 2000; $tick++ ) {
			if ( ! $runner->tick( $budget )->has_more_work() ) {
				break;
			}
		}
		$finished = $store->get( $job->id );
		self::assertNotNull( $finished );
		return $finished;
	}

	/**
	 * @param list<string> $files
	 */
	private function import( array $files ): void {
		self::$admin?->execute( 'CREATE DATABASE `' . self::$database . '_restored` CHARACTER SET utf8mb4' );
		sort( $files );
		$process = proc_open( MysqliConnection::client( self::$database . '_restored' ), [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		foreach ( $files as $file ) {
			fwrite( $pipes[0], (string) file_get_contents( $file ) );
		}
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), 'Import failed: ' . $output );
	}
}
