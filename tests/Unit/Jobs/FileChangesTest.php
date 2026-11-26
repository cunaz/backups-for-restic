<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Apply\ApplyJournal;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\ChunkDigest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\LiveSpellings;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Drives the ordinary runner with real source, destination and journal files. */
final class FileChangesTest extends TestCase {

	private string $directory;
	private string $live;
	private string $files;
	private string $uploads;
	private string $restore;
	private FrozenClock $clock;
	private JobStore $jobs;
	private Staging $staging;
	private FileChanges $changes;
	private Runner $runner;
	private bool $allowed = true;
	private ?int $free    = PHP_INT_MAX;
	/** @var list<int> Users who may still write live files. */
	private array $administrators = [ 7 ];

	/**
	 * Every plan that the job service asked for, in order.
	 *
	 * @var list<ApplyPlan>
	 */
	private array $plans = [];

	/**
	 * Told of each new job directory, synchronisation and document rename of the store.
	 *
	 * @var (\Closure(string, string): void)|null
	 */
	private ?\Closure $job_writes = null;

	/** Snapshots taken of job directories (snapshot()). */
	private int $snapshots = 0;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'apply-job' );
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

	/** The service of the test site, with the given clock. */
	private function service( Clock $clock ): FileChanges {
		return new FileChanges(
			$this->jobs,
			$this->staging,
			$clock,
			1,
			function ( bool $config, string $id, ?string $uploads = null ): ApplyPlan {
				$restore = $this->jobs->get( $id );
				self::assertNotNull( $restore );
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

	private function write_report( string $link ): void {
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

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function start(): Job {
		$plan = $this->changes->preview( $this->restore );
		self::assertTrue( $plan['applicable'] );
		$entries = array_column( $plan['entries'], 'action', 'path' );
		self::assertSame( 'skip', $entries[ $this->live . '/wp-config.php' ] );
		self::assertSame( 'skip', $entries[ $this->live . '/skipped-link' ] );
		return $this->changes->start_apply( $this->restore, false, $plan['digest'] );
	}

	private function run_job( Job $job, JobState $expected = JobState::Succeeded ): Job {
		for ( $i = 0; $i < 24; ++$i ) {
			$run = $this->runner->tick( 2.0 )->job;
			self::assertNotNull( $run );
			self::assertSame( $job->id, $run->id );
			if ( $run->state->is_terminal() ) {
				self::assertFileDoesNotExist( $this->live . '/.maintenance' );
				self::assertSame( $expected, $run->state, $run->error?->message ?? '' );
				return $run;
			}
		}
		self::fail( 'The file change job did not finish.' );
	}

	private function undo( Job $apply ): Job {
		$plan = $this->changes->preview_undo( $apply->id );
		self::assertTrue( $plan['applicable'] );
		return $this->run_job( $this->changes->start_undo( $apply->id, $plan['digest'] ) );
	}

	/**
	 * The entries an undo kept, page by page, with their reasons.
	 *
	 * @return array<string, string> Reason by live path.
	 */
	private function kept( Job $undo ): array {
		$kept   = [];
		$offset = 0;
		do {
			$page = $this->changes->kept_page( $undo->id, $offset );
			foreach ( $page['entries'] as $entry ) {
				self::assertSame( 'keep', $entry['action'] );
				$kept[ (string) $entry['path'] ] = $entry['reason'];
			}
			$offset = $page['next_offset'];
		} while ( null !== $offset );
		self::assertCount( $page['total'], $kept );
		return $kept;
	}

	/**
	 * What an undo did, by outcome.
	 *
	 * @return array<string, int|string|bool|null>
	 */
	private static function outcomes( Job $undo ): array {
		return array_intersect_key( $undo->result, array_flip( [ 'restored', 'removed', 'unchanged', 'kept', 'changed_after_review', 'apply_state' ] ) );
	}

	/**
	 * Stages a second restore of the same site with other contents for some files.
	 *
	 * @param array<string, string> $contents Staged contents by live path.
	 * @return string Restore job ID.
	 */
	private function second_restore( array $contents ): string {
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

	/** The step at which a job logged its first error. */
	private function failed_step( Job $job ): ?string {
		foreach ( $this->jobs->log( $job )->read() as $entry ) {
			if ( 'error' === $entry['level'] && is_array( $entry['context'] ?? null ) ) {
				return $entry['context']['step'] ?? null;
			}
		}
		return null;
	}

	/**
	 * Puts a copy of a live file in its place, as the undo of a later apply over it leaves
	 * it: the same bytes, permissions and modification time under a new inode.
	 */
	private static function rewrite_as_copy( string $path ): void {
		clearstatcache();
		$mtime = filemtime( $path );
		copy( $path, $path . '.copy' );
		chmod( $path . '.copy', fileperms( $path ) & 07777 );
		touch( $path . '.copy', (int) $mtime );
		rename( $path . '.copy', $path );
		clearstatcache();
	}

	public function test_normal_apply_and_undo_pin_staging_beyond_its_download_expiry(): void {
		$job = $this->start();
		try {
			$this->staging->remove( $this->restore, PHP_FLOAT_MAX );
			self::fail( 'Manual removal must refuse an active apply.' );
		} catch ( JobConflict $refused ) {
			self::assertDirectoryExists( $this->files );
			// A job that uses the files, not a restore that is still running.
			self::assertSame( [ 'code' => 'restore_in_use' ], $refused->context() );
		}
		$this->clock->advance( 3601 );
		$this->jobs->prune( 1, $this->clock->seconds );
		self::assertNotNull( $this->jobs->get( $this->restore ) );
		self::assertNull( $this->staging->open_download( $this->restore, 1, 'report', '' ) );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryExists( $this->files );
		$done = $this->run_job( $job );
		self::assertSame( 3, $done->result['files_changed'] );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'created', file_get_contents( $this->live . '/b-new/new.txt' ) );
		self::assertSame( 0644, fileperms( $this->live . '/b-new/new.txt' ) & 0777 );
		self::assertFileDoesNotExist( $this->live . '/wp-config.php' );
		self::assertSame( $job->id, $this->changes->undoable()[0]['job'] );
		try {
			$this->staging->remove( $this->restore, PHP_FLOAT_MAX );
			self::fail( 'Manual removal must refuse the files that the undo needs.' );
		} catch ( JobConflict $refused ) {
			self::assertDirectoryExists( $this->files );
			// Until the recovery window of the apply ends, which the refusal names.
			self::assertSame(
				[
					'code'  => 'restore_kept_for_undo',
					'until' => $done->finished_at + 86400,
				],
				$refused->context()
			);
			self::assertSame( $done->finished_at + 86400, $this->changes->undoable()[0]['expires'] );
		}
		$this->undo( $job );
		self::assertSame( [], $this->changes->undoable() );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $this->files );
		self::assertDirectoryDoesNotExist( $this->jobs->directory( $job ) . '/undo' );
	}

	public function test_apply_reports_dense_preimage_and_candidate_bytes_and_blocks_a_full_volume(): void {
		$unit = max( 1, stat( $this->jobs->storage_directory() )['blksize'] );
		$old  = fopen( $this->live . '/a-existing', 'r+b' );
		ftruncate( $old, 3 * $unit + 1 );
		fclose( $old );
		$this->free = 6 * $unit - 1;
		$review     = $this->changes->request_preview( $this->restore, false, false );
		self::assertIsArray( $review );
		self::assertSame( 6 * $unit, $review['required_bytes'] ); // Four blocks for old bytes, one for each new payload.
		self::assertSame( $this->free, $review['available_bytes'] );
		self::assertFalse( $review['enough_space'] );
		self::assertFalse( $review['applicable'] );
		try {
			$this->changes->start_apply( $this->restore, false, $review['digest'] );
			self::fail( 'A rejected file-space check must prevent enqueue.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_space', $refused->context()['code'] );
		}
		self::assertNull( $this->jobs->active() );
		self::assertFileDoesNotExist( $this->live . '/.maintenance' );
		++$this->free;
		$ready = $this->changes->preview( $this->restore );
		self::assertSame( $review['digest'], $ready['digest'] );
		self::assertTrue( $ready['enough_space'] );
		self::assertTrue( $ready['applicable'] );
	}

	public function test_later_disk_pressure_blocks_the_first_write_even_after_successful_preflight(): void {
		$apply = $this->start();
		$this->runner->tick( 0.0 ); // Preflight completed, mutation request has not started.
		$this->free = max( 1, stat( $this->jobs->storage_directory() )['blksize'] ) * 2;
		$failed     = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_pre_upgrade_in_progress_apply_estimates_the_full_remainder_before_resuming(): void {
		$apply = $this->start();
		$this->runner->tick( 0.0 );
		$saved = $this->jobs->get( $apply->id );
		self::assertNotNull( $saved );
		unset( $saved->data['remaining_bytes'] );
		$this->jobs->save( $saved );
		$this->free = max( 1, stat( $this->jobs->storage_directory() )['blksize'] ) * 2;
		$this->runner->tick( 0.0 );
		self::assertSame( 1, $this->jobs->get( $apply->id )?->data['space_cursor'] );
		$failed = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_partial_apply_fails_on_new_disk_pressure_and_undo_reuses_its_preimage(): void {
		$apply = $this->start();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		$this->free = 0;
		$failed     = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		self::assertSame( 1, $failed->result['entries_completed'] );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$review = $this->changes->preview_undo( $apply->id );
		self::assertSame( 0, $review['required_bytes'] );
		self::assertSame( 0, $review['available_bytes'] );
		self::assertTrue( $review['enough_space'] );
		$this->undo( $apply );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_host_with_unknown_capacity_still_validates_and_applies_reviewed_bytes(): void {
		$this->free = null;
		$review     = $this->changes->preview( $this->restore );
		self::assertGreaterThan( 0, $review['required_bytes'] );
		self::assertNull( $review['available_bytes'] );
		self::assertNull( $review['enough_space'] );
		$this->run_job( $this->start() );
	}

	public function test_stale_confirmation_refused_before_enqueue_and_again_before_writing(): void {
		$review = $this->changes->preview( $this->restore );
		file_put_contents( $this->live . '/a-existing', 'administrator edit' );
		try {
			$this->changes->start_apply( $this->restore, false, $review['digest'] );
			self::fail( 'A changed live file must invalidate the preview.' );
		} catch ( JobConflict $stale ) {
			self::assertNull( $this->jobs->active() );
			self::assertSame( 'file_changed_after_review', $stale->context()['code'] ?? null );
		}
		$apply = $this->start();
		file_put_contents( $this->files . $this->live . '/b-new/new.txt', 'different staged file' );
		$failed = $this->run_job( $apply, JobState::Failed );
		// Not "Another backup job is still running": the files changed after the review.
		self::assertSame( 'file_changed_after_review', $failed->error?->code );
		self::assertSame( [ 0, 0 ], [ $failed->result['entries_completed'], $failed->result['entries_attempted'] ] );
		self::assertSame( 'administrator edit', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_cancelling_a_partial_apply_and_restoring_the_preimage(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$this->runner->cancel( $job->id );
		$partial = $this->run_job( $job, JobState::Cancelled );
		self::assertSame( 1, $partial->result['entries_completed'] );
		$this->undo( $job );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_changing_owned_directory_between_ticks_prevents_child_publication(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		rename( $this->live . '/b-new', $this->live . '/elsewhere' );
		mkdir( $this->live . '/b-new', 0755 );
		$failed = $this->run_job( $job, JobState::Failed );
		// Two of three changes are made; the page says so and that they can be undone.
		self::assertSame( 'file_changed_after_review', $failed->error?->code );
		self::assertSame( [ 2, 2 ], [ $failed->result['entries_completed'], $failed->result['entries_attempted'] ] );
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
		$reasons = array_column( $this->changes->preview_undo( $job->id )['entries'], 'reason', 'path' );
		self::assertSame( 'changed_since_apply', $reasons[ $this->live . '/b-new' ] );
		self::assertSame( 'undo_journal', $reasons[ $this->live . '/a-existing' ] );
		// The replaced file is reverted; the directory that someone else put there stays.
		self::assertSame( 'partly_undone', $this->undo( $job )->result['apply_state'] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryExists( $this->live . '/b-new' );
		self::assertDirectoryExists( $this->live . '/elsewhere' );
	}

	public function test_the_apply_document_that_names_the_manifest_is_on_disk_before_the_first_live_change(): void {
		$job       = $this->start();
		$directory = $this->directory . '/jobs/' . $job->id;
		$live      = null;
		// Without it a power loss can bring back a document without the manifest hash, and undo
		// cannot read the journal of a partial apply.
		$this->job_writes = function ( string $operation, string $path ) use ( $directory, &$live ): void {
			if ( null !== $live || 'sync' !== $operation || $directory !== $path ) {
				return;
			}
			$document = json_decode( (string) file_get_contents( $directory . '/job.json' ), true );
			if ( is_array( $document ) && 'applying' === ( $document['step'] ?? null ) && is_string( $document['data']['manifest_hash'] ?? null ) ) {
				$live = file_get_contents( $this->live . '/a-existing' );
			}
		};
		$this->run_job( $job );
		self::assertSame( 'original', $live );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_a_worker_killed_after_publishing_a_directory_lets_the_apply_and_its_undo_finish(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		self::assertDirectoryExists( $this->live . '/b-new' );
		// The state of a worker killed after the rename and before it recorded the result.
		$entry  = $this->jobs->directory( $job ) . '/undo/item-1/entry.json';
		$record = json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( 'done', $record['phase'] );
		self::assertNotNull( $record['candidate'] );
		$record['phase'] = 'ready';
		file_put_contents( $entry, json_encode( $record, JSON_THROW_ON_ERROR ) );
		$saved = $this->jobs->get( $job->id );
		self::assertNotNull( $saved );
		self::assertSame( 2, $saved->progress['done'] );
		$saved->progress['done'] = 1;
		$this->jobs->save( $saved );
		$this->run_job( $job );
		self::assertSame( 'created', file_get_contents( $this->live . '/b-new/new.txt' ) );
		$this->undo( $job );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( [ '.', '..', 'a-existing' ], scandir( $this->live ) );
	}

	public function test_undo_reverts_the_other_entries_and_keeps_an_edited_file_and_a_folder_with_an_upload(): void {
		$job      = $this->run_job( $this->start() );
		$approved = $this->changes->preview_undo( $job->id );
		// A visitor uploads into the folder that the apply created, and a replaced file is edited.
		file_put_contents( $this->live . '/b-new/new-work', 'keep external changes' );
		file_put_contents( $this->live . '/a-existing', 'keep edit' );
		try {
			$this->changes->start_undo( $job->id, $approved['digest'] );
			self::fail( 'A confirmation of the review before the changes must be stale.' );
		} catch ( JobConflict $stale ) {
			self::assertNotSame( 'undo_nothing_to_revert', $stale->context()['code'] ?? null );
		}
		$review = $this->changes->preview_undo( $job->id );
		self::assertTrue( $review['applicable'] );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 1,
				'keep'    => 2,
			],
			$review['totals']
		);
		$rows = array_column( $review['entries'], null, 'path' );
		self::assertSame( [ 'keep', 'changed_since_apply' ], [ $rows[ $this->live . '/a-existing' ]['action'], $rows[ $this->live . '/a-existing' ]['reason'] ] );
		self::assertSame( [ 'keep', 'new_children' ], [ $rows[ $this->live . '/b-new' ]['action'], $rows[ $this->live . '/b-new' ]['reason'] ] );
		self::assertSame( [ 'remove', 'undo_journal' ], [ $rows[ $this->live . '/b-new/new.txt' ]['action'], $rows[ $this->live . '/b-new/new.txt' ]['reason'] ] );
		$undo = $this->run_job( $this->changes->start_undo( $job->id, $review['digest'] ) );
		self::assertSame(
			[
				'restored'             => 0,
				'removed'              => 1,
				'unchanged'            => 0,
				'kept'                 => 2,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $undo )
		);
		self::assertSame(
			[
				$this->live . '/b-new'      => 'new_children',
				$this->live . '/a-existing' => 'changed_since_apply',
			],
			$this->kept( $undo )
		);
		self::assertSame( 'keep edit', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'keep external changes', file_get_contents( $this->live . '/b-new/new-work' ) );
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
		// The apply says what state it is in, and its kept entries stay undoable in the window.
		$apply = $this->jobs->get( $job->id );
		self::assertNotNull( $apply );
		self::assertSame( 'partly_undone', $apply->result['undo_state'] );
		self::assertSame( 2, $apply->result['undo_kept'] );
		self::assertSame( $undo->id, $apply->result['undo_job'] );
		$listed = $this->changes->undoable();
		self::assertSame( $job->id, $listed[0]['job'] );
		self::assertSame( 'partly_undone', $listed[0]['state'] );
		self::assertSame( 2, $listed[0]['kept'] );
		self::assertDirectoryExists( $this->jobs->directory( $job ) . '/undo/item-0' );
		self::assertFileExists( $this->jobs->directory( $job ) . '/undo/item-0/original' );
	}

	public function test_a_partial_apply_whose_replaced_file_was_updated_reverts_every_other_entry(): void {
		file_put_contents( $this->live . '/c-other', 'other original' );
		file_put_contents( $this->files . $this->live . '/c-other', 'other replacement' );
		$this->write_report( $this->live . '/skipped-link' );
		$apply = $this->start();
		for ( $tick = 0; $tick < 4; ++$tick ) {
			$this->runner->tick( 0.0 ); // Preflight, then one entry per tick.
		}
		self::assertSame( 'created', file_get_contents( $this->live . '/b-new/new.txt' ) );
		$this->free = 0;
		$failed     = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		self::assertSame( 3, $failed->result['entries_attempted'] );
		self::assertSame( 'other original', file_get_contents( $this->live . '/c-other' ) );
		// The site runs half-applied, and an automatic update rewrites one replaced file.
		file_put_contents( $this->live . '/a-existing.new', 'updated by the site' );
		rename( $this->live . '/a-existing.new', $this->live . '/a-existing' );
		$undo = $this->undo( $apply );
		self::assertSame( 2, $undo->result['removed'] );
		self::assertSame( 1, $undo->result['kept'] );
		self::assertSame( [ $this->live . '/a-existing' => 'changed_since_apply' ], $this->kept( $undo ) );
		self::assertSame( 'updated by the site', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( 'other original', file_get_contents( $this->live . '/c-other' ) );
	}

	public function test_undoing_a_later_apply_over_the_same_file_lets_the_earlier_apply_be_undone(): void {
		$first  = $this->run_job( $this->start() );
		$later  = $this->second_restore( [ $this->live . '/a-existing' => 'second replacement' ] );
		$second = $this->run_job( $this->changes->start_apply( $later, false, $this->changes->preview( $later )['digest'] ) );
		self::assertSame( 'second replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertCount( 2, $this->changes->undoable() );
		$installed = fileinode( $this->live . '/a-existing' );
		self::assertSame( 'undone', $this->undo( $second )->result['apply_state'] );
		// The undo put its own copy of the first apply's file back, under a new inode.
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertNotSame( $installed, fileinode( $this->live . '/a-existing' ) );
		$review = $this->changes->preview_undo( $first->id );
		$rows   = array_column( $review['entries'], null, 'path' );
		self::assertSame( [ 'restore', 'installed_content' ], [ $rows[ $this->live . '/a-existing' ]['action'], $rows[ $this->live . '/a-existing' ]['reason'] ] );
		$undo = $this->run_job( $this->changes->start_undo( $first->id, $review['digest'] ) );
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 2,
				'unchanged'            => 0,
				'kept'                 => 0,
				'changed_after_review' => 0,
				'apply_state'          => 'undone',
			],
			self::outcomes( $undo )
		);
		self::assertSame( [], $this->kept( $undo ) );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_a_second_undo_reverts_entries_that_the_first_kept(): void {
		$first  = $this->run_job( $this->start() );
		$later  = $this->second_restore( [ $this->live . '/a-existing' => 'second replacement' ] );
		$second = $this->run_job( $this->changes->start_apply( $later, false, $this->changes->preview( $later )['digest'] ) );
		// The first apply is undone first: its replaced file belongs to the second apply now.
		$partial = $this->undo( $first );
		self::assertSame( [ $this->live . '/a-existing' => 'changed_since_apply' ], $this->kept( $partial ) );
		self::assertSame( 'partly_undone', $partial->result['apply_state'] );
		self::assertSame( 'second replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$states = array_column( $this->changes->undoable(), 'state', 'job' );
		self::assertSame( 'partly_undone', $states[ $first->id ] );
		self::assertSame( 'applied', $states[ $second->id ] );
		$this->undo( $second );
		// Within the window, a second undo of the first apply reverts the entry it kept.
		$review = $this->changes->preview_undo( $first->id );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 0,
				'keep'    => 2,
			],
			$review['totals']
		);
		self::assertSame( [ 'already_undone' ], array_values( array_unique( array_column( array_filter( $review['entries'], static fn ( array $entry ): bool => 'keep' === $entry['action'] ), 'reason' ) ) ) );
		$again = $this->run_job( $this->changes->start_undo( $first->id, $review['digest'] ) );
		self::assertSame( 1, $again->result['restored'] );
		self::assertSame( 2, $again->result['unchanged'] );
		self::assertSame( 'undone', $again->result['apply_state'] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( [], $this->changes->undoable() );
		self::assertFileExists( $this->jobs->directory( $first ) . '/undo/reverted' );
	}

	public function test_an_entry_that_changes_between_review_and_work_is_kept_and_reported(): void {
		$job    = $this->run_job( $this->start() );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 2,
				'keep'    => 0,
			],
			$review['totals']
		);
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		// A process that maintenance does not stop rewrites the replaced file and adds a file to
		// the created folder before the worker reaches them.
		file_put_contents( $this->live . '/a-existing', 'deployed meanwhile' );
		file_put_contents( $this->live . '/b-new/added', 'deployed meanwhile' );
		$done = $this->run_job( $undo );
		self::assertSame(
			[
				'restored'             => 0,
				'removed'              => 1,
				'unchanged'            => 0,
				'kept'                 => 2,
				'changed_after_review' => 2,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $done )
		);
		self::assertSame(
			[
				$this->live . '/b-new'      => 'new_children',
				$this->live . '/a-existing' => 'changed_since_apply',
			],
			$this->kept( $done )
		);
		self::assertSame( 'deployed meanwhile', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'deployed meanwhile', file_get_contents( $this->live . '/b-new/added' ) );
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
	}

	public function test_an_entry_reviewed_as_kept_stays_kept_when_it_becomes_revertible_before_the_worker(): void {
		$job  = $this->run_job( $this->start() );
		$file = $this->live . '/a-existing';
		clearstatcache();
		$installed = (int) filemtime( $file );
		file_put_contents( $file, 'edited' );
		touch( $file, $installed + 60 );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame( [ 'keep', 'changed_since_apply' ], [ array_column( $review['entries'], 'action', 'path' )[ $file ], array_column( $review['entries'], 'reason', 'path' )[ $file ] ] );
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		// The edit is taken back before the worker comes, with the installed bytes and time:
		// the file is as the apply left it, but the administrator confirmed an undo that keeps it.
		file_put_contents( $file, 'replacement' );
		touch( $file, $installed );
		$done = $this->run_job( $undo );
		self::assertSame( 'replacement', file_get_contents( $file ) );
		self::assertSame(
			[
				'restored'             => 0,
				'removed'              => 2,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $done )
		);
		self::assertSame( [ $file => 'changed_since_apply' ], $this->kept( $done ) );
		// A later undo reverts it.
		self::assertSame( 'restore', array_column( $this->changes->preview_undo( $job->id )['entries'], 'action', 'path' )[ $file ] );
	}

	public function test_an_entry_with_nothing_left_to_revert_that_comes_back_before_the_worker_keeps_the_apply_open(): void {
		// A file that the apply creates in a folder that exists already.
		$file = $this->live . '/c-new.txt';
		file_put_contents( $this->files . $file, 'created' );
		$this->write_report( $this->live . '/skipped-link' );
		$job = $this->run_job( $this->start() );
		clearstatcache();
		$installed = [ fileperms( $file ) & 0777, (int) filemtime( $file ) ];
		unlink( $file );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame( [ 'keep', 'removed_since_apply' ], [ array_column( $review['entries'], 'action', 'path' )[ $file ], array_column( $review['entries'], 'reason', 'path' )[ $file ] ] );
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		// Before the worker comes, the file is back as the apply left it.
		file_put_contents( $file, 'created' );
		chmod( $file, $installed[0] );
		touch( $file, $installed[1] );
		$done = $this->run_job( $undo );
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 2,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 1,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $done )
		);
		self::assertSame( [ $file => 'changed_since_apply' ], $this->kept( $done ) );
		self::assertSame( 'created', file_get_contents( $file ) );
		// The undo window stays open, and the next undo reverts it.
		self::assertFileDoesNotExist( $this->jobs->directory( $job ) . '/undo/reverted' );
		self::assertSame( 'remove', array_column( $this->changes->preview_undo( $job->id )['entries'], 'action', 'path' )[ $file ] );
	}

	public function test_a_folder_whose_parent_would_refuse_its_removal_is_kept_and_the_undo_goes_on(): void {
		// A folder that the apply creates in one that the site has already.
		mkdir( $this->live . '/sub' );
		mkdir( $this->files . $this->live . '/sub/made', 0700, true );
		$this->write_report( $this->live . '/skipped-link' );
		$job = $this->run_job( $this->start() );
		self::assertDirectoryExists( $this->live . '/sub/made' );
		// That folder becomes read-only before the review.
		chmod( $this->live . '/sub', 0500 );
		try {
			if ( is_writable( $this->live . '/sub' ) ) {
				self::markTestSkipped( 'This user bypasses directory write permissions.' );
			}
			$review = $this->changes->preview_undo( $job->id );
			self::assertSame( [ 'keep', 'unwritable_destination' ], [ array_column( $review['entries'], 'action', 'path' )[ $this->live . '/sub/made' ], array_column( $review['entries'], 'reason', 'path' )[ $this->live . '/sub/made' ] ] );
			$done = $this->run_job( $this->changes->start_undo( $job->id, $review['digest'] ) );
		} finally {
			chmod( $this->live . '/sub', 0700 );
		}
		self::assertSame( [ 1, 2, 1, 'partly_undone' ], [ $done->result['restored'], $done->result['removed'], $done->result['kept'], $done->result['apply_state'] ] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryExists( $this->live . '/sub/made' );
	}

	public function test_an_entry_that_someone_else_removed_is_offered_again_when_it_comes_back(): void {
		// A file that the apply creates in a folder that exists already.
		$file = $this->live . '/c-new.txt';
		file_put_contents( $this->files . $file, 'created' );
		$this->write_report( $this->live . '/skipped-link' );
		$job = $this->run_job( $this->start() );
		clearstatcache();
		$installed = [ fileperms( $file ) & 0777, (int) filemtime( $file ) ];
		// The replaced file is edited and stays kept, so that the apply stays open.
		file_put_contents( $this->live . '/a-existing', 'edited' );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame( 'remove', array_column( $review['entries'], 'action', 'path' )[ $file ] );
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		unlink( $file );
		$done = $this->run_job( $undo );
		self::assertSame( [ 2, 1, 1, 'partly_undone' ], [ $done->result['removed'], $done->result['unchanged'], $done->result['kept'], $done->result['apply_state'] ] );
		// The file comes back as the apply left it: the next review offers its removal. A file
		// system that reuses inode numbers can give it the recorded one, which makes the reason
		// undo_journal rather than installed_content; either way the removal is offered.
		file_put_contents( $file, 'created' );
		chmod( $file, $installed[0] );
		touch( $file, $installed[1] );
		$again = $this->changes->preview_undo( $job->id );
		self::assertSame( 'remove', array_column( $again['entries'], 'action', 'path' )[ $file ] );
	}

	public function test_an_entry_that_someone_else_removed_after_the_review_is_not_counted_as_removed(): void {
		$job    = $this->run_job( $this->start() );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 2,
				'keep'    => 0,
			],
			$review['totals']
		);
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		unlink( $this->live . '/b-new/new.txt' );
		$done = $this->run_job( $undo );
		// The undo restored the replaced file and removed the directory; the file was gone already.
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 1,
				'unchanged'            => 1,
				'kept'                 => 0,
				'changed_after_review' => 0,
				'apply_state'          => 'undone',
			],
			self::outcomes( $done )
		);
		self::assertSame( 2, $done->result['files_changed'] );
	}

	public function test_an_undo_interrupted_between_its_entries_resumes_without_counting_twice(): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/a-existing', 'edited after the apply' );
		$review = $this->changes->preview_undo( $job->id );
		$undo   = $this->changes->start_undo( $job->id, $review['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The created file.
		$this->runner->tick( 0.0 ); // The created directory.
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$saved  = fn (): Job => $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		$counts = static fn ( int $removed, int $unchanged, int $kept ): array => [
			'restored'             => 0,
			'removed'              => $removed,
			'unchanged'            => $unchanged,
			'kept'                 => $kept,
			'changed_after_review' => 0,
		];
		$rewind = function ( int $done, array $outcomes, ?string $page = null ) use ( $saved ): void {
			$document = $saved();
			// The step, the cursor and the counts, with the kept page that the counts name, as
			// the document last on disk had them.
			$document->step                = 'undoing';
			$document->progress['done']    = $done;
			$document->data['undo_counts'] = $outcomes;
			unset( $document->data['kept_page_digest'] );
			if ( null !== $page ) {
				$document->data['kept_page_digest'] = $page;
			}
			$this->jobs->save( $document );
		};
		// A worker killed after the record that completes the directory, before the document:
		// that record names this undo, and the retry counts the removal as its own.
		$entry  = $this->jobs->directory( $job ) . '/undo/item-1/entry.json';
		$record = json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( [ 'undone', $undo->id ], [ $record['phase'], $record['undo'] ] );
		$rewind( 1, $counts( 1, 0, 0 ) );
		$this->runner->tick( 0.0 ); // The directory again: gone, and removed by this undo.
		self::assertSame( 2, $saved()->progress['done'] );
		self::assertSame( $counts( 2, 0, 0 ), $saved()->data['undo_counts'] );
		// One killed after it removed the directory, before that record: the last record is the
		// apply's, as after a removal by someone else, which the retry cannot tell apart.
		$record['phase'] = 'done';
		$record['undo']  = null;
		file_put_contents( $entry, json_encode( $record, JSON_THROW_ON_ERROR ) );
		$rewind( 1, $counts( 1, 0, 0 ) );
		$this->runner->tick( 0.0 );
		self::assertSame( $counts( 1, 1, 0 ), $saved()->data['undo_counts'] );
		$record = json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( [ 'done', null ], [ $record['phase'], $record['undo'] ] );
		$this->runner->tick( 0.0 ); // The edited file is kept and listed.
		self::assertSame( $counts( 1, 1, 1 ), $saved()->data['undo_counts'] );
		// A worker killed after it listed the kept entry, before the document counted it.
		$rewind( 2, $counts( 1, 1, 0 ) );
		$this->runner->tick( 0.0 ); // The edited file again, at the same place in the list.
		self::assertSame( $counts( 1, 1, 1 ), $saved()->data['undo_counts'] );
		// And one killed after the document counted the last entry, before the next step.
		$rewind( 3, $counts( 1, 1, 1 ), $saved()->data['kept_page_digest'] );
		$done = $this->run_job( $undo );
		self::assertSame(
			[
				'restored'             => 0,
				'removed'              => 1,
				'unchanged'            => 1,
				'kept'                 => 1,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $done )
		);
		self::assertSame( [ $this->live . '/a-existing' => 'changed_since_apply' ], $this->kept( $done ) );
		self::assertSame( 'edited after the apply', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'done', json_decode( (string) file_get_contents( $this->jobs->directory( $job ) . '/undo/item-0/entry.json' ), true, flags: JSON_THROW_ON_ERROR )['phase'] );
	}

	public function test_an_upload_into_a_created_folder_keeps_the_folder_and_reverts_the_rest(): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/b-new/upload.jpg', 'a visitor\'s upload' );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 1,
				'keep'    => 1,
			],
			$review['totals']
		);
		$undo = $this->changes->start_undo( $job->id, $review['digest'] );
		self::assertSame( $this->uploads, $undo->data['uploads_dir'] );
		self::assertSame( $this->clock->seconds, $undo->created_at );
		$this->runner->tick( 2.0 ); // Preflight.
		// One tick reverts entries until its budget is used, not one entry per tick.
		$done = $this->runner->tick( 2.0 )->job;
		self::assertNotNull( $done );
		self::assertSame( JobState::Succeeded, $done->state );
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 1,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $done )
		);
		self::assertSame( $this->restore, $done->result['restore'] );
		self::assertSame( 2, $done->result['files_changed'] );
		self::assertSame( [ $this->live . '/b-new' => 'new_children' ], $this->kept( $done ) );
		self::assertFileExists( $this->jobs->directory( $done ) . '/kept/sealed.json' );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
		self::assertSame( 'a visitor\'s upload', file_get_contents( $this->live . '/b-new/upload.jpg' ) );
	}

	public function test_kept_entries_are_listed_only_for_an_undo_that_has_ended_and_wrote_them(): void {
		$job = $this->run_job( $this->start() );
		clearstatcache();
		$installed = (int) filemtime( $this->live . '/a-existing' );
		file_put_contents( $this->live . '/a-existing', 'edited' );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		foreach ( [ '20260101T000000Z-000000', $job->id, $undo->id ] as $id ) {
			try {
				$this->changes->kept_page( $id, 0 );
				self::fail( 'Only an undo that has ended lists kept entries: ' . $id );
			} catch ( JobConflict ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$done = $this->run_job( $undo );
		$end  = $this->changes->kept_page( $done->id, 1 );
		self::assertSame( [ [], 1, 1, null, $done->id ], [ $end['entries'], $end['total'], $end['offset'], $end['next_offset'], $end['plan_job'] ] );
		foreach ( [ -1, 2 ] as $offset ) {
			try {
				$this->changes->kept_page( $done->id, $offset );
				self::fail( 'A page outside the list must be refused.' );
			} catch ( InvalidArgument ) {
				$this->addToAssertionCount( 1 );
			}
		}
		// The list must be the one that this undo wrote for its apply.
		foreach ( [ [ 'result', 'kept', 2 ], [ 'data', 'apply', $this->restore ] ] as [ $field, $key, $value ] ) {
			$tampered                   = $this->jobs->get( $done->id );
			$tampered->{$field}[ $key ] = $value;
			$this->jobs->save( $tampered );
			try {
				$this->changes->kept_page( $done->id, 0 );
				self::fail( 'A list that does not belong to this undo must be refused.' );
			} catch ( StorageFailed $refused ) {
				self::assertStringContainsString( 'does not belong', $refused->getMessage() );
			}
			$this->jobs->save( $done );
		}
		// Put the installed bytes and time back, and the next undo reverts the entry and keeps nothing.
		file_put_contents( $this->live . '/a-existing', 'replacement' );
		touch( $this->live . '/a-existing', $installed );
		$again = $this->undo( $job );
		self::assertSame( 1, $again->result['restored'] );
		$empty = $this->changes->kept_page( $again->id, 0 );
		self::assertSame( [ [], 0, 0, null, $again->id, '' ], [ $empty['entries'], $empty['total'], $empty['offset'], $empty['next_offset'], $empty['plan_job'], $empty['digest'] ] );
		$this->expectException( InvalidArgument::class );
		$this->changes->kept_page( $again->id, 1 );
	}

	public function test_kept_entries_of_a_job_that_exists_say_why_there_is_no_list_instead_of_that_it_does_not_exist(): void {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		// A document that this version cannot read, written after the undo was queued: it would
		// keep every new job from starting.
		$unreadable = '20260101T000000Z-0000aa';
		mkdir( $this->directory . '/jobs/' . $unreadable, 0700 );
		file_put_contents( $this->directory . '/jobs/' . $unreadable . '/job.json', '{' );
		$missing = '20260101T000000Z-000000';
		$codes   = [];
		foreach ( [ $missing, $unreadable, $job->id, $this->restore, $undo->id ] as $id ) {
			try {
				$this->changes->kept_page( $id, 0 );
				self::fail( 'Only an undo that has ended lists kept entries: ' . $id );
			} catch ( JobConflict $refused ) {
				$codes[ $id ] = $refused->context()['code'] ?? null;
			}
		}
		// Before, each of them was "The job does not exist."
		self::assertSame(
			[
				$missing       => 'not_found',
				$unreadable    => 'job_unreadable',
				$job->id       => 'not_an_undo',
				$this->restore => 'not_an_undo',
				$undo->id      => 'undo_not_ended',
			],
			$codes
		);
		// Once the undo has ended it lists what it kept: nothing here.
		unlink( $this->directory . '/jobs/' . $unreadable . '/job.json' );
		$done = $this->run_job( $undo );
		self::assertSame( 0, $done->result['kept'] );
		$page = $this->changes->kept_page( $done->id, 0 );
		self::assertSame( [ [], 0, null ], [ $page['entries'], $page['total'], $page['next_offset'] ] );
	}

	public function test_an_undo_queued_within_the_window_finishes_after_it(): void {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->clock->advance( 86400 );
		self::assertSame( [], $this->changes->undoable() );
		self::assertSame( 'undone', $this->run_job( $undo )->result['apply_state'] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_the_host_policy_is_checked_before_maintenance_and_before_each_undo_tick(): void {
		$job           = $this->run_job( $this->start() );
		$digest        = $this->changes->preview_undo( $job->id )['digest'];
		$before        = $this->changes->start_undo( $job->id, $digest );
		$this->allowed = false;
		$failed        = $this->run_job( $before, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'preflight', $this->failed_step( $failed ) );
		$this->allowed = true;
		$during        = $this->changes->start_undo( $job->id, $digest );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		$this->allowed = false;
		$failed        = $this->run_job( $during, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'undoing', $this->failed_step( $failed ) );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertFileExists( $this->live . '/b-new/new.txt' );
	}

	public function test_an_undo_refuses_a_damaged_cursor_count_or_review(): void {
		$job    = $this->run_job( $this->start() );
		$digest = $this->changes->preview_undo( $job->id )['digest'];
		$review = 'The confirmed undo review';
		$cases  = [
			[ 'progress', 'done', -1, 'The undo cursor is invalid.', 'undoing' ],
			[ 'progress', 'done', 4, 'The undo cursor is invalid.', 'undoing' ],
			[ 'data', 'undo_counts', [ 'kept' => -1 ], 'The counted undo outcomes are invalid.', 'undoing' ],
			[ 'data', 'undo_counts', [ 'kept' => '1' ], 'The counted undo outcomes are invalid.', 'undoing' ],
			[ 'data', 'undo_counts', 'none', 'The counted undo outcomes are invalid.', 'undoing' ],
			[ 'data', 'reviewed', 'all', $review, 'preflight' ],
			[ 'data', 'reviewed', [ [ 'keep', 'not_applied' ] ], $review, 'preflight' ],
			[
				'data',
				'reviewed',
				[
					'a' => [ 'keep', 'x' ],
					'b' => [ 'keep', 'x' ],
					'c' => [ 'keep', 'x' ],
				],
				$review,
				'preflight',
			],
			[ 'data', 'reviewed', [ 'keep', 'keep', 'keep' ], $review, 'preflight' ],
			[ 'data', 'reviewed', [ [ 'refuse', 'x' ], [ 'keep', 'x' ], [ 'keep', 'x' ] ], $review, 'preflight' ],
			[ 'data', 'reviewed', [ [ 'keep', 1 ], [ 'keep', 'x' ], [ 'keep', 'x' ] ], $review, 'preflight' ],
		];
		foreach ( $cases as $number => [ $field, $key, $value, $message, $step ] ) {
			$undo                   = $this->changes->start_undo( $job->id, $digest );
			$undo->{$field}[ $key ] = $value;
			$this->jobs->save( $undo );
			$failed = $this->run_job( $undo, JobState::Failed );
			self::assertStringStartsWith( $message, (string) $failed->error?->message, 'Case ' . $number );
			self::assertSame( $step, $this->failed_step( $failed ), 'Case ' . $number );
			self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ), 'Case ' . $number );
		}
		self::assertFileExists( $this->live . '/b-new/new.txt' );
	}

	public function test_a_cancelled_undo_records_what_it_reverted_before_it_stopped(): void {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( 1, $cancelled->result['removed'] );
		self::assertSame( 1, $cancelled->result['files_changed'] );
		self::assertSame( 'partly_undone', $cancelled->result['apply_state'] );
		self::assertSame( [], $this->kept( $cancelled ) );
		$apply = $this->jobs->get( $job->id );
		self::assertNotNull( $apply );
		self::assertSame( [ 'partly_undone', null, $undo->id ], [ $apply->result['undo_state'], $apply->result['undo_kept'], $apply->result['undo_job'] ] );
		$listed = $this->changes->undoable()[0];
		self::assertSame( [ 'partly_undone', null ], [ $listed['state'], $listed['kept'] ] );
		// The rest is undone by the next undo.
		$rest = $this->undo( $job );
		self::assertSame( [ 1, 1, 1, 'undone' ], [ $rest->result['restored'], $rest->result['removed'], $rest->result['unchanged'], $rest->result['apply_state'] ] );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
	}

	/**
	 * Where a worker stops in the restore of the last entry that an undo reverts.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stops_of_a_restore(): array {
		return [
			'after the record that completes it' => [ 'undone' ],
			'after its rename'                   => [ 'restore' ],
			'before its rename'                  => [ 'before' ],
			'with a record that cannot be read'  => [ 'damaged' ],
		];
	}

	#[DataProvider( 'stops_of_a_restore' )]
	public function test_a_cancelled_undo_counts_the_restore_at_its_cursor_when_a_record_proves_it( string $stop ): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The edited file is kept.
		$this->runner->tick( 0.0 ); // And the folder that holds it.
		$saved  = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		$entry  = $this->jobs->directory( $job ) . '/undo/item-0/entry.json';
		$record = static fn (): array => json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
		if ( 'damaged' === $stop ) {
			file_put_contents( $entry, '{' );
		} elseif ( 'before' === $stop ) {
			// The record that names this undo is on disk, its rename is not.
			$intent          = $record();
			$intent['phase'] = 'restore';
			$intent['undo']  = $undo->id;
			file_put_contents( $entry, json_encode( $intent, JSON_THROW_ON_ERROR ) );
		} else {
			$this->runner->tick( 0.0 ); // The replaced file is restored and counted.
			self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
			self::assertSame( [ 'undone', $undo->id ], [ $record()['phase'], $record()['undo'] ] );
			// The worker was stopped before the document counted the restore: the step, the
			// cursor and the counts as the document last on disk had them.
			$document                      = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
			$document->step                = 'undoing';
			$document->progress['done']    = $saved->progress['done'];
			$document->data['undo_counts'] = $saved->data['undo_counts'];
			$this->jobs->save( $document );
			if ( 'restore' === $stop ) {
				// And before the record that completes it.
				$intent          = $record();
				$intent['phase'] = 'restore';
				file_put_contents( $entry, json_encode( $intent, JSON_THROW_ON_ERROR ) );
			}
		}
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		$restored  = in_array( $stop, [ 'undone', 'restore' ], true );
		self::assertSame(
			[
				'restored'             => $restored ? 1 : 0,
				'removed'              => 0,
				'unchanged'            => 0,
				'kept'                 => 2,
				'changed_after_review' => 0,
				'apply_state'          => $restored ? 'partly_undone' : 'applied',
			],
			self::outcomes( $cancelled )
		);
		self::assertSame( $restored ? 3 : 2, $cancelled->progress['done'] );
		self::assertSame( $restored ? 'partly_undone' : null, $this->jobs->get( $job->id )?->result['undo_state'] ?? null );
		// Nothing on the live site changed since the stop; a proven restore's record is completed,
		// and one that cannot be read is reported and leaves the entry uncounted.
		self::assertSame( $restored ? 'original' : 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		$warnings = array_values( array_filter( $this->jobs->log( $cancelled )->read(), static fn ( array $line ): bool => 'warning' === $line['level'] ) );
		if ( 'damaged' === $stop ) {
			self::assertSame( [ 'The entry at which the undo stopped could not be checked; it is not counted.', 'environment' ], [ $warnings[0]['message'] ?? null, $warnings[0]['context']['code'] ?? null ] );
		} else {
			self::assertSame( [], $warnings );
			self::assertSame( [ $restored ? 'undone' : 'restore', $undo->id ], [ $record()['phase'], $record()['undo'] ] );
		}
		self::assertSame(
			[
				$this->live . '/b-new/new.txt' => 'changed_since_apply',
				$this->live . '/b-new'         => 'new_children',
			],
			$this->kept( $cancelled )
		);
	}

	/**
	 * An undo whose worker counted its last entry and stopped before the runner saved the step
	 * after it: the job document on disk names the step that reverts, with every entry counted.
	 *
	 * @param string $before What happens to the created file: nothing, edited before the review
	 *                       (the undo keeps it and its folder), or deleted after it (the undo
	 *                       finds nothing left to revert there).
	 * @return array{Job, Job} The apply and the undo.
	 */
	private function counted_and_stopped( string $before ): array {
		$job = $this->run_job( $this->start() );
		if ( 'edited' === $before ) {
			file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		}
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		if ( 'gone' === $before ) {
			unlink( $this->live . '/b-new/new.txt' );
		}
		$this->runner->tick( 0.0 ); // Preflight.
		for ( $entry = 0; $entry < 3; ++$entry ) {
			$this->runner->tick( 0.0 );
		}
		$document = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		self::assertSame( [ 3, 3, 'recording' ], [ $document->progress['total'], $document->progress['done'], $document->step ] );
		// What the worker's own save after the last entry left, before the runner saved the next step.
		$document->step = 'undoing';
		$this->jobs->save( $document );
		return [ $job, $undo ];
	}

	/**
	 * What happens to the created file, what the undo counts (restored, removed, unchanged,
	 * kept), and what its apply is recorded as then.
	 *
	 * @return array<string, array{0: string, 1: list<int>, 2: string, 3: int, 4: bool}>
	 */
	public static function counted_undos(): array {
		return [
			'that reverted every entry'                    => [ 'nothing', [ 1, 2, 0, 0 ], 'undone', 0, true ],
			'that found an entry deleted after its review' => [ 'gone', [ 1, 1, 1, 0 ], 'undone', 0, true ],
			'that kept two entries'                        => [ 'edited', [ 1, 0, 0, 2 ], 'partly_undone', 2, false ],
		];
	}

	/**
	 * @param list<int> $counts Restored, removed, unchanged and kept entries.
	 */
	#[DataProvider( 'counted_undos' )]
	public function test_an_undo_cancelled_after_it_counted_every_entry_is_recorded_as_one_that_finished( string $before, array $counts, string $state, int $kept, bool $reverted ): void {
		[ $job, $undo ] = $this->counted_and_stopped( $before );
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( [ ...$counts, $state ], [ $cancelled->result['restored'], $cancelled->result['removed'], $cancelled->result['unchanged'], $cancelled->result['kept'], $cancelled->result['apply_state'] ] );
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		// Before: partly undone, the number of kept entries unknown, and the apply listed for undo.
		self::assertSame( [ $state, $kept, $undo->id ], [ $apply->result['undo_state'], $apply->result['undo_kept'], $apply->result['undo_job'] ] );
		self::assertSame( $reverted, is_file( $this->jobs->directory( $job ) . '/undo/reverted' ) );
		self::assertSame( $reverted ? [] : [ [ 'partly_undone', 2 ] ], array_map( static fn ( array $listed ): array => [ $listed['state'], $listed['kept'] ], $this->changes->undoable() ) );
		self::assertSame( 'edited' === $before ? 'edited' : null, is_file( $this->live . '/b-new/new.txt' ) ? file_get_contents( $this->live . '/b-new/new.txt' ) : null );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		if ( 'edited' === $before ) {
			self::assertSame(
				[
					$this->live . '/b-new/new.txt' => 'changed_since_apply',
					$this->live . '/b-new'         => 'new_children',
				],
				$this->kept( $cancelled )
			);
		}
	}

	public function test_an_undo_of_one_entry_cancelled_after_it_counted_the_entry_is_recorded_as_undone(): void {
		$restore = $this->second_restore( [ $this->live . '/single.txt' => 'one' ] );
		$job     = $this->run_job( $this->changes->start_apply( $restore, false, $this->changes->preview( $restore )['digest'] ) );
		$undo    = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The created file is removed.
		$document = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		self::assertSame( [ 1, 1, 'recording' ], [ $document->progress['total'], $document->progress['done'], $document->step ] );
		$document->step = 'undoing';
		$this->jobs->save( $document );
		$this->runner->cancel( $undo->id );
		$this->run_job( $undo, JobState::Cancelled );
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		self::assertSame( [ 'undone', 0 ], [ $apply->result['undo_state'], $apply->result['undo_kept'] ] );
		self::assertFileExists( $this->jobs->directory( $job ) . '/undo/reverted' );
		self::assertFileDoesNotExist( $this->live . '/single.txt' );
	}

	public function test_an_undo_cancelled_after_it_counted_every_entry_stays_partly_undone_when_its_apply_cannot_be_marked(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse the marker.' );
		}
		[ $job, $undo ] = $this->counted_and_stopped( 'nothing' );
		$workspace      = $this->jobs->directory( $job ) . '/undo';
		$this->runner->cancel( $undo->id );
		chmod( $workspace, 0500 );
		try {
			$cancelled = $this->run_job( $undo, JobState::Cancelled );
		} finally {
			chmod( $workspace, 0700 );
		}
		// Without the fallback the cleanup ended on the marker, and neither the undo nor its apply
		// recorded that three entries were reverted.
		self::assertSame( [ 1, 2, 0, 0, 'partly_undone' ], [ $cancelled->result['restored'] ?? null, $cancelled->result['removed'] ?? null, $cancelled->result['unchanged'] ?? null, $cancelled->result['kept'] ?? null, $cancelled->result['apply_state'] ?? null ] );
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		self::assertSame( [ 'partly_undone', null, $undo->id ], [ $apply->result['undo_state'] ?? null, $apply->result['undo_kept'] ?? null, $apply->result['undo_job'] ?? null ] );
		self::assertFileDoesNotExist( $workspace . '/reverted' );
		// The apply stays listed, so the next review can settle it.
		self::assertSame( [ [ 'partly_undone', null ] ], array_map( static fn ( array $listed ): array => [ $listed['state'], $listed['kept'] ], $this->changes->undoable() ) );
		$warnings = array_values( array_filter( $this->jobs->log( $cancelled )->read(), static fn ( array $line ): bool => 'warning' === $line['level'] ) );
		self::assertSame( [ 'The undo counted every entry, but its apply could not be marked as undone; the apply stays open for a later undo.', 'environment' ], [ $warnings[0]['message'] ?? null, $warnings[0]['context']['code'] ?? null ] );
	}

	/**
	 * Documents of a stopped undo that do not establish that every entry was counted: what
	 * happens to the created file, the cursor's total and position, the counts by outcome, the
	 * apply's journal count (null as the apply left it, empty for none, otherwise its content),
	 * and what the apply is recorded as then.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: array<string, int>, 4: string|null, 5: string|null}>
	 */
	public static function uncounted_undos(): array {
		$two   = [
			'restored' => 1,
			'removed'  => 1,
		];
		$three = [
			'restored' => 1,
			'removed'  => 2,
		];
		$kept  = [
			'restored' => 1,
			'kept'     => 2,
		];
		return [
			// Counts that add up to a cursor at its total, which the apply's journal contradicts.
			'a total short of the attempted entries'  => [ 'nothing', 2, 2, $two, null, 'partly_undone' ],
			'counts that do not add up to the cursor' => [ 'nothing', 3, 3, $two, null, 'partly_undone' ],
			// The cursor is on the kept folder, which no record proves reverted.
			'a cursor short of counts that add up to the total' => [ 'edited', 3, 1, $kept, null, 'partly_undone' ],
			'a journal count that cannot be read'     => [ 'nothing', 3, 3, $three, 'three', 'partly_undone' ],
			// Nothing counted and nothing attempted: no entry is established, and none was reverted.
			'a document that counts no entry'         => [ 'nothing', 0, 0, [], '', null ],
		];
	}

	/**
	 * @param array<string, int> $counts
	 */
	#[DataProvider( 'uncounted_undos' )]
	public function test_an_undo_cancelled_with_a_document_that_does_not_count_every_entry_stays_partly_undone( string $before, int $total, int $done, array $counts, ?string $journal, ?string $state ): void {
		[ $job, $undo ]                = $this->counted_and_stopped( $before );
		$document                      = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		$document->progress['total']   = $total;
		$document->progress['done']    = $done;
		$document->data['undo_counts'] = $counts;
		$this->jobs->save( $document );
		$attempted = $this->jobs->directory( $job ) . '/undo/attempted';
		if ( '' === $journal ) {
			unlink( $attempted );
		} elseif ( null !== $journal ) {
			file_put_contents( $attempted, $journal );
		}
		$this->runner->cancel( $undo->id );
		$this->run_job( $undo, JobState::Cancelled );
		// What cannot be established keeps the apply open for the next review (fail closed).
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		self::assertSame( [ $state, null ], [ $apply->result['undo_state'] ?? null, $apply->result['undo_kept'] ?? null ] );
		self::assertFileDoesNotExist( $this->jobs->directory( $job ) . '/undo/reverted' );
		self::assertSame( null === $state ? [] : [ [ 'partly_undone', null ] ], array_map( static fn ( array $listed ): array => [ $listed['state'], $listed['kept'] ], $this->changes->undoable() ) );
	}

	/**
	 * An undo that is asked to stop after it removed the file that its apply created; the request
	 * is recorded, and the tick that follows runs the cleanup.
	 *
	 * @return array{Job, Job} The apply and the undo.
	 */
	private function cancelled_after_one_revert(): array {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The created file is removed.
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
		$this->runner->cancel( $undo->id );
		return [ $job, $undo ];
	}

	/**
	 * The lines of a job's log at one level.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function log_lines( Job $job, string $level ): array {
		return array_values( array_filter( $this->jobs->log( $job )->read(), static fn ( array $line ): bool => $level === $line['level'] ) );
	}

	/**
	 * What a stopped undo recorded: its removals and the state that it names for its apply, and
	 * the state and undo that the apply's document names.
	 *
	 * @return list<int|string|null>
	 */
	private function recorded_stop( Job $job, Job $undo ): array {
		$stopped = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		$apply   = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		return [ $stopped->result['removed'] ?? null, $stopped->result['apply_state'] ?? null, $apply->result['undo_state'] ?? null, $apply->result['undo_job'] ?? null ];
	}

	public function test_a_stopped_undo_records_what_it_reverted_when_the_site_cannot_leave_maintenance_mode(): void {
		[ $job, $undo ] = $this->cancelled_after_one_revert();
		$verification   = $this->jobs->directory( $undo ) . '/verification';
		mkdir( $verification );
		touch( $verification . '/checkpoint' );
		$mode = fileperms( $this->live ) & 0777;
		// The WordPress directory refuses the removal of the maintenance marker.
		chmod( $this->live, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_writable( $this->live ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The undo is gone.' );
		} finally {
			restore_error_handler();
			chmod( $this->live, $mode );
		}
		// Before, the cleanup ended at the marker: neither the undo nor its apply recorded the
		// removal, and the cleanup's other parts did not run.
		self::assertSame( [ 1, 'partly_undone', 'partly_undone', $undo->id ], $this->recorded_stop( $job, $undo ) );
		self::assertSame( [ JobState::Cancelled, 'maintenance_stuck' ], [ $ended->state, $ended->error?->code ] );
		self::assertFileExists( $this->live . '/.maintenance' );
		self::assertDirectoryDoesNotExist( $verification );
		self::assertSame( [ [ 'partly_undone', null ] ], array_map( static fn ( array $listed ): array => [ $listed['state'], $listed['kept'] ], $this->changes->undoable() ) );
	}

	public function test_a_stopped_undo_records_what_it_reverted_when_its_verification_data_cannot_be_removed(): void {
		[ $job, $undo ] = $this->cancelled_after_one_revert();
		$held           = $this->jobs->directory( $undo ) . '/verification/held';
		mkdir( $held, 0700, true );
		touch( $held . '/checkpoint' );
		// The folder refuses the removal of the checkpoint in it.
		chmod( $held, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_writable( $held ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The undo is gone.' );
		} finally {
			restore_error_handler();
			chmod( $held, 0700 );
		}
		// Before, the cleanup ended at the removal, and neither the undo nor its apply recorded it.
		self::assertSame( [ 1, 'partly_undone', 'partly_undone', $undo->id ], $this->recorded_stop( $job, $undo ) );
		self::assertSame( [ JobState::Cancelled, 'file_undo_failed' ], [ $ended->state, $ended->error?->code ] );
		self::assertFileDoesNotExist( $this->live . '/.maintenance' );
		self::assertFileExists( $held . '/checkpoint' );
	}

	public function test_a_stopped_undo_records_what_it_reverted_in_the_tick_in_which_its_cleanup_begins(): void {
		[ $job, $undo ] = $this->cancelled_after_one_revert();
		$verification   = $this->jobs->directory( $undo ) . '/verification';
		mkdir( $verification );
		touch( $verification . '/checkpoint' );
		// The tick has no time left for the removal, as one that a deactivation runs with a budget
		// that is used up: the removal waits for a later tick, which may not come.
		$frozen               = $this->clock->seconds;
		$this->clock->seconds = (int) microtime( true ) + 60;
		$this->runner->tick( 0.0 );
		$this->clock->seconds = $frozen;
		$stopped              = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		self::assertSame( [ 'cleaning_up', JobState::Running ], [ $stopped->step, $stopped->state ] );
		self::assertFileExists( $verification . '/checkpoint' );
		// Before, the record waited for the removal: nothing was recorded in that tick.
		self::assertSame( [ 1, 'partly_undone', 'partly_undone', $undo->id ], $this->recorded_stop( $job, $undo ) );
		// The next tick removes the rest and ends the undo with what it recorded once.
		$ended = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( [ 1, 'partly_undone', 'partly_undone', $undo->id ], $this->recorded_stop( $job, $undo ) );
		self::assertNull( $ended->error );
		self::assertDirectoryDoesNotExist( $verification );
	}

	public function test_a_stopped_undo_whose_record_fails_still_leaves_maintenance_mode_and_removes_its_verification_data(): void {
		[ $job, $undo ] = $this->cancelled_after_one_revert();
		$verification   = $this->jobs->directory( $undo ) . '/verification';
		mkdir( $verification );
		touch( $verification . '/checkpoint' );
		$apply = $this->jobs->directory( $job );
		// The apply's document cannot be written, so the undo cannot record what it did.
		chmod( $apply, 0500 );
		try {
			if ( is_writable( $apply ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The undo is gone.' );
		} finally {
			chmod( $apply, 0700 );
		}
		self::assertSame( [ JobState::Cancelled, 'file_undo_failed' ], [ $ended->state, $ended->error?->code ] );
		// Nothing is recorded that the documents do not hold, and the other parts still ran. An undo
		// that stopped before it counted every entry takes no fallback of a finished one.
		self::assertSame( [ null, null, null, null ], $this->recorded_stop( $job, $undo ) );
		self::assertSame( [], $this->log_lines( $ended, 'warning' ) );
		self::assertFileDoesNotExist( $this->live . '/.maintenance' );
		self::assertDirectoryDoesNotExist( $verification );
	}

	public function test_a_stopped_undo_whose_release_and_removal_both_fail_reports_each_and_records_what_it_did(): void {
		[ $job, $undo ] = $this->cancelled_after_one_revert();
		$held           = $this->jobs->directory( $undo ) . '/verification/held';
		mkdir( $held, 0700, true );
		touch( $held . '/checkpoint' );
		$mode = fileperms( $this->live ) & 0777;
		chmod( $this->live, 0500 );
		chmod( $held, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_writable( $this->live ) || is_writable( $held ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The undo is gone.' );
		} finally {
			restore_error_handler();
			chmod( $this->live, $mode );
			chmod( $held, 0700 );
		}
		self::assertSame( [ 1, 'partly_undone', 'partly_undone', $undo->id ], $this->recorded_stop( $job, $undo ) );
		// The first failure is the job's error; the log names each failure once, the other one first.
		self::assertSame( 'maintenance_stuck', $ended->error?->code );
		self::assertSame(
			[ [ 'file_undo_failed', 'cleaning_up' ], [ 'maintenance_stuck', 'cleaning_up' ] ],
			array_map( static fn ( array $line ): array => [ $line['context']['code'] ?? null, $line['context']['step'] ?? null ], $this->log_lines( $ended, 'error' ) )
		);
	}

	public function test_an_undo_cancelled_before_its_first_entry_ends_without_an_error_and_records_nothing(): void {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( [ null, [] ], [ $cancelled->error, $cancelled->result ] );
		self::assertArrayNotHasKey( 'undo_state', $this->jobs->get( $job->id )?->result ?? [ 'undo_state' => 'gone' ] );
		self::assertSame( [ [ $job->id, 'applied' ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->changes->undoable() ) );
	}

	public function test_an_apply_can_be_undone_until_the_last_second_of_its_window_and_only_once_it_has_ended(): void {
		$job     = $this->run_job( $this->start() );
		$journal = new ApplyJournal( $this->jobs, $this->staging, $this->clock, 1 );
		$apply   = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		$this->clock->advance( 86399 );
		self::assertSame( [ $job->id ], array_column( $this->changes->undoable(), 'job' ) );
		// An apply that has not ended is no apply to undo, not even for a runner that undoes it.
		self::assertTrue( $journal->window( $apply, true ) );
		$apply->state = JobState::Running;
		self::assertFalse( $journal->window( $apply, true ) );
		// Nor is a job of another type, whatever records lie in its directory.
		$restore = $this->jobs->get( $this->restore ) ?? self::fail( 'The restore is gone.' );
		mkdir( $this->jobs->directory( $restore ) . '/undo', 0700 );
		file_put_contents( $this->jobs->directory( $restore ) . '/undo/attempted', '1' );
		self::assertFalse( $journal->window( $restore, true ) );
		// Nor an ID that no job has.
		try {
			$this->changes->preview_undo( '20260101T000000Z-ffffff' );
			self::fail( 'An undo was offered for no job.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'undo_unavailable', $refused->context()['code'] ?? null );
		}
		$this->clock->advance( 1 );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_a_stopped_apply_records_its_changes_when_the_site_cannot_leave_maintenance_mode(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The first entry is replaced.
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		$this->runner->cancel( $job->id );
		$mode = fileperms( $this->live ) & 0777;
		chmod( $this->live, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_writable( $this->live ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The apply is gone.' );
		} finally {
			restore_error_handler();
			chmod( $this->live, $mode );
		}
		self::assertSame( [ JobState::Cancelled, 'maintenance_stuck' ], [ $ended->state, $ended->error?->code ] );
		// Before, the cleanup ended at the marker before the apply recorded what it changed.
		self::assertSame( [ 1, 1 ], [ $ended->result['entries_completed'] ?? null, $ended->result['entries_attempted'] ?? null ] );
		self::assertSame( [ $job->id ], array_column( $this->changes->undoable(), 'job' ) );
		self::assertSame( [], $this->log_lines( $ended, 'warning' ) );
	}

	/**
	 * The records of an apply's undo: how to put them in place, and whether they hold its
	 * recovery window open (true), end it (false) or cannot be established (null).
	 *
	 * @return array<string, array{0: string, 1: bool|null}>
	 */
	public static function undo_records(): array {
		return [
			'an attempted change that no undo reverted'    => [ 'attempted', true ],
			'an undo that reverted the apply completely'   => [ 'reverted', false ],
			'no attempted change'                          => [ 'none', false ],
			'records below a folder that cannot be searched' => [ 'unsearchable', null ],
			'a folder at the name of the attempted count'  => [ 'attempted folder', null ],
			'a link at the name of the reverted marker'    => [ 'reverted link', null ],
			'a link in place of the folder of the records' => [ 'linked folder', null ],
		];
	}

	/**
	 * Puts the records of an apply's undo in one of the states of undo_records().
	 *
	 * @return \Closure(): void Puts them back as the apply left them.
	 */
	private function undo_records_as( Job $job, string $records ): \Closure {
		$undo  = $this->jobs->directory( $job ) . '/undo';
		$count = (string) file_get_contents( $undo . '/attempted' );
		if ( 'reverted' === $records ) {
			file_put_contents( $undo . '/reverted', '1' );
			return static fn (): bool => unlink( $undo . '/reverted' );
		}
		if ( 'none' === $records ) {
			unlink( $undo . '/attempted' );
			return static fn (): int|false => file_put_contents( $undo . '/attempted', $count );
		}
		if ( 'unsearchable' === $records ) {
			chmod( $undo, 0600 );
			return static fn (): bool => chmod( $undo, 0700 );
		}
		if ( 'attempted folder' === $records ) {
			unlink( $undo . '/attempted' );
			mkdir( $undo . '/attempted', 0700 );
			return static fn (): int|false => rmdir( $undo . '/attempted' ) ? file_put_contents( $undo . '/attempted', $count ) : false;
		}
		if ( 'reverted link' === $records ) {
			file_put_contents( $this->directory . '/elsewhere', '1' );
			symlink( $this->directory . '/elsewhere', $undo . '/reverted' );
			return static fn (): bool => unlink( $undo . '/reverted' );
		}
		if ( 'linked folder' === $records ) {
			rename( $undo, $undo . '-moved' );
			symlink( $undo . '-moved', $undo );
			return static fn (): bool => unlink( $undo ) && rename( $undo . '-moved', $undo );
		}
		return static fn (): bool => true;
	}

	#[DataProvider( 'undo_records' )]
	public function test_the_undo_records_of_an_apply_decide_its_window_only_where_they_are_established( string $records, ?bool $open ): void {
		$job = $this->run_job( $this->start() );
		// As an earlier undo that kept two entries leaves it.
		$job->result['undo_state'] = 'partly_undone';
		$job->result['undo_kept']  = 2;
		$this->jobs->save( $job );
		$undo    = $this->jobs->directory( $job ) . '/undo';
		$restore = $this->undo_records_as( $job, $records );
		try {
			if ( 'unsearchable' === $records && is_executable( $undo ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// The staged files stay for the window, also where its records cannot be established.
			self::assertSame( false === $open ? [] : [ $this->restore => $job->finished_at + 86400 ], $this->jobs->restore_pins( $this->clock->seconds ) );
			// The undo list says what is the case: an apply that can be undone, or one whose records cannot be read.
			self::assertSame( false === $open ? [] : [ true === $open ? [ $job->id, 'partly_undone', 2 ] : [ $job->id, 'unreadable', null ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'], $listed['kept'] ], $this->changes->undoable() ) );
			// An undo is offered only for records that hold the window open, and refused with a
			// code of its own, not as one past its window, where they cannot be established.
			try {
				$review = $this->changes->request_preview( $job->id, true, false );
				self::assertTrue( $open, 'An undo was offered.' );
				self::assertIsArray( $review );
				self::assertTrue( $review['applicable'] );
			} catch ( JobConflict $refused ) {
				self::assertNotTrue( $open, $refused->getMessage() );
				self::assertSame( false === $open ? 'undo_unavailable' : 'undo_records_unreadable', $refused->context()['code'] ?? null );
			}
			// Past the staging's own hour, the heartbeat keeps the staged files and the journal for
			// the window, and removes them where the records establish that no window is open.
			$this->clock->advance( 3601 );
			$this->staging->remove_due( 1, PHP_FLOAT_MAX );
			self::assertSame( false !== $open, is_dir( $this->files ) );
			self::assertSame( false !== $open, is_dir( $undo ) );
		} finally {
			if ( false !== $open ) {
				$restore();
			}
		}
		if ( false !== $open ) {
			// The records can be read again, and the undo reverts what the apply changed.
			self::assertSame( [ [ $job->id, 'partly_undone' ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->changes->undoable() ) );
			$this->undo( $job );
			self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: bool|null}>
	 */
	public static function undo_records_of_a_background_apply(): array {
		return [
			'an attempted change that no undo reverted'  => [ 'attempted', true ],
			'an undo that reverted the apply completely' => [ 'reverted', false ],
			'a link at the name of the reverted marker'  => [ 'reverted link', null ],
		];
	}

	#[DataProvider( 'undo_records_of_a_background_apply' )]
	public function test_the_sealed_review_of_an_apply_stays_while_its_undo_records_hold_the_window_or_cannot_be_established( string $records, ?bool $open ): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$this->undo_records_as( $apply, $records );
		$sealed = dirname( $this->files ) . '/plans/' . $plan->id;
		self::assertDirectoryExists( $sealed );
		// Past the hour in which a review can be confirmed, after which an unused one goes.
		$this->clock->advance( 3601 );
		self::assertSame( false !== $open, isset( $this->jobs->pinned_file_plans( $this->clock->seconds )[ $plan->id ] ) );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertSame( false !== $open, is_dir( $sealed ) );
	}

	public function test_a_background_undo_review_whose_records_cannot_be_established_ends_with_a_code_of_its_own(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$this->undo_records_as( $apply, 'reverted link' );
		$failed = $this->run_job( $review, JobState::Failed );
		// Before, the review ended with the code of an apply past its window, undo_unavailable.
		self::assertSame( 'undo_records_unreadable', $failed->error?->code );
		self::assertDirectoryExists( $this->files );
	}

	/**
	 * How the document of an ended apply comes to be one that this process cannot read.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function apply_documents_that_cannot_be_read(): array {
		return [
			'below a job directory that cannot be searched' => [ 'unsearchable' ],
			'written by a newer version of the plugin' => [ 'newer' ],
		];
	}

	/**
	 * Makes the document of a job one that get() does not load, and returns what makes it
	 * readable again.
	 *
	 * @param Job    $job Job.
	 * @param string $how unsearchable or newer.
	 * @return \Closure(): void Undoes it.
	 */
	private function unreadable_document( Job $job, string $how ): \Closure {
		$folder = $this->jobs->directory( $job );
		if ( 'newer' === $how ) {
			$saved = (string) file_get_contents( $folder . '/job.json' );
			file_put_contents( $folder . '/job.json', (string) json_encode( [ 'schema' => Job::SCHEMA + 1 ] + (array) json_decode( $saved, true ) ) );
			$readable = static function () use ( $folder, $saved ): void {
				file_put_contents( $folder . '/job.json', $saved );
			};
		} else {
			chmod( $folder, 0600 );
			$readable = static function () use ( $folder ): void {
				chmod( $folder, 0700 );
			};
			if ( is_executable( $folder ) ) {
				$readable();
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
		}
		self::assertNull( $this->jobs->get( $job->id ) );
		self::assertSame( $job->id, $this->jobs->unreadable() );
		return $readable;
	}

	#[DataProvider( 'apply_documents_that_cannot_be_read' )]
	public function test_an_apply_whose_document_cannot_be_read_keeps_the_staged_files_that_its_undo_needs( string $how ): void {
		$apply    = $this->run_job( $this->start() );
		$readable = $this->unreadable_document( $apply, $how );
		try {
			// Past the staging's own hour, well within the apply's window of 24 hours.
			$this->clock->advance( 3601 );
			$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		} finally {
			$readable();
		}
		// Before, the staging expired with its own hour while the journal stayed: the apply was
		// listed as undoable again, and its undo was refused with file_staging_gone.
		self::assertDirectoryExists( $this->files );
		self::assertSame( [ [ $apply->id, 'applied' ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->changes->undoable() ) );
		$this->undo( $apply );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		// With every document readable, the staging expires as before once nothing needs it.
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $this->files );
	}

	#[DataProvider( 'apply_documents_that_cannot_be_read' )]
	public function test_the_undo_of_an_apply_whose_document_cannot_be_read_is_refused_as_such_and_not_as_past_its_window( string $how ): void {
		$apply    = $this->run_job( $this->start() );
		$review   = $this->changes->preview_undo( $apply->id );
		$readable = $this->unreadable_document( $apply, $how );
		$refusals = [];
		try {
			foreach ( [
				'review'            => fn () => $this->changes->preview_undo( $apply->id ),
				'background review' => fn () => $this->changes->request_preview( $apply->id, true, false ),
				'undo'              => fn () => $this->changes->start_undo( $apply->id, $review['digest'] ),
			] as $what => $request ) {
				try {
					$request();
					self::fail( 'The ' . $what . ' of an apply whose document cannot be read was accepted.' );
				} catch ( JobConflict $refused ) {
					$refusals[ $what ] = [ $refused->context()['code'] ?? null, $refused->context()['job'] ?? null ];
				}
			}
		} finally {
			$readable();
		}
		// Before, each was refused as for an apply past its window, undo_unavailable, which said
		// that the undo was gone for good.
		$code = 'unsearchable' === $how ? 'job_inaccessible' : 'job_unreadable';
		self::assertSame( array_fill_keys( [ 'review', 'background review', 'undo' ], [ $code, $apply->id ] ), $refusals );
		// An ID that no job has is still an apply without an undo.
		try {
			$this->changes->preview_undo( '20260101T000000Z-000000' );
			self::fail( 'An undo of no job was reviewed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'undo_unavailable', $refused->context()['code'] ?? null );
		}
		$this->undo( $apply );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	#[DataProvider( 'apply_documents_that_cannot_be_read' )]
	public function test_a_background_apply_whose_document_cannot_be_read_keeps_its_sealed_review_and_staged_files( string $how ): void {
		$plan     = $this->run_job( $this->queue_large_preview() );
		$apply    = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$sealed   = dirname( $this->files ) . '/plans/' . $plan->id;
		$readable = $this->unreadable_document( $apply, $how );
		try {
			$this->clock->advance( 3601 );
			$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		} finally {
			$readable();
		}
		// Before, the sealed review went after its hour and the staging after its own, as for an
		// apply whose window had ended.
		self::assertDirectoryExists( $sealed );
		self::assertDirectoryExists( $this->files );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		$this->run_job( $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id ) );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_a_stopped_apply_records_no_count_of_attempted_entries_that_cannot_be_established(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The first entry is replaced.
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		$this->runner->cancel( $job->id );
		// A folder at the name of the count, which is not what the apply wrote.
		$attempted = $this->jobs->directory( $job ) . '/undo/attempted';
		unlink( $attempted );
		mkdir( $attempted, 0700 );
		$ended = $this->runner->tick( 2.0 )->job ?? self::fail( 'The apply is gone.' );
		// Before, the apply recorded that it attempted no entry, although it replaced one.
		self::assertSame( [], $ended->result );
		self::assertSame( [ JobState::Cancelled, 'file_apply_failed', 'The undo cursor cannot be looked up.' ], [ $ended->state, $ended->error?->code, $ended->error?->message ] );
		self::assertFileDoesNotExist( $this->live . '/.maintenance' );
		self::assertSame( [ [ $job->id, 'unreadable' ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->changes->undoable() ) );
	}

	public function test_a_stopped_undo_whose_reverted_marker_cannot_be_established_is_recorded_as_one_that_stopped(): void {
		[ $job, $undo ] = $this->counted_and_stopped( 'nothing' );
		$workspace      = $this->jobs->directory( $job ) . '/undo';
		// A link at the name of the marker, and a folder that refuses to take the marker.
		file_put_contents( $this->directory . '/elsewhere', '1' );
		symlink( $this->directory . '/elsewhere', $workspace . '/reverted' );
		$this->runner->cancel( $undo->id );
		chmod( $workspace, 0500 );
		try {
			if ( is_writable( $workspace ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$cancelled = $this->run_job( $undo, JobState::Cancelled );
		} finally {
			chmod( $workspace, 0700 );
		}
		// Before, the link counted as the marker: the failure was reported and nothing recorded.
		$warnings = $this->log_lines( $cancelled, 'warning' );
		self::assertSame(
			[ 'The undo counted every entry, but its apply could not be marked as undone; the apply stays open for a later undo.', 'environment', 'A file in the private directory could not be written.' ],
			[ $warnings[0]['message'] ?? null, $warnings[0]['context']['code'] ?? null, $warnings[0]['context']['reason'] ?? null ]
		);
		self::assertSame( [ 1, 2, 0, 0, 'partly_undone' ], [ $cancelled->result['restored'] ?? null, $cancelled->result['removed'] ?? null, $cancelled->result['unchanged'] ?? null, $cancelled->result['kept'] ?? null, $cancelled->result['apply_state'] ?? null ] );
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		self::assertSame( [ 'partly_undone', null, $undo->id ], [ $apply->result['undo_state'] ?? null, $apply->result['undo_kept'] ?? null, $apply->result['undo_job'] ?? null ] );
		self::assertSame( [ [ $job->id, 'unreadable' ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->changes->undoable() ) );
	}

	public function test_a_stopped_undo_that_marked_its_apply_as_undone_reports_an_apply_document_that_cannot_be_saved(): void {
		[ $job, $undo ] = $this->counted_and_stopped( 'nothing' );
		$this->runner->cancel( $undo->id );
		$apply = $this->jobs->directory( $job );
		chmod( $apply, 0500 );
		try {
			if ( is_writable( $apply ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$cancelled = $this->run_job( $undo, JobState::Cancelled );
		} finally {
			chmod( $apply, 0700 );
		}
		// With its marker in place the apply is undone; the failure is reported, not hidden by
		// the record of an undo that stopped.
		self::assertFileExists( $apply . '/undo/reverted' );
		self::assertSame( [ 'file_undo_failed', [] ], [ $cancelled->error?->code, $cancelled->result ] );
		self::assertSame( [], $this->changes->undoable() );
		self::assertSame( [], $this->log_lines( $cancelled, 'warning' ) );
	}

	/**
	 * A live folder with more names than a review lists at once, to which the staged files add
	 * one file.
	 *
	 * @return string The folder.
	 */
	private function wide_folder(): string {
		$wide = $this->live . '/wide';
		mkdir( $wide );
		for ( $i = 0; $i < 2050; ++$i ) {
			touch( $wide . '/upload-' . $i );
		}
		mkdir( $this->files . $wide, 0700 );
		file_put_contents( $this->files . $wide . '/added.txt', 'added' );
		$this->write_report( $this->live . '/skipped-link' );
		return $wide;
	}

	/**
	 * Ticks a job whose worker indexes the names of a wide folder, and once the index has begun
	 * puts an upload into the folder before every tick, until the job ends or twenty uploads
	 * have arrived. Each upload also gives the folder a modification time of its own, as a change
	 * in a later second does.
	 *
	 * @return Job The job as the last tick left it.
	 */
	private function tick_while_uploading( Job $job, string $wide ): Job {
		$indexed = fn (): bool => ! in_array( glob( $this->jobs->directory( $job ) . '/verification/spell-*.head' ), [ false, [] ], true );
		$tick    = 0;
		while ( ! $indexed() && ++$tick < 40 ) {
			$this->runner->tick( 0.0 );
		}
		self::assertTrue( $indexed() );
		$current = $job;
		$upload  = 0;
		while ( $upload < 20 && ! $current->state->is_terminal() ) {
			touch( $wide . '/arrived-' . $upload );
			touch( $wide, 1700000000 + $upload++ );
			$current = $this->runner->tick( 0.0 )->job ?? self::fail( 'The job is gone.' );
		}
		return $current;
	}

	public function test_a_background_preview_of_a_wide_folder_that_changes_between_every_two_ticks_ends_with_a_code_of_its_own(): void {
		$wide    = $this->wide_folder();
		$preview = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $preview );
		$ended = $this->tick_while_uploading( $preview, $wide );
		// Before, every tick began the reading of the folder's names again, and the preview went on for ever.
		self::assertSame( [ JobState::Failed, 'file_folder_changing' ], [ $ended->state, $ended->error?->code ] );
		$errors = array_values( array_filter( $this->jobs->log( $ended )->read(), static fn ( array $line ): bool => 'error' === $line['level'] ) );
		self::assertSame( $wide, $errors[0]['context']['folder'] ?? null );
		self::assertFileDoesNotExist( $wide . '/added.txt' );
	}

	public function test_a_background_undo_review_keeps_the_entry_of_a_wide_folder_that_changes_between_every_two_ticks(): void {
		$wide    = $this->wide_folder();
		$preview = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $preview );
		$plan  = $this->run_job( $preview );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		self::assertSame( 'added', file_get_contents( $wide . '/added.txt' ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$reviewed = $this->tick_while_uploading( $review, $wide );
		// The rest of the review does not read the folder. Its check of the folder that the apply
		// created, once a tick without time ended in it, waits for the second of that folder's
		// last change, which ticks that end at once can all fall into: they are bounded by time,
		// with room for a slow runner, since the loop ends as soon as the review does.
		$until = microtime( true ) + 30.0;
		while ( ! $reviewed->state->is_terminal() && microtime( true ) < $until ) {
			usleep( 20000 );
			$reviewed = $this->runner->tick( 2.0 )->job ?? self::fail( 'The review is gone.' );
		}
		// Before, the review read the folder again at every tick while the uploads lasted, and
		// decided the entry once they stopped. Now another spelling may be in the folder: its
		// entry is kept, and the review decides the others.
		self::assertSame( JobState::Succeeded, $reviewed->state );
		$page = $this->changes->request_preview( $apply->id, true, false, $reviewed->id );
		self::assertIsArray( $page );
		$decisions = [];
		foreach ( $page['entries'] as $entry ) {
			$decisions[ (string) $entry['destination'] ] = [ $entry['action'], $entry['reason'] ];
		}
		self::assertSame( [ 'keep', 'ambiguous_destination' ], $decisions[ $wide . '/added.txt' ] );
		self::assertSame( [ 'restore', 'undo_journal' ], $decisions[ $this->live . '/a-existing' ] );
	}

	public function test_an_apply_whose_wide_folder_changes_between_every_two_ticks_of_its_check_stops_before_its_first_change(): void {
		$wide    = $this->wide_folder();
		$preview = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $preview );
		$plan  = $this->run_job( $preview );
		$apply = $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id );
		$ended = $this->tick_while_uploading( $apply, $wide );
		// Before, the apply checked the reviewed changes again for ever, before maintenance.
		self::assertSame( [ JobState::Failed, 'file_folder_changing' ], [ $ended->state, $ended->error?->code ] );
		self::assertFileDoesNotExist( $this->live . '/.maintenance' );
		self::assertFileDoesNotExist( $wide . '/added.txt' );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_kept_entries_of_an_undo_whose_document_cannot_be_looked_up_are_not_those_of_no_job(): void {
		$job    = $this->run_job( $this->start() );
		$undo   = $this->undo( $job );
		$folder = $this->jobs->directory( $undo );
		chmod( $folder, 0600 );
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$this->changes->kept_page( $undo->id, 0 );
			self::fail( 'The kept entries of an undo whose document cannot be looked up were listed.' );
		} catch ( JobConflict $refused ) {
			// Before, the answer was that of an ID that no job has, not_found.
			self::assertSame( [ 'job_inaccessible', $undo->id ], [ $refused->context()['code'] ?? null, $refused->context()['job'] ?? null ] );
		} finally {
			chmod( $folder, 0700 );
		}
	}

	public function test_a_review_that_finds_nothing_left_to_revert_records_the_apply_as_undone(): void {
		$job = $this->run_job( $this->start() );
		$new = $this->live . '/b-new/new.txt';
		clearstatcache();
		$installed = (int) filemtime( $new );
		file_put_contents( $new, 'edited' );
		touch( $new, $installed + 60 );
		$first = $this->undo( $job );
		self::assertSame( 2, $first->result['kept'] );
		// The edit is taken back with the installed bytes and time, and a second undo is
		// stopped after it removed both entries, before it came to the replaced file, which
		// the first undo restored.
		file_put_contents( $new, 'created' );
		touch( $new, $installed );
		$review = $this->changes->preview_undo( $job->id );
		$undo   = $this->changes->start_undo( $job->id, $review['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The created file.
		$this->runner->tick( 0.0 ); // The created directory.
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( [ 2, 'partly_undone' ], [ $cancelled->result['removed'], $cancelled->result['apply_state'] ] );
		self::assertSame( 'partly_undone', $this->changes->undoable()[0]['state'] );
		// While another job is active the review only shows that nothing is left to revert.
		$other = $this->jobs->create( JobType::Restore, 'manual', $this->clock->seconds );
		$busy  = $this->changes->request_preview( $job->id, true, false );
		self::assertIsArray( $busy );
		self::assertArrayNotHasKey( 'undone', $busy );
		self::assertSame( $job->id, $this->changes->undoable()[0]['job'] );
		$other->state       = JobState::Cancelled;
		$other->finished_at = $this->clock->seconds;
		$this->jobs->save( $other );
		// Then it records the apply as undone instead of offering an undo that would be refused.
		$third = $this->changes->preview_undo( $job->id );
		self::assertTrue( $third['undone'] ?? null );
		self::assertFalse( $third['applicable'] );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 0,
				'keep'    => 3,
			],
			$third['totals']
		);
		self::assertSame( [ 'already_undone' ], array_values( array_unique( array_column( $third['entries'], 'reason' ) ) ) );
		self::assertFileExists( $this->jobs->directory( $job ) . '/undo/reverted' );
		$apply = $this->jobs->get( $job->id );
		self::assertNotNull( $apply );
		self::assertSame( [ 'undone', 0, $undo->id ], [ $apply->result['undo_state'], $apply->result['undo_kept'], $apply->result['undo_job'] ] );
		self::assertSame( [], $this->changes->undoable() );
		// Its staging and journal are no longer pinned.
		$this->clock->advance( 3601 );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $this->files );
		$this->expectException( JobConflict::class );
		$this->expectExceptionMessage( 'This apply has no available undo.' );
		$this->changes->request_preview( $job->id, true, false );
	}

	public function test_a_background_review_that_finds_nothing_left_to_revert_records_the_apply_as_undone(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$page   = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'undone', $page );
		$undo = $this->run_job( $this->changes->start_undo( $apply->id, $page['digest'], '', $review->id ) );
		self::assertSame( [ 1, 2, 'partly_undone' ], [ $undo->result['restored'], $undo->result['kept'], $undo->result['apply_state'] ] );
		// The kept directory is deleted with the edited file in it: nothing is left to revert.
		TempDir::remove( $this->live . '/b-new' );
		$again = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		self::assertSame( [ 0, 0, 3, 3 ], [ $again->result['restore'], $again->result['remove'], $again->result['keep'], $again->result['settled'] ] );
		// A later page does not decide; the first one does.
		$later = $this->changes->request_preview( $apply->id, true, false, $again->id, 3 );
		self::assertIsArray( $later );
		self::assertArrayNotHasKey( 'undone', $later );
		$first = $this->changes->request_preview( $apply->id, true, false, $again->id );
		self::assertIsArray( $first );
		self::assertTrue( $first['undone'] ?? null );
		self::assertFileExists( $this->jobs->directory( $apply ) . '/undo/reverted' );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_a_stopped_undo_lists_the_entries_its_document_counted_after_a_stop_at_any_write(): void {
		// Four replaced files that someone edits between the review and the worker: the undo
		// keeps each of them and reverts the other entries.
		$replaced = [];
		foreach ( [ 1, 2, 3, 4 ] as $number ) {
			$path = $this->live . '/replaced-' . $number;
			file_put_contents( $path, 'original' );
			file_put_contents( $this->files . $path, 'replacement' );
			$replaced[] = $path;
		}
		$this->write_report( $this->live . '/skipped-link' );
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		foreach ( $replaced as $path ) {
			file_put_contents( $path, 'edited after the review' );
		}
		// What the undo's directory holds right after each synchronisation, rename and removal
		// of its document and of its list of kept entries: what a stop there leaves.
		$directory        = $this->jobs->directory( $undo );
		$stops            = [];
		$this->job_writes = function ( string $operation, string $path ) use ( &$stops, $directory ): void {
			$stops[] = [ $operation . ' ' . basename( $path ), $this->snapshot( $directory ) ];
		};
		$kept             = $this->kept( $this->run_job( $undo ) );
		$this->job_writes = null;
		self::assertEqualsCanonicalizing( $replaced, array_keys( $kept ) );
		self::assertSame( [ 'changed_since_apply' ], array_values( array_unique( $kept ) ) );
		self::assertContains( 'rename rows-0.4', array_column( $stops, 0 ) );
		self::assertContains( 'unlink rows-0.2', array_column( $stops, 0 ) );
		$manifest = json_decode( (string) file_get_contents( $this->jobs->directory( $job ) . '/undo/manifest.json' ), true, flags: JSON_THROW_ON_ERROR );
		$retried  = 0;
		foreach ( $stops as [ $stop, $snapshot ] ) {
			// Cancelled after the stop: the list holds what the document counted.
			$document = $this->ended_after( $undo, $snapshot, $stop, $kept );
			$done     = $document->progress['done'] ?? 0;
			if ( 'undoing' !== $document->step || $document->state->is_terminal() || $done >= count( $manifest ) ) {
				continue;
			}
			$path = Bytes::base64url_decode( $manifest[ count( $manifest ) - 1 - $done ]['destination'] );
			if ( ! in_array( $path, $replaced, true ) ) {
				continue;
			}
			// The worker comes back instead, after someone deleted the file at its cursor, keeps
			// that entry for another reason and is stopped again at any write.
			$this->stopped( $undo, $snapshot );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			$again            = [];
			$this->job_writes = function ( string $operation, string $written ) use ( &$again, $directory ): void {
				$again[] = [ $operation . ' ' . basename( $written ), $this->snapshot( $directory ) ];
			};
			$this->runner->tick( 0.0 );
			$this->job_writes = null;
			self::assertContains( 'rename job.json', array_column( $again, 0 ), $stop );
			$counted = array_slice( $kept, 0, $document->data['undo_counts']['kept'] ?? 0, true ) + [ $path => 'deleted_since_apply' ];
			foreach ( $again as [ $second, $retry ] ) {
				$this->ended_after( $undo, $retry, $stop . ', then ' . $second, $counted );
				++$retried;
			}
		}
		self::assertGreaterThan( 20, $retried );
	}

	public function test_a_stopped_undo_lists_the_entries_its_document_counted_across_a_page_boundary(): void {
		// More replaced files than a page of the list holds, each edited between the review and
		// the worker: the undo keeps them all, and its list gets the slot of its first page.
		$replaced = [];
		foreach ( range( 1, DiskFilePlan::PAGE + 2 ) as $number ) {
			$path = sprintf( '%s/replaced-%03d', $this->live, $number );
			file_put_contents( $path, 'original' );
			file_put_contents( $this->files . $path, 'replacement' );
			$replaced[] = $path;
		}
		$this->write_report( $this->live . '/skipped-link' );
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		foreach ( $replaced as $path ) {
			file_put_contents( $path, 'edited after the review' );
		}
		$directory        = $this->jobs->directory( $undo );
		$stops            = [];
		$this->job_writes = function ( string $operation, string $path ) use ( &$stops, $directory ): void {
			$stops[] = [ $operation . ' ' . basename( $path ), $this->snapshot( $directory ) ];
		};
		$kept             = $this->kept( $this->run_job( $undo ) );
		$this->job_writes = null;
		self::assertCount( DiskFilePlan::PAGE + 2, $kept );
		self::assertContains( 'rename rows.idx', array_column( $stops, 0 ) );
		$manifest = json_decode( (string) file_get_contents( $this->jobs->directory( $job ) . '/undo/manifest.json' ), true, flags: JSON_THROW_ON_ERROR );
		$checked  = 0;
		$retried  = 0;
		foreach ( $stops as [ $stop, $snapshot ] ) {
			// Around the end of the first page: the stops while the document counts 98 to 101.
			$counted = json_decode( (string) file_get_contents( $snapshot . '/job.json' ), true, flags: JSON_THROW_ON_ERROR )['data']['undo_counts']['kept'] ?? 0;
			if ( $counted < DiskFilePlan::PAGE - 2 || $counted > DiskFilePlan::PAGE + 1 ) {
				continue;
			}
			$document = $this->ended_after( $undo, $snapshot, $stop, $kept );
			++$checked;
			$done = $document->progress['done'] ?? 0;
			if ( 'undoing' !== $document->step || $document->state->is_terminal() || $done >= count( $manifest ) ) {
				continue;
			}
			$path = Bytes::base64url_decode( $manifest[ count( $manifest ) - 1 - $done ]['destination'] );
			if ( ! in_array( $path, $replaced, true ) ) {
				continue;
			}
			// The worker comes back after the file at its cursor was deleted, keeps that entry for
			// another reason, and is stopped again at any write.
			$this->stopped( $undo, $snapshot );
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			$again            = [];
			$this->job_writes = function ( string $operation, string $written ) use ( &$again, $directory ): void {
				$again[] = [ $operation . ' ' . basename( $written ), $this->snapshot( $directory ) ];
			};
			$this->runner->tick( 0.0 );
			$this->job_writes = null;
			$counts           = array_slice( $kept, 0, $document->data['undo_counts']['kept'] ?? 0, true ) + [ $path => 'deleted_since_apply' ];
			foreach ( $again as [ $second, $retry ] ) {
				$this->ended_after( $undo, $retry, $stop . ', then ' . $second, $counts );
				++$retried;
			}
		}
		self::assertGreaterThan( 20, $checked );
		self::assertGreaterThan( 100, $retried );
	}

	/**
	 * Puts back what a stop left in an undo's directory, lets the undo end, cancelled unless
	 * only its recording was left, and compares its list of kept entries with the entries
	 * that the document on disk counted.
	 *
	 * @param Job                  $undo Undo.
	 * @param string               $snapshot What the stop left (snapshot()).
	 * @param string               $stop Where the worker stopped.
	 * @param array<string,string> $kept Reasons of the kept entries in the order the undo kept them.
	 * @return Job The document that the stop left.
	 */
	private function ended_after( Job $undo, string $snapshot, string $stop, array $kept ): Job {
		$document = $this->stopped( $undo, $snapshot );
		$counted  = $document->data['undo_counts']['kept'] ?? 0;
		$ended    = $document;
		if ( ! $document->state->is_terminal() ) {
			// A queued undo is cancelled at once; a running one by its next tick.
			$ended = $this->runner->cancel( $undo->id ) ?? self::fail( 'The undo is gone.' );
			if ( ! $ended->state->is_terminal() ) {
				$ended = $this->run_job( $undo, 'recording' === $document->step ? JobState::Succeeded : JobState::Cancelled );
			}
		}
		self::assertSame( array_slice( $kept, 0, $counted, true ), $this->kept( $ended ), $stop );
		self::assertSame( $counted, $ended->result['kept'] ?? 0, $stop );
		return $document;
	}

	/**
	 * Puts back what a stop left in an undo's directory, and lets the lease of the stopped
	 * tick run out, as it does when no worker renews it.
	 *
	 * @return Job The document that the stop left.
	 */
	private function stopped( Job $undo, string $snapshot ): Job {
		$this->restore_directory( $this->jobs->directory( $undo ), $snapshot );
		$document = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		if ( '' !== $document->lease_owner ) {
			$this->clock->advance( max( 0, $document->lease_expires_at - $this->clock->now()[0] ) + 1 );
		}
		return $document;
	}

	/**
	 * Keeps what a directory holds now by hard links: the job store and the list of kept
	 * entries replace a file by a rename or remove it and never write one in place, and the
	 * private maintenance marker stays the inode of the live one.
	 *
	 * @return string Directory of the snapshot.
	 */
	private function snapshot( string $directory ): string {
		++$this->snapshots;
		$copy = $this->directory . '/snapshots/' . $this->snapshots;
		mkdir( $copy, 0700, true );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$target = $copy . substr( $item->getPathname(), strlen( $directory ) );
			if ( $item->isDir() ) {
				mkdir( $target, 0700 );
			} else {
				link( $item->getPathname(), $target );
			}
		}
		return $copy;
	}

	/** Makes a directory hold what a snapshot holds, by the same inodes. */
	private function restore_directory( string $directory, string $copy ): void {
		TempDir::remove( $directory );
		mkdir( $directory, 0700 );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $copy, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$target = $directory . substr( $item->getPathname(), strlen( $copy ) );
			if ( $item->isDir() ) {
				mkdir( $target, 0700 );
			} else {
				link( $item->getPathname(), $target );
			}
		}
	}

	/**
	 * Whether an undo whose list cannot be sealed finishes or is cancelled.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function finished_and_cancelled(): array {
		return [
			'finished'  => [ true ],
			'cancelled' => [ false ],
		];
	}

	#[DataProvider( 'finished_and_cancelled' )]
	public function test_an_undo_whose_kept_list_cannot_be_sealed_records_what_it_did_without_the_list( bool $finished ): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The edited file is kept.
		$this->runner->tick( 0.0 ); // And the folder that holds it.
		if ( $finished ) {
			$this->runner->tick( 0.0 ); // The replaced file is restored; recording is next.
		}
		$document = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		self::assertSame( [ $finished ? 'recording' : 'undoing', 2 ], [ $document->step, $document->data['undo_counts']['kept'] ] );
		// The counted page is written again before the seal, with valid entries, as many as before.
		$page              = $this->jobs->directory( $undo ) . '/kept/rows-0.2';
		$rows              = json_decode( (string) file_get_contents( $page ), true, flags: JSON_THROW_ON_ERROR );
		$rows[0]['reason'] = 'protected';
		file_put_contents( $page, (string) json_encode( $rows, JSON_UNESCAPED_SLASHES ) );
		if ( ! $finished ) {
			$this->runner->cancel( $undo->id );
		}
		$ended = $this->run_job( $undo, $finished ? JobState::Succeeded : JobState::Cancelled );
		// What the undo did is recorded, in its result and in its apply, without the list.
		self::assertSame(
			[
				'restored'             => $finished ? 1 : 0,
				'removed'              => 0,
				'unchanged'            => 0,
				'kept'                 => 2,
				'changed_after_review' => 0,
				'apply_state'          => $finished ? 'partly_undone' : 'applied',
			],
			self::outcomes( $ended )
		);
		self::assertArrayNotHasKey( 'kept_digest', $ended->data );
		$apply = $this->jobs->get( $job->id ) ?? self::fail( 'The apply is gone.' );
		self::assertSame( $finished ? [ 'partly_undone', 2 ] : [ null, null ], [ $apply->result['undo_state'] ?? null, $apply->result['undo_kept'] ?? null ] );
		$warnings = array_values( array_filter( $this->jobs->log( $ended )->read(), static fn ( array $line ): bool => 'warning' === $line['level'] ) );
		self::assertSame(
			[ 'The list of kept entries cannot be sealed; the undo is recorded without it.', 'environment', 'A page of the kept entries changed after it was written.' ],
			[ $warnings[0]['message'] ?? null, $warnings[0]['context']['code'] ?? null, $warnings[0]['context']['reason'] ?? null ]
		);
		// The list is said to be unavailable, not shown as an empty one.
		try {
			$this->changes->kept_page( $undo->id, 0 );
			self::fail( 'A list that could not be sealed must not be read as empty.' );
		} catch ( JobConflict $unavailable ) {
			self::assertSame( 'kept_list_unavailable', $unavailable->context()['code'] ?? null );
		}
	}

	public function test_an_undo_whose_document_counts_kept_entries_without_naming_their_page_stops(): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 ); // Preflight.
		$this->runner->tick( 0.0 ); // The edited file is kept.
		// A document as an earlier version of the list wrote it: the count without the digest of
		// the page that holds what it counts.
		$document = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
		self::assertSame( 1, $document->data['undo_counts']['kept'] );
		unset( $document->data['kept_page_digest'] );
		$this->jobs->save( $document );
		// The next kept entry is not built on a page that the document does not name: the undo
		// stops, and records what it did without the list.
		$failed = $this->run_job( $undo, JobState::Failed );
		// The undo's own code, not "This server cannot run the backup right now"; the log has the message.
		self::assertSame( [ 'file_undo_failed', 'The job does not name the counted page of its kept entries.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( [ 0, 1, 'applied' ], [ $failed->result['restored'], $failed->result['kept'], $failed->result['apply_state'] ] );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
		try {
			$this->changes->kept_page( $undo->id, 0 );
			self::fail( 'The list must be said to be unavailable.' );
		} catch ( JobConflict $unavailable ) {
			self::assertSame( 'kept_list_unavailable', $unavailable->context()['code'] ?? null );
		}
	}

	public function test_a_review_whose_last_check_ends_after_its_budget_does_not_retire_the_apply(): void {
		// An apply of one file, which is gone since: nothing of it is left to revert.
		$restore = $this->second_restore( [ $this->live . '/single.txt' => 'one' ] );
		$job     = $this->run_job( $this->changes->start_apply( $restore, false, $this->changes->preview( $restore )['digest'] ) );
		unlink( $this->live . '/single.txt' );
		// Each reading of this clock is one and a half seconds after the one before: the check of
		// the entry begins within the budget of two seconds and ends after it.
		$clock  = new class( $this->clock->seconds ) implements Clock {
			private float $time;

			public function __construct( int $start ) {
				$this->time = $start;
			}

			public function now(): array {
				$this->time += 1.5;
				return [ (int) floor( $this->time ), (int) round( fmod( $this->time, 1.0 ) * 1000000000 ) ];
			}
		};
		$review = $this->service( $clock )->preview_undo( $job->id );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 0,
				'keep'    => 1,
			],
			$review['totals']
		);
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFileDoesNotExist( $this->jobs->directory( $job ) . '/undo/reverted' );
		// Within the budget, the same review retires the apply.
		self::assertTrue( $this->changes->preview_undo( $job->id )['undone'] ?? null );
	}

	public function test_a_review_does_not_retire_an_apply_whose_file_cannot_be_looked_up(): void {
		// An apply of one file into a folder that the site has already.
		$file = $this->live . '/sub/single.txt';
		mkdir( $this->live . '/sub' );
		$restore = $this->second_restore( [ $file => 'one' ] );
		$job     = $this->run_job( $this->changes->start_apply( $restore, false, $this->changes->preview( $restore )['digest'] ) );
		// The folder loses its search permission: the file looks missing, but it is there.
		chmod( $this->live . '/sub', 0600 );
		try {
			if ( file_exists( $file ) ) {
				self::markTestSkipped( 'This user bypasses directory search permissions.' );
			}
			$review = $this->changes->preview_undo( $job->id );
		} finally {
			chmod( $this->live . '/sub', 0700 );
		}
		self::assertSame( [ [ $file, 'keep', 'changed_since_apply' ] ], array_map( static fn ( array $entry ): array => [ $entry['path'], $entry['action'], $entry['reason'] ], $review['entries'] ) );
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFileDoesNotExist( $this->jobs->directory( $job ) . '/undo/reverted' );
		self::assertSame( 'one', file_get_contents( $file ) );
		self::assertSame( 'remove', array_column( $this->changes->preview_undo( $job->id )['entries'], 'action', 'path' )[ $file ] );
	}

	public function test_a_review_does_not_retire_an_apply_whose_folder_is_replaced_by_a_link(): void {
		// An apply of one file into a folder that the site has already.
		$file = $this->live . '/sub/single.txt';
		mkdir( $this->live . '/sub' );
		$restore = $this->second_restore( [ $file => 'one' ] );
		$job     = $this->run_job( $this->changes->start_apply( $restore, false, $this->changes->preview( $restore )['digest'] ) );
		// The folder is moved aside and a link to an empty folder takes its name.
		rename( $this->live . '/sub', $this->live . '/sub.aside' );
		mkdir( $this->directory . '/empty' );
		symlink( $this->directory . '/empty', $this->live . '/sub' );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame( [ [ $file, 'keep', 'parent_changed' ] ], array_map( static fn ( array $entry ): array => [ $entry['path'], $entry['action'], $entry['reason'] ], $review['entries'] ) );
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFileDoesNotExist( $this->jobs->directory( $job ) . '/undo/reverted' );
		// Put back, the file is revertible.
		unlink( $this->live . '/sub' );
		rename( $this->live . '/sub.aside', $this->live . '/sub' );
		self::assertSame( 'remove', array_column( $this->changes->preview_undo( $job->id )['entries'], 'action', 'path' )[ $file ] );
	}

	public function test_a_review_does_not_retire_an_apply_whose_entry_came_back_since(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$page   = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		$this->run_job( $this->changes->start_undo( $apply->id, $page['digest'], '', $review->id ) );
		// The kept folder is deleted, and the background review finds nothing left to revert.
		TempDir::remove( $this->live . '/b-new' );
		$again = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		self::assertSame( [ 0, 0, 3, 3 ], [ $again->result['restore'], $again->result['remove'], $again->result['keep'], $again->result['settled'] ] );
		// Before its first page is read, the folder is there again.
		mkdir( $this->live . '/b-new' );
		$first = $this->changes->request_preview( $apply->id, true, false, $again->id );
		self::assertIsArray( $first );
		self::assertArrayNotHasKey( 'undone', $first );
		self::assertFileDoesNotExist( $this->jobs->directory( $apply ) . '/undo/reverted' );
		self::assertSame( [ $apply->id, 'partly_undone' ], [ $this->changes->undoable()[0]['job'], $this->changes->undoable()[0]['state'] ] );
	}

	public function test_an_undo_with_nothing_left_to_revert_is_refused_with_its_own_code(): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/a-existing', 'edited' );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited as well' );
		$review = $this->changes->preview_undo( $job->id );
		self::assertFalse( $review['applicable'] );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 0,
				'keep'    => 3,
			],
			$review['totals']
		);
		try {
			$this->changes->start_undo( $job->id, $review['digest'] );
			self::fail( 'An undo with nothing to revert must be refused.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'undo_nothing_to_revert', JobError::from_throwable( $refused )->code );
		}
		self::assertNull( $this->jobs->active() );
		// The apply stays listed: its entries can become revertible again within the window.
		self::assertSame( $job->id, $this->changes->undoable()[0]['job'] );
		self::assertSame( 'edited', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_an_undo_confirmed_before_reviewed_decisions_were_kept_is_reviewed_again(): void {
		$job  = $this->run_job( $this->start() );
		$new  = $this->live . '/b-new/new.txt';
		$mode = fileperms( $new ) & 0777;
		unlink( $new );
		$review = $this->changes->preview_undo( $job->id );
		self::assertSame( 'keep', array_column( $review['entries'], 'action', 'path' )[ $new ] );
		// Queued as an earlier version queued it, without the decisions; one of them also
		// after the worker of that version began to revert.
		foreach ( [ 'preflight', 'undoing' ] as $step ) {
			$undo = $this->changes->start_undo( $job->id, $review['digest'] );
			self::assertCount( 3, $undo->data['reviewed'] );
			if ( 'undoing' === $step ) {
				$this->runner->tick( 0.0 ); // Preflight.
				$undo = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
				self::assertSame( 'undoing', $undo->step );
			}
			unset( $undo->data['reviewed'] );
			$this->jobs->save( $undo );
			// Before the worker comes, the same bytes are uploaded again.
			file_put_contents( $new, 'created' );
			chmod( $new, $mode );
			$failed = $this->run_job( $undo, JobState::Failed );
			self::assertSame( 'undo_review_required', $failed->error?->code, $step );
			self::assertSame( $step, $this->failed_step( $failed ) );
			self::assertSame( 'created', file_get_contents( $new ) );
			self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
			self::assertSame( [ $job->id, 'applied' ], [ $this->changes->undoable()[0]['job'], $this->changes->undoable()[0]['state'] ] );
			unlink( $new );
		}
	}

	public function test_undo_after_a_created_directory_was_removed_restores_the_replaced_file(): void {
		$job = $this->run_job( $this->start() );
		TempDir::remove( $this->live . '/b-new' );
		$review  = $this->changes->preview_undo( $job->id );
		$actions = array_column( $review['entries'], 'action', 'path' );
		self::assertSame( 'keep', $actions[ $this->live . '/b-new/new.txt' ] );
		self::assertSame( 'keep', $actions[ $this->live . '/b-new' ] );
		self::assertSame( 'restore', $actions[ $this->live . '/a-existing' ] );
		$this->undo( $job );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
	}

	public function test_background_undo_review_keeps_entries_below_a_removed_directory(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		TempDir::remove( $this->live . '/b-new' );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		self::assertSame( 0, $review->result['refused'] );
		$this->run_job( $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id ) );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
	}

	public function test_a_heartbeat_that_stops_while_it_deletes_an_undone_journal_leaves_the_window_closed(): void {
		$apply = $this->run_job( $this->start() );
		$this->undo( $apply );
		$workspace = $this->jobs->directory( $apply ) . '/undo';
		$names     = static fn (): array => array_values( array_diff( (array) scandir( $workspace ), [ '.', '..' ] ) );
		$journal   = $names();
		self::assertContains( 'reverted', $journal );
		self::assertContains( 'attempted', $journal );
		self::assertFalse( $this->jobs->undo_open( $apply ) );
		// A heartbeat whose deadline comes as soon as one entry of the journal is gone, whichever
		// entry readdir() lists first: the deletion stops there.
		$clock = new class( $this->clock, static fn (): bool => count( $names() ) < count( $journal ) ) implements Clock {
			/**
			 * @param FrozenClock          $base Clock of the test.
			 * @param \Closure(): bool     $past Whether the deadline has come.
			 */
			public function __construct( private FrozenClock $base, private \Closure $past ) {
			}

			public function now(): array {
				return ( $this->past )() ? [ $this->base->seconds + 1000, 0 ] : $this->base->now();
			}
		};
		( new Staging( $this->directory . '/restores', $this->jobs, $clock ) )->remove_due( 24, $this->clock->seconds + 100 );
		// The count of attempted changes goes first. Before, the entry that went was the one that
		// readdir() listed first; on tmpfs that is the undone marker, the newest entry, and the count
		// that it left made the window of the apply, undone completely, open again.
		self::assertSame( array_values( array_diff( $journal, [ 'attempted' ] ) ), $names() );
		self::assertFalse( $this->jobs->undo_open( $apply ) );
		self::assertSame( [], $this->changes->undoable() );
		// The next heartbeat deletes the rest.
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $workspace );
	}

	public function test_a_heartbeat_that_cannot_remove_the_count_of_an_undone_journal_deletes_nothing_of_it(): void {
		$apply = $this->run_job( $this->start() );
		$this->undo( $apply );
		$workspace = $this->jobs->directory( $apply ) . '/undo';
		$tree      = static function () use ( $workspace ): array {
			$entries = [];
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $workspace, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
				$entries[ substr( $item->getPathname(), strlen( $workspace ) ) ] = $item->isDir() ? 'dir' : hash_file( 'sha256', $item->getPathname() );
			}
			ksort( $entries );
			return $entries;
		};
		$journal   = $tree();
		self::assertGreaterThan( 3, count( $journal ) );
		$closed           = 0;
		$this->job_writes = static function ( string $operation, string $path ) use ( $workspace, &$closed ): void {
			$closed += (int) ( 'sync' === $operation && $workspace === $path );
		};
		chmod( $workspace, 0500 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_writable( $workspace ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// The window cannot be closed durably, so nothing of the journal goes: not the saved
			// original files of its items, which a deletion in readdir() order may reach first, and
			// the journal's folder is not synchronised as one whose window is closed.
			self::assertSame( 1, $this->staging->remove_due( 24, PHP_FLOAT_MAX )['failed'] );
			self::assertSame( 0, $closed );
		} finally {
			restore_error_handler();
			chmod( $workspace, 0700 );
			$this->job_writes = null;
		}
		self::assertSame( $journal, $tree() );
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $workspace );
	}

	public function test_the_closed_window_of_an_undone_journal_is_on_disk_before_the_rest_of_it_goes(): void {
		$apply = $this->run_job( $this->start() );
		$this->undo( $apply );
		$workspace = $this->jobs->directory( $apply ) . '/undo';
		$journal   = array_values( array_diff( (array) scandir( $workspace ), [ '.', '..', 'attempted' ] ) );
		$synced    = [];
		// What is left of the journal when its folder is synchronised.
		$this->job_writes = static function ( string $operation, string $path ) use ( $workspace, &$synced ): void {
			if ( 'sync' === $operation && $workspace === $path ) {
				$synced[] = array_values( array_diff( (array) scandir( $workspace ), [ '.', '..' ] ) );
			}
		};
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		$this->job_writes = null;
		// The removal of the count is synchronised before anything else of the journal goes, so
		// that a power loss cannot bring back the count without the undone marker.
		self::assertSame( [ $journal ], $synced );
		self::assertDirectoryDoesNotExist( $workspace );
	}

	public function test_a_link_in_place_of_an_ended_journal_is_removed_as_a_link_and_nothing_behind_it_goes(): void {
		$apply     = $this->run_job( $this->start() );
		$workspace = $this->jobs->directory( $apply ) . '/undo';
		$elsewhere = $this->directory . '/elsewhere';
		rename( $workspace, $elsewhere );
		symlink( $elsewhere, $workspace );
		// Past the window, which ends the journal whatever its records say.
		$this->clock->advance( 86400 );
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertFalse( is_link( $workspace ) );
		// Before the guard, the count of attempted changes went first, through the link.
		self::assertFileExists( $elsewhere . '/attempted', 'A file behind the link was deleted.' );
		self::assertDirectoryExists( $elsewhere . '/item-0' );
	}

	public function test_an_unused_sealed_review_is_removed_one_hour_after_its_preview_ended_and_not_before(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$sealed = dirname( $this->files ) . '/plans/' . $plan->id;
		self::assertDirectoryExists( $sealed );
		$this->clock->seconds = $plan->finished_at + 3599;
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertDirectoryExists( $sealed, 'A review that can still be confirmed was removed.' );
		$this->clock->seconds = $plan->finished_at + 3600;
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $sealed );
	}

	public function test_expired_undo_is_removed_and_staging_released(): void {
		$job = $this->run_job( $this->start() );
		$this->clock->advance( 86400 );
		self::assertSame( [], $this->changes->undoable() );
		try {
			$this->changes->request_preview( $job->id, true, false );
			self::fail( 'An apply past its recovery window was offered for undo.' );
		} catch ( JobConflict $closed ) {
			self::assertSame( 'undo_unavailable', $closed->context()['code'] ?? null );
		}
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $this->jobs->directory( $job ) . '/undo' );
		self::assertDirectoryDoesNotExist( $this->files );
	}

	public function test_disabling_host_policy_stops_queued_work(): void {
		$job = $this->start();
		$this->runner->tick( 0.0 );
		$this->allowed = false;
		$failed        = $this->run_job( $job, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_every_tick_rechecks_the_administrator_who_confirmed_in_the_browser(): void {
		$plan  = $this->changes->preview( $this->restore );
		$grant = hash( 'sha256', 'control token' );
		try {
			$this->changes->start_apply( $this->restore, false, $plan['digest'], $grant );
			self::fail( 'A browser confirmation needs the user who confirmed it.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_writes_refused', $refused->context()['code'] );
		}
		$job = $this->changes->start_apply( $this->restore, false, $plan['digest'], $grant, null, 7 );
		self::assertSame( 7, $job->data['user'] );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		// The account loses its role while the site is in maintenance.
		$this->administrators = [];
		$failed               = $this->run_job( $job, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		// The command line has no WordPress account; the host policy alone applies to it.
		$this->run_job( $this->start() );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_foreign_maintenance_marker_survives_and_blocks_publication(): void {
		$job = $this->start();
		file_put_contents( $this->live . '/.maintenance', 'owned by WordPress' );
		$finished = $this->runner->tick( 2.0 )->job;
		self::assertSame( JobState::Failed, $finished?->state );
		self::assertSame( $job->id, $finished->id );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'owned by WordPress', file_get_contents( $this->live . '/.maintenance' ) );
	}

	public function test_payload_missing_from_the_staged_report_prevents_writable_preview(): void {
		file_put_contents( $this->files . $this->live . '/unreported', 'unreviewed' );
		$this->expectException( InvalidArgument::class );
		$this->changes->preview( $this->restore );
	}
	private function stage_fresh(): void {
		TempDir::remove( $this->files );
		$content = '/old/site-content';
		$media   = '/archived-media';
		$private = '/old/private';
		mkdir( $this->files . $content . '/plugins/active', 0700, true );
		mkdir( $this->files . $media . '/album', 0700, true );
		mkdir( $this->files . $private . '/spool', 0700, true );
		mkdir( $this->uploads );
		file_put_contents( $this->files . $private . '/spool/db.sql', 'private SQL' );
		file_put_contents( $this->files . $content . '/wp-config.php', 'old configuration' );
		file_put_contents( $this->files . $content . '/plugins/active/code.php', 'active plugin' );
		file_put_contents( $this->files . $content . '/a-existing', 'imported replacement' );
		file_put_contents( $this->files . $media . '/album/photo.txt', 'imported upload' );
		$this->write_report( $content . '/link' );
		$job = $this->jobs->get( $this->restore );
		self::assertNotNull( $job );
		$snapshot                   = str_repeat( 'e', 64 );
		$job->data['snapshot']      = $snapshot;
		$job->data['fresh_install'] = true;
		$job->data['site_layout']   = SiteLayout::bind(
			$snapshot,
			[
				'database' => true,
				'content'  => $content,
				'uploads'  => $media,
			],
			$private . '/spool/manifest.json'
		);
		$this->jobs->save( $job );
	}

	public function test_fresh_apply_maps_verified_content_and_uploads_and_reverses_the_mapped_targets(): void {
		$this->stage_fresh();
		self::assertTrue( $this->staging->listing( 1 )[0]->to_array()['fresh_install'] );
		$preview = $this->changes->preview( $this->restore );
		self::assertTrue( $preview['applicable'] );
		$entries = array_column( $preview['entries'], null, 'source' );
		self::assertSame( $this->uploads . '/album/photo.txt', $entries['/archived-media/album/photo.txt']['destination'] );
		self::assertSame( 'skip', $entries['/old/site-content/plugins/active/code.php']['action'] );
		self::assertNull( $entries['/old/site-content/wp-config.php']['destination'] );
		self::assertNull( $entries['/old/private/spool/db.sql']['destination'] );
		$job = $this->run_job( $this->changes->start_apply( $this->restore, false, $preview['digest'] ) );
		self::assertSame( 'imported replacement', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'imported upload', file_get_contents( $this->uploads . '/album/photo.txt' ) );
		self::assertFileDoesNotExist( $this->live . '/wp-config.php' );
		self::assertFileDoesNotExist( $this->live . '/plugins/active/code.php' );
		$previous      = $this->uploads;
		$this->uploads = $this->live . '/changed-layout';
		// Entries that the site now maps elsewhere are kept; the others can still be reverted.
		$moved = $this->changes->preview_undo( $job->id );
		self::assertTrue( $moved['applicable'] );
		$reasons = array_column( $moved['entries'], 'reason', 'destination' );
		self::assertSame( 'destination_changed', $reasons[ $previous . '/album/photo.txt' ] );
		self::assertSame( 'destination_changed', $reasons[ $previous . '/album' ] );
		self::assertSame( 'undo_journal', $reasons[ $this->live . '/a-existing' ] );
		$this->uploads = $previous;
		$this->undo( $job );
		self::assertDirectoryDoesNotExist( $this->uploads . '/album' );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	/**
	 * An inline and a background apply and their undo.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function inline_and_background(): array {
		return [
			'inline'     => [ false ],
			'background' => [ true ],
		];
	}

	#[DataProvider( 'inline_and_background' )]
	public function test_an_entry_whose_mapping_the_layout_now_refuses_is_kept_and_the_undo_goes_on( bool $background ): void {
		$this->stage_fresh();
		// A file that the apply installs in a folder of the content tree.
		mkdir( $this->files . '/old/site-content/changed-layout', 0700 );
		file_put_contents( $this->files . '/old/site-content/changed-layout/settings.php', 'settings' );
		$this->write_report( '/old/site-content/link' );
		if ( $background ) {
			$restore                  = $this->jobs->get( $this->restore ) ?? self::fail( 'The restore is gone.' );
			$restore->result['files'] = DiskFilePlan::PAGE + 1;
			$this->jobs->save( $restore );
			$plan = $this->run_job( $this->changes->request_preview( $this->restore, false, false ) );
			$job  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		} else {
			$job = $this->run_job( $this->changes->start_apply( $this->restore, false, $this->changes->preview( $this->restore )['digest'] ) );
		}
		self::assertSame( 'settings', file_get_contents( $this->live . '/changed-layout/settings.php' ) );
		// That folder becomes the uploads root: the content path of the file would now map into
		// the uploads tree, a mapping that the layout refuses.
		$this->uploads = $this->live . '/changed-layout';
		$review        = $this->changes->request_preview( $job->id, true, false );
		$plan_job      = null;
		if ( $review instanceof Job ) {
			$plan_job = $this->run_job( $review )->id;
			$review   = $this->changes->request_preview( $job->id, true, false, $plan_job );
		}
		self::assertIsArray( $review );
		$reasons = array_column( $review['entries'], 'reason', 'destination' );
		self::assertSame( 'destination_changed', $reasons[ $this->live . '/changed-layout/settings.php' ] );
		self::assertSame( 'destination_changed', $reasons[ $this->live . '/changed-layout' ] );
		self::assertSame( 'undo_journal', $reasons[ $this->live . '/a-existing' ] );
		$undo = $this->run_job( $this->changes->start_undo( $job->id, $review['digest'], '', $plan_job ) );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 'settings', file_get_contents( $this->live . '/changed-layout/settings.php' ) );
		self::assertSame( 'destination_changed', $this->kept( $undo )[ $this->live . '/changed-layout/settings.php' ] );
	}

	#[DataProvider( 'inline_and_background' )]
	public function test_a_created_folder_that_the_worker_cannot_read_is_kept_and_the_undo_goes_on( bool $background ): void {
		if ( $background ) {
			$restore                  = $this->jobs->get( $this->restore ) ?? self::fail( 'The restore is gone.' );
			$restore->result['files'] = DiskFilePlan::PAGE + 1;
			$this->jobs->save( $restore );
			$plan = $this->run_job( $this->changes->request_preview( $this->restore, false, false ) );
			$job  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		} else {
			$job = $this->run_job( $this->start() );
		}
		// The folder that the apply created keeps the attributes that the apply recorded, and
		// the worker cannot read it, as a worker of another user cannot read a folder that the
		// apply's worker created for itself alone. Only the record of its permissions is
		// brought in line with the folder here.
		$folder = $this->live . '/b-new';
		chmod( $folder, 0311 );
		try {
			if ( is_readable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory read permissions.' );
			}
			clearstatcache();
			$stat = lstat( $folder );
			self::assertIsArray( $stat );
			$entries = glob( $this->jobs->directory( $job ) . '/undo/item-*/entry.json' );
			self::assertNotEmpty( $entries );
			foreach ( $entries as $entry ) {
				$record = json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
				if ( 'mkdir' === $record['action'] ) {
					$record['installed'] = hash( 'sha256', implode( '|', [ $stat['mode'], $stat['uid'], $stat['gid'], 0 ] ) );
					file_put_contents( $entry, json_encode( $record, JSON_THROW_ON_ERROR ) );
				}
			}
			$review   = $this->changes->request_preview( $job->id, true, false );
			$plan_job = null;
			if ( $review instanceof Job ) {
				$plan_job = $this->run_job( $review )->id;
				$review   = $this->changes->request_preview( $job->id, true, false, $plan_job );
			}
			self::assertIsArray( $review );
			$decisions = [];
			foreach ( $review['entries'] as $entry ) {
				$decisions[ (string) $entry['destination'] ] = [ $entry['action'], $entry['reason'] ];
			}
			// The site policy cannot rule out another spelling of the file's name in the folder,
			// and the folder cannot be told empty: both are kept, and the rest is reverted.
			// A background plan lists the entries of a folder in the order in which the file
			// system names them, so the folder and the file beside it come in either order.
			self::assertEquals(
				[
					$folder . '/new.txt'        => [ 'keep', 'ambiguous_destination' ],
					$folder                     => [ 'keep', 'changed_since_apply' ],
					$this->live . '/a-existing' => [ 'restore', 'undo_journal' ],
				],
				$decisions
			);
			self::assertCount( 3, $decisions );
			// What the apply put into the folder is reviewed before the folder itself.
			$order = array_keys( $decisions );
			self::assertLessThan( array_search( $folder, $order, true ), array_search( $folder . '/new.txt', $order, true ) );
			$undo = $this->run_job( $this->changes->start_undo( $job->id, $review['digest'], '', $plan_job ) );
			self::assertSame(
				[
					'restored'             => 1,
					'removed'              => 0,
					'unchanged'            => 0,
					'kept'                 => 2,
					'changed_after_review' => 0,
					'apply_state'          => 'partly_undone',
				],
				self::outcomes( $undo )
			);
			self::assertSame(
				[
					$folder . '/new.txt' => 'ambiguous_destination',
					$folder              => 'changed_since_apply',
				],
				$this->kept( $undo )
			);
			self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		} finally {
			chmod( $folder, 0755 );
		}
	}

	public function test_a_confirmed_apply_keeps_its_uploads_root_while_its_ticks_boot_without_the_filter(): void {
		$this->stage_fresh();
		$confirmed = $this->uploads;
		$preview   = $this->changes->preview( $this->restore );
		$job       = $this->changes->start_apply( $this->restore, false, $preview['digest'] );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		// Ticks under the marker boot without the ordinary plugin whose upload_dir filter
		// chose the uploads root of the review, and WordPress reports its default instead.
		$this->uploads = $this->live . '/default-uploads';
		mkdir( $this->uploads );
		$this->run_job( $job );
		self::assertSame( 'imported upload', file_get_contents( $confirmed . '/album/photo.txt' ) );
		self::assertDirectoryDoesNotExist( $this->uploads . '/album' );
	}

	public function test_overlapping_fresh_destinations_refuse_the_entire_apply(): void {
		$this->stage_fresh();
		mkdir( $this->files . '/old/site-content/uploads', 0700 );
		file_put_contents( $this->files . '/old/site-content/uploads/conflict', 'duplicate destination' );
		$this->write_report( '/old/site-content/link' );
		$preview = $this->changes->preview( $this->restore );
		self::assertFalse( $preview['applicable'] );
		$entries = array_column( $preview['entries'], 'action', 'source' );
		self::assertSame( 'refuse', $entries['/old/site-content/uploads/conflict'] );
		$this->expectException( JobConflict::class );
		$this->changes->start_apply( $this->restore, false, $preview['digest'] );
	}

	public function test_fresh_mapping_rejects_configuration_override(): void {
		$this->stage_fresh();
		$this->expectException( InvalidArgument::class );
		$this->changes->preview( $this->restore, true );
	}
	private function queue_preview_job(): Job {
		$queued = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $queued );
		return $queued;
	}

	private function queue_large_preview(): Job {
		for ( $index = 0; $index < 111; ++$index ) {
			$name = $this->live . '/same-' . $index;
			file_put_contents( $name, 'already current' );
			file_put_contents( $this->files . $name, 'already current' );
		}
		$this->write_report( $this->live . '/skipped-link' );
		$queued = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $queued );
		self::assertSame( JobType::FilePlan, $queued->type );
		return $queued;
	}

	public function test_disk_preview_is_paged_and_authenticated_then_large_apply_and_undo_use_it(): void {
		$original    = str_repeat( 'before', 500000 );
		$replacement = str_repeat( 'after', 800000 );
		file_put_contents( $this->live . '/a-existing', $original );
		file_put_contents( $this->files . $this->live . '/a-existing', $replacement );
		$plan  = $this->queue_large_preview();
		$again = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $again );
		self::assertSame( $plan->id, $again->id );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		self::assertSame( 'planning', $this->jobs->get( $plan->id )?->step );
		$this->run_job( $plan );
		$page = $this->changes->request_preview( $this->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		self::assertCount( 100, $page['entries'] );
		self::assertTrue( $page['applicable'] );
		$space = new FileSpace( $this->jobs->storage_directory() );
		self::assertSame( $space->allocation( strlen( $original ) ) + $space->allocation( strlen( $replacement ) ) + $space->allocation( 7 ), $page['required_bytes'] );
		self::assertTrue( $page['enough_space'] );
		self::assertSame( 100, $page['next_offset'] );
		$last = $this->changes->request_preview( $this->restore, false, false, $plan->id, 100 );
		self::assertIsArray( $last );
		self::assertNull( $last['next_offset'] );
		self::assertSame( $last['digest'], $page['digest'] );
		$apply    = $this->changes->start_apply( $this->restore, false, $page['digest'], '', $plan->id );
		$finished = $this->run_job( $apply );
		self::assertSame( 3, $finished->result['files_changed'] );
		self::assertSame( hash( 'sha256', $replacement ), hash_file( 'sha256', $this->live . '/a-existing' ) );
		$this->clock->advance( 3601 );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		$undo_plan = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $undo_plan );
		$this->run_job( $undo_plan );
		$undo = $this->changes->request_preview( $apply->id, true, false, $undo_plan->id );
		self::assertIsArray( $undo );
		self::assertTrue( $undo['applicable'] );
		self::assertSame( 0, $undo['required_bytes'] );
		$this->run_job( $this->changes->start_undo( $apply->id, $undo['digest'], '', $undo_plan->id ) );
		self::assertSame( hash( 'sha256', $original ), hash_file( 'sha256', $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		$this->staging->remove_due( 1, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( dirname( $this->files ) );
	}

	public function test_a_large_apply_into_one_wide_folder_lists_it_about_once_per_tick_and_second(): void {
		$media = $this->live . '/media';
		mkdir( $media );
		mkdir( $this->files . $media, 0700 );
		for ( $i = 0; $i < 2500; ++$i ) {
			touch( $media . '/existing-' . $i );
		}
		for ( $i = 0; $i < 150; ++$i ) {
			file_put_contents( $this->files . $media . '/new-' . $i, 'new ' . $i );
		}
		$this->write_report( $this->live . '/skipped-link' );
		$review = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		// A review takes the administrator longer than this; the staged tree has settled.
		$ready = time() + 1 + LiveSpellings::CLOCK_MARGIN;
		while ( microtime( true ) < $ready ) {
			usleep( 10000 );
		}
		$this->plans = [];
		$started     = time();
		$apply       = $this->run_job( $this->changes->start_apply( $this->restore, false, $review->result['digest'], '', $review->id ) );
		$seconds     = time() - $started + 1;
		self::assertSame( 153, $apply->result['files_changed'] );
		self::assertSame( 'new 149', file_get_contents( $media . '/new-149' ) );
		$wide   = array_sum( array_map( static fn ( ApplyPlan $plan ): int => $plan->listings( $media ), $this->plans ) );
		$staged = array_sum( array_map( fn ( ApplyPlan $plan ): int => $plan->listings( $this->files . $media ), $this->plans ) );
		// Every recheck of the 150 new files used to list both folders, about 750 times each.
		self::assertGreaterThan( 0, $wide );
		self::assertLessThanOrEqual( count( $this->plans ) + 2 * $seconds, $wide );
		self::assertGreaterThan( 0, $staged );
		self::assertLessThanOrEqual( count( $this->plans ), $staged );
	}

	public function test_legacy_sealed_plan_without_an_estimate_restarts_interrupted_preflight_before_mutating(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$sealed = dirname( $this->files ) . '/plans/' . $plan->id . '/sealed.json';
		$header = json_decode( file_get_contents( $sealed ), true, 512, JSON_THROW_ON_ERROR );
		unset( $header['required_bytes'] );
		$old                    = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$plan->result['digest'] = hash( 'sha256', $old );
		file_put_contents( $sealed, $old );
		$this->jobs->save( $plan );

		$review = $this->changes->request_preview( $this->restore, false, false, $plan->id );
		self::assertIsArray( $review );
		self::assertNull( $review['required_bytes'] );
		self::assertTrue( $review['applicable'] );
		$apply                       = $this->changes->start_apply( $this->restore, false, $review['digest'], '', $plan->id );
		$apply->progress['verified'] = $header['entries'];
		$this->jobs->save( $apply );
		$this->free = 0;
		$failed     = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
		self::assertSame( [], $this->changes->undoable() );
	}

	public function test_background_preflight_refuses_changed_files_before_the_first_live_write(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id );
		file_put_contents( $this->files . $this->live . '/b-new/new.txt', 'subsequent modification' );
		$failed = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_changed_after_review', $failed->error?->code );
		self::assertSame( [ 0, 0 ], [ $failed->result['entries_completed'], $failed->result['entries_attempted'] ] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
	}

	public function test_a_live_file_created_after_a_background_review_stops_the_apply_with_its_own_code(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id );
		// An update installs the directory that the reviewed plan creates.
		mkdir( $this->live . '/b-new' );
		file_put_contents( $this->live . '/b-new/new.txt', 'from an update' );
		$failed = $this->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_changed_after_review', $failed->error?->code );
		self::assertSame( 'from an update', file_get_contents( $this->live . '/b-new/new.txt' ) );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_an_apply_whose_staged_files_are_gone_says_so(): void {
		$apply = $this->start();
		TempDir::remove( dirname( $this->files ) );
		$failed = $this->run_job( $apply, JobState::Failed );
		// Before: "The backup is not configured completely".
		self::assertSame( 'file_staging_gone', $failed->error?->code );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_previews_count_their_entries_by_action_above_the_confirmation(): void {
		$inline = $this->changes->request_preview( $this->restore, false, false );
		self::assertIsArray( $inline );
		$counted = array_count_values( array_column( $inline['entries'], 'action' ) );
		self::assertSame( [ 1, 1, 1, 2, 0 ], [ $counted['replace'], $counted['mkdir'], $counted['create'], $counted['skip'], $counted['refuse'] ?? 0 ] );
		self::assertSame( [ 'create', 'replace', 'mkdir', 'keep', 'skip', 'refuse' ], array_keys( $inline['actions'] ?? [] ) );
		foreach ( $inline['actions'] as $action => $count ) {
			self::assertSame( $counted[ $action ] ?? 0, $count, $action );
		}
		// A background preview is read a page at a time: its totals cover every page.
		$plan   = $this->run_job( $this->queue_large_preview() );
		$page   = $this->changes->request_preview( $this->restore, false, false, $plan->id );
		$rows   = [];
		$totals = $page['actions'] ?? null;
		do {
			self::assertIsArray( $page );
			self::assertSame( $totals, $page['actions'] ?? null, 'Every page has the same totals.' );
			array_push( $rows, ...array_column( $page['entries'], 'action' ) );
			$next = $page['next_offset'];
			$page = null === $next ? null : $this->changes->request_preview( $this->restore, false, false, $plan->id, $next );
		} while ( null !== $page );
		self::assertGreaterThan( 100, count( $rows ) );
		self::assertIsArray( $totals );
		self::assertSame( 111 + ( $counted['keep'] ?? 0 ), $totals['keep'] );
		$counted = array_count_values( $rows );
		foreach ( $totals as $action => $count ) {
			self::assertSame( $counted[ $action ] ?? 0, $count, $action );
		}
		// An undo review keeps its totals of its own.
		self::assertArrayNotHasKey( 'actions', $this->changes->request_preview( $this->run_job( $this->start() )->id, true, false ) );
	}

	public function test_a_background_preview_begun_without_counts_by_action_shows_none(): void {
		$plan = $this->queue_large_preview();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$saved = $this->jobs->get( $plan->id );
		self::assertNotNull( $saved );
		self::assertGreaterThan( 0, $saved->data['plan_cursor']['counts']['rows'] ?? 0 );
		// An earlier version counted no actions of an apply preview in its checkpoint.
		$saved->data['plan_cursor']['actions'] = [];
		$this->jobs->save( $saved );
		$done = $this->run_job( $plan );
		$page = $this->changes->request_preview( $this->restore, false, false, $done->id );
		self::assertIsArray( $page );
		self::assertArrayHasKey( 'actions', $page );
		self::assertNull( $page['actions'] );
		self::assertTrue( $page['applicable'] );
	}

	public function test_file_plan_limit_fails_the_entire_job_instead_of_publishing_a_prefix(): void {
		$plan = $this->queue_large_preview();
		$this->runner->tick( 0.0 );
		$saved = $this->jobs->get( $plan->id );
		self::assertNotNull( $saved );
		$saved->data['plan_cursor'] = [ 'counts' => [ 'rows' => 1000000 ] ];
		$this->jobs->save( $saved );
		$failed = $this->run_job( $plan, JobState::Failed );
		self::assertSame( 'file_plan_too_large', $failed->error?->code );
		self::assertArrayNotHasKey( 'digest', $failed->result );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_background_preview_rejects_a_different_report_after_its_first_checkpoint(): void {
		$plan = $this->queue_large_preview();
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$saved = $this->jobs->get( $plan->id );
		self::assertNotNull( $saved );
		self::assertArrayHasKey( 'report_identity', $saved->data );
		$report = dirname( $this->files ) . '/entries.jsonl';
		copy( $report, $report . '.next' );
		rename( $report . '.next', $report );
		$failed = $this->run_job( $plan, JobState::Failed );
		// Not "Another backup job is still running": the preview failed, the log says why.
		self::assertSame( 'file_preview_failed', $failed->error?->code );
		self::assertSame( 'The staged report changed while its preview was being built.', $failed->error->message );
		self::assertDirectoryDoesNotExist( dirname( $report ) . '/plans/' . $plan->id );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_background_undo_keeps_a_directory_that_gained_a_child_after_the_review(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		self::assertSame( [ 1, 2, 0, 3, 0 ], [ $review->result['restore'], $review->result['remove'], $review->result['keep'], $review->result['changes'], $review->result['refused'] ] );
		$undo = $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
		file_put_contents( $this->live . '/b-new/added-after-review', 'independent data' );
		$done = $this->run_job( $undo );
		self::assertSame( 'independent data', file_get_contents( $this->live . '/b-new/added-after-review' ) );
		self::assertFileDoesNotExist( $this->live . '/b-new/new.txt' );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertSame( 1, $done->result['changed_after_review'] );
		self::assertSame( [ $this->live . '/b-new' => 'new_children' ], $this->kept( $done ) );
	}

	public function test_a_background_undo_review_keeps_an_entry_whose_wide_folder_cannot_be_read_and_goes_on(): void {
		// A file that the apply adds to a live folder with more names than a review lists at once.
		$wide = $this->live . '/wide';
		mkdir( $wide );
		for ( $i = 0; $i < 2050; ++$i ) {
			touch( $wide . '/upload-' . $i );
		}
		mkdir( $this->files . $wide, 0700 );
		file_put_contents( $this->files . $wide . '/added.txt', 'added' );
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		self::assertSame( 'added', file_get_contents( $wide . '/added.txt' ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		// The review begins the index of the folder's names; then the folder can no longer be read.
		$indexed = fn (): bool => ! in_array( glob( $this->jobs->directory( $review ) . '/verification/spell-*.head' ), [ false, [] ], true );
		$tick    = 0;
		while ( ! $indexed() && ++$tick < 40 ) {
			$this->runner->tick( 0.0 );
		}
		self::assertTrue( $indexed() );
		chmod( $wide, 0311 );
		try {
			if ( is_readable( $wide ) ) {
				self::markTestSkipped( 'This user bypasses directory read permissions.' );
			}
			$reviewed = $this->run_job( $review );
			$page     = $this->changes->request_preview( $apply->id, true, false, $reviewed->id );
			self::assertIsArray( $page );
			$decisions = [];
			foreach ( $page['entries'] as $entry ) {
				$decisions[ (string) $entry['destination'] ] = [ $entry['action'], $entry['reason'] ];
			}
			self::assertSame( [ 'keep', 'ambiguous_destination' ], $decisions[ $wide . '/added.txt' ] );
			self::assertSame( [ 'restore', 'undo_journal' ], $decisions[ $this->live . '/a-existing' ] );
			$undo = $this->run_job( $this->changes->start_undo( $apply->id, $page['digest'], '', $reviewed->id ) );
			self::assertSame( [ 1, 2, 1, 'partly_undone' ], [ $undo->result['restored'], $undo->result['removed'], $undo->result['kept'], $undo->result['apply_state'] ] );
			self::assertSame( [ $wide . '/added.txt' => 'ambiguous_destination' ], $this->kept( $undo ) );
			self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		} finally {
			chmod( $wide, 0755 );
		}
		self::assertSame( 'added', file_get_contents( $wide . '/added.txt' ) );
	}

	public function test_a_large_copy_is_verified_across_ticks_by_the_background_review_and_the_undo(): void {
		$large = str_repeat( 'L', 6 * ChunkDigest::BLOCK + 3 );
		file_put_contents( $this->files . $this->live . '/a-existing', $large );
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		// The undo of a later apply leaves the same bytes and attributes under a new inode.
		self::rewrite_as_copy( $this->live . '/a-existing' );
		// The copy's change second passes, so that a prefix read in one tick counts in the next.
		sleep( 1 );
		$ticks  = function ( Job $job ): int {
			for ( $ticks = 1; $ticks < 200; ++$ticks ) {
				if ( $this->runner->tick( 0.0 )->job?->state->is_terminal() ) {
					self::assertSame( JobState::Succeeded, $this->jobs->get( $job->id )?->state );
					return $ticks;
				}
			}
			self::fail( 'The job did not finish.' );
		};
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		self::assertGreaterThan( 6, $ticks( $review ) );
		$page = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 2,
				'keep'    => 0,
			],
			$page['totals']
		);
		self::assertSame( 'installed_content', array_column( $page['entries'], 'reason', 'path' )[ $this->live . '/a-existing' ] );
		$undo = $this->changes->start_undo( $apply->id, $page['digest'], '', $review->id );
		self::assertGreaterThan( 6, $ticks( $undo ) );
		self::assertSame( 1, $this->jobs->get( $undo->id )?->result['restored'] );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_a_background_undo_with_nothing_left_to_revert_is_refused(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		file_put_contents( $this->live . '/a-existing', 'edited' );
		TempDir::remove( $this->live . '/b-new' );
		$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$page   = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertFalse( $page['applicable'] );
		self::assertSame( [ 'keep' ], array_values( array_unique( array_column( $page['entries'], 'action' ) ) ) );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 0,
				'keep'    => 3,
			],
			$page['totals']
		);
		$this->expectException( JobConflict::class );
		$this->expectExceptionMessage( 'Nothing of this apply can be undone now.' );
		$this->changes->start_undo( $apply->id, $page['digest'], '', $review->id );
	}

	public function test_a_single_small_selection_beside_many_unrelated_live_files_gets_a_background_review(): void {
		for ( $i = 0; $i < 2050; ++$i ) {
			touch( $this->live . '/unselected-' . $i );
		}
		$plan = $this->changes->request_preview( $this->restore, false, false );
		self::assertInstanceOf( Job::class, $plan );
		self::assertSame( JobType::FilePlan, $plan->type );
		$ready = $this->run_job( $plan );
		self::assertSame( 3, $ready->result['changes'] );
		self::assertSame( 0, $ready->result['refused'] );
		self::assertDirectoryDoesNotExist( $this->jobs->directory( $ready ) . '/verification' );
	}

	public function test_wide_staged_directory_uses_a_bounded_worker_index_and_refuses_ambiguous_names(): void {
		$plan = $this->queue_large_preview();
		for ( $i = 0; $i < 2050; ++$i ) {
			touch( $this->files . $this->live . '/extra-slot-' . $i );
		}
		touch( $this->files . $this->live . '/A-existing' );
		$ready = $this->run_job( $plan );
		$page  = $this->changes->request_preview( $this->restore, false, false, $ready->id );
		self::assertIsArray( $page );
		self::assertFalse( $page['applicable'] );
		self::assertGreaterThan( 0, $ready->result['refused'] );
		self::assertDirectoryDoesNotExist( $this->jobs->directory( $ready ) . '/verification' );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_background_pages_refuse_tampering_and_expire_without_a_consumer(): void {
		$plan = $this->run_job( $this->queue_large_preview() );
		$dir  = dirname( $this->files ) . '/plans/' . $plan->id;
		file_put_contents( $dir . '/rows-0', '[]' );
		try {
			$this->changes->request_preview( $this->restore, false, false, $plan->id );
			self::fail( 'A tampered page was returned.' );
		} catch ( \BackupsForRestic\Exception\StorageFailed ) {
			self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		}
		$this->clock->advance( 3601 );
		try {
			$this->changes->request_preview( $this->restore, false, false, $plan->id );
			self::fail( 'An expired preview was returned.' );
		} catch ( JobConflict $expired ) {
			self::assertSame( 'file_preview_expired', $expired->context()['code'] ?? null );
		}
		$this->staging->remove_due( 24, PHP_FLOAT_MAX );
		self::assertDirectoryDoesNotExist( $dir );
		self::assertDirectoryExists( $this->files );
	}

	public function test_a_background_undo_review_sealed_without_totals_is_reviewed_again(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$sealed = dirname( $this->files ) . '/plans/' . $review->id . '/sealed.json';
		$header = json_decode( (string) file_get_contents( $sealed ), true, 512, JSON_THROW_ON_ERROR );
		// A review that an earlier version sealed counted kept entries as changes and had no totals.
		unset( $header['actions'] );
		$old                      = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$review->result['digest'] = hash( 'sha256', $old );
		file_put_contents( $sealed, $old );
		$this->jobs->save( $review );
		$page = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertFalse( $page['applicable'] );
		self::assertNull( $page['totals'] );
		try {
			$this->changes->start_undo( $apply->id, $page['digest'], '', $review->id );
			self::fail( 'An undo review without totals must be reviewed again.' );
		} catch ( JobConflict $stale ) {
			// A review to do again, not another job that is still running.
			self::assertSame( 'file_changed_after_review', JobError::from_throwable( $stale )->code );
		}
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_a_background_undo_queued_with_a_review_without_totals_is_reviewed_again(): void {
		$plan  = $this->run_job( $this->queue_large_preview() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		// Queued as an earlier version queued it, with a review sealed without totals; one of
		// them also after the worker had passed its preflight.
		foreach ( [ 'preflight', 'undoing' ] as $step ) {
			$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
			$page   = $this->changes->request_preview( $apply->id, true, false, $review->id );
			self::assertIsArray( $page );
			$undo = $this->changes->start_undo( $apply->id, $page['digest'], '', $review->id );
			if ( 'undoing' === $step ) {
				$this->runner->tick( 0.0 ); // Preflight.
				self::assertSame( 'undoing', $this->jobs->get( $undo->id )?->step );
			}
			$sealed = dirname( $this->files ) . '/plans/' . $review->id . '/sealed.json';
			$header = json_decode( (string) file_get_contents( $sealed ), true, 512, JSON_THROW_ON_ERROR );
			unset( $header['actions'] );
			$old                      = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			$review->result['digest'] = hash( 'sha256', $old );
			file_put_contents( $sealed, $old );
			$this->jobs->save( $review );
			// That version confirmed the undo with the digest of its review.
			$queued                 = $this->jobs->get( $undo->id ) ?? self::fail( 'The undo is gone.' );
			$queued->data['digest'] = $review->result['digest'];
			$this->jobs->save( $queued );
			$failed = $this->run_job( $undo, JobState::Failed );
			self::assertSame( 'undo_review_required', $failed->error?->code, $step );
			self::assertSame( $step, $this->failed_step( $failed ) );
			self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
			self::assertFileExists( $this->live . '/b-new/new.txt' );
		}
	}

	public function test_a_background_undo_review_rebuilds_a_checkpoint_from_before_its_totals(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$saved  = $this->jobs->get( $review->id );
		$cursor = $saved?->data['plan_cursor'];
		self::assertIsArray( $cursor );
		self::assertSame( 1, $cursor['counts']['rows'] );
		// As an earlier version left it: kept entries counted as changes, a changed entry
		// refused, and no totals.
		unset( $cursor['actions'] );
		$cursor['changes']          = 1;
		$cursor['refused']          = 1;
		$saved->data['plan_cursor'] = $cursor;
		$this->jobs->save( $saved );
		$done = $this->run_job( $review );
		self::assertSame( [ 1, 2, 0, 3, 0 ], [ $done->result['restore'], $done->result['remove'], $done->result['keep'], $done->result['changes'], $done->result['refused'] ] );
		$page = $this->changes->request_preview( $apply->id, true, false, $done->id );
		self::assertIsArray( $page );
		self::assertTrue( $page['applicable'] );
		// A checkpoint whose totals are not counts stops the review.
		$broken = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $broken );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 );
		$saved                                 = $this->jobs->get( $broken->id );
		$saved->data['plan_cursor']['actions'] = 'none';
		$this->jobs->save( $saved );
		$failed = $this->run_job( $broken, JobState::Failed );
		self::assertSame( 'The background file preview checkpoint is invalid.', $failed->error?->message );
	}

	public function test_a_folder_kept_after_its_scan_yielded_leaves_no_position_to_the_next_folder(): void {
		// Two folders that the apply creates side by side, and the usual many unchanged files.
		foreach ( [ 'a-dir/1.txt', 'a-dir/2.txt', 'z-dir/1.txt' ] as $name ) {
			if ( ! is_dir( dirname( $this->files . $this->live . '/' . $name ) ) ) {
				mkdir( dirname( $this->files . $this->live . '/' . $name ), 0700 );
			}
			file_put_contents( $this->files . $this->live . '/' . $name, 'created' );
		}
		$queued = $this->queue_large_preview();
		// The report lists a-dir last, before the queued preview reads it, so that the undo,
		// which goes backwards, reviews a-dir before the other created folders.
		$report = dirname( $this->files ) . '/entries.jsonl';
		$lines  = array_filter( explode( "\n", (string) file_get_contents( $report ) ) );
		$moved  = array_filter( $lines, fn ( string $line ): bool => str_starts_with( (string) json_decode( $line, true, flags: JSON_THROW_ON_ERROR )['path'], $this->live . '/a-dir' ) );
		file_put_contents( $report, implode( "\n", [ ...array_diff( $lines, $moved ), ...$moved ] ) . "\n" );
		$plan  = $this->run_job( $queued );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		clearstatcache();
		$folder = ApplyManifest::identity( $this->live . '/a-dir' );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		// Ticks without budget until the review yields in a-dir's names.
		for ( $tick = 0; $tick < 20; ++$tick ) {
			$this->runner->tick( 0.0 );
			$saved = $this->jobs->get( $review->id );
			if ( null !== ( $saved?->data['plan_cursor']['pending']['identity'] ?? null ) ) {
				break;
			}
		}
		self::assertSame( $folder, $saved?->data['plan_cursor']['pending']['identity'] ?? null );
		// a-dir changes before the review goes on, so that it is kept for another reason.
		chmod( $this->live . '/a-dir', 0750 );
		$done = $this->run_job( $review );
		$page = $this->changes->request_preview( $apply->id, true, false, $done->id );
		self::assertIsArray( $page );
		$rows = array_column( $page['entries'], null, 'path' );
		self::assertSame( [ 'keep', 'changed_since_apply' ], [ $rows[ $this->live . '/a-dir' ]['action'], $rows[ $this->live . '/a-dir' ]['reason'] ] );
		foreach ( [ 'z-dir', 'b-new' ] as $name ) {
			self::assertSame( [ 'remove', 'undo_journal' ], [ $rows[ $this->live . '/' . $name ]['action'], $rows[ $this->live . '/' . $name ]['reason'] ], $name );
		}
	}

	/**
	 * A review tick stopped after it flushed its first row and the row's claim, before its
	 * document; and a checkpoint of an earlier version, which is reviewed from the start.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function reviewed_again(): array {
		return [
			'lost document'    => [ true ],
			'reset checkpoint' => [ false ],
		];
	}

	#[DataProvider( 'reviewed_again' )]
	public function test_a_row_reviewed_again_withdraws_the_removal_that_it_claimed_before( bool $lost_document ): void {
		$new    = $this->live . '/b-new/new.txt';
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$this->runner->tick( 0.0 ); // Preflight.
		$before = $this->jobs->get( $review->id );
		self::assertNotNull( $before );
		$this->runner->tick( 0.0 ); // The created file, reviewed for removal.
		$saved = $this->jobs->get( $review->id );
		self::assertNotNull( $saved );
		self::assertSame( 1, $saved->data['plan_cursor']['counts']['rows'] );
		if ( $lost_document ) {
			$this->jobs->save( $before );
		} else {
			unset( $saved->data['plan_cursor']['actions'] );
			$this->jobs->save( $saved );
		}
		// The file is edited before the review goes on: it is kept, and so is its directory.
		file_put_contents( $new, 'edited' );
		$done = $this->run_job( $review );
		$page = $this->changes->request_preview( $apply->id, true, false, $done->id );
		self::assertIsArray( $page );
		$rows = array_column( $page['entries'], null, 'path' );
		self::assertSame( [ 'keep', 'changed_since_apply' ], [ $rows[ $new ]['action'], $rows[ $new ]['reason'] ] );
		self::assertSame( [ 'keep', 'new_children' ], [ $rows[ $this->live . '/b-new' ]['action'], $rows[ $this->live . '/b-new' ]['reason'] ] );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 0,
				'keep'    => 2,
			],
			$page['totals']
		);
		// Nothing changed after the review: the undo keeps what the review showed as kept.
		$undo = $this->run_job( $this->changes->start_undo( $apply->id, $page['digest'], '', $done->id ) );
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 0,
				'unchanged'            => 0,
				'kept'                 => 2,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			self::outcomes( $undo )
		);
	}

	public function test_a_background_undo_review_keeps_entries_that_the_site_now_maps_elsewhere(): void {
		$this->stage_fresh();
		for ( $index = 0; $index < 111; ++$index ) {
			file_put_contents( $this->live . '/same-' . $index, 'already current' );
			file_put_contents( $this->files . '/old/site-content/same-' . $index, 'already current' );
		}
		$this->write_report( '/old/site-content/link' );
		$plan  = $this->run_job( $this->queue_preview_job() );
		$apply = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		self::assertSame( 'imported upload', file_get_contents( $this->uploads . '/album/photo.txt' ) );
		$previous      = $this->uploads;
		$this->uploads = $this->live . '/changed-layout';
		$review        = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$page          = $this->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		$reasons = array_column( $page['entries'], 'reason', 'destination' );
		self::assertSame( 'destination_changed', $reasons[ $previous . '/album/photo.txt' ] );
		self::assertSame( 'destination_changed', $reasons[ $previous . '/album' ] );
		self::assertSame( 'undo_journal', $reasons[ $this->live . '/a-existing' ] );
		// The content mapping did not change: the folder the apply created there still goes.
		self::assertSame( 'undo_journal', $reasons[ $this->live . '/plugins' ] );
		self::assertSame(
			[
				'restore' => 1,
				'remove'  => 1,
				'keep'    => 2,
			],
			$page['totals']
		);
		$this->uploads = $previous;
	}

	public function test_a_background_undo_starts_only_with_the_review_of_its_own_apply(): void {
		$plan      = $this->run_job( $this->queue_large_preview() );
		$apply     = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review    = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$directory = dirname( $this->files ) . '/plans/' . $review->id;
		$original  = [];
		foreach ( (array) scandir( $directory ) as $name ) {
			if ( is_file( $directory . '/' . $name ) ) {
				$original[ $name ] = (string) file_get_contents( $directory . '/' . $name );
			}
		}
		$header  = ( new DiskFilePlan( $directory ) )->open( $review->result['digest'] );
		$sealed  = 'The sealed file preview does not match the approved operation.';
		$foreign = 'The approved undo does not match the apply it reverses.';
		$cases   = [
			[ 'restore', 'another', $sealed ],
			[ 'subject', 'another', $sealed ],
			[ 'undo', false, $sealed ],
			[ 'config', true, $sealed ],
			[ 'rows', 2, $sealed ],
			[ 'changes', 0, $sealed ],
			[ 'refused', 1, $sealed ],
			[ 'path', Bytes::base64url_encode( $this->live . '/another' ), $foreign ],
			[ 'destination', Bytes::base64url_encode( $this->live . '/another' ), $foreign ],
			[ 'action', 'refuse', $foreign ],
		];
		foreach ( $cases as [ $field, $value, $message ] ) {
			foreach ( $original as $name => $content ) {
				file_put_contents( $directory . '/' . $name, $content );
			}
			$undo = $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
			$disk = new DiskFilePlan( $directory );
			$disk->open( $review->result['digest'] );
			$sealing = [
				'subject' => $header['subject'],
				'restore' => $header['restore'],
				'undo'    => $header['undo'],
				'config'  => $header['config'],
				'rows'    => $header['entries'],
				'changes' => $header['changes'],
				'refused' => $header['refused'],
			];
			if ( array_key_exists( $field, $sealing ) ) {
				$sealing[ $field ] = $value;
			} else {
				$row           = $disk->row( 0 );
				$row[ $field ] = $value;
				$disk->put( 'rows', 0, $row );
			}
			$undo->data['digest'] = $disk->seal( $sealing['subject'], $sealing['restore'], $sealing['undo'], $sealing['config'], [ 'rows' => $sealing['rows'] ], $sealing['changes'], $sealing['refused'], 0, $header['actions'] );
			$this->jobs->save( $undo );
			$failed = $this->run_job( $undo, JobState::Failed );
			self::assertSame( $message, $failed->error?->message, $field );
			self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ), $field );
			self::assertFileExists( $this->live . '/b-new/new.txt', $field );
		}
		// A review that changes between the preflight and the work is refused there as well.
		foreach ( $original as $name => $content ) {
			file_put_contents( $directory . '/' . $name, $content );
		}
		$undo = $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
		$this->runner->tick( 0.0 );
		$disk = new DiskFilePlan( $directory );
		$disk->open( $review->result['digest'] );
		$saved                 = $this->jobs->get( $undo->id );
		$saved->data['digest'] = $disk->seal( $header['subject'], $header['restore'], true, false, [ 'rows' => 2 ], $header['changes'], 0, 0, $header['actions'] );
		$this->jobs->save( $saved );
		$failed = $this->run_job( $undo, JobState::Failed );
		self::assertSame( 'The undo cursor is invalid.', $failed->error?->message );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_every_undo_tick_puts_a_missing_maintenance_marker_back(): void {
		$job  = $this->run_job( $this->start() );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		unlink( $this->live . '/.maintenance' );
		$this->runner->tick( 0.0 );
		self::assertFileExists( $this->live . '/.maintenance' );
		self::assertSame( 'undone', $this->run_job( $undo )->result['apply_state'] );
	}

	public function test_an_undo_checks_the_manifest_of_its_apply_before_maintenance(): void {
		$job      = $this->run_job( $this->start() );
		$undo     = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$manifest = $this->jobs->directory( $job ) . '/undo/manifest.json';
		$original = (string) file_get_contents( $manifest );
		file_put_contents( $manifest, $original . ' ' );
		$failed = $this->run_job( $undo, JobState::Failed );
		self::assertSame( 'The confirmed file manifest cannot be verified.', $failed->error?->message );
		self::assertSame( 'preflight', $this->failed_step( $failed ) );
		file_put_contents( $manifest, $original );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_a_background_undo_checks_the_plan_of_its_apply_before_maintenance(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->run_job( $this->changes->request_preview( $apply->id, true, false ) );
		$undo   = $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
		$sealed = dirname( $this->files ) . '/plans/' . $plan->id . '/sealed.json';
		file_put_contents( $sealed, file_get_contents( $sealed ) . ' ' );
		$failed = $this->run_job( $undo, JobState::Failed );
		self::assertSame( 'The sealed private file plan cannot be verified.', $failed->error?->message );
		self::assertSame( 'preflight', $this->failed_step( $failed ) );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
	}

	public function test_an_undo_that_stopped_before_it_changed_anything_leaves_the_state_of_its_apply(): void {
		$job = $this->run_job( $this->start() );
		file_put_contents( $this->live . '/b-new/new.txt', 'edited' );
		$undo = $this->changes->start_undo( $job->id, $this->changes->preview_undo( $job->id )['digest'] );
		$this->runner->tick( 0.0 );
		$this->runner->tick( 0.0 ); // The edited file is kept.
		$this->runner->cancel( $undo->id );
		$cancelled = $this->run_job( $undo, JobState::Cancelled );
		self::assertSame( [ 1, 0 ], [ $cancelled->result['kept'], $cancelled->result['files_changed'] ] );
		self::assertSame( 'applied', $cancelled->result['apply_state'] );
		self::assertArrayNotHasKey( 'undo_state', $this->jobs->get( $job->id )?->result ?? [] );
		self::assertSame( 'applied', $this->changes->undoable()[0]['state'] );
	}
}
