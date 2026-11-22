<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobSteps;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\RestoreRequest;
use BackupsForRestic\Jobs\Restore\RestoreServices;
use BackupsForRestic\Jobs\Restore\RestoreSteps;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
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
 * The restore job (ADR 0037): a snapshot that a backup job wrote comes back into the staging
 * directory of the job, through the runner and in ticks as short as a host can make them.
 */
final class RestoreStepsTest extends TestCase {

	private const string PASSWORD = 'repository password';

	private string $directory;

	private string $site;

	private InMemoryOptions $options;

	private InMemoryRestServer $server;

	private JobStore $jobs;

	private FrozenClock $clock;

	/**
	 * The free-space probe that the restore services get; the host's by default.
	 *
	 * @var (\Closure(string): (float|false))|null
	 */
	private ?\Closure $space = null;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'restore-steps' );
		$this->site      = $this->directory . '/site';
		$this->options   = new InMemoryOptions();
		$this->server    = new InMemoryRestServer();
		$this->clock     = new FrozenClock();
		( new PrivateDirectory( $this->directory . '/private' ) )->ensure();
		$this->jobs = new JobStore( $this->directory . '/private/jobs', new Redactor(), new SystemRandom() );
		( new SettingsStore( $this->options ) )->update(
			[
				'repository.url'  => 'https://backup.example.net:8000/site/',
				'setup.completed' => true,
			]
		);
		( new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RepositoryPassword, new Secret( self::PASSWORD ) );

		mkdir( $this->site . '/docs/deep', 0700, true );
		file_put_contents( $this->site . '/hello.txt', 'Hello from the site.' );
		file_put_contents( $this->site . '/empty.txt', '' );
		file_put_contents( $this->site . '/docs/readme.md', str_repeat( "A line of the readme.\n", 400 ) );
		file_put_contents( $this->site . '/docs/deep/large.bin', random_bytes( 1_500_000 ) );
		symlink( 'hello.txt', $this->site . '/link' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function access(): RepositoryAccess {
		$secrets = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		return new RepositoryAccess( ( new SettingsStore( $this->options ) )->load(), $secrets, $this->server, new SystemRandom(), 'backups-for-restic-tests' );
	}

	private function services(): RestoreServices {
		$private = new PrivateDirectory( $this->directory . '/private' );
		$private->ensure();
		$site = $this->site;
		return new RestoreServices(
			new BackupServices(
				( new SettingsStore( $this->options ) )->load(),
				$this->access(),
				$private,
				$this->jobs,
				new SiteState( $this->options ),
				$this->clock,
				new SystemRandom(),
				new Identity( 'example.com', 'www-data' ),
				static fn ( Job $job ): BackupPlan => new BackupPlan( [ $site ] )
			),
			null,
			$this->space
		);
	}

	/**
	 * A runner as the composition root builds it; every tick is a request of its own.
	 */
	private function runner(): Runner {
		$services = $this->services();
		$backup   = new BackupSteps( $services->common );
		// No maintenance job runs here; the backup steps stand in for that provider.
		return new Runner( $this->jobs, new RunnerLock( $this->directory . '/runner.lock' ), new JobSteps( $backup, $backup, new RestoreSteps( $services, $backup ) ), $this->clock, new SystemRandom() );
	}

	/**
	 * Runs ticks without a budget until the job has ended or the condition holds: each tick
	 * does one unit of work and stops, as on a host that ends requests early.
	 *
	 * @param (\Closure(Job): bool)|null $until Stops the ticks early.
	 */
	private function drive( Job $job, ?\Closure $until = null ): Job {
		for ( $tick = 0; $tick < 3000; $tick++ ) {
			$this->runner()->tick( 0.0 );
			$now = $this->jobs->get( $job->id );
			self::assertNotNull( $now );
			if ( $now->state->is_terminal() || ( null !== $until && $until( $now ) ) ) {
				return $now;
			}
		}
		self::fail( 'The job did not end.' );
	}

	/**
	 * Creates the repository and backs the site up with a backup job.
	 *
	 * @return string Snapshot ID.
	 */
	private function backup(): string {
		Repository::initialize( $this->access()->backend(), self::PASSWORD, new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$job = $this->drive( $this->jobs->create( JobType::Backup, 'manual', $this->clock->seconds ) );
		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		$this->clock->advance( 60 );
		return (string) $job->result['snapshot'];
	}

	/**
	 * @param list<string>|null $paths
	 */
	private function restore_job( string $snapshot, ?array $paths = null, bool $archive = false ): Job {
		return $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds, RestoreRequest::from_input( $snapshot, $paths, $archive )->to_data() );
	}

	/**
	 * @return list<string> Names of the lock objects on the server.
	 */
	private function locks(): array {
		return array_values( array_filter( array_keys( $this->server->objects ), static fn ( string $path ): bool => str_starts_with( $path, '/site/locks/' ) ) );
	}

	private function staging( Job $job ): string {
		return $this->directory . '/private/restores/' . $job->id;
	}

	public function test_a_zip_of_selected_files_is_built_in_resumable_ticks(): void {
		$snapshot = $this->backup();
		$job      = $this->restore_job( $snapshot, [ $this->site . '/hello.txt' ], true );
		$building = $this->drive( $job, static fn ( Job $pending ): bool => ( $pending->progress['archive_bytes'] ?? 0 ) > 0 );
		self::assertFalse( $building->state->is_terminal() );
		self::assertFileDoesNotExist( $this->staging( $job ) . '/selection.zip' );
		self::assertSame( [], $this->locks(), 'Local ZIP creation needs no repository lock.' );
		$done = $this->drive( $job );
		self::assertSame( JobState::Succeeded, $done->state );
		$zip = (string) file_get_contents( $this->staging( $job ) . '/selection.zip' );
		self::assertStringStartsWith( "PK\x03\x04", $zip );
		self::assertStringContainsString( 'Hello from the site.', $zip );
		self::assertStringNotContainsString( 'empty.txt', $zip );
		self::assertSame( [], $done->warnings );
	}

	/**
	 * A restore of the docs with an archive, driven until the archive is being written.
	 */
	private function archiving(): Job {
		$job      = $this->restore_job( $this->backup(), [ $this->site . '/docs' ], true );
		$building = $this->drive( $job, static fn ( Job $pending ): bool => ( $pending->progress['archive_bytes'] ?? 0 ) > 0 );
		self::assertSame( 'archiving', $building->progress['phase'] ?? null );
		self::assertFileExists( $this->staging( $job ) . '/selection.zip.part', 'The archive is being written.' );
		return $building;
	}

	/**
	 * What a cancel that came while the archive was built leaves: the staged files, recorded
	 * as the result of a restore that succeeded, and nothing of the archive.
	 */
	private function assert_staged_without_archive( Job $job ): void {
		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertFileEquals( $this->site . '/docs/deep/large.bin', $this->staging( $job ) . '/files' . $this->site . '/docs/deep/large.bin' );
		self::assertFileExists( $this->staging( $job ) . '/entries.jsonl' );
		foreach ( [ '', '.part', '.entries', '.state' ] as $suffix ) {
			self::assertFileDoesNotExist( $this->staging( $job ) . '/selection.zip' . $suffix );
		}
		self::assertCount( 1, $job->warnings );
		self::assertStringContainsString( 'the ZIP was not built', $job->warnings[0] );
		self::assertSame( $job->id, ( new SiteState( $this->options ) )->get( 'last_restore' )['job'] ?? null );
		self::assertSame( [], $this->locks() );
	}

	public function test_a_cancel_while_the_zip_is_built_ends_only_the_zip(): void {
		$job = $this->archiving();

		$this->runner()->cancel( $job->id );

		$this->assert_staged_without_archive( $this->drive( $job ) );
	}

	public function test_deactivating_the_plugin_while_the_zip_is_built_keeps_the_staged_files(): void {
		$job = $this->archiving();

		// What the deactivation does: no tick comes after this call.
		$stopped = $this->runner()->stop( 10.0, [ 'reason' => 'deactivation' ] );

		self::assertNotNull( $stopped );
		$this->assert_staged_without_archive( $stopped );
	}

	public function test_a_zip_that_would_not_fit_is_not_begun_and_the_staged_files_stay(): void {
		$job = $this->restore_job( $this->backup(), [ $this->site . '/docs' ], true );
		$this->drive( $job, static fn ( Job $pending ): bool => 'archiving' === ( $pending->progress['phase'] ?? null ) );
		// The files are staged. A second copy of them and the reserve beside it do not fit.
		$this->space = static fn ( string $path ): float => 32.0 * 1048576;

		$done = $this->drive( $job );

		self::assertSame( JobState::Succeeded, $done->state, (string) $done->error?->message );
		self::assertFileEquals( $this->site . '/docs/deep/large.bin', $this->staging( $job ) . '/files' . $this->site . '/docs/deep/large.bin' );
		foreach ( [ '', '.part', '.entries', '.state' ] as $suffix ) {
			self::assertFileDoesNotExist( $this->staging( $job ) . '/selection.zip' . $suffix, 'Not a byte of the archive was written.' );
		}
		self::assertCount( 1, $done->warnings );
		self::assertStringContainsString( 'ZIP unavailable. It needs about 66 MiB of free disk space, and 32 MiB are free.', $done->warnings[0] );
	}

	public function test_a_backup_and_a_restore_in_many_short_ticks_stage_identical_bytes(): void {
		$snapshot = $this->backup();

		$job = $this->drive( $this->restore_job( $snapshot ) );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertGreaterThan( 8, $job->ticks, 'The restore went through many ticks.' );
		$staged = $this->staging( $job ) . '/files' . $this->site;
		foreach ( [ '/hello.txt', '/empty.txt', '/docs/readme.md', '/docs/deep/large.bin' ] as $file ) {
			self::assertFileEquals( $this->site . $file, $staged . $file );
		}
		self::assertFileDoesNotExist( $staged . '/link', 'A link is recorded, not created.' );
		self::assertSame(
			[
				'snapshot' => $snapshot,
				'files'    => 4,
				'bytes'    => 20 + 400 * 22 + 1_500_000,
				'skipped'  => 1,
				'staging'  => 'restores/' . $job->id,
				'report'   => 'restores/' . $job->id . '/entries.jsonl',
			],
			$job->result
		);
		self::assertSame( 100, $job->progress['percent'] ?? null );
		self::assertSame( 'complete', $job->progress['phase'] ?? null );
		$report = (string) file_get_contents( $this->staging( $job ) . '/entries.jsonl' );
		self::assertStringContainsString( '"type":"symlink"', $report );
		self::assertSame( 0600, fileperms( $this->staging( $job ) . '/entries.jsonl' ) & 0777 );
		self::assertSame( 0700, fileperms( $this->staging( $job ) ) & 0777 );
		self::assertDirectoryDoesNotExist( $this->directory . '/private/jobs/' . $job->id . '/restore', 'The working files of the run are gone.' );
		self::assertSame( [], $this->locks() );
		$last = ( new SiteState( $this->options ) )->get( 'last_restore' );
		self::assertSame( [ $job->id, $snapshot, 4, 'restores/' . $job->id ], [ $last['job'] ?? null, $last['snapshot'] ?? null, $last['files'] ?? null, $last['staging'] ?? null ] );
	}

	public function test_the_ticks_of_a_restore_share_the_index_cache_of_the_jobs(): void {
		$snapshot = $this->backup();
		$before   = count( $this->server->requests );

		$job = $this->drive( $this->restore_job( $snapshot ) );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertGreaterThan( 8, $job->ticks );
		$downloads = array_filter( array_slice( $this->server->requests, $before ), static fn ( string $request ): bool => 1 === preg_match( '~^GET /site/index/[0-9a-f]{64}~', $request ) );
		self::assertNotSame( [], $downloads, 'The index file that the backup wrote last is not in its cache yet.' );
		self::assertSame( array_values( array_unique( $downloads ) ), array_values( $downloads ), 'No tick of the restore downloads an index file again.' );
	}

	public function test_a_restore_of_selected_paths_stages_only_those(): void {
		$snapshot = $this->backup();

		$job = $this->drive( $this->restore_job( $snapshot, [ $this->site . '/docs/deep/', $this->site . '/hello.txt', $this->site . '/docs/deep/large.bin' ] ) );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		$staged = $this->staging( $job ) . '/files' . $this->site;
		self::assertFileEquals( $this->site . '/docs/deep/large.bin', $staged . '/docs/deep/large.bin' );
		self::assertFileEquals( $this->site . '/hello.txt', $staged . '/hello.txt' );
		self::assertFileDoesNotExist( $staged . '/docs/readme.md' );
		self::assertSame( 2, $job->result['files'] ?? null );
		self::assertSame( 0, $job->result['skipped'] ?? null );
	}

	public function test_a_restore_job_whose_tick_was_abandoned_resumes(): void {
		$snapshot = $this->backup();
		$job      = $this->drive( $this->restore_job( $snapshot ), static fn ( Job $now ): bool => 'restoring' === $now->step && ( $now->progress['files'] ?? 0 ) > 0 );
		self::assertSame( JobState::Running, $job->state );

		// The host ends a request in the middle of the step: the run has checkpointed more
		// than the job document shows, and the lease of the dead tick is still in it.
		$services = $this->services();
		$steps    = new RestoreSteps( $services, new BackupSteps( $services->common ) );
		self::assertFalse( $steps->restore( clone $job, $this->jobs->log( $job ), 0.0 ) );
		$job->lease_owner      = 'a tick that died';
		$job->lease_expires_at = $this->clock->seconds + 90;
		$this->jobs->save( $job );
		self::assertSame( 'busy', $this->runner()->tick( 0.0 )->status );

		$this->clock->advance( 91 );
		$job = $this->drive( $job );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		$staged = $this->staging( $job ) . '/files' . $this->site;
		foreach ( [ '/hello.txt', '/empty.txt', '/docs/readme.md', '/docs/deep/large.bin' ] as $file ) {
			self::assertFileEquals( $this->site . $file, $staged . $file );
		}
		self::assertSame( 4, $job->result['files'] ?? null );
		self::assertSame( [], $this->locks() );
	}

	public function test_a_restore_job_that_is_cancelled_while_restoring_leaves_no_lock_and_no_staging(): void {
		$snapshot = $this->backup();
		$job      = $this->drive( $this->restore_job( $snapshot ), static fn ( Job $now ): bool => 'restoring' === $now->step && ( $now->progress['files'] ?? 0 ) > 0 );
		self::assertCount( 1, $this->locks(), 'A restore holds its lock between ticks.' );
		self::assertDirectoryExists( $this->staging( $job ) . '/files' . $this->site );

		$this->runner()->cancel( $job->id );
		$job = $this->drive( $job );

		self::assertSame( JobState::Cancelled, $job->state );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ) );
		self::assertDirectoryDoesNotExist( $this->directory . '/private/jobs/' . $job->id . '/restore' );
		self::assertSame( [], ( new SiteState( $this->options ) )->get( 'last_restore' ), 'Only a finished restore is remembered.' );
	}

	public function test_a_restore_job_whose_step_fails_ends_as_failed_with_the_lock_released(): void {
		$snapshot = $this->backup();
		$job      = $this->drive( $this->restore_job( $snapshot ), static fn ( Job $now ): bool => 'restoring' === $now->step );
		self::assertCount( 1, $this->locks() );

		// Somebody deletes the packs under the restore.
		foreach ( array_keys( $this->server->objects ) as $path ) {
			if ( str_starts_with( $path, '/site/data/' ) ) {
				unset( $this->server->objects[ $path ] );
			}
		}
		$job = $this->drive( $job );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'repository_missing', $job->error?->code );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ), 'A failed restore leaves no partial tree.' );
		self::assertDirectoryDoesNotExist( $this->directory . '/private/jobs/' . $job->id . '/restore' );
	}

	public function test_a_cleanup_that_fails_after_a_finished_restore_keeps_the_job_and_its_staging(): void {
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			self::markTestSkipped( 'Needs a named pipe.' );
		}
		$snapshot = $this->backup();
		$job      = $this->drive( $this->restore_job( $snapshot ), static fn ( Job $now ): bool => 'recording' === $now->step );
		self::assertFalse( $job->state->is_terminal() );
		// Every file is staged. A pipe is neither a file nor a directory: the working directory
		// that holds one cannot be removed.
		$pipe = $this->directory . '/private/jobs/' . $job->id . '/restore/pipe';
		self::assertTrue( posix_mkfifo( $pipe, 0600 ) );

		// The removal that fails says so with a warning of PHP as well.
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$job = $this->drive( $job );
		} finally {
			restore_error_handler();
			unlink( $pipe );
		}

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertFileExists( $this->staging( $job ) . '/files' . $this->site . '/hello.txt' );
		self::assertSame( $job->id, ( new SiteState( $this->options ) )->get( 'last_restore' )['job'] ?? null );
		self::assertDirectoryExists( $this->directory . '/private/jobs/' . $job->id . '/restore', 'The cleanup did fail.' );
	}

	public function test_a_snapshot_that_the_repository_does_not_hold_fails_the_job_in_its_first_tick(): void {
		$this->backup();

		$job = $this->drive( $this->restore_job( str_repeat( 'ab', 32 ) ) );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'repository_changed', $job->error?->code );
		self::assertArrayNotHasKey( 'resumptions', $job->data, 'A snapshot that is not there is not a lost lock.' );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ) );
	}

	public function test_a_path_that_the_snapshot_does_not_hold_fails_the_job_before_a_file_is_written(): void {
		$snapshot = $this->backup();

		$job = $this->drive( $this->restore_job( $snapshot, [ $this->site . '/missing' ] ) );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'restore_path_missing', $job->error?->code, 'The setup is complete; the snapshot lacks the path.' );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ) );
	}

	public function test_a_restore_that_is_known_not_to_fit_fails_with_a_space_error_before_a_file_is_written(): void {
		$snapshot    = $this->backup();
		$this->space = static fn ( string $path ): float => 1048576.0;

		$job = $this->drive( $this->restore_job( $snapshot ) );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'staging_space', $job->error?->code, 'Not "the repository contains data the plugin cannot read".' );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ) );
	}

	public function test_a_restore_without_a_free_space_figure_goes_on_and_says_so_once(): void {
		$snapshot    = $this->backup();
		$this->space = static fn ( string $path ): bool => false;

		$job = $this->drive( $this->restore_job( $snapshot ) );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertCount( 1, $job->warnings );
		self::assertStringContainsString( 'free disk space is unknown', $job->warnings[0] );
		self::assertFileEquals( $this->site . '/docs/deep/large.bin', $this->staging( $job ) . '/files' . $this->site . '/docs/deep/large.bin' );
	}

	#[RunInSeparateProcess]
	public function test_a_write_that_the_disk_refuses_fails_the_restore_with_a_storage_error(): void {
		if ( ! function_exists( 'posix_setrlimit' ) || ! function_exists( 'pcntl_signal_get_handler' ) || ! defined( 'POSIX_RLIMIT_FSIZE' ) ) {
			self::markTestSkipped( 'This failure injection needs POSIX file-size limits and signals.' );
		}
		$snapshot = $this->backup();
		$limits   = posix_getrlimit();
		$soft     = is_int( $limits['soft filesize'] ) ? $limits['soft filesize'] : POSIX_RLIMIT_INFINITY;
		$hard     = is_int( $limits['hard filesize'] ) ? $limits['hard filesize'] : POSIX_RLIMIT_INFINITY;
		$handler  = pcntl_signal_get_handler( SIGXFSZ );
		// As a full disk or a quota refuses a write: no file may grow beyond 512 KiB, and the site has one of 1.5 MB.
		pcntl_signal( SIGXFSZ, SIG_IGN );
		self::assertTrue( posix_setrlimit( POSIX_RLIMIT_FSIZE, 524288, $hard ) );
		set_error_handler( static fn (): bool => true, E_WARNING | E_NOTICE );
		try {
			$job = $this->drive( $this->restore_job( $snapshot ) );
		} finally {
			restore_error_handler();
			posix_setrlimit( POSIX_RLIMIT_FSIZE, $soft, $hard );
			pcntl_signal( SIGXFSZ, $handler );
		}

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'staging_write', $job->error?->code, 'The user is told about the disk, not about damage in the repository.' );
		self::assertSame( [], $this->locks() );
		self::assertDirectoryDoesNotExist( $this->staging( $job ), 'A failed restore leaves no partial tree.' );
	}

	public function test_an_error_of_php_while_a_finished_restore_releases_its_lock_keeps_the_staged_files(): void {
		$snapshot = $this->backup();
		$job      = $this->drive( $this->restore_job( $snapshot ), static fn ( Job $now ): bool => 'recording' === $now->step );
		self::assertFalse( $job->state->is_terminal() );
		// As where a host disabled a function that the request releasing the lock calls.
		$this->server->before = static function ( string $method, string $path ): never {
			throw new \Error( 'Call to undefined function disk_free_space()' );
		};

		$job = $this->drive( $job );

		self::assertSame( JobState::Succeeded, $job->state, (string) $job->error?->message );
		self::assertFileEquals( $this->site . '/hello.txt', $this->staging( $job ) . '/files' . $this->site . '/hello.txt' );
		self::assertStringContainsString( 'could not be asked for leftover locks', (string) file_get_contents( $this->directory . '/private/jobs/' . $job->id . '/events.log' ) );
	}

	/**
	 * @return iterable<string, array{mixed, mixed, string}>
	 */
	public static function invalid_requests(): iterable {
		$snapshot = str_repeat( 'ab', 32 );
		yield 'no snapshot' => [ null, null, 'invalid_snapshot' ];
		yield 'a short snapshot ID' => [ 'abcdef12', null, 'invalid_snapshot' ];
		yield 'upper case' => [ strtoupper( $snapshot ), null, 'invalid_snapshot' ];
		yield 'a path into the snapshot ID' => [ '../' . substr( $snapshot, 3 ), null, 'invalid_snapshot' ];
		yield 'a trailing line break' => [ $snapshot . "\n", null, 'invalid_snapshot' ];
		yield 'paths as text' => [ $snapshot, '/var/www', 'invalid_paths' ];
		yield 'an empty list' => [ $snapshot, [], 'invalid_paths' ];
		yield 'a map' => [ $snapshot, [ 'a' => '/var/www' ], 'invalid_paths' ];
		yield 'a number in the list' => [ $snapshot, [ 7 ], 'invalid_paths' ];
		yield 'a relative path' => [ $snapshot, [ 'var/www' ], 'invalid_paths' ];
		yield 'a path that climbs' => [ $snapshot, [ '/var/../etc' ], 'invalid_paths' ];
		yield 'a NUL byte' => [ $snapshot, [ "/var/www\0" ], 'invalid_paths' ];
		yield 'bytes that are not UTF-8' => [ $snapshot, [ "/var/\xff" ], 'invalid_paths' ];
		yield 'too many paths' => [ $snapshot, array_map( static fn ( int $number ): string => '/' . $number, range( 0, 256 ) ), 'invalid_paths' ];
	}

	#[DataProvider( 'invalid_requests' )]
	public function test_invalid_restore_data_is_refused_before_a_job_exists( mixed $snapshot, mixed $paths, string $code ): void {
		try {
			$this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds, RestoreRequest::from_input( $snapshot, $paths )->to_data() );
			self::fail( 'The request must be refused.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( $code, $refused->context()['code'] ?? null );
		}
		self::assertNull( $this->jobs->active(), 'No job was created.' );
	}

	public function test_a_request_names_the_whole_snapshot_by_default_and_drops_overlapping_paths(): void {
		$snapshot = str_repeat( 'ab', 32 );
		self::assertSame(
			[
				'snapshot' => $snapshot,
				'paths'    => [ '/' ],
			],
			RestoreRequest::from_input( $snapshot, null )->to_data()
		);
		self::assertSame( [ '/var/www', '/x' ], RestoreRequest::from_input( $snapshot, [ '/x', '/var/www/', '/var/www/uploads' ] )->paths );
	}

	public function test_a_job_document_without_a_valid_request_fails_in_the_preflight(): void {
		$this->backup();
		$job = $this->drive( $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds, [ 'snapshot' => 'latest' ] ) );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'preflight', $this->failed_step( $job ) );
		self::assertDirectoryDoesNotExist( $this->staging( $job ) );
	}

	public function test_a_restore_waits_for_the_completed_setup(): void {
		$this->backup();
		( new SettingsStore( $this->options ) )->update( [ 'setup.completed' => false ] );
		self::assertSame( 'setup_incomplete', JobType::Restore->refusal( ( new SettingsStore( $this->options ) )->load() ) );

		$job = $this->drive( $this->restore_job( str_repeat( 'ab', 32 ) ) );

		self::assertSame( JobState::Failed, $job->state );
		self::assertSame( 'preflight', $this->failed_step( $job ) );
	}

	public function test_an_active_restore_keeps_every_other_job_from_starting(): void {
		$this->restore_job( str_repeat( 'ab', 32 ) );

		foreach ( [ JobType::Backup, JobType::Forget, JobType::Prune, JobType::Check, JobType::Restore ] as $type ) {
			try {
				$this->jobs->create( $type, 'schedule', $this->clock->seconds );
				self::fail( 'A second job must not start: ' . $type->value );
			} catch ( JobConflict ) {
				// The schedule finds its slot unhandled at the next heartbeat and tries again.
				self::assertSame( JobType::Restore, $this->jobs->active()?->type );
			}
		}
	}

	public function test_the_steps_of_a_restore_job(): void {
		self::assertSame( [ 'preflight', 'unlocking', 'planning', 'restoring', 'recording' ], JobType::Restore->steps() );
		self::assertSame( [ 'releasing', 'cleaning_up' ], JobType::Restore->cleanup_steps() );
		self::assertTrue( JobType::Restore->cancels_before( 'restoring' ) );
		self::assertTrue( JobType::Restore->cancels_before( 'restoring', 'restore' ) );
		foreach ( [ 'complete', 'database_import', 'database_cleanup', 'archiving' ] as $phase ) {
			self::assertFalse( JobType::Restore->cancels_before( 'restoring', $phase ), 'Every file is staged in ' . $phase . '; the step answers the cancel itself.' );
		}
		self::assertTrue( JobType::Backup->cancels_before( 'backing_up', 'archiving' ), 'Only a restore knows these phases.' );
		self::assertFalse( JobType::Restore->cancels_before( 'recording' ), 'Staged files are recorded, not thrown away by a late cancel.' );
		self::assertFalse( JobType::Restore->is_maintenance() );
	}

	/**
	 * The step in which a job failed, from its log.
	 */
	private function failed_step( Job $job ): string {
		$log = (string) file_get_contents( $this->directory . '/private/jobs/' . $job->id . '/events.log' );
		return 1 === preg_match( '/"level":"error".*"step":"([a-z_]+)"/', $log, $match ) ? $match[1] : '';
	}
}
