<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Tests\Support\ApplySite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an undo writes to the live site and records: its last step, the cleanup of an undo
 * that stopped, entries that change between the review and the worker, and the records of
 * the apply that it trusts.
 */
final class FileChangesUndoTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'undo-changes' );
	}

	protected function tearDown(): void {
		$this->site->remove();
	}

	/**
	 * Confirms the inline undo of an apply, runs its preflight and then one entry per tick.
	 *
	 * @return array{0: Job, 1: Job} The apply and the undo as its document stands.
	 */
	private function undo_after( int $entries, ?Job $apply = null ): array {
		$apply ??= $this->site->run_job( $this->site->start() );
		$undo    = $this->site->changes->start_undo( $apply->id, $this->site->changes->preview_undo( $apply->id )['digest'] );
		$this->site->runner->tick( 0.0 ); // Preflight.
		for ( $entry = 0; $entry < $entries; ++$entry ) {
			$this->site->runner->tick( 0.0 );
		}
		return [ $apply, $this->site->saved( $undo ) ];
	}

	/**
	 * Puts the document of an undo back as a worker that was killed after it reverted an entry,
	 * before the document counted it, leaves it: the step that reverts, without a count.
	 */
	private function uncounted( Job $undo ): Job {
		$undo->step = 'undoing';
		unset( $undo->progress['done'], $undo->data['undo_counts'], $undo->data['kept_page_digest'] );
		$this->site->jobs->save( $undo );
		return $undo;
	}

	/** Cancels an undo and runs its cleanup. */
	private function cancelled( Job $undo ): Job {
		$this->site->runner->cancel( $undo->id );
		return $this->site->run_job( $undo, JobState::Cancelled );
	}

	/** The entries that an undo counted as reverted. */
	private static function reverted( Job $undo ): ?int {
		return isset( $undo->result['restored'], $undo->result['removed'] ) ? $undo->result['restored'] + $undo->result['removed'] : null;
	}

	/** The warnings in a job's log. */
	private function warnings( Job $job ): array {
		return array_column( $this->site->log_lines( $job, 'warning' ), 'message' );
	}

	public function test_a_finished_undo_whose_apply_cannot_be_marked_as_undone_fails_and_leaves_the_apply_open(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse the marker.' );
		}
		[ $apply, $undo ] = $this->undo_after( 3 );
		self::assertSame( [ 3, 'recording' ], [ $undo->progress['done'], $undo->step ] );
		$workspace = $this->site->jobs->directory( $apply ) . '/undo';
		chmod( $workspace, 0500 );
		try {
			if ( is_writable( $workspace ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$ended = $this->site->run_job( $undo, JobState::Failed );
		} finally {
			chmod( $workspace, 0700 );
		}
		// The record of the finished undo fails, and the cleanup records what it reverted.
		self::assertSame( [ 'file_undo_failed', 'recording' ], [ $ended->error?->code, $this->site->failed_step( $ended ) ] );
		self::assertSame( [ 3, 0, 'partly_undone' ], [ self::reverted( $ended ), $ended->result['kept'], $ended->result['apply_state'] ] );
		self::assertSame( [ 'The undo counted every entry, but its apply could not be marked as undone; the apply stays open for a later undo.' ], $this->warnings( $ended ) );
		self::assertFileDoesNotExist( $workspace . '/reverted' );
		self::assertSame( [ [ $apply->id, 'partly_undone', null ] ], array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'], $listed['kept'] ], $this->site->changes->undoable() ) );
	}

	public function test_an_undo_whose_apply_is_gone_before_its_record_fails_with_its_own_message(): void {
		[ $apply, $undo ] = $this->undo_after( 3 );
		self::assertSame( 'recording', $undo->step );
		unlink( $this->site->jobs->directory( $apply ) . '/job.json' );
		$failed = $this->site->run_job( $undo, JobState::Failed );
		self::assertSame( [ 'file_undo_failed', 'The original apply is missing.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'recording', $this->site->failed_step( $failed ) );
	}

	public function test_a_stopped_undo_counts_its_first_entry_when_a_record_proves_that_the_worker_reverted_it(): void {
		[ $apply, $undo ] = $this->undo_after( 1 );
		self::assertSame( 1, $undo->progress['done'] );
		$cancelled = $this->cancelled( $this->uncounted( $undo ) );
		// Before the document counted anything, the cleanup reads the entry at its cursor.
		self::assertSame( [ 1, 1, 'partly_undone' ], [ self::reverted( $cancelled ), $cancelled->progress['done'], $cancelled->result['apply_state'] ] );
		self::assertSame( [], $this->warnings( $cancelled ) );
		self::assertSame( 'partly_undone', $this->site->saved( $apply )->result['undo_state'] ?? null );
	}

	/**
	 * Documents of a stopped undo whose cursor or total this version does not write: what
	 * becomes of the cursor or the total after two counted entries.
	 *
	 * @return array<string, array{0: string, 1: int|string}>
	 */
	public static function damaged_cursors(): array {
		return [
			'a cursor that is no number' => [ 'done', '1' ],
			'a cursor below zero'        => [ 'done', -1 ],
			'a total that is no number'  => [ 'total', '3' ],
		];
	}

	#[DataProvider( 'damaged_cursors' )]
	public function test_a_stopped_undo_whose_cursor_or_total_is_damaged_counts_only_what_its_document_established( string $field, int|string $value ): void {
		[ $apply, $undo ] = $this->undo_after( 2 );
		self::assertSame( 2, $undo->progress['done'] );
		$undo->progress[ $field ] = $value;
		$this->site->jobs->save( $undo );
		$cancelled = $this->cancelled( $undo );
		// The two counted reverts are recorded, nothing is counted twice, and nothing is checked
		// that the document cannot place.
		self::assertNull( $cancelled->error );
		self::assertSame( [ 2, 'partly_undone' ], [ self::reverted( $cancelled ), $cancelled->result['apply_state'] ?? null ] );
		self::assertSame( [], $this->warnings( $cancelled ) );
		self::assertSame( [ 'partly_undone', null ], [ $this->site->saved( $apply )->result['undo_state'] ?? null, $this->site->saved( $apply )->result['undo_kept'] ?? null ] );
	}

	public function test_a_stopped_undo_counts_what_it_reverted_also_after_the_window_and_the_hour_of_its_staging(): void {
		[ , $undo ] = $this->undo_after( 1 );
		$this->uncounted( $undo );
		// The undo began within the window; its cleanup runs after the window and the staging's own hour.
		$this->site->clock->advance( 86401 );
		$cancelled = $this->cancelled( $undo );
		self::assertSame( [ 1, 'partly_undone' ], [ self::reverted( $cancelled ), $cancelled->result['apply_state'] ] );
		self::assertSame( [], $this->warnings( $cancelled ) );
	}

	public function test_a_stopped_undo_of_a_fresh_site_apply_counts_what_its_record_proves(): void {
		$this->site->stage_fresh_uploads();
		$apply = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $this->site->changes->preview( $this->site->restore )['digest'] ) );
		self::assertSame( 'imported upload', file_get_contents( $this->site->uploads . '/album/photo.txt' ) );
		[ , $undo ] = $this->undo_after( 1, $apply );
		$cancelled  = $this->cancelled( $this->uncounted( $undo ) );
		self::assertSame( [ 1, 'partly_undone' ], [ self::reverted( $cancelled ), $cancelled->result['apply_state'] ] );
		self::assertSame( [], $this->warnings( $cancelled ) );
		self::assertFileDoesNotExist( $this->site->uploads . '/album/photo.txt' );
	}

	public function test_a_stopped_undo_whose_total_is_not_the_count_of_its_apply_checks_no_entry(): void {
		[ , $undo ] = $this->undo_after( 1 );
		$this->uncounted( $undo );
		$undo->progress['total'] = 2;
		$this->site->jobs->save( $undo );
		$cancelled = $this->cancelled( $undo );
		self::assertSame( [ 'The entry at which the undo stopped could not be checked; it is not counted.' ], $this->warnings( $cancelled ) );
		self::assertSame( [], $cancelled->result );
	}

	public function test_entries_that_the_site_maps_elsewhere_by_the_time_the_worker_comes_are_kept(): void {
		$this->site->stage_fresh_uploads();
		$album = '/archived-media/album';
		file_put_contents( $this->site->files . $album . '/other.txt', 'another upload' );
		$this->site->write_report( '/old/site-content/link' );
		$apply = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $this->site->changes->preview( $this->site->restore )['digest'] ) );
		// One of the uploads is gone again: nothing of it is left to revert.
		unlink( $this->site->uploads . '/album/other.txt' );
		$review = $this->site->changes->preview_undo( $apply->id );
		self::assertSame(
			[
				'restore' => 0,
				'remove'  => 2,
				'keep'    => 1,
			],
			$review['totals']
		);
		$undo = $this->site->changes->start_undo( $apply->id, $review['digest'] );
		// Queued as an earlier version queued it, without the uploads root of its review; the
		// site's root changes before the worker comes.
		$undo->data['uploads_dir'] = null;
		$this->site->jobs->save( $undo );
		$previous            = $this->site->uploads;
		$this->site->uploads = $this->site->live . '/elsewhere';
		mkdir( $this->site->uploads );
		$done = $this->site->run_job( $undo );
		self::assertSame(
			[
				'restored'             => 0,
				'removed'              => 0,
				'unchanged'            => 0,
				'kept'                 => 3,
				'changed_after_review' => 3,
				'apply_state'          => 'partly_undone',
			],
			ApplySite::outcomes( $done )
		);
		self::assertSame( [ 'destination_changed' ], array_values( array_unique( $this->site->kept( $done ) ) ) );
		self::assertSame( 'imported upload', file_get_contents( $previous . '/album/photo.txt' ) );
	}

	public function test_a_created_file_that_was_moved_aside_and_back_before_the_worker_is_kept_and_stays_undoable(): void {
		// A file that the apply creates in a folder that the site has already.
		$file = $this->site->live . '/c-new.txt';
		file_put_contents( $this->site->files . $file, 'created' );
		$this->site->write_report( $this->site->live . '/skipped-link' );
		$apply = $this->site->run_job( $this->site->start() );
		// A deploy tool moves it aside: nothing of it is left to revert at the review.
		rename( $file, $this->site->directory . '/aside' );
		$review = $this->site->changes->preview_undo( $apply->id );
		self::assertSame( [ 'keep', 'removed_since_apply' ], [ array_column( $review['entries'], 'action', 'path' )[ $file ], array_column( $review['entries'], 'reason', 'path' )[ $file ] ] );
		$undo = $this->site->changes->start_undo( $apply->id, $review['digest'] );
		// And back before the worker comes: the very inode that the apply published.
		rename( $this->site->directory . '/aside', $file );
		$done = $this->site->run_job( $undo );
		self::assertSame(
			[
				'restored'             => 1,
				'removed'              => 2,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 1,
				'apply_state'          => 'partly_undone',
			],
			ApplySite::outcomes( $done )
		);
		self::assertSame( [ $file => 'changed_since_apply' ], $this->site->kept( $done ) );
		self::assertSame( 'created', file_get_contents( $file ) );
		self::assertFileDoesNotExist( $this->site->jobs->directory( $apply ) . '/undo/reverted' );
		self::assertSame( [ 'remove', 'undo_journal' ], [ array_column( $this->site->changes->preview_undo( $apply->id )['entries'], 'action', 'path' )[ $file ], array_column( $this->site->changes->preview_undo( $apply->id )['entries'], 'reason', 'path' )[ $file ] ] );
	}

	public function test_a_background_undo_whose_sealed_review_lacks_a_total_is_reviewed_again(): void {
		$plan   = $this->site->run_job( $this->site->queue_large_preview() );
		$apply  = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->site->run_job( $this->site->changes->request_preview( $apply->id, true, false ) );
		$undo   = $this->site->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
		// The review is sealed again with totals that lack the kept entries.
		$disk    = new DiskFilePlan( dirname( $this->site->files ) . '/plans/' . $review->id );
		$header  = $disk->open( $review->result['digest'] );
		$actions = $header['actions'] ?? [];
		unset( $actions['keep'] );
		$undo->data['digest'] = $disk->seal( $header['subject'], $header['restore'], true, false, $header['counts'], $header['changes'], $header['refused'], $header['required_bytes'], $actions );
		$this->site->jobs->save( $undo );
		$failed = $this->site->run_job( $undo, JobState::Failed );
		self::assertSame( [ 'undo_review_required', 'preflight' ], [ $failed->error?->code, $this->site->failed_step( $failed ) ] );
		self::assertSame( 'replacement', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertFileExists( $this->site->live . '/b-new/new.txt' );
	}

	public function test_an_undo_whose_count_of_attempted_entries_exceeds_the_manifest_of_its_apply_reverts_nothing(): void {
		$apply = $this->site->run_job( $this->site->start() );
		$undo  = $this->site->changes->start_undo( $apply->id, $this->site->changes->preview_undo( $apply->id )['digest'] );
		// The count is damaged afterwards, and the stored review covers what it names.
		file_put_contents( $this->site->jobs->directory( $apply ) . '/undo/attempted', '5' );
		$undo->data['reviewed'] = array_fill( 0, 5, [ 'remove', 'undo_journal' ] );
		$this->site->jobs->save( $undo );
		try {
			$this->site->changes->preview_undo( $apply->id );
			self::fail( 'A review of a journal that exceeds its manifest was shown.' );
		} catch ( StorageFailed $refused ) {
			self::assertSame( 'The undo journal exceeds its manifest.', $refused->getMessage() );
		}
		$failed = $this->site->run_job( $undo, JobState::Failed );
		self::assertSame( [ 'file_undo_failed', 'The file change manifest is incomplete.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'replacement', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertFileExists( $this->site->live . '/b-new/new.txt' );
	}

	public function test_an_undo_is_queued_only_while_the_host_and_the_confirming_administrator_permit_file_changes(): void {
		$apply  = $this->site->run_job( $this->site->start() );
		$digest = $this->site->changes->preview_undo( $apply->id )['digest'];
		$grant  = hash( 'sha256', 'control token' );
		// The site's first account is an administrator; the one who confirms is not any more.
		$this->site->administrators = [ 1 ];
		foreach ( [
			'the host refuses'                     => [ false, '', 0 ],
			'the confirming user lost the role'    => [ true, $grant, 7 ],
			'a browser confirmation names no user' => [ true, $grant, null ],
		] as $case => [ $allowed, $control, $user ] ) {
			$this->site->allowed = $allowed;
			try {
				null === $user ? $this->site->changes->start_undo( $apply->id, $digest, $control ) : $this->site->changes->start_undo( $apply->id, $digest, $control, null, $user );
				self::fail( 'An undo was queued: ' . $case );
			} catch ( JobConflict $refused ) {
				self::assertSame( 'file_writes_refused', $refused->context()['code'] ?? null, $case );
			}
			self::assertNull( $this->site->jobs->active(), $case );
		}
		$this->site->allowed = true;
		$undo                = $this->site->changes->start_undo( $apply->id, $digest, $grant, null, 1 );
		self::assertSame( [ 1, $this->site->clock->seconds ], [ $undo->data['user'], $undo->created_at ] );
	}

	/**
	 * Manifests of an apply that are not what its preflight wrote, with the hash that the
	 * apply's document holds for them, and how the review refuses each.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function damaged_manifests(): array {
		$shape    = 'The confirmed file manifest has an invalid shape.';
		$metadata = 'A confirmed file mutation has incomplete metadata.';
		$verified = 'The confirmed file manifest cannot be verified.';
		return [
			'an object instead of a list'     => [ 'object', $shape ],
			'no array'                        => [ 'string', $shape ],
			'more rows than a manifest holds' => [ 'rows 1001', $shape ],
			'a row that changes nothing'      => [ 'keep', $metadata ],
			'a row without permissions'       => [ 'mode', $metadata ],
			'a row without a destination'     => [ 'destination', $metadata ],
			'more bytes than a manifest has'  => [ 'bytes 16777217', $verified ],
			'a link to a copy of it'          => [ 'link', $verified ],
			'no manifest'                     => [ 'missing', $verified ],
		];
	}

	/** Writes a manifest and, unless it is a link or missing, gives its hash to the apply. */
	private function manifest_as( Job $apply, string $damage ): void {
		$file  = $this->site->jobs->directory( $apply ) . '/undo/manifest.json';
		$rows  = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		$flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
		if ( 'link' === $damage ) {
			copy( $file, $file . '.copy' );
			unlink( $file );
			symlink( $file . '.copy', $file );
			return;
		}
		if ( 'missing' === $damage ) {
			unlink( $file );
			return;
		}
		$json = match ( true ) {
			'object' === $damage => (string) json_encode( array_combine( [ 'a', 'b', 'c' ], $rows ), $flags ),
			'string' === $damage => '"rows"',
			str_starts_with( $damage, 'rows ' ) => (string) json_encode( array_pad( $rows, (int) substr( $damage, 5 ), $rows[0] ), $flags ),
			str_starts_with( $damage, 'bytes ' ) => str_pad( (string) json_encode( $rows, $flags ), (int) substr( $damage, 6 ), ' ' ),
			'keep' === $damage => (string) json_encode( [ [ 'action' => 'keep' ] + $rows[0], ...array_slice( $rows, 1 ) ], $flags ),
			default => (string) json_encode( [ [ $damage => null ] + $rows[0], ...array_slice( $rows, 1 ) ], $flags ),
		};
		file_put_contents( $file, $json );
		$apply->data['manifest_hash'] = hash( 'sha256', $json );
		$this->site->jobs->save( $apply );
	}

	#[DataProvider( 'damaged_manifests' )]
	public function test_an_undo_review_refuses_a_manifest_that_the_preflight_of_its_apply_did_not_write( string $damage, string $message ): void {
		$apply = $this->site->run_job( $this->site->start() );
		$this->manifest_as( $apply, $damage );
		try {
			$this->site->changes->preview_undo( $apply->id );
			self::fail( 'A review of a damaged manifest was shown.' );
		} catch ( StorageFailed $refused ) {
			self::assertSame( $message, $refused->getMessage() );
		}
	}

	public function test_an_undo_review_reads_a_manifest_of_as_many_rows_and_bytes_as_a_manifest_can_have(): void {
		foreach ( [ 'rows 1000', 'bytes 16777216' ] as $limit ) {
			$apply = $this->site->run_job( $this->site->start() );
			$this->manifest_as( $apply, $limit );
			// The undo reverts the three entries that the apply attempted, whatever follows them.
			self::assertSame( 3, $this->site->undo( $apply )->result['files_changed'], $limit );
			self::assertSame( 'original', file_get_contents( $this->site->live . '/a-existing' ) );
		}
	}
}
