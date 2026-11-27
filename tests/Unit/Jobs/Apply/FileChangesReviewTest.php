<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Tests\Support\ApplySite;
use BackupsForRestic\Tests\Support\SteppingClock;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The reviews of the apply jobs as an administrator requests them: which requests are
 * answered and how, a review that offers no change, and a review that finds nothing of an
 * apply left to revert and records the apply as undone.
 */
final class FileChangesReviewTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'apply-reviews' );
	}

	protected function tearDown(): void {
		$this->site->remove();
	}

	/** Makes a restore one whose review is a job. */
	private function large( string $restore ): void {
		$job                  = $this->site->jobs->get( $restore ) ?? self::fail( 'The restore is gone.' );
		$job->result['files'] = DiskFilePlan::PAGE + 1;
		$this->site->jobs->save( $job );
	}

	/**
	 * Applies a restore of the given files, reviewed inline or in the background.
	 *
	 * @param array<string, string> $contents Staged contents by live path.
	 */
	private function applied( array $contents, bool $background ): Job {
		$restore = $this->site->second_restore( $contents );
		if ( ! $background ) {
			return $this->site->run_job( $this->site->changes->start_apply( $restore, false, $this->site->changes->preview( $restore )['digest'] ) );
		}
		$this->large( $restore );
		$plan = $this->site->run_job( $this->site->changes->request_preview( $restore, false, false ) );
		return $this->site->run_job( $this->site->changes->start_apply( $restore, false, $plan->result['digest'], '', $plan->id ) );
	}

	/** The background undo review of an apply, run to its end. */
	private function undo_review( Job $apply ): Job {
		$review = $this->site->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		return $this->site->run_job( $review );
	}

	/** Whether an apply is recorded as undone, with its marker. */
	private function undone( Job $apply ): bool {
		$saved = $this->site->saved( $apply );
		self::assertSame( 'undone' === ( $saved->result['undo_state'] ?? null ), is_file( $this->site->jobs->directory( $apply ) . '/undo/reverted' ) );
		return 'undone' === ( $saved->result['undo_state'] ?? null );
	}

	public function test_a_staging_kept_only_for_the_undo_of_an_apply_is_not_offered_for_another_apply(): void {
		$this->site->run_job( $this->site->start() );
		// The staging's own hour is over; the apply's window keeps its files.
		$this->site->clock->advance( 3601 );
		self::assertDirectoryExists( $this->site->files );
		foreach ( [
			'review'  => fn () => $this->site->changes->preview( $this->site->restore ),
			'request' => fn () => $this->site->changes->request_preview( $this->site->restore, false, false ),
		] as $what => $request ) {
			try {
				$request();
				self::fail( 'A ' . $what . ' of a staging kept for an undo was answered.' );
			} catch ( InvalidArgument $refused ) {
				self::assertSame( 'file_staging_gone', $refused->context()['code'] ?? null, $what );
			}
		}
	}

	public function test_a_request_for_a_review_of_an_invalid_scope_or_page_is_refused(): void {
		$plan = $this->site->run_job( $this->site->queue_large_preview() );
		foreach ( [
			'a page before the first'        => [ $this->site->restore, false, false, $plan->id, -1 ],
			'a page of no completed review'  => [ $this->site->restore, false, false, null, 1 ],
			'an undo with the configuration' => [ $this->site->restore, true, true, null, 0 ],
		] as $case => [ $id, $undo, $config, $plan_job, $offset ] ) {
			try {
				$this->site->changes->request_preview( $id, $undo, $config, $plan_job, $offset );
				self::fail( 'A request for ' . $case . ' was answered.' );
			} catch ( InvalidArgument $refused ) {
				self::assertSame( 'The file preview request has an invalid scope or page.', $refused->getMessage(), $case );
			}
		}
		// An apply with the configuration has a review.
		$second = $this->site->second_restore( [ $this->site->live . '/wp-config.php' => 'restored configuration' ] );
		$review = $this->site->changes->request_preview( $second, false, true );
		self::assertIsArray( $review );
		self::assertSame( 'create', array_column( $review['entries'], 'action', 'path' )[ $this->site->live . '/wp-config.php' ] );
	}

	public function test_a_review_of_a_fresh_site_with_the_configuration_is_refused_and_not_queued(): void {
		$this->site->stage_fresh_uploads();
		try {
			$this->site->changes->request_preview( $this->site->restore, false, true );
			self::fail( 'A review of a fresh site with the configuration was answered.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( 'A fresh-site import preserves the current installation configuration.', $refused->getMessage() );
		}
		self::assertNull( $this->site->jobs->active() );
	}

	public function test_a_review_requested_while_another_review_runs_is_refused_and_not_answered_with_that_review(): void {
		$other = $this->site->second_restore( [ $this->site->live . '/other.txt' => 'other' ] );
		$this->large( $other );
		$first = $this->site->queue_large_preview();
		self::assertSame( $this->site->clock->seconds, $first->created_at );
		try {
			$this->site->changes->request_preview( $other, false, false );
			self::fail( 'A review of another restore was answered while one ran.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( [ 'Another job is still running.', $first->id ], [ $refused->getMessage(), $refused->context()['job'] ?? null ] );
		}
		// A request of the same scope follows the review that runs.
		$again = $this->site->changes->request_preview( $this->site->restore, false, false );
		self::assertInstanceOf( Job::class, $again );
		self::assertSame( $first->id, $again->id );
	}

	public function test_a_review_of_a_restore_whose_staged_files_are_gone_queues_no_job(): void {
		$this->large( $this->site->restore );
		TempDir::remove( $this->site->files );
		try {
			$this->site->changes->request_preview( $this->site->restore, false, false );
			self::fail( 'A review of a restore without its staged files was queued.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( 'file_staging_gone', $refused->context()['code'] ?? null );
		}
		self::assertNull( $this->site->jobs->active() );
	}

	public function test_a_review_that_offers_no_change_cannot_be_confirmed(): void {
		// The restore of a file as the site has it.
		$same   = $this->site->second_restore( [ $this->site->live . '/a-existing' => 'original' ] );
		$review = $this->site->changes->preview( $same );
		self::assertSame( [ 'keep', 'identical_contents' ], [ array_column( $review['entries'], 'action', 'path' )[ $this->site->live . '/a-existing' ], array_column( $review['entries'], 'reason', 'path' )[ $this->site->live . '/a-existing' ] ] );
		self::assertFalse( $review['applicable'] );
		try {
			$this->site->changes->start_apply( $same, false, $review['digest'] );
			self::fail( 'A review without a change was confirmed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_changed_after_review', $refused->context()['code'] ?? null );
		}
	}

	public function test_an_inline_undo_review_of_an_apply_reviewed_in_the_background_is_refused(): void {
		$apply = $this->applied( [ $this->site->live . '/c1.txt' => 'one' ], true );
		$this->expectException( JobConflict::class );
		$this->expectExceptionMessage( 'This apply requires a completed background undo preview.' );
		$this->site->changes->preview_undo( $apply->id );
	}

	public function test_a_background_review_that_offered_reverts_does_not_retire_an_apply_whose_entries_are_gone_by_its_first_page(): void {
		$apply  = $this->applied(
			[
				$this->site->live . '/c1.txt' => 'one',
				$this->site->live . '/c2.txt' => 'two',
			],
			true
		);
		$review = $this->undo_review( $apply );
		self::assertSame( [ 0, 2, 0 ], [ $review->result['restore'], $review->result['remove'], $review->result['keep'] ] );
		// Someone removes both files after the review, before its first page is read.
		unlink( $this->site->live . '/c1.txt' );
		unlink( $this->site->live . '/c2.txt' );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		// The page offers what the review found; the next review settles the apply.
		self::assertSame( [ true, false ], [ $page['applicable'], isset( $page['undone'] ) ] );
		self::assertFalse( $this->undone( $apply ) );
		$again = $this->site->changes->request_preview( $apply->id, true, false, $this->undo_review( $apply )->id );
		self::assertIsArray( $again );
		self::assertTrue( $again['undone'] ?? null );
		self::assertTrue( $this->undone( $apply ) );
	}

	public function test_an_earlier_review_that_offered_a_restore_and_a_removal_does_not_retire_an_apply_that_another_undo_reverted_since(): void {
		// A replaced file and a created one, applied after a background review.
		$apply = $this->applied(
			[
				$this->site->live . '/a-existing' => 'replacement',
				$this->site->live . '/c1.txt'     => 'one',
			],
			true
		);
		$first = $this->undo_review( $apply );
		self::assertSame( [ 1, 1, 0 ], [ $first->result['restore'], $first->result['remove'], $first->result['keep'] ] );
		// The created file is edited: a second undo restores the replaced file and keeps it.
		file_put_contents( $this->site->live . '/c1.txt', 'edited' );
		$second = $this->undo_review( $apply );
		$undo   = $this->site->run_job( $this->site->changes->start_undo( $apply->id, $second->result['digest'], '', $second->id ) );
		self::assertSame( [ 1, 1, 'partly_undone' ], [ $undo->result['restored'], $undo->result['kept'], $undo->result['apply_state'] ] );
		// Then it is removed, and nothing of the apply is left to revert. The first review's page
		// shows what that review found, and does not retire the apply.
		unlink( $this->site->live . '/c1.txt' );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $first->id );
		self::assertIsArray( $page );
		self::assertSame( [ 1, 1 ], [ $page['totals']['restore'] ?? null, $page['totals']['remove'] ?? null ] );
		self::assertArrayNotHasKey( 'undone', $page );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_background_review_whose_count_of_settled_entries_is_damaged_does_not_retire_an_apply(): void {
		$apply = $this->applied(
			[
				$this->site->live . '/c1.txt' => 'one',
				$this->site->live . '/c2.txt' => 'two',
			],
			true
		);
		unlink( $this->site->live . '/c1.txt' );
		unlink( $this->site->live . '/c2.txt' );
		$review                    = $this->undo_review( $apply );
		$review->result['settled'] = '2';
		$this->site->jobs->save( $review );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'undone', $page );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_background_review_sealed_without_totals_does_not_retire_an_apply(): void {
		$apply = $this->applied(
			[
				$this->site->live . '/c1.txt' => 'one',
				$this->site->live . '/c2.txt' => 'two',
			],
			true
		);
		unlink( $this->site->live . '/c1.txt' );
		unlink( $this->site->live . '/c2.txt' );
		$review = $this->undo_review( $apply );
		self::assertSame( [ 2, 2 ], [ $review->result['keep'], $review->result['settled'] ] );
		// Sealed as a version before the totals sealed it.
		$disk                     = new DiskFilePlan( dirname( $this->site->directory . '/restores/' . $apply->data['restore'] . '/files' ) . '/plans/' . $review->id );
		$head                     = $disk->open( $review->result['digest'] );
		$review->result['digest'] = $disk->seal( $head['subject'], $head['restore'], true, false, $head['counts'], $head['changes'], $head['refused'], $head['required_bytes'] );
		$this->site->jobs->save( $review );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertSame( [ null, false ], [ $page['totals'], isset( $page['undone'] ) ] );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_review_after_the_hour_of_the_staging_retires_an_apply_with_nothing_left_to_revert(): void {
		$apply = $this->applied( [ $this->site->live . '/c1.txt' => 'one' ], false );
		unlink( $this->site->live . '/c1.txt' );
		$this->site->clock->advance( 3601 );
		self::assertTrue( $this->site->changes->preview_undo( $apply->id )['undone'] ?? null );
		self::assertTrue( $this->undone( $apply ) );
	}

	public function test_a_review_retires_a_fresh_site_apply_with_nothing_left_to_revert(): void {
		$this->site->stage_fresh_uploads();
		$apply = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $this->site->changes->preview( $this->site->restore )['digest'] ) );
		TempDir::remove( $this->site->uploads . '/album' );
		self::assertTrue( $this->site->changes->preview_undo( $apply->id )['undone'] ?? null );
		self::assertTrue( $this->undone( $apply ) );
	}

	public function test_a_background_review_does_not_retire_an_apply_whose_entries_the_site_maps_elsewhere_by_its_first_page(): void {
		$this->site->stage_fresh_uploads();
		$this->large( $this->site->restore );
		$plan  = $this->site->run_job( $this->site->changes->request_preview( $this->site->restore, false, false ) );
		$apply = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		TempDir::remove( $this->site->uploads . '/album' );
		$review = $this->undo_review( $apply );
		self::assertSame( [ 2, 2 ], [ $review->result['keep'], $review->result['settled'] ] );
		// The uploads root changes before the first page is read.
		$this->site->uploads = $this->site->live . '/elsewhere';
		mkdir( $this->site->uploads );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'undone', $page );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_review_whose_last_check_ends_exactly_at_the_end_of_its_budget_does_not_retire_the_apply(): void {
		$apply = $this->applied( [ $this->site->live . '/c1.txt' => 'one' ], false );
		unlink( $this->site->live . '/c1.txt' );
		// Each reading of this clock is a second after the one before: the budget of two
		// seconds ends with the reading after the check of the entry.
		$review = $this->site->service( new SteppingClock( $this->site->clock->seconds, 1.0 ) )->preview_undo( $apply->id );
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_review_whose_budget_ends_before_its_last_entry_does_not_retire_the_apply(): void {
		$apply = $this->applied(
			[
				$this->site->live . '/c1.txt' => 'one',
				$this->site->live . '/c2.txt' => 'two',
			],
			false
		);
		unlink( $this->site->live . '/c1.txt' );
		unlink( $this->site->live . '/c2.txt' );
		// The first entry is checked a second and a half after the budget began, the second after three.
		$review = $this->site->service( new SteppingClock( $this->site->clock->seconds, 1.5 ) )->preview_undo( $apply->id );
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFalse( $this->undone( $apply ) );
		self::assertTrue( $this->site->changes->preview_undo( $apply->id )['undone'] ?? null );
	}

	public function test_the_budget_of_a_review_counts_the_fractions_of_its_seconds(): void {
		$apply = $this->applied( [ $this->site->live . '/c1.txt' => 'one' ], false );
		unlink( $this->site->live . '/c1.txt' );
		// The review reads the clock twice for the window of the apply, then when its budget of
		// two seconds begins, before the check of the entry, and after it. Readings half a second
		// apart that begin on a whole second end the check within the budget.
		$review = $this->site->service( new SteppingClock( $this->site->clock->seconds - 1.5, 0.5 ) )->preview_undo( $apply->id );
		self::assertTrue( $review['undone'] ?? null );
		self::assertTrue( $this->undone( $apply ) );
		// Readings a second and a quarter apart end it half a second after the budget.
		$apply = $this->applied( [ $this->site->live . '/c2.txt' => 'two' ], false );
		unlink( $this->site->live . '/c2.txt' );
		$review = $this->site->service( new SteppingClock( $this->site->clock->seconds - 3.75, 1.25 ) )->preview_undo( $apply->id );
		self::assertArrayNotHasKey( 'undone', $review );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_background_review_does_not_retire_an_apply_whose_sealed_plan_cannot_be_read_any_more(): void {
		$apply = $this->applied(
			[
				$this->site->live . '/c1.txt' => 'one',
				$this->site->live . '/c2.txt' => 'two',
			],
			true
		);
		unlink( $this->site->live . '/c1.txt' );
		unlink( $this->site->live . '/c2.txt' );
		$review = $this->undo_review( $apply );
		self::assertSame( 2, $review->result['settled'] );
		$sealed = $this->site->directory . '/restores/' . $apply->data['restore'] . '/plans/' . $apply->data['plan_job'] . '/sealed.json';
		file_put_contents( $sealed, file_get_contents( $sealed ) . ' ' );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'undone', $page );
		self::assertFalse( $this->undone( $apply ) );
	}

	public function test_a_background_review_does_not_retire_an_apply_whose_large_file_came_back_before_its_first_page(): void {
		$big   = $this->site->live . '/big.bin';
		$apply = $this->applied(
			[
				$big                          => str_repeat( 'b', 2 * 1048576 + 1 ),
				$this->site->live . '/c1.txt' => 'one',
			],
			true
		);
		rename( $big, $this->site->directory . '/aside' );
		unlink( $this->site->live . '/c1.txt' );
		$review = $this->undo_review( $apply );
		self::assertSame( [ 2, 2 ], [ $review->result['keep'], $review->result['settled'] ] );
		// The file is back before the first page is read: its check takes more than this request.
		rename( $this->site->directory . '/aside', $big );
		$page = $this->site->changes->request_preview( $apply->id, true, false, $review->id );
		self::assertIsArray( $page );
		self::assertArrayNotHasKey( 'undone', $page );
		self::assertFalse( $this->undone( $apply ) );
	}
}
