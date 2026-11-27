<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Apply\ApplyJournal;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Tests\Support\ApplySite;
use PHPUnit\Framework\TestCase;

/**
 * The records of an apply that decide its undo: the count of attempted entries, and the
 * private folders that hold the journal.
 */
final class ApplyJournalTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'apply-journal' );
	}

	protected function tearDown(): void {
		$this->site->remove();
		unset( $this->site );
	}

	private function journal(): ApplyJournal {
		return new ApplyJournal( $this->site->jobs, $this->site->staging, $this->site->clock, 1 );
	}

	public function test_the_count_of_attempted_entries_is_read_up_to_the_limit_of_a_plan_and_refused_beyond(): void {
		$apply   = $this->site->run_job( $this->site->start() );
		$journal = $this->journal();
		$count   = $journal->workspace( $apply ) . '/attempted';
		self::assertSame( 3, $journal->attempted( $apply ) );
		// A background plan holds up to a million entries: an apply of all of them stays undoable.
		file_put_contents( $count, '1000000' );
		self::assertSame( 1000000, $journal->attempted( $apply ) );
		file_put_contents( $count, '7' );
		self::assertSame( 7, $journal->attempted( $apply ) );
		// No count is read from what the apply cannot have written.
		foreach ( [ '1000001', '9999999', '12345678', '0', '01', '-1', '+1', ' 1', '1 ', "1\n", '1.0', 'x', '' ] as $damaged ) {
			file_put_contents( $count, $damaged );
			try {
				$journal->attempted( $apply );
				self::fail( 'A damaged count was read: ' . json_encode( $damaged ) );
			} catch ( StorageFailed $refused ) {
				self::assertSame( 'The undo cursor is damaged.', $refused->getMessage(), json_encode( $damaged ) );
			}
		}
		// Only a count that is established missing means that nothing was attempted.
		unlink( $count );
		self::assertSame( 0, $journal->attempted( $apply ) );
	}

	public function test_the_journal_and_its_items_are_folders_of_the_owner_alone(): void {
		$umask = umask( 0022 );
		try {
			$plan  = $this->site->run_job( $this->site->queue_large_preview() );
			$apply = $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id );
			$this->site->runner->tick( 0.0 ); // The preflight checks the sealed plan in a verification folder.
			$verification = $this->site->jobs->directory( $apply ) . '/verification';
			self::assertSame( 0700, fileperms( $verification ) & 0777 );
			$apply  = $this->site->run_job( $apply );
			$folder = $this->site->jobs->directory( $apply ) . '/undo';
			// The journal holds the only copies of the files the apply replaced.
			self::assertSame( 0700, fileperms( $folder ) & 0777 );
			$items = glob( $folder . '/item-*', GLOB_ONLYDIR );
			self::assertCount( 3, $items );
			foreach ( $items as $item ) {
				self::assertSame( 0700, fileperms( $item ) & 0777, $item );
			}
			$plans = dirname( $this->site->files ) . '/plans';
			self::assertSame( 0700, fileperms( $plans ) & 0777 );
			self::assertSame( 0700, fileperms( $plans . '/' . $plan->id ) & 0777 );
			// An undo that keeps an entry lists it in a folder of its own.
			file_put_contents( $this->site->live . '/a-existing', 'edited' );
			$review = $this->site->run_job( $this->site->changes->request_preview( $apply->id, true, false ) );
			$undo   = $this->site->run_job( $this->site->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id ) );
			self::assertSame( 1, $undo->result['kept'] );
			self::assertSame( 0700, fileperms( $this->site->jobs->directory( $undo ) . '/kept' ) & 0777 );
		} finally {
			umask( $umask );
		}
	}

	public function test_an_apply_refuses_a_link_in_place_of_its_journal_before_it_changes_a_file(): void {
		$apply = $this->site->start();
		// Something else puts a link at the name of the journal before the worker comes.
		$elsewhere = $this->site->directory . '/elsewhere';
		mkdir( $elsewhere, 0700 );
		symlink( $elsewhere, $this->site->jobs->directory( $apply ) . '/undo' );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'The apply journal directory is unavailable.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		// Nothing of the site went into the folder behind the link, and nothing changed.
		self::assertSame( [ '.', '..' ], scandir( $elsewhere ) );
		self::assertSame( 'original', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->site->live . '/b-new' );
	}
}
