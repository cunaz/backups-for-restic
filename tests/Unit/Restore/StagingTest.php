<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\StagedRestore;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The staging of finished restores (ADR 0040) on a real directory: what is listed, when it
 * expires, and how it is removed without offering anything half removed.
 */
final class StagingTest extends TestCase {

	private const string SNAPSHOT = '5d1f3c1a8e0b4f6e9a7c2d3b4e5f60718293a4b5c6d7e8f9011223344556677f';

	private const float NO_DEADLINE = PHP_FLOAT_MAX;

	private string $directory;

	private string $restores;

	private JobStore $jobs;

	private FrozenClock $clock;

	private Staging $staging;

	/** Unix time at which the next job of a test is created; every job gets a minute of its own. */
	private int $created;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'staging' );
		$this->restores  = $this->directory . '/private/restores';
		mkdir( $this->restores, 0700, true );
		$this->clock   = new FrozenClock();
		$this->created = $this->clock->seconds - 86400;
		$this->jobs    = new JobStore( $this->directory . '/private/jobs', new Redactor(), new SystemRandom() );
		$this->staging = new Staging( $this->restores, $this->jobs, $this->clock );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * A job that has ended, or with a state that is not terminal the one active job.
	 *
	 * @param array<string, int|string|bool|null> $result
	 */
	private function job( JobState $state, int $finished = 0, array $result = [], JobType $type = JobType::Restore ): Job {
		$job              = $this->jobs->create( $type, 'manual', $this->created );
		$this->created   += 60;
		$job->state       = $state;
		$job->finished_at = $finished;
		$job->result      = $result;
		$this->jobs->save( $job );
		return $job;
	}

	/**
	 * A restore job that succeeded at a given time, with its staging directory.
	 */
	private function restored( int $finished ): Job {
		$job = $this->job(
			JobState::Succeeded,
			$finished,
			[
				'snapshot' => self::SNAPSHOT,
				'files'    => 3,
				'bytes'    => 12,
				'skipped'  => 0,
				'staging'  => 'restores/placeholder',
				'report'   => 'restores/placeholder/entries.jsonl',
			]
		);
		$this->stage( $job->id );
		return $job;
	}

	/**
	 * A staging directory as a restore job leaves it: three files in two directories, and
	 * the report unless the job never came to move it there.
	 */
	private function stage( string $id, bool $report = true ): string {
		$path = $this->restores . '/' . $id;
		mkdir( $path . '/files/site/deep', 0700, true );
		file_put_contents( $path . '/files/site/one.txt', 'one!' );
		file_put_contents( $path . '/files/site/two.txt', 'two!' );
		file_put_contents( $path . '/files/site/deep/three.txt', 'four' );
		if ( $report ) {
			file_put_contents( $path . '/entries.jsonl', "{}\n" );
		}
		return $path;
	}

	/**
	 * @return list<string> Job IDs that the list shows.
	 */
	private function listed( int $hours = 24 ): array {
		return array_map( static fn ( StagedRestore $restore ): string => $restore->job, $this->staging->listing( $hours ) );
	}

	/**
	 * @return list<string> Names in restores/.
	 */
	private function names(): array {
		return array_values( array_diff( (array) scandir( $this->restores ), [ '.', '..' ] ) );
	}

	public function test_downloads_use_only_regular_files_in_unexpired_successful_staging(): void {
		$job                = $this->restored( $this->clock->seconds );
		$job->data['paths'] = [ '/site/one.txt' ];
		$this->jobs->save( $job );
		$dir = $this->restores . '/' . $job->id;
		file_put_contents( $dir . '/selection.zip', 'archive' );
		file_put_contents( $dir . '/database-preview.json', '{}' );
		self::assertSame( '/site/one.txt', $this->staging->listing( 24 )[0]->file );
		self::assertTrue( $this->staging->listing( 24 )[0]->archive );
		foreach ( [ [ 'file', '/site/one.txt', 'one!' ], [ 'report', '', "{}\n" ], [ 'archive', '', 'archive' ], [ 'database_report', '', '{}' ] ] as [ $kind, $path, $content ] ) {
			$handle = $this->staging->open_download( $job->id, 24, $kind, $path );
			self::assertIsResource( $handle );
			self::assertSame( $content, stream_get_contents( $handle ) );
			fclose( $handle );
		}
		symlink( $dir, $dir . '/files/other' );
		link( $dir . '/entries.jsonl', $dir . '/files/alias.txt' );
		foreach ( [ '/other/selection.zip', '/alias.txt', '/../selection.zip', '/site/one.txt/', '/site', '/' ] as $path ) {
			self::assertNull( $this->staging->open_download( $job->id, 24, 'file', $path ) );
		}
		self::assertNull( $this->staging->open_download( $job->id, 24, 'report', '' ) );
		self::assertNull( $this->staging->open_download( '../' . $job->id, 24, 'archive', '' ) );
		$this->clock->advance( 24 * 3600 );
		self::assertNull( $this->staging->open_download( $job->id, 24, 'archive', '' ) );
	}

	public function test_the_list_shows_a_finished_restore_with_what_its_job_recorded(): void {
		$finished = $this->clock->seconds - 600;
		$older    = $this->restored( $finished - 60 );
		$newer    = $this->restored( $finished );

		$list = $this->staging->listing( 24 );

		self::assertSame( [ $newer->id, $older->id ], array_column( array_map( static fn ( StagedRestore $restore ): array => $restore->to_array(), $list ), 'job' ), 'Newest first.' );
		self::assertSame(
			[
				'job'             => $newer->id,
				'snapshot'        => self::SNAPSHOT,
				'finished'        => $finished,
				'expires'         => $finished + 24 * 3600,
				'files'           => 3,
				'bytes'           => 12,
				'staging'         => 'restores/' . $newer->id,
				'report'          => 'restores/' . $newer->id . '/entries.jsonl',
				'archive'         => false,
				'file'            => null,
				'database_report' => false,
				'fresh_install'   => false,
			],
			$list[0]->to_array()
		);
		self::assertSame( 0, $this->staging->removing( 24 ) );
	}

	public function test_a_staging_whose_job_document_is_gone_is_listed_with_unknown_counts(): void {
		// The ID names its creation, an hour before the frozen clock's 2026-01-01T00:00:00Z.
		$id = '20251231T230000Z-0a1b2c';
		$this->stage( $id );

		$list = $this->staging->listing( 24 );

		self::assertCount( 1, $list );
		self::assertSame(
			[
				'job'             => $id,
				'snapshot'        => null,
				'finished'        => null,
				// Counted from the creation that the ID names: never later than from the unknown end.
				'expires'         => $this->clock->seconds - 3600 + 24 * 3600,
				'files'           => null,
				'bytes'           => null,
				'staging'         => 'restores/' . $id,
				'report'          => 'restores/' . $id . '/entries.jsonl',
				'archive'         => false,
				'file'            => null,
				'database_report' => false,
				'fresh_install'   => false,
			],
			$list[0]->to_array()
		);

		$this->clock->advance( 23 * 3600 );
		self::assertSame( [], $this->listed(), 'It expires 24 hours after the time in its ID.' );
		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 0,
			],
			$this->staging->remove_due( 24, self::NO_DEADLINE )
		);
		self::assertSame( [], $this->names() );
	}

	public function test_a_result_that_is_not_what_a_restore_records_is_shown_as_unknown(): void {
		$job = $this->job(
			JobState::Succeeded,
			$this->clock->seconds,
			[
				'snapshot' => '../../wp-config.php',
				'files'    => '3',
				'bytes'    => -1,
			]
		);
		$this->stage( $job->id, false );

		$restore = $this->staging->listing( 24 )[0];

		self::assertNull( $restore->snapshot );
		self::assertNull( $restore->files );
		self::assertNull( $restore->bytes );
		self::assertNull( $restore->report, 'The report is named only while it is there.' );
	}

	public function test_a_staging_expires_exactly_when_its_hours_are_over(): void {
		$finished = $this->clock->seconds;
		$job      = $this->restored( $finished );
		$none     = [
			'removed' => 0,
			'pending' => 0,
			'failed'  => 0,
		];

		$this->clock->advance( 2 * 3600 - 1 );
		self::assertSame( [ $job->id ], $this->listed( 2 ) );
		self::assertSame( $none, $this->staging->remove_due( 2, self::NO_DEADLINE ), 'One second before its time nothing is removed.' );
		self::assertDirectoryExists( $this->restores . '/' . $job->id . '/files/site/deep' );
		self::assertSame( [], $this->listed( 1 ), 'A shorter setting applies to what is already staged.' );

		$this->clock->advance( 1 );
		self::assertSame( [], $this->listed( 2 ), 'What expired is not offered, even before a heartbeat removed it.' );
		self::assertSame( 1, $this->staging->removing( 2 ) );
		self::assertSame( [ $job->id ], $this->listed( 3 ) );
		self::assertSame( [ 'removed' => 1 ] + $none, $this->staging->remove_due( 2, self::NO_DEADLINE ) );
		self::assertSame( [], $this->names() );
		self::assertSame( 0, $this->staging->removing( 2 ) );
		self::assertNotNull( $this->jobs->get( $job->id ), 'The job and its log stay.' );
	}

	public function test_removing_one_staging_leaves_the_others(): void {
		$first  = $this->restored( $this->clock->seconds - 120 );
		$second = $this->restored( $this->clock->seconds - 60 );

		self::assertTrue( $this->staging->remove( $first->id, self::NO_DEADLINE ) );

		self::assertSame( [ $second->id ], $this->names() );
		self::assertSame( [ $second->id ], $this->listed() );
		self::assertFileExists( $this->restores . '/' . $second->id . '/files/site/deep/three.txt' );
		self::assertNull( $this->staging->remove( $first->id, self::NO_DEADLINE ), 'Nothing is staged for that job any more.' );
		self::assertNull( $this->staging->remove( '20200101T000000Z-000000', self::NO_DEADLINE ), 'Nor for a job that never was.' );
	}

	public function test_leftovers_are_never_listed_and_removed_at_once(): void {
		$kept      = $this->restored( $this->clock->seconds - 60 );
		$failed    = $this->job( JobState::Failed, $this->clock->seconds - 60 );
		$cancelled = $this->job( JobState::Cancelled, $this->clock->seconds - 60 );
		$backup    = $this->job( JobState::Succeeded, $this->clock->seconds - 60, [], JobType::Backup );
		$this->stage( $failed->id );
		$this->stage( $cancelled->id, false );
		$this->stage( $backup->id );
		// No job document and no report: no restore ever finished here.
		$this->stage( '20251231T235900Z-0a1b2c', false );
		// Under the name of a job that succeeded, but no directory.
		$file = $this->job( JobState::Succeeded, $this->clock->seconds - 60 );
		file_put_contents( $this->restores . '/' . $file->id, 'not a staging' );
		// Names that are not those of jobs are not this class's to judge.
		mkdir( $this->restores . '/unrelated' );
		file_put_contents( $this->restores . '/notes.txt', 'keep' );

		self::assertSame( [ $kept->id ], $this->listed() );
		self::assertSame( 5, $this->staging->removing( 24 ) );
		self::assertSame(
			[
				'removed' => 5,
				'pending' => 0,
				'failed'  => 0,
			],
			$this->staging->remove_due( 24, self::NO_DEADLINE )
		);

		$left = $this->names();
		sort( $left, SORT_STRING );
		$expected = [ $kept->id, 'notes.txt', 'unrelated' ];
		sort( $expected, SORT_STRING );
		self::assertSame( $expected, $left );
		self::assertFileExists( $this->restores . '/' . $kept->id . '/entries.jsonl' );
	}

	public function test_the_staging_of_an_active_job_is_spared(): void {
		// Long past any expiry, were it finished.
		$this->created = $this->clock->seconds - 30 * 86400;
		foreach ( [ JobState::Queued, JobState::Running ] as $state ) {
			$job = $this->job( $state );
			$this->stage( $job->id, false );

			self::assertSame( [], $this->listed(), 'What is not finished is not offered.' );
			self::assertSame( 0, $this->staging->removing( 1 ) );
			self::assertSame( 0, array_sum( $this->staging->remove_due( 1, self::NO_DEADLINE ) ) );
			try {
				$this->staging->remove( $job->id, self::NO_DEADLINE );
				self::fail( 'The staging of an active job must not be removed.' );
			} catch ( JobConflict $conflict ) {
				self::assertSame( 'job_conflict', JobError::from_throwable( $conflict )->code );
			}
			self::assertFileExists( $this->restores . '/' . $job->id . '/files/site/deep/three.txt' );

			// The job ends without success: now its directory is a leftover.
			$job->state = JobState::Failed;
			$this->jobs->save( $job );
			self::assertSame( 1, $this->staging->remove_due( 1, self::NO_DEADLINE )['removed'] );
			self::assertSame( [], $this->names() );
		}
	}

	public function test_a_refused_removal_says_whether_a_job_uses_the_staging_or_until_when_its_undo_needs_it(): void {
		$restore = $this->restored( $this->clock->seconds - 7200 );
		$applies = [];
		foreach ( [ 3600, 600 ] as $ago ) {
			// Two applies of the same staging that changed live files, an hour and ten minutes ago.
			$apply       = $this->job( JobState::Failed, $this->clock->seconds - $ago, [], JobType::Apply );
			$apply->data = [ 'restore' => $restore->id ];
			$this->jobs->save( $apply );
			$workspace = $this->jobs->directory( $apply ) . '/undo';
			mkdir( $workspace, 0700, true );
			file_put_contents( $workspace . '/attempted', '1' );
			$applies[] = $apply;
		}
		$refusal = function () use ( $restore ): array {
			try {
				$this->staging->remove( $restore->id, self::NO_DEADLINE );
			} catch ( JobConflict $conflict ) {
				// Not "this restore is still running": a restore that ended has nothing to cancel.
				return $conflict->context();
			}
			self::fail( 'Staged files that an undo needs must not be removed.' );
		};
		// The later apply's window ends last.
		self::assertSame(
			[
				'code'  => 'restore_kept_for_undo',
				'until' => $this->clock->seconds - 600 + 86400,
			],
			$refusal()
		);
		self::assertSame( [ $restore->id => $this->clock->seconds - 600 + 86400 ], $this->jobs->restore_pins( $this->clock->seconds ) );
		self::assertSame( [ $restore->id => true ], $this->jobs->pinned_restores( $this->clock->seconds ) );
		// A preview of the same staging that is still at work: the files are in use.
		$preview       = $this->job( JobState::Running, 0, [], JobType::FilePlan );
		$preview->data = [ 'restore' => $restore->id ];
		$this->jobs->save( $preview );
		self::assertSame( [ 'code' => 'restore_in_use' ], $refusal() );
		self::assertSame( [ $restore->id => null ], $this->jobs->restore_pins( $this->clock->seconds ) );
		$preview->state       = JobState::Succeeded;
		$preview->finished_at = $this->clock->seconds;
		$this->jobs->save( $preview );
		// Once both windows have ended, the files can go.
		file_put_contents( $this->jobs->directory( $applies[1] ) . '/undo/reverted', '1' );
		self::assertSame( $this->clock->seconds - 3600 + 86400, $refusal()['until'] );
		$this->clock->advance( 86400 );
		self::assertSame( [], $this->jobs->restore_pins( $this->clock->seconds ) );
		self::assertTrue( $this->staging->remove( $restore->id, self::NO_DEADLINE ) );
	}

	public function test_an_active_job_without_a_staging_directory_is_a_conflict_not_an_unknown_job(): void {
		$job = $this->job( JobState::Queued );

		$this->expectException( JobConflict::class );
		$this->staging->remove( $job->id, self::NO_DEADLINE );
	}

	public function test_an_active_job_that_is_not_a_restore_has_no_staging_and_is_no_conflict(): void {
		$job = $this->job( JobState::Queued, 0, [], JobType::Backup );
		self::assertNull( $this->staging->remove( $job->id, self::NO_DEADLINE ), 'Nothing is staged for a queued backup, and nobody is told to wait for it.' );

		$job->state = JobState::Running;
		$this->jobs->save( $job );
		self::assertNull( $this->staging->remove( $job->id, self::NO_DEADLINE ), 'Nothing is staged for a running backup either.' );
	}

	public function test_a_directory_with_more_entries_than_one_read_returns_is_removed_completely(): void {
		// Far more than the names that one read of a directory returns, files and directories mixed.
		$fill = function ( string $id ): void {
			$site = $this->restores . '/' . $id . '/files/site';
			for ( $number = 0; $number < 600; $number++ ) {
				file_put_contents( $site . '/file-' . $number . '.txt', 'more' );
			}
			for ( $number = 0; $number < 300; $number++ ) {
				mkdir( $site . '/folder-' . $number, 0700 );
				file_put_contents( $site . '/folder-' . $number . '/inside.txt', 'more' );
			}
		};

		$asked = $this->restored( $this->clock->seconds - 60 );
		$fill( $asked->id );
		self::assertTrue( $this->staging->remove( $asked->id, self::NO_DEADLINE ) );
		self::assertSame( [], $this->names(), 'Nothing of a large directory is left when an administrator removes it.' );

		$expired = $this->restored( $this->clock->seconds - 3 * 3600 );
		$fill( $expired->id );
		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 0,
			],
			$this->staging->remove_due( 1, self::NO_DEADLINE )
		);
		self::assertSame( [], $this->names(), 'Nothing of a large directory is left when it expires.' );
	}

	public function test_a_job_document_that_cannot_be_read_keeps_its_staging(): void {
		$job  = $this->job( JobState::Running );
		$path = $this->stage( $job->id, false );
		// As a newer version of the plugin writes it: nothing proves that this job has ended.
		file_put_contents( $this->directory . '/private/jobs/' . $job->id . '/job.json', (string) json_encode( [ 'schema' => Job::SCHEMA + 1 ] + $job->to_array() ) );
		self::assertNull( $this->jobs->get( $job->id ) );
		$this->clock->advance( 30 * 86400 );

		self::assertSame( [], $this->listed() );
		self::assertSame( 0, array_sum( $this->staging->remove_due( 1, self::NO_DEADLINE ) ) );
		try {
			$this->staging->remove( $job->id, self::NO_DEADLINE );
			self::fail( 'A job that cannot be read may still be running.' );
		} catch ( JobConflict $conflict ) {
			self::assertSame( 'job_unreadable', JobError::from_throwable( $conflict )->code );
		}
		self::assertFileExists( $path . '/files/site/one.txt' );
	}

	public function test_no_staging_is_removed_on_request_while_another_stored_job_cannot_be_read(): void {
		$restore = $this->restored( $this->clock->seconds - 60 );
		$path    = $this->restores . '/' . $restore->id;
		// Any other job: what it is cannot be read, so it may be an apply within its window whose
		// undo needs this staging.
		$unreadable = $this->job( JobState::Succeeded, $this->clock->seconds );
		file_put_contents( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json', '{' );
		try {
			$this->staging->remove( $restore->id, self::NO_DEADLINE );
			self::fail( 'A staging was removed while a stored job cannot be read.' );
		} catch ( JobConflict $conflict ) {
			// Before, it was removed, and that apply's undo was refused with file_staging_gone
			// once its document could be read again.
			self::assertSame( [ 'job_unreadable', $unreadable->id ], [ JobError::from_throwable( $conflict )->code, $conflict->context()['job'] ?? null ] );
		}
		// Nothing was set aside.
		self::assertSame( [ $restore->id ], $this->names() );
		self::assertFileExists( $path . '/files/site/one.txt' );
		// Nothing staged under an ID is still answered as nothing staged.
		self::assertNull( $this->staging->remove( '20251229T000000Z-0d0e0f', self::NO_DEADLINE ) );
		unlink( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json' );
		self::assertTrue( $this->staging->remove( $restore->id, self::NO_DEADLINE ) );
		self::assertSame( [], $this->names() );
	}

	public function test_a_job_document_that_cannot_be_looked_up_keeps_its_staging(): void {
		$job    = $this->restored( $this->clock->seconds - 3 * 86400 );
		$folder = $this->directory . '/private/jobs/' . $job->id;
		chmod( $folder, 0600 );
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// Before, the document counted as gone: the staging expired by the time in its ID, and
			// the heartbeat removed it although nothing proves that its job has ended.
			self::assertSame( [], $this->listed() );
			self::assertSame( 0, array_sum( $this->staging->remove_due( 1, self::NO_DEADLINE ) ) );
			try {
				$this->staging->remove( $job->id, self::NO_DEADLINE );
				self::fail( 'A staging whose job cannot be read was removed.' );
			} catch ( JobConflict $conflict ) {
				// Named, and as a job that the PHP user cannot open, not one for another plugin version.
				self::assertSame( [ 'job_inaccessible', $job->id ], [ JobError::from_throwable( $conflict )->code, $conflict->context()['job'] ?? null ] );
			}
			self::assertNull( $this->staging->files_directory( $job->id, 24 ) );
		} finally {
			chmod( $folder, 0700 );
		}
		self::assertFileExists( $this->restores . '/' . $job->id . '/files/site/one.txt' );
		// Once the document can be read, the staging expires as its job says.
		self::assertSame( 1, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'] );
		self::assertDirectoryDoesNotExist( $this->restores . '/' . $job->id );
	}

	public function test_no_staging_expires_while_a_stored_job_cannot_be_read(): void {
		$expired = $this->restored( $this->clock->seconds - 3 * 86400 );
		// A leftover: no job accounts for it and it holds no report, so it would go at once.
		$leftover = '20251230T000000Z-0a1b2c';
		$this->stage( $leftover, false );
		$aside = $this->restores . '/' . Staging::REMOVING . '20251229T000000Z-0d0e0f';
		mkdir( $aside . '/files', 0700, true );
		// Any job: what it is cannot be read, so it may be an apply whose undo needs a staging.
		$unreadable = $this->job( JobState::Succeeded, $this->clock->seconds );
		file_put_contents( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json', '{' );
		self::assertSame( $unreadable->id, $this->jobs->unreadable() );
		// Only what was set aside is on its way out.
		self::assertSame( 1, $this->staging->removing( 24 ) );
		// Before, both expired with their own hours.
		self::assertSame( 1, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'] );
		self::assertEqualsCanonicalizing( [ $expired->id, $leftover ], $this->names(), 'What was set aside before goes on being deleted.' );
		unlink( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json' );
		self::assertNull( $this->jobs->unreadable() );
		self::assertSame( 2, $this->staging->removing( 24 ) );
		self::assertSame( 2, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'] );
		self::assertSame( [], $this->names() );
	}

	public function test_a_staging_whose_job_document_is_gone_is_no_leftover_while_its_report_cannot_be_looked_up(): void {
		$job = $this->restored( $this->clock->seconds - 60 );
		// Pruned with the old jobs: the job's directory is gone, so it is established that no document is.
		TempDir::remove( $this->directory . '/private/jobs/' . $job->id );
		$path = $this->restores . '/' . $job->id;
		chmod( $path, 0000 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_executable( $path ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// Before, it counted as a leftover without a report and was set aside at once.
			self::assertSame( 0, array_sum( $this->staging->remove_due( 24, self::NO_DEADLINE ) ) );
			self::assertSame( [ $job->id ], $this->names() );
		} finally {
			restore_error_handler();
			foreach ( [ $path, $this->restores . '/' . Staging::REMOVING . $job->id ] as $directory ) {
				if ( is_dir( $directory ) ) {
					chmod( $directory, 0700 );
				}
			}
		}
		// Once its report can be read, it is listed by the time in its ID, as before.
		self::assertSame( [ $job->id ], $this->listed( 48 ) );
	}

	public function test_the_undo_workspace_of_an_apply_stays_until_the_last_second_of_its_window(): void {
		// An apply that attempted a change, which no undo reverted, and whose window ends in a second.
		$apply = $this->job( JobState::Succeeded, $this->clock->seconds - 86399, [], JobType::Apply );
		$undo  = $this->jobs->directory( $apply ) . '/undo';
		mkdir( $undo, 0700 );
		file_put_contents( $undo . '/attempted', '1' );
		$this->staging->remove_due( 24, self::NO_DEADLINE );
		self::assertDirectoryExists( $undo );
		$this->clock->advance( 1 );
		$this->staging->remove_due( 24, self::NO_DEADLINE );
		self::assertDirectoryDoesNotExist( $undo );
	}

	public function test_the_undo_workspace_of_an_apply_stays_beyond_its_window_while_a_stored_job_cannot_be_read(): void {
		// An apply whose window ended an hour ago, with the journal of a change that no undo reverted.
		$apply = $this->job( JobState::Succeeded, $this->clock->seconds - 90000, [], JobType::Apply );
		$undo  = $this->jobs->directory( $apply ) . '/undo';
		mkdir( $undo, 0700 );
		file_put_contents( $undo . '/attempted', '1' );
		// Any job: what it is cannot be read, so it may be an undo of this apply that was queued
		// within the window and still runs, which keeps the journal as long as it is active.
		$unreadable = $this->job( JobState::Running, $this->clock->seconds );
		file_put_contents( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json', '{' );
		self::assertNull( $this->jobs->active() );
		self::assertSame( $unreadable->id, $this->jobs->unreadable() );
		// Before, the journal went with the end of the window, and the saved originals with it.
		$this->staging->remove_due( 24, self::NO_DEADLINE );
		self::assertFileExists( $undo . '/attempted' );
		unlink( $this->directory . '/private/jobs/' . $unreadable->id . '/job.json' );
		$this->staging->remove_due( 24, self::NO_DEADLINE );
		self::assertDirectoryDoesNotExist( $undo );
	}

	public function test_a_link_under_the_name_of_a_job_is_removed_also_where_it_leads_to_a_report(): void {
		$target = $this->directory . '/elsewhere';
		mkdir( $target . '/files', 0700, true );
		file_put_contents( $target . '/entries.jsonl', "{}\n" );
		// A job ID that no job directory has: its document is gone, and the link is no staging.
		symlink( $target, $this->restores . '/20251230T000000Z-0a1b2c' );
		self::assertSame( 1, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'] );
		self::assertSame( [], $this->names() );
		self::assertFileExists( $target . '/entries.jsonl', 'Nothing is deleted through the link.' );
	}

	public function test_a_symbolic_link_inside_staging_is_removed_as_a_link_and_its_target_stays(): void {
		$outside = $this->directory . '/outside';
		mkdir( $outside . '/directory', 0700, true );
		file_put_contents( $outside . '/directory/keep.txt', 'keep' );
		file_put_contents( $outside . '/file.txt', 'keep' );
		$job  = $this->restored( $this->clock->seconds );
		$path = $this->restores . '/' . $job->id;
		symlink( $outside . '/directory', $path . '/files/site/to-directory' );
		symlink( $outside . '/file.txt', $path . '/files/site/deep/to-file' );
		symlink( $outside . '/absent', $path . '/files/dangling' );
		// A link under the name of a job is no staging: it goes as a leftover, and only the link.
		$linked = $this->job( JobState::Succeeded, $this->clock->seconds );
		symlink( $outside, $this->restores . '/' . $linked->id );

		self::assertSame( [ $job->id ], $this->listed() );
		self::assertTrue( $this->staging->remove( $job->id, self::NO_DEADLINE ) );
		self::assertSame( 1, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'] );

		self::assertSame( [], $this->names() );
		self::assertSame( 'keep', file_get_contents( $outside . '/directory/keep.txt' ) );
		self::assertSame( 'keep', file_get_contents( $outside . '/file.txt' ) );
	}

	public function test_a_removal_that_hits_its_deadline_continues_at_the_next_call_and_is_not_listed_in_between(): void {
		$job = $this->restored( $this->clock->seconds );
		for ( $number = 0; $number < 20; $number++ ) {
			file_put_contents( $this->restores . '/' . $job->id . '/files/site/more-' . $number . '.txt', 'more' );
		}
		// Every look at this clock takes a second, so a deadline is reached after as many entries.
		$ticking = new class( $this->clock->seconds ) implements Clock {

			public function __construct( public int $seconds ) {
			}

			public function now(): array {
				return [ $this->seconds++, 0 ];
			}
		};
		$staging = new Staging( $this->restores, $this->jobs, $ticking );
		$files   = static fn ( string $root ): int => iterator_count( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) );
		self::assertSame( 24, $files( $this->restores . '/' . $job->id ) );

		// Ten entries are looked at before the deadline, and at most four of them are directories or the report.
		self::assertFalse( $staging->remove( $job->id, (float) ( $ticking->seconds + 10 ) ), 'The deadline came before the tree was gone.' );

		$aside = $this->restores . '/' . Staging::REMOVING . $job->id;
		self::assertSame( [ Staging::REMOVING . $job->id ], $this->names(), 'The directory is set aside under a name that no list shows.' );
		$left = $files( $aside );
		self::assertGreaterThan( 0, $left, 'A part is left.' );
		self::assertLessThan( 24, $left, 'A part is gone.' );
		self::assertSame( [], $this->listed(), 'Nothing half removed is offered.' );
		self::assertSame( 1, $staging->removing( 24 ) );

		// A call whose time is over before it starts deletes nothing and loses nothing.
		self::assertSame(
			[
				'removed' => 0,
				'pending' => 1,
				'failed'  => 0,
			],
			$staging->remove_due( 24, 0.0 )
		);
		self::assertSame( $left, $files( $aside ) );

		// The next heartbeat finishes what the request began.
		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 0,
			],
			$staging->remove_due( 24, self::NO_DEADLINE )
		);
		self::assertSame( [], $this->names() );
		self::assertSame( 0, $staging->removing( 24 ) );
	}

	public function test_what_is_due_leaves_the_list_even_when_no_time_is_left_to_delete_it(): void {
		$expired = $this->restored( $this->clock->seconds - 3 * 3600 );
		$kept    = $this->restored( $this->clock->seconds - 60 );

		$report = $this->staging->remove_due( 2, (float) $this->clock->seconds );

		self::assertSame(
			[
				'removed' => 0,
				'pending' => 1,
				'failed'  => 0,
			],
			$report
		);
		self::assertSame( [ $kept->id ], $this->listed( 2 ) );
		self::assertFileExists( $this->restores . '/' . Staging::REMOVING . $expired->id . '/files/site/one.txt' );
		// Asking for the removal of what is already set aside finishes it.
		self::assertTrue( $this->staging->remove( $expired->id, self::NO_DEADLINE ) );
		self::assertSame( [ $kept->id ], $this->names() );
	}

	public function test_a_directory_that_cannot_be_removed_does_not_keep_the_others(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse deletion.' );
		}
		$stuck = $this->job( JobState::Failed );
		$other = $this->job( JobState::Failed );
		$this->stage( $stuck->id );
		$this->stage( $other->id );
		chmod( $this->restores . '/' . $stuck->id . '/files/site', 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$report = $this->staging->remove_due( 24, self::NO_DEADLINE );
		} finally {
			restore_error_handler();
			chmod( $this->restores . '/' . Staging::REMOVING . $stuck->id . '/files/site', 0700 );
		}

		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 1,
			],
			$report
		);
		self::assertSame( [ Staging::REMOVING . $stuck->id ], $this->names(), 'What could not be deleted is set aside, not offered.' );
		self::assertSame( 1, $this->staging->remove_due( 24, self::NO_DEADLINE )['removed'], 'The next call tries again.' );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function malformed_ids(): iterable {
		$id = '20260101T000000Z-0a1b2c';
		yield 'empty' => [ '' ];
		yield 'parent directory' => [ '..' ];
		yield 'path into the jobs' => [ '../jobs/' . $id ];
		yield 'path below a staging' => [ $id . '/files' ];
		yield 'upper case' => [ strtoupper( $id ) ];
		yield 'trailing line break' => [ $id . "\n" ];
		yield 'NUL byte' => [ $id . "\0" ];
		yield 'name of a removal' => [ Staging::REMOVING . $id ];
		yield 'not an ID' => [ 'latest' ];
	}

	#[DataProvider( 'malformed_ids' )]
	public function test_a_malformed_job_id_is_refused( string $id ): void {
		$job = $this->restored( $this->clock->seconds );
		mkdir( $this->restores . '/' . Staging::REMOVING . '20260101T000000Z-0a1b2c' );

		try {
			$this->staging->remove( $id, self::NO_DEADLINE );
			self::fail( 'Only the ID of a job names a staging directory.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( 'invalid_job_id', $refused->context()['code'] ?? null );
		}
		self::assertFileExists( $this->restores . '/' . $job->id . '/entries.jsonl' );
		self::assertDirectoryExists( $this->restores . '/' . Staging::REMOVING . '20260101T000000Z-0a1b2c' );
		self::assertDirectoryExists( $this->directory . '/private/jobs/' . $job->id );
	}

	/**
	 * A file preview job that ended and keeps its private plan in the staging of a restore.
	 */
	private function retained_plan( JobStore $jobs, string $restores, Job $restore, int $finished ): Job {
		$plan                        = $jobs->create( JobType::FilePlan, 'manual', $this->created );
		$this->created              += 60;
		$plan->state                 = JobState::Failed;
		$plan->finished_at           = $finished;
		$plan->data['restore']       = $restore->id;
		$plan->data['plan_retained'] = true;
		$jobs->save( $plan );
		mkdir( $restores . '/' . $restore->id . '/plans/' . $plan->id, 0700, true );
		file_put_contents( $restores . '/' . $restore->id . '/plans/' . $plan->id . '/plan.bin', 'rows' );
		return $plan;
	}

	public function test_a_private_directory_named_through_a_link_expires_preview_data_and_staging(): void {
		mkdir( $this->directory . '/volumes/data', 0700, true );
		if ( ! symlink( $this->directory . '/volumes/data', $this->directory . '/data' ) ) {
			self::markTestSkipped( 'Symbolic links cannot be created here.' );
		}
		// As the plugin builds them from BACKUPS_FOR_RESTIC_PRIVATE_DIR=<...>/data/acme/private.
		$private = PrivateDirectory::locate( $this->directory . '/data/acme/private', $this->directory, $this->directory );
		$private->ensure();
		$jobs     = new JobStore( $private->subdirectory( 'jobs' ), new Redactor(), new SystemRandom() );
		$restores = $private->subdirectory( 'restores' );
		$staging  = new Staging( $restores, $jobs, $this->clock );
		$restored = function ( int $finished ) use ( $jobs, $restores ): Job {
			$job              = $jobs->create( JobType::Restore, 'manual', $this->created );
			$this->created   += 60;
			$job->state       = JobState::Succeeded;
			$job->finished_at = $finished;
			$jobs->save( $job );
			mkdir( $restores . '/' . $job->id . '/files', 0700, true );
			file_put_contents( $restores . '/' . $job->id . '/files/one.txt', 'one!' );
			file_put_contents( $restores . '/' . $job->id . '/entries.jsonl', "{}\n" );
			return $job;
		};
		$expired  = $restored( $this->clock->seconds - 3 * 86400 );
		$kept     = $restored( $this->clock->seconds - 60 );
		// A large preview that failed an hour ago and keeps its plan, as the cleanup of a failed preview may.
		$plan = $this->retained_plan( $jobs, $restores, $kept, $this->clock->seconds - 3601 );

		self::assertSame( realpath( $restores ), $restores, 'The plugin works with the real path.' );
		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 0,
			],
			$staging->remove_due( 24, self::NO_DEADLINE )
		);

		self::assertDirectoryDoesNotExist( $restores . '/' . $kept->id . '/plans/' . $plan->id );
		self::assertArrayNotHasKey( 'plan_retained', $jobs->get( $plan->id )?->data ?? [ 'plan_retained' => true ] );
		self::assertDirectoryDoesNotExist( $restores . '/' . $expired->id );
		self::assertFileExists( $restores . '/' . $kept->id . '/files/one.txt' );
		self::assertSame( [ $kept->id ], array_map( static fn ( StagedRestore $restore ): string => $restore->job, $staging->listing( 24 ) ) );
	}

	public function test_preview_data_that_cannot_be_removed_does_not_keep_other_staging_from_expiring(): void {
		$expired = $this->restored( $this->clock->seconds - 3 * 86400 );
		$kept    = $this->restored( $this->clock->seconds - 60 );
		$other   = $this->restored( $this->clock->seconds - 60 );
		$removed = $this->retained_plan( $this->jobs, $this->restores, $other, $this->clock->seconds - 7200 );
		// Created last, so the loop over the file jobs meets it first: its plans folder is a link.
		$stuck   = $this->retained_plan( $this->jobs, $this->restores, $kept, $this->clock->seconds - 7200 );
		$outside = $this->directory . '/outside';
		rename( $this->restores . '/' . $kept->id . '/plans', $outside );
		symlink( $outside, $this->restores . '/' . $kept->id . '/plans' );

		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 1,
			],
			$this->staging->remove_due( 24, self::NO_DEADLINE ),
			'The plan that cannot be removed is counted; the expired staging goes all the same.'
		);

		self::assertDirectoryDoesNotExist( $this->restores . '/' . $expired->id );
		self::assertDirectoryDoesNotExist( $this->restores . '/' . $other->id . '/plans/' . $removed->id, 'The plan of the next job is removed.' );
		self::assertArrayNotHasKey( 'plan_retained', $this->jobs->get( $removed->id )?->data ?? [ 'plan_retained' => true ] );
		self::assertFileExists( $outside . '/' . $stuck->id . '/plan.bin', 'Nothing is deleted through the link.' );
		self::assertTrue( $this->jobs->get( $stuck->id )?->data['plan_retained'] ?? false, 'The next heartbeat tries again.' );
	}

	public function test_an_undo_workspace_or_a_job_that_cannot_be_saved_does_not_keep_staging_from_expiring(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse deletion.' );
		}
		$expired = $this->restored( $this->clock->seconds - 3 * 86400 );
		$kept    = $this->restored( $this->clock->seconds - 60 );
		// An apply whose undo window closed a day ago, with a workspace the file system keeps.
		$apply = $this->job( JobState::Succeeded, $this->clock->seconds - 2 * 86400, [], JobType::Apply );
		$undo  = $this->jobs->directory( $apply ) . '/undo';
		mkdir( $undo . '/entries', 0700, true );
		file_put_contents( $undo . '/entries/copy', 'preimage' );
		file_put_contents( $undo . '/attempted', '1' );
		chmod( $undo . '/entries', 0500 );
		// A preview whose plan can be removed, but whose job document cannot be written afterwards.
		$plan = $this->retained_plan( $this->jobs, $this->restores, $kept, $this->clock->seconds - 7200 );
		chmod( $this->jobs->directory( $plan ), 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$report = $this->staging->remove_due( 24, self::NO_DEADLINE );
		} finally {
			restore_error_handler();
			chmod( $undo . '/entries', 0700 );
			chmod( $this->directory . '/private/jobs/' . $plan->id, 0700 );
		}

		self::assertSame(
			[
				'removed' => 1,
				'pending' => 0,
				'failed'  => 2,
			],
			$report
		);
		self::assertDirectoryDoesNotExist( $this->restores . '/' . $expired->id );
		self::assertFileExists( $undo . '/entries/copy' );
		self::assertTrue( $this->jobs->get( $plan->id )?->data['plan_retained'] ?? false, 'The document still says so, and the next call saves it.' );
		self::assertSame( 0, $this->staging->remove_due( 24, self::NO_DEADLINE )['failed'] );
		self::assertDirectoryDoesNotExist( $undo );
		self::assertArrayNotHasKey( 'plan_retained', $this->jobs->get( $plan->id )?->data ?? [ 'plan_retained' => true ] );
	}

	public function test_nothing_is_listed_before_the_private_directory_has_its_folder(): void {
		$staging = new Staging( $this->directory . '/absent/restores', $this->jobs, $this->clock );

		self::assertSame( [], $staging->listing( 24 ) );
		self::assertSame( 0, $staging->removing( 24 ) );
		self::assertSame( 0, array_sum( $staging->remove_due( 24, self::NO_DEADLINE ) ) );
		self::assertNull( $staging->remove( '20260101T000000Z-0a1b2c', self::NO_DEADLINE ) );
	}
}
