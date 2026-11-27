<?php
/**
 * A site for the tests of the file apply jobs.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Redactor;
use PHPUnit\Framework\Assert;

/**
 * The site of FileChangesTest, for test classes of their own: a live directory with a file
 * that the staged restore replaces (a-existing), a folder that it creates (b-new) with a file
 * in it (new.txt), a configuration file and a link that it skips, the restore's report, and
 * the job service of the apply jobs with an ordinary runner. Its jobs run with real files.
 */
final class ApplySite {

	public string $directory;
	public string $live;
	public string $files;
	public string $uploads;
	public string $restore;
	public FrozenClock $clock;
	public JobStore $jobs;
	public Staging $staging;
	public FileChanges $changes;
	public Runner $runner;

	/** Whether the host lets the plugin write live files. */
	public bool $allowed = true;

	/** Free bytes that the private volume reports, null where it reports none. */
	public ?int $free = PHP_INT_MAX;

	/**
	 * Users who may still write live files.
	 *
	 * @var list<int>
	 */
	public array $administrators = [ 7 ];

	/**
	 * Every plan that the job service asked for, in order.
	 *
	 * @var list<ApplyPlan>
	 */
	public array $plans = [];

	/**
	 * Told of each new job directory, synchronisation and document rename of the store.
	 *
	 * @var (\Closure(string, string): void)|null
	 */
	public ?\Closure $job_writes = null;

