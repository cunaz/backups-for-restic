<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Restore\MysqliConnection as RestoreConnection;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobSteps;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\DatabasePreview;
use BackupsForRestic\Jobs\Restore\RestoreRequest;
use BackupsForRestic\Jobs\Restore\RestoreServices;
use BackupsForRestic\Jobs\Restore\RestoreSteps;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\InMemoryRestServer;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\StagedDump;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * A request to cancel that reaches a restore during its database rehearsal, after every file
 * was staged (ADR 0037): the rehearsal stops, its tables go, and the staged files stay with a
 * job that succeeded. A real server, because the tables are real.
 */
final class RestoreRehearsalCancelTest extends TestCase {

	/** Where the staged dump lies in the snapshot; its manifest is one level above. */
	private const string SPOOL = '/site/spool';

	private static ?MysqliConnection $admin = null;

	private MysqliConnection $db;

	private string $database = '';

	private string $directory = '';

	private InMemoryOptions $options;

	private JobStore $jobs;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void {
		self::$admin = MysqliConnection::connect();
		if ( null === self::$admin ) {
			self::markTestSkipped( 'No MySQL or MariaDB server is reachable.' );
		}
	}

	protected function setUp(): void {
		$this->database = 'bfr_cancel_' . bin2hex( random_bytes( 6 ) );
		self::$admin?->execute( 'CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$db = MysqliConnection::connect( $this->database );
		self::assertNotNull( $db );
		$this->db        = $db;
		$this->directory = (string) realpath( TempDir::create( 'rehearsal-cancel' ) );
		$this->options   = new InMemoryOptions();
		$this->clock     = new FrozenClock();
		( new PrivateDirectory( $this->directory . '/private' ) )->ensure();
		$this->jobs = new JobStore( $this->directory . '/private/jobs', new Redactor(), new SystemRandom() );
		( new SettingsStore( $this->options ) )->update(
			[
				'repository.url'  => 'https://backup.example.net:8000/site/',
				'setup.completed' => true,
			]
		);
	}

	protected function tearDown(): void {
		self::$admin?->execute( 'DROP DATABASE IF EXISTS `' . $this->database . '`' );
		TempDir::remove( $this->directory );
	}

	private function services(): RestoreServices {
		$private = new PrivateDirectory( $this->directory . '/private' );
		$secrets = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		$access  = new RepositoryAccess( ( new SettingsStore( $this->options ) )->load(), $secrets, new InMemoryRestServer(), new SystemRandom(), 'backups-for-restic-tests' );
		$mysqli  = $this->db->mysqli;
		return new RestoreServices(
			new BackupServices(
				( new SettingsStore( $this->options ) )->load(),
				$access,
				$private,
				$this->jobs,
				new SiteState( $this->options ),
				$this->clock,
				new SystemRandom(),
				new Identity( 'example.com', 'www-data' ),
				static fn ( Job $job ): BackupPlan => new BackupPlan( [] )
			),
			static fn (): DatabasePreview => new DatabasePreview( new RestoreConnection( $mysqli ), 'wp_' )
		);
	}

	private function runner( RestoreServices $services ): Runner {
		$backup = new BackupSteps( $services->common );
		return new Runner( $this->jobs, new RunnerLock( $this->directory . '/runner.lock' ), new JobSteps( $backup, $backup, new RestoreSteps( $services, $backup ) ), $this->clock, new SystemRandom() );
	}

	/**
	 * A dump of twenty tables in the staging of a job, more than one tick of the rehearsal imports.
	 */
	private function stage( Job $job ): string {
		$dump = $this->directory . '/private/restores/' . $job->id . '/files' . self::SPOOL . '/database';
		mkdir( $dump, 0700, true );
		StagedDump::write( $dump );
		$tables = [];
		for ( $number = 0; $number < 20; ++$number ) {
			$name     = sprintf( 'wp_rows_%02d', $number );
			$sql      = StagedDump::with_updated_trailer( str_replace( '`wp_rows`', '`' . $name . '`', StagedDump::table() ) );
			$file     = ( new Table( $name, '', [], null, [] ) )->file();
			$tables[] = [
				'name'  => $name,
				'file'  => $file,
				'rows'  => 4,
				'bytes' => strlen( $sql ),
			];
			file_put_contents( $dump . '/' . $file, $sql );
		}
		unlink( $dump . '/' . ( new Table( 'wp_rows', '', [], null, [] ) )->file() );
		$manifest           = json_decode( (string) file_get_contents( $dump . '/manifest.json' ), true );
		$manifest['tables'] = $tables;
		file_put_contents( $dump . '/manifest.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		file_put_contents( $this->directory . '/private/restores/' . $job->id . '/files' . self::SPOOL . '/manifest.json', '{}' );
		return $dump;
	}

	/**
	 * @return list<string> Tables that a rehearsal owns, by the mark in their comment.
	 */
	private function rehearsal_tables(): array {
		return array_column( $this->db->rows( "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_COMMENT LIKE 'backups-for-restic-restore/1:%'" ), 'name' );
	}

	public function test_a_cancel_during_the_rehearsal_removes_its_tables_and_keeps_the_staged_files(): void {
		$request = RestoreRequest::from_input( str_repeat( 'ab', 32 ), [ '/site' ], true, true );
		$job     = $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds, $request->to_data() );
		$dump    = $this->stage( $job );
		// As the step restoring leaves the job once every file is staged and the rehearsal began.
		$services   = $this->services();
		$job->state = JobState::Running;
		$job->step  = 'restoring';
		$preview    = ( $services->preview ?? self::fail( 'No preview.' ) )();
		$preview->bind( $services, $job, self::SPOOL . '/manifest.json', 'https://example.com', null );
		$job->progress = [ 'phase' => 'database_import' ];
		self::assertFalse( $preview->advance( $services, $job, 0.0 ), 'The rehearsal is not done after one short tick.' );
		self::assertNotSame( [], $this->rehearsal_tables(), 'It has tables of its own by now.' );
		mkdir( $this->directory . '/private/jobs/' . $job->id . '/restore', 0700 );
		file_put_contents( $this->directory . '/private/jobs/' . $job->id . '/restore/entries.jsonl', "{}\n" );
		$this->jobs->save( $job );

		$this->runner( $services )->cancel( $job->id );
		for ( $tick = 0; $tick < 20; ++$tick ) {
			$this->runner( $this->services() )->tick( 5.0 );
			$done = $this->jobs->get( $job->id );
			if ( null === $done || $done->state->is_terminal() ) {
				break;
			}
		}
		$done = $this->jobs->get( $job->id );

		self::assertNotNull( $done );
		self::assertSame( JobState::Succeeded, $done->state, (string) $done->error?->message );
		self::assertSame( [], $this->rehearsal_tables(), 'The tables of the rehearsal are gone.' );
		self::assertArrayNotHasKey( 'database_binding', $done->data );
		self::assertArrayNotHasKey( 'database_report', $done->result );
		self::assertFileDoesNotExist( $this->directory . '/private/restores/' . $job->id . '/' . DatabasePreview::REPORT );
		self::assertFileDoesNotExist( $this->directory . '/private/restores/' . $job->id . '/' . RestoreServices::ARCHIVE, 'The archive that was asked for is not built either.' );
		self::assertFileExists( $dump . '/manifest.json', 'The staged files stay.' );
		self::assertCount( 2, $done->warnings );
		self::assertStringContainsString( 'database rehearsal stopped', $done->warnings[0] );
		self::assertStringContainsString( 'the ZIP was not built', $done->warnings[1] );
	}
}
