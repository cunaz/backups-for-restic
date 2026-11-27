<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Tests\Support\ApplySite;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The background reviews of the apply jobs: when a review is inline and when it is a job,
 * which pages of a sealed review are served to whom, what the job records while it runs and
 * when it ends, and the space estimate of an apply that began before the estimate existed.
 */
final class FilePlansTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'file-plans' );
	}

	protected function tearDown(): void {
		$this->site->remove();
		unset( $this->site );
	}

	/** Builds the background review of the restore. */
	private function plan(): Job {
		return $this->site->run_job( $this->site->queue_large_preview() );
	}

	/** Whether a request for the restore's review gets a review job rather than an inline review. */
	private function in_background(): bool {
		$review = $this->site->changes->request_preview( $this->site->restore, false, false );
		if ( $review instanceof Job ) {
			$this->site->runner->cancel( $review->id );
			return true;
		}
		return false;
	}

	/** Sets a field of the restore job's result. */
	private function restore_result( string $field, int $value ): void {
		$restore                   = $this->site->jobs->get( $this->site->restore ) ?? self::fail( 'The restore is gone.' );
		$restore->result[ $field ] = $value;
		$this->site->jobs->save( $restore );
	}

	public function test_a_review_is_inline_up_to_its_limits_and_a_job_beyond_them(): void {
		// The restore's own count of bytes and files, then the entries of its report.
		foreach ( [ [ 'bytes', 8388608, false ], [ 'bytes', 8388609, true ], [ 'files', 100, false ], [ 'files', 101, true ] ] as [ $field, $value, $background ] ) {
			$this->restore_result( $field, $value );
			self::assertSame( $background, $this->in_background(), $field . ' ' . $value );
			$this->restore_result( $field, 0 );
		}
		$report = dirname( $this->site->files ) . '/entries.jsonl';
		$index  = 0;
		while ( substr_count( (string) file_get_contents( $report ), "\n" ) < 100 ) {
			file_put_contents( $this->site->files . $this->site->live . '/same-' . $index, 'staged' );
			$this->site->write_report( $this->site->live . '/skipped-link' );
			++$index;
		}
		self::assertSame( 100, substr_count( (string) file_get_contents( $report ), "\n" ) );
		self::assertFalse( $this->in_background(), 'A report of 100 entries.' );
		file_put_contents( $this->site->files . $this->site->live . '/same-' . $index, 'staged' );
		$this->site->write_report( $this->site->live . '/skipped-link' );
		self::assertTrue( $this->in_background(), 'A report of 101 entries.' );
		// Without its report the restore has no review.
		unlink( $report );
		try {
			$this->site->changes->request_preview( $this->site->restore, false, false );
			self::fail( 'A restore without its report was reviewed.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( 'file_staging_gone', $refused->context()['code'] ?? null );
		}
	}

	public function test_a_page_of_a_background_review_is_served_for_one_hour_after_the_review_ended(): void {
		$plan                       = $this->plan();
		$this->site->clock->seconds = $plan->finished_at + 3599;
		self::assertIsArray( $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id ) );
		$this->site->clock->seconds = $plan->finished_at + 3600;
		try {
			$this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
			self::fail( 'A review past its hour was served.' );
		} catch ( JobConflict $expired ) {
			self::assertSame( 'file_preview_expired', $expired->context()['code'] ?? null );
		}
	}

	public function test_a_page_is_served_only_to_a_request_of_the_scope_of_its_review(): void {
		$plan     = $this->plan();
		$other    = $this->site->second_restore( [ $this->site->live . '/other' => 'other' ] );
		$plans    = $this->site->file_plans();
		$restore  = $this->site->restore;
		$running  = $this->site->jobs->create( JobType::FilePlan, 'manual', $this->site->clock->seconds, $plan->data );
		$requests = [
			'another subject'         => [ $other, $restore, false, false, $plan->id ],
			'an undo'                 => [ $restore, $restore, true, false, $plan->id ],
			'the configuration'       => [ $restore, $restore, false, true, $plan->id ],
			'another staging'         => [ $restore, $other, false, false, $plan->id ],
			'a review that runs'      => [ $restore, $restore, false, false, $running->id ],
			'a review of no job'      => [ $restore, $restore, false, false, '20260101T000000Z-000000' ],
			'a job that is no review' => [ $restore, $restore, false, false, $restore ],
		];
		foreach ( $requests as $case => [ $id, $staging, $undo, $config, $plan_job ] ) {
			try {
				$plans->plan_page( $id, $staging, $undo, $config, $plan_job, 0 );
				self::fail( 'A page was served for ' . $case . '.' );
			} catch ( JobConflict $refused ) {
				self::assertSame( 'file_preview_expired', $refused->context()['code'] ?? null, $case );
			}
		}
		// A review whose result lost its digest is no review either.
		$saved = $this->site->saved( $plan );
		unset( $saved->result['digest'] );
		$this->site->jobs->save( $saved );
		try {
			$plans->plan_page( $restore, $restore, false, false, $plan->id, 0 );
			self::fail( 'A page of a review without a digest was served.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_preview_expired', $refused->context()['code'] ?? null );
		}
	}

	/**
	 * Fields of a sealed review that no longer name the scope of its job.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function foreign_headers(): array {
		return [
			'another subject'   => [ 'subject', '20260101T000000Z-000000' ],
			'another staging'   => [ 'restore', '20260101T000000Z-000000' ],
			'an undo'           => [ 'undo', true ],
			'the configuration' => [ 'config', true ],
		];
	}

	#[DataProvider( 'foreign_headers' )]
	public function test_a_page_is_served_only_while_the_sealed_review_names_the_scope_of_its_job( string $field, mixed $value ): void {
		$plan                    = $this->plan();
		$disk                    = new DiskFilePlan( dirname( $this->site->files ) . '/plans/' . $plan->id );
		$head                    = $disk->open( $plan->result['digest'] );
		$sealed                  = [
			'subject' => $head['subject'],
			'restore' => $head['restore'],
			'undo'    => $head['undo'],
			'config'  => $head['config'],
		];
		$sealed[ $field ]        = $value;
		$saved                   = $this->site->saved( $plan );
		$saved->result['digest'] = $disk->seal( $sealed['subject'], $sealed['restore'], $sealed['undo'], $sealed['config'], $head['counts'], $head['changes'], $head['refused'], $head['required_bytes'], $head['actions'] );
		$this->site->jobs->save( $saved );
		$this->expectException( JobConflict::class );
		$this->expectExceptionMessage( 'This file preview does not match the requested page.' );
		$this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
	}

	public function test_the_pages_of_a_review_end_with_its_last_entry(): void {
		$plan = $this->plan();
		$last = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id, $plan->result['entries'] );
		self::assertIsArray( $last );
		self::assertSame( [ [], null, $plan->result['entries'] ], [ $last['entries'], $last['next_offset'], $last['total'] ] );
		$this->expectException( JobConflict::class );
		$this->expectExceptionMessage( 'This file preview does not match the requested page.' );
		$this->site->changes->request_preview( $this->site->restore, false, false, $plan->id, $plan->result['entries'] + 1 );
	}

	public function test_a_background_review_without_entries_is_refused_as_one_without_changes(): void {
		$this->restore_result( 'files', 101 );
		file_put_contents( dirname( $this->site->files ) . '/entries.jsonl', '' );
		$plan = $this->site->run_job( $this->site->changes->request_preview( $this->site->restore, false, false ) );
		self::assertSame( [ 0, 0 ], [ $plan->result['entries'], $plan->result['changes'] ] );
		// Its page shows that it holds nothing, and every action with no row.
		$page = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		self::assertSame( [ [], false, array_fill_keys( [ 'create', 'replace', 'mkdir', 'keep', 'skip', 'refuse' ], 0 ) ], [ $page['entries'], $page['applicable'], $page['actions'] ] );
		try {
			$this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id );
			self::fail( 'A review without changes was confirmed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_changed_after_review', $refused->context()['code'] ?? null );
		}
	}

	public function test_a_page_of_a_review_whose_folder_is_gone_says_so_and_creates_none(): void {
		$plan   = $this->plan();
		$folder = dirname( $this->site->files ) . '/plans/' . $plan->id;
		TempDir::remove( $folder );
		try {
			$this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
			self::fail( 'A page of a review without its folder was served.' );
		} catch ( \BackupsForRestic\Exception\StorageFailed $refused ) {
			self::assertSame( 'The private file plan directory is unavailable.', $refused->getMessage() );
		}
		self::assertDirectoryDoesNotExist( $folder );
	}

	/** Stages one file of the given content for the given live path, and nothing else. */
	private function stage_only( string $path, string $content ): void {
		TempDir::remove( $this->site->files );
		mkdir( dirname( $this->site->files . $path ), 0700, true );
		file_put_contents( $this->site->files . $path, $content );
		$this->site->write_report( null );
	}

	/**
	 * The totals by action of the restore's inline review, checked against its rows.
	 *
	 * @return array<string, int>
	 */
	private function inline_totals(): array {
		$review = $this->site->changes->request_preview( $this->site->restore, false, false );
		self::assertIsArray( $review );
		$counted = array_count_values( array_column( $review['entries'], 'action' ) );
		foreach ( $review['actions'] ?? [] as $action => $count ) {
			self::assertSame( $counted[ $action ] ?? 0, $count, $action );
		}
		return $review['actions'] ?? [];
	}

	public function test_the_totals_of_an_inline_review_name_every_action_with_the_number_of_its_rows(): void {
		// The live root and the folders above it are kept rows of every review.
		$this->stage_only( $this->site->live . '/c-new.txt', 'created' );
		self::assertSame( [ 1, 0, 0, 0, 0 ], array_values( array_intersect_key( $this->inline_totals(), array_flip( [ 'create', 'replace', 'mkdir', 'skip', 'refuse' ] ) ) ) );
		$this->stage_only( $this->site->live . '/a-existing', 'replacement' );
		self::assertSame( [ 0, 1, 0, 0, 0 ], array_values( array_intersect_key( $this->inline_totals(), array_flip( [ 'create', 'replace', 'mkdir', 'skip', 'refuse' ] ) ) ) );
		// A staged file in place of a live folder is refused.
		mkdir( $this->site->live . '/conflict' );
		$this->stage_only( $this->site->live . '/conflict', 'a file' );
		self::assertSame( [ 0, 0, 0, 0, 1 ], array_values( array_intersect_key( $this->inline_totals(), array_flip( [ 'create', 'replace', 'mkdir', 'skip', 'refuse' ] ) ) ) );
	}

	/**
	 * Checkpoints of the space estimate of an apply that began before the estimate existed,
	 * which the apply did not write, by the field and the value.
	 *
	 * @return array<string, array{0: string, 1: int|string}>
	 */
	public static function damaged_estimates(): array {
		return [
			'a cursor that is no number'       => [ 'space_cursor', '1' ],
			'a cursor before the entries left' => [ 'space_cursor', 0 ],
			'a cursor beyond the entries'      => [ 'space_cursor', 4 ],
			'a total that is no number'        => [ 'space_total', '0' ],
			'a total below zero'               => [ 'space_total', -1 ],
		];
	}

	/** Runs the preflight and the first entry of an apply, and removes its space total. */
	private function apply_without_estimate(): Job {
		$apply = $this->site->start();
		$this->site->runner->tick( 0.0 ); // Preflight.
		$this->site->runner->tick( 0.0 ); // The first entry.
		$saved = $this->site->saved( $apply );
		self::assertSame( 1, $saved->progress['done'] );
		// As a version without the estimate left it.
		unset( $saved->data['remaining_bytes'] );
		$this->site->jobs->save( $saved );
		return $saved;
	}

	#[DataProvider( 'damaged_estimates' )]
	public function test_an_apply_refuses_a_damaged_checkpoint_of_its_space_estimate( string $field, int|string $value ): void {
		$apply                 = $this->apply_without_estimate();
		$apply->data[ $field ] = $value;
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'The remaining file estimate cursor is invalid.' ], [ $failed->error?->code, $failed->error?->message ] );
	}

	/** The space that the remaining entries of an apply's manifest take, from the given one on. */
	private function remainder( Job $apply, int $from ): int {
		$rows  = json_decode( (string) file_get_contents( $this->site->jobs->directory( $apply ) . '/undo/manifest.json' ), true, flags: JSON_THROW_ON_ERROR );
		$space = $this->site->space();
		$bytes = 0;
		foreach ( array_slice( $rows, $from ) as $row ) {
			$bytes += $space->row( ApplyManifest::checked( $row ) );
		}
		return $bytes;
	}

	public function test_the_space_estimate_of_an_apply_that_began_without_it_counts_each_remaining_entry_once(): void {
		$apply = $this->apply_without_estimate();
		// One entry of the estimate per tick that has used its budget, the last one with the entry.
		$this->site->runner->tick( 0.0 );
		$saved = $this->site->saved( $apply );
		self::assertSame( [ 2, $this->remainder( $apply, 1 ) - $this->remainder( $apply, 2 ), 1 ], [ $saved->data['space_cursor'], $saved->data['space_total'], $saved->progress['done'] ] );
		$this->site->runner->tick( 0.0 );
		$saved = $this->site->saved( $apply );
		self::assertSame( [ 2, $this->remainder( $apply, 2 ) ], [ $saved->progress['done'], $saved->data['remaining_bytes'] ] );
		self::assertArrayNotHasKey( 'space_cursor', $saved->data );
		$this->site->run_job( $apply );
	}

	public function test_the_space_estimate_of_an_apply_whose_last_entry_is_left_finishes_with_it_in_one_tick(): void {
		$apply = $this->site->start();
		for ( $tick = 0; $tick < 3; ++$tick ) {
			$this->site->runner->tick( 0.0 ); // Preflight and two entries.
		}
		$saved = $this->site->saved( $apply );
		self::assertSame( 2, $saved->progress['done'] );
		unset( $saved->data['remaining_bytes'] );
		$this->site->jobs->save( $saved );
		$this->site->runner->tick( 0.0 );
		self::assertSame( [ 3, 'recording' ], [ $this->site->saved( $apply )->progress['done'], $this->site->saved( $apply )->step ] );
		// A complete estimate in a checkpoint is taken as it is.
		$again = $this->site->undo( $this->site->run_job( $apply ) );
		self::assertSame( 'undone', $again->result['apply_state'] );
		$apply = $this->site->start();
		for ( $tick = 0; $tick < 3; ++$tick ) {
			$this->site->runner->tick( 0.0 );
		}
		$saved = $this->site->saved( $apply );
		unset( $saved->data['remaining_bytes'] );
		$saved->data['space_cursor'] = 3;
		$saved->data['space_total']  = $this->remainder( $apply, 2 );
		$this->site->jobs->save( $saved );
		self::assertSame( 3, $this->site->run_job( $apply )->result['files_changed'] );
	}

	public function test_a_background_review_whose_staging_passes_its_hour_while_the_review_runs_completes(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 ); // Preflight.
		$this->site->runner->tick( 0.0 ); // The first row.
		self::assertSame( 1, $this->site->saved( $queued )->progress['done'] );
		$this->site->clock->advance( 3601 );
		$plan = $this->site->run_job( $queued );
		self::assertSame( [ 3, $plan->result['entries'] ], [ $plan->result['changes'], $this->site->saved( $plan )->progress['done'] ] );
	}

	public function test_a_background_review_whose_report_is_gone_ends_with_the_code_of_gone_staging(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		unlink( dirname( $this->site->files ) . '/entries.jsonl' );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( [ 'file_staging_gone', 'The original staging report is no longer available.' ], [ $failed->error?->code, $failed->error?->message ] );
	}

	public function test_a_report_that_others_may_read_and_run_is_reviewed_as_any_other(): void {
		chmod( dirname( $this->site->files ) . '/entries.jsonl', 0755 );
		$plan = $this->plan();
		self::assertSame( 3, $plan->result['changes'] );
	}

	public function test_a_cancelled_background_review_leaves_neither_its_plan_nor_its_job_behind(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		$this->site->runner->cancel( $queued->id );
		$cancelled = $this->site->run_job( $queued, JobState::Cancelled );
		self::assertDirectoryDoesNotExist( dirname( $this->site->files ) . '/plans/' . $queued->id );
		self::assertDirectoryDoesNotExist( $this->site->jobs->directory( $cancelled ) . '/verification' );
		self::assertArrayNotHasKey( 'plan_retained', $cancelled->data );
		// Its job goes with the history like any other ended job, once a newer one is kept.
		$this->site->clock->advance( 10 );
		$this->site->jobs->create( JobType::Restore, 'manual', $this->site->clock->seconds );
		$this->site->jobs->prune( 1, $this->site->clock->seconds );
		self::assertNull( $this->site->jobs->get( $queued->id ) );
	}

	public function test_a_cancelled_background_review_removes_its_verification_data_also_when_its_first_cleanup_has_no_time(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		$verification = $this->site->jobs->directory( $queued ) . '/verification';
		self::assertDirectoryExists( $verification );
		file_put_contents( $verification . '/held', 'checkpoint' );
		$this->site->runner->cancel( $queued->id );
		// The cleanup tick has no time for the removal: the review goes on cleaning up.
		$frozen                     = $this->site->clock->seconds;
		$this->site->clock->seconds = (int) microtime( true ) + 60;
		$this->site->runner->tick( 0.0 );
		$this->site->clock->seconds = $frozen;
		self::assertSame( [ 'cleaning_up', JobState::Running ], [ $this->site->saved( $queued )->step, $this->site->saved( $queued )->state ] );
		$this->site->run_job( $queued, JobState::Cancelled );
		self::assertDirectoryDoesNotExist( $verification );
		self::assertDirectoryDoesNotExist( dirname( $this->site->files ) . '/plans/' . $queued->id );
	}

	public function test_a_review_counts_its_rows_while_it_runs_and_in_its_result(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		self::assertSame( 1, $this->site->saved( $queued )->progress['done'] );
		$plan = $this->site->run_job( $queued );
		$page = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		self::assertSame( [ $page['total'], $page['total'] ], [ $plan->result['entries'], $plan->progress['done'] ] );
		self::assertGreaterThan( 100, $page['total'] );
	}

	public function test_a_review_built_in_one_tick_estimates_the_space_of_every_row(): void {
		$review = $this->site->changes->preview( $this->site->restore );
		$plan   = $this->plan();
		$page   = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		self::assertSame( $review['required_bytes'], $page['required_bytes'] );
	}

	public function test_an_undo_review_that_began_within_the_window_of_its_apply_completes_after_it(): void {
		$plan                       = $this->plan();
		$apply                      = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		$this->site->clock->seconds = $apply->finished_at + 86399;
		$queued                     = $this->site->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $queued );
		$this->site->runner->tick( 0.0 ); // Preflight.
		$this->site->clock->advance( 2 );
		$review = $this->site->run_job( $queued );
		self::assertSame( [ 1, 2, 0 ], [ $review->result['restore'], $review->result['remove'], $review->result['keep'] ] );
		// The window has ended: the review cannot be confirmed any more.
		try {
			$this->site->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
			self::fail( 'An undo was queued after the window of its apply.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'undo_unavailable', $refused->context()['code'] ?? null );
		}
	}

	public function test_jobs_with_a_step_of_another_version_fail_with_their_own_message(): void {
		$queued        = $this->site->queue_large_preview();
		$queued->step  = 'indexing';
		$queued->state = JobState::Running;
		$this->site->jobs->save( $queued );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( [ 'file_preview_failed', 'Unknown background file preview step.' ], [ $failed->error?->code, $failed->error?->message ] );
		$apply        = $this->site->start();
		$apply->step  = 'staging';
		$apply->state = JobState::Running;
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'Unknown live file change step.' ], [ $failed->error?->code, $failed->error?->message ] );
	}
}