	public function __construct( string $prefix = 'apply-site' ) {
		$this->directory = TempDir::create( $prefix );
		$this->live      = $this->directory . '/live';
		mkdir( $this->live );
		$this->clock         = new FrozenClock();
		$random              = new SystemRandom();
		$this->jobs          = new JobStore(
			$this->directory . '/jobs',
			new Redactor(),
			$random,
			function ( string $operation, string $path ): void {
				if ( null !== $this->job_writes ) {
					( $this->job_writes )( $operation, $path );
				}
			}
		);
		$this->staging       = new Staging( $this->directory . '/restores', $this->jobs, $this->clock );
		$source              = $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds - 60 );
		$source->state       = JobState::Succeeded;
		$source->finished_at = $this->clock->seconds;
		$this->jobs->save( $source );
		$this->restore = $source->id;
		$this->files   = $this->directory . '/restores/' . $source->id . '/files';
		mkdir( $this->files . $this->live . '/b-new', 0700, true );
		file_put_contents( $this->live . '/a-existing', 'original' );
		file_put_contents( $this->files . $this->live . '/a-existing', 'replacement' );
		file_put_contents( $this->files . $this->live . '/b-new/new.txt', 'created' );
		file_put_contents( $this->files . $this->live . '/wp-config.php', 'never publish' );
		$this->write_report( $this->live . '/skipped-link' );
		$this->uploads = $this->live . '/uploads';
		$this->changes = $this->service( $this->clock );
		$this->runner  = new Runner( $this->jobs, new RunnerLock( $this->directory . '/runner.lock' ), $this->changes, $this->clock, $random );
	}

	/** Removes the site. */
	public function remove(): void {
		TempDir::remove( $this->directory );
	}

	/** The job service of the site, with the given clock. */
	public function service( Clock $clock ): FileChanges {
		return new FileChanges(
			$this->jobs,
			$this->staging,
			$clock,
			1,
			function ( bool $config, string $id, ?string $uploads = null ): ApplyPlan {
				$restore = $this->jobs->get( $id );
				Assert::assertNotNull( $restore );
				$layout        = SiteLayout::from_job( $restore, $this->live, $uploads ?? $this->uploads );
				$plan          = new ApplyPlan( [ $this->live ], [ $this->live . '/.maintenance', $this->live . '/plugins/active' ], [ $this->live . '/wp-config.php' ], $config, $layout );
				$this->plans[] = $plan;
				return $plan;
			},
			new FileMaintenance( $this->live ),
			fn ( ?int $user = null ): bool => $this->allowed && ( null === $user || in_array( $user, $this->administrators, true ) ),
			new FileSpace( $this->jobs->storage_directory(), fn ( string $path ): ?int => $this->free ),
			fn (): string => $this->uploads
		);
	}

	/** Writes the report of the staged files, with a link at the given path that the restore skips. */
	public function write_report( string $link ): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->files, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		$lines    = [];
		foreach ( $iterator as $item ) {
			$lines[] = json_encode(
				[
					'path' => substr( $item->getPathname(), strlen( $this->files ) ),
					'type' => $item->isDir() ? 'dir' : 'file',
					'mode' => $item->isDir() ? 0755 : 0644,
				],
				JSON_THROW_ON_ERROR
			);
		}
		$lines[] = json_encode(
			[
				'path' => $link,
				'type' => 'symlink',
				'mode' => 0777,
			],
			JSON_THROW_ON_ERROR
		);
		file_put_contents( dirname( $this->files ) . '/entries.jsonl', implode( "\n", $lines ) . "\n" );
	}

	/** Queues the apply of the reviewed inline preview of the restore. */
	public function start(): Job {
		$plan = $this->changes->preview( $this->restore );
		Assert::assertTrue( $plan['applicable'] );
		return $this->changes->start_apply( $this->restore, false, $plan['digest'] );
	}

	/** Runs a job to its end with ticks of two seconds, and checks how it ended. */
	public function run_job( Job $job, JobState $expected = JobState::Succeeded ): Job {
		for ( $i = 0; $i < 24; ++$i ) {
			$run = $this->runner->tick( 2.0 )->job;
			Assert::assertNotNull( $run );
			Assert::assertSame( $job->id, $run->id );
			if ( $run->state->is_terminal() ) {
				Assert::assertFileDoesNotExist( $this->live . '/.maintenance' );
				Assert::assertSame( $expected, $run->state, $run->error?->message ?? '' );
				return $run;
			}
		}
		Assert::fail( 'The file change job did not finish.' );
	}

	/** Reviews the undo of an apply inline, confirms it and runs it. */
	public function undo( Job $apply ): Job {
		$plan = $this->changes->preview_undo( $apply->id );
		Assert::assertTrue( $plan['applicable'] );
		return $this->run_job( $this->changes->start_undo( $apply->id, $plan['digest'] ) );
	}

	/** Reviews the undo of an apply in the background, confirms the review and runs the undo. */
	public function undo_background( Job $apply ): Job {
		$review = $this->changes->request_preview( $apply->id, true, false );
		Assert::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		return $this->run_job( $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id ) );
	}

	/**
	 * The entries an undo kept, page by page, with their reasons.
	 *
	 * @return array<string, string> Reason by live path.
	 */
	public function kept( Job $undo ): array {
		$kept   = [];
		$offset = 0;
		do {
			$page = $this->changes->kept_page( $undo->id, $offset );
			foreach ( $page['entries'] as $entry ) {
				Assert::assertSame( 'keep', $entry['action'] );
				$kept[ (string) $entry['path'] ] = $entry['reason'];
			}
			$offset = $page['next_offset'];
		} while ( null !== $offset );
		Assert::assertCount( $page['total'], $kept );
		return $kept;
	}

	/**
	 * What an undo did, by outcome.
	 *
	 * @return array<string, int|string|bool|null>
	 */
	public static function outcomes( Job $undo ): array {
		return array_intersect_key( $undo->result, array_flip( [ 'restored', 'removed', 'unchanged', 'kept', 'changed_after_review', 'apply_state' ] ) );
	}

	/**
	 * Stages a second restore of the same site with other contents for some files.
	 *
	 * @param array<string, string> $contents Staged contents by live path.
	 * @return string Restore job ID.
	 */
	public function second_restore( array $contents ): string {
		$job              = $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds - 30 );
		$job->state       = JobState::Succeeded;
		$job->finished_at = $this->clock->seconds;
		$this->jobs->save( $job );
		$files = $this->directory . '/restores/' . $job->id . '/files';
		foreach ( $contents as $path => $content ) {
			if ( ! is_dir( $files . dirname( $path ) ) ) {
				mkdir( $files . dirname( $path ), 0700, true );
			}
			file_put_contents( $files . $path, $content );
		}
		$lines = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $files, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$lines[] = json_encode(
				[
					'path' => substr( $item->getPathname(), strlen( $files ) ),
					'type' => $item->isDir() ? 'dir' : 'file',
					'mode' => $item->isDir() ? 0755 : 0644,
				],
				JSON_THROW_ON_ERROR
			);
		}
		file_put_contents( dirname( $files ) . '/entries.jsonl', implode( "\n", $lines ) . "\n" );
		return $job->id;
	}

	/**
	 * Adds 111 files to the restore that the site has with the same content, so that its
	 * review needs a background preview, and queues that preview.
	 */
	public function queue_large_preview( bool $config = false ): Job {
		for ( $index = 0; $index < 111; ++$index ) {
			$name = $this->live . '/same-' . $index;
			file_put_contents( $name, 'already current' );
			file_put_contents( $this->files . $name, 'already current' );
		}
		$this->write_report( $this->live . '/skipped-link' );
		$queued = $this->changes->request_preview( $this->restore, false, $config );
		Assert::assertInstanceOf( Job::class, $queued );
		Assert::assertSame( JobType::FilePlan, $queued->type );
		return $queued;
	}

	/**
	 * Makes the restore one of another site, whose uploads a fresh-site apply maps into this
	 * site's uploads root: a folder with one file. Its content directory adds nothing.
	 */
	public function stage_fresh_uploads(): void {
		TempDir::remove( $this->files );
		$media = '/archived-media';
		mkdir( $this->files . $media . '/album', 0700, true );
		mkdir( $this->files . '/old/site-content', 0700, true );
		if ( ! is_dir( $this->uploads ) ) {
			mkdir( $this->uploads );
		}
		file_put_contents( $this->files . $media . '/album/photo.txt', 'imported upload' );
		$this->write_report( '/old/site-content/link' );
		$job = $this->jobs->get( $this->restore );
		Assert::assertNotNull( $job );
		$snapshot                   = str_repeat( 'e', 64 );
		$job->data['snapshot']      = $snapshot;
		$job->data['fresh_install'] = true;
		$job->data['site_layout']   = SiteLayout::bind(
			$snapshot,
			[
				'database' => true,
				'content'  => '/old/site-content',
				'uploads'  => $media,
			],
			'/old/private/spool/manifest.json'
		);
		$this->jobs->save( $job );
	}

	/** The step at which a job logged its first error. */
	public function failed_step( Job $job ): ?string {
		foreach ( $this->jobs->log( $job )->read() as $entry ) {
			if ( 'error' === $entry['level'] && is_array( $entry['context'] ?? null ) ) {
				return $entry['context']['step'] ?? null;
			}
		}
		return null;
	}

	/**
	 * The lines of a job's log at one level.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function log_lines( Job $job, string $level ): array {
		return array_values( array_filter( $this->jobs->log( $job )->read(), static fn ( array $line ): bool => $level === $line['level'] ) );
	}

	/** The saved document of a job. */
	public function saved( Job $job ): Job {
		return $this->jobs->get( $job->id ) ?? Assert::fail( 'The job ' . $job->id . ' is gone.' );
	}
}
