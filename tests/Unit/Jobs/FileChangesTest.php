<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\ChunkDigest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\LiveSpellings;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Support\Bytes;
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
		$this->changes = new FileChanges(
			$this->jobs,
			$this->staging,
			$this->clock,
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
		$this->runner  = new Runner( $this->jobs, new RunnerLock( $this->directory . '/runner.lock' ), $this->changes, $this->clock, $random );
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
		} catch ( JobConflict ) {
			self::assertDirectoryExists( $this->files );
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
		} catch ( JobConflict ) {
			self::assertNull( $this->jobs->active() );
		}
		$apply = $this->start();
		file_put_contents( $this->files . $this->live . '/b-new/new.txt', 'different staged file' );
		$this->run_job( $apply, JobState::Failed );
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
		$this->run_job( $job, JobState::Failed );
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
		$counts = static fn ( int $removed, int $kept ): array => [
			'restored'             => 0,
			'removed'              => $removed,
			'unchanged'            => 0,
			'kept'                 => $kept,
			'changed_after_review' => 0,
		];
		$rewind = function ( int $done, array $outcomes ) use ( $saved ): void {
			$document = $saved();
			// The step and the cursor as the document last on disk had them.
			$document->step                = 'undoing';
			$document->progress['done']    = $done;
			$document->data['undo_counts'] = $outcomes;
			$this->jobs->save( $document );
		};
		// A worker killed after it removed the directory, before the record that completes it
		// and the document: the last record is the removal that names this undo.
		$entry  = $this->jobs->directory( $job ) . '/undo/item-1/entry.json';
		$record = json_decode( (string) file_get_contents( $entry ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( [ 'undone', $undo->id ], [ $record['phase'], $record['undo'] ] );
		$record['phase'] = 'remove';
		file_put_contents( $entry, json_encode( $record, JSON_THROW_ON_ERROR ) );
		$rewind( 1, $counts( 1, 0 ) );
		$this->runner->tick( 0.0 ); // The directory again: gone, and removed by this undo.
		self::assertSame( 2, $saved()->progress['done'] );
		self::assertSame( $counts( 2, 0 ), $saved()->data['undo_counts'] );
		$this->runner->tick( 0.0 ); // The edited file is kept and listed.
		self::assertSame( $counts( 2, 1 ), $saved()->data['undo_counts'] );
		// A worker killed after it listed the kept entry, before the document counted it.
		$rewind( 2, $counts( 2, 0 ) );
		$this->runner->tick( 0.0 ); // The edited file again, at the same place in the list.
		self::assertSame( $counts( 2, 1 ), $saved()->data['undo_counts'] );
		// And one killed after the document counted the last entry, before the next step.
		$rewind( 3, $counts( 2, 1 ) );
		$done = $this->run_job( $undo );
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
		// Put the installed bytes back, and the next undo reverts the entry and keeps nothing.
		file_put_contents( $this->live . '/a-existing', 'replacement' );
		$again = $this->undo( $job );
		self::assertSame( 1, $again->result['restored'] );
		$empty = $this->changes->kept_page( $again->id, 0 );
		self::assertSame( [ [], 0, 0, null, $again->id, '' ], [ $empty['entries'], $empty['total'], $empty['offset'], $empty['next_offset'], $empty['plan_job'], $empty['digest'] ] );
		$this->expectException( InvalidArgument::class );
		$this->changes->kept_page( $again->id, 1 );
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

	public function test_expired_undo_is_removed_and_staging_released(): void {
		$job = $this->run_job( $this->start() );
		$this->clock->advance( 86400 );
		self::assertSame( [], $this->changes->undoable() );
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
		$this->run_job( $apply, JobState::Failed );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->live . '/b-new' );
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
		$this->run_job( $plan, JobState::Failed );
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
			self::assertSame( 'job_conflict', JobError::from_throwable( $stale )->code );
		}
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
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
