<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Tests\Support\ApplySite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an inline apply writes to the live site, from its preflight to its record: the checks
 * of the confirmed plan before maintenance and before each entry, what a retried tick does,
 * and what the job document says about it.
 */
final class FileChangesApplyTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'apply-changes' );
	}

	protected function tearDown(): void {
		$this->site->remove();
	}

	/** Runs the preflight of an apply, which puts the site into maintenance. */
	private function preflighted( ?Job $apply = null ): Job {
		$apply ??= $this->site->start();
		$this->site->runner->tick( 0.0 );
		$saved = $this->site->saved( $apply );
		self::assertSame( 'applying', $saved->step );
		self::assertFileExists( $this->site->live . '/.maintenance' );
		return $saved;
	}

	/** Checks that the live files of the site are as before the apply. */
	private function assert_unchanged(): void {
		self::assertSame( 'original', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->site->live . '/b-new' );
	}

	/**
	 * What a stopped apply recorded: the entries it completed and those it attempted.
	 *
	 * @return array{0: mixed, 1: mixed}
	 */
	private static function entries( Job $apply ): array {
		return [ $apply->result['entries_completed'] ?? null, $apply->result['entries_attempted'] ?? null ];
	}

	/** The undo opportunities that the site lists, by apply and state. */
	private function undoable(): array {
		return array_map( static fn ( array $listed ): array => [ $listed['job'], $listed['state'] ], $this->site->changes->undoable() );
	}

	public function test_an_apply_whose_worker_stopped_after_its_last_entry_finishes_and_records_every_entry(): void {
		$apply = $this->preflighted();
		for ( $entry = 0; $entry < 3; ++$entry ) {
			$this->site->runner->tick( 0.0 ); // One entry per tick.
		}
		$document = $this->site->saved( $apply );
		self::assertSame( [ 3, 3, 'recording' ], [ $document->progress['total'], $document->progress['done'], $document->step ] );
		// What the worker's own save after the last entry left, before the runner saved the step after it.
		$document->step = 'applying';
		$this->site->jobs->save( $document );
		$done = $this->site->run_job( $apply );
		self::assertSame(
			[
				'restore'       => $this->site->restore,
				'files_changed' => 3,
			],
			$done->result
		);
		self::assertSame( 'replacement', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertSame( 'created', file_get_contents( $this->site->live . '/b-new/new.txt' ) );
		self::assertSame( [ [ $apply->id, 'applied' ] ], $this->undoable() );
	}

	public function test_an_apply_refuses_a_damaged_cursor_before_it_changes_a_file(): void {
		foreach ( [ -1, 4, '1' ] as $cursor ) {
			$apply                   = $this->preflighted();
			$apply->progress['done'] = $cursor;
			$this->site->jobs->save( $apply );
			$failed = $this->site->run_job( $apply, JobState::Failed );
			self::assertSame( [ 'file_apply_failed', 'The file change cursor is invalid.' ], [ $failed->error?->code, $failed->error?->message ], (string) json_encode( $cursor ) );
			self::assertSame( 'applying', $this->site->failed_step( $failed ) );
			$this->assert_unchanged();
		}
		self::assertSame( [], $this->undoable() );
	}

	public function test_an_apply_whose_remaining_space_total_is_damaged_stops_before_its_first_change(): void {
		// A total that the document lacks is computed again from the plan (FilePlans::resume_space()).
		foreach ( [ 0, -1, '9999999999' ] as $remaining ) {
			$apply                          = $this->preflighted();
			$apply->data['remaining_bytes'] = $remaining;
			$this->site->jobs->save( $apply );
			$failed = $this->site->run_job( $apply, JobState::Failed );
			self::assertSame( [ 'file_apply_failed', 'The remaining file-space total is inconsistent.' ], [ $failed->error?->code, $failed->error?->message ], (string) json_encode( $remaining ) );
			$this->assert_unchanged();
		}
		self::assertSame( [], $this->undoable() );
	}

	public function test_each_tick_of_an_apply_puts_a_missing_maintenance_marker_back_before_it_changes_a_file(): void {
		$apply = $this->preflighted();
		// Something removes the marker between two ticks.
		unlink( $this->site->live . '/.maintenance' );
		$this->site->runner->tick( 0.0 ); // The first entry.
		self::assertFileExists( $this->site->live . '/.maintenance' );
		self::assertSame( 1, $this->site->saved( $apply )->progress['done'] );
		$this->site->run_job( $apply );
	}

	public function test_an_apply_charges_the_space_of_each_entry_once(): void {
		$review = $this->site->changes->preview( $this->site->restore );
		self::assertGreaterThan( 0, $review['required_bytes'] );
		// The volume has exactly the room that the review asked for, as long as the apply runs.
		$this->site->free = $review['required_bytes'];
		$apply            = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $review['digest'] ) );
		self::assertSame( 3, $apply->result['files_changed'] );
		self::assertSame( 'replacement', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertSame( 0, $this->site->saved( $apply )->data['remaining_bytes'] );
		// One byte less, and the apply stops before its first change.
		$this->site->undo( $apply );
		$review           = $this->site->changes->preview( $this->site->restore );
		$this->site->free = $review['required_bytes'];
		$again            = $this->site->changes->start_apply( $this->site->restore, false, $review['digest'] );
		$again            = $this->preflighted( $again );
		$this->site->free = $review['required_bytes'] - 1;
		$failed           = $this->site->run_job( $again, JobState::Failed );
		self::assertSame( 'file_space', $failed->error?->code );
		$this->assert_unchanged();
	}

	/**
	 * Changes to a reviewed file after the preflight that leave its content as it was: the
	 * permissions of the live file, and those of the staged one.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function changed_after_preflight(): array {
		return [
			'the live file'   => [ 'live' ],
			'the staged file' => [ 'staged' ],
		];
	}

	#[DataProvider( 'changed_after_preflight' )]
	public function test_a_file_that_changes_after_the_preflight_stops_the_apply_before_its_entry_counts_as_attempted( string $side ): void {
		$apply = $this->preflighted();
		$file  = 'live' === $side ? $this->site->live . '/a-existing' : $this->site->files . $this->site->live . '/a-existing';
		chmod( $file, 0640 );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'A destination or staged file changed after confirmation.' ], [ $failed->error?->code, $failed->error?->message ] );
		// The reviewed file is left as it is, and the apply did not count it as attempted, so
		// that no undo is offered for it. The other entries may come before it.
		clearstatcache();
		self::assertSame( 'original', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertSame( 'live' === $side ? 0640 : 0644, fileperms( $this->site->live . '/a-existing' ) & 0777 );
		[ $completed, $attempted ] = self::entries( $failed );
		self::assertSame( $completed, $attempted );
		self::assertFileDoesNotExist( $this->site->jobs->directory( $apply ) . '/undo/item-' . $attempted . '/entry.json' );
	}

	public function test_the_document_of_an_apply_counts_each_entry_before_the_next_one_begins(): void {
		$apply  = $this->preflighted();
		$file   = $this->site->jobs->directory( $apply ) . '/job.json';
		$counts = [];
		// What a request that reads the job while its tick runs finds, and what is left on disk
		// when the worker is killed: each completed entry is counted at once.
		$this->site->job_writes = static function ( string $operation, string $path ) use ( $file, &$counts ): void {
			if ( 'rename' === $operation && $file === $path ) {
				$document = json_decode( (string) file_get_contents( $file ), true );
				$counts[] = $document['progress']['done'] ?? null;
			}
		};
		$this->site->runner->tick( 2.0 );
		$this->site->job_writes = null;
		// The runner's own save at the start of the tick counts none yet.
		self::assertSame( [ 1, 2, 3 ], array_slice( array_values( array_filter( $counts, static fn ( mixed $count ): bool => null !== $count ) ), 0, 3 ) );
	}

	public function test_an_apply_stops_at_a_folder_above_a_destination_that_its_manifest_does_not_create(): void {
		$apply    = $this->preflighted();
		$manifest = $this->site->jobs->directory( $apply ) . '/undo/manifest.json';
		$rows     = json_decode( (string) file_get_contents( $manifest ), true, flags: JSON_THROW_ON_ERROR );
		// A manifest that names the file in the new folder but not the folder.
		$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => 'mkdir' !== $row['action'] ) );
		$json = (string) json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		file_put_contents( $manifest, $json );
		$apply->data['manifest_hash'] = hash( 'sha256', $json );
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'An unreviewed directory appeared above the destination.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertDirectoryDoesNotExist( $this->site->live . '/b-new' );
	}

	public function test_an_apply_whose_document_lost_a_field_of_its_confirmation_stops_before_maintenance(): void {
		foreach ( [ 'digest', 'restore' ] as $field ) {
			$apply = $this->site->start();
			unset( $apply->data[ $field ] );
			$this->site->jobs->save( $apply );
			$failed = $this->site->run_job( $apply, JobState::Failed );
			self::assertSame( [ 'file_apply_failed', 'The file change request is incomplete.' ], [ $failed->error?->code, $failed->error?->message ], $field );
			self::assertSame( 'preflight', $this->site->failed_step( $failed ), $field );
			$this->assert_unchanged();
		}
	}

	public function test_an_apply_whose_journal_folder_cannot_be_created_stops_before_maintenance(): void {
		$apply = $this->site->start();
		// A file takes the name of the journal's folder.
		file_put_contents( $this->site->jobs->directory( $apply ) . '/undo', 'not a folder' );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$failed = $this->site->run_job( $apply, JobState::Failed );
		} finally {
			restore_error_handler();
		}
		self::assertSame( [ 'file_apply_failed', 'The apply journal directory is unavailable.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		$this->assert_unchanged();
	}

	public function test_an_apply_whose_recorded_uploads_root_cannot_be_read_stops_before_it_changes_a_file(): void {
		foreach ( [ '', 5, [ 'uploads' ] ] as $uploads ) {
			$apply                      = $this->site->start();
			$apply->data['uploads_dir'] = $uploads;
			$this->site->jobs->save( $apply );
			$failed = $this->site->run_job( $apply, JobState::Failed );
			self::assertSame( [ 'file_apply_failed', 'The confirmed uploads directory of the job is unreadable.' ], [ $failed->error?->code, $failed->error?->message ], (string) json_encode( $uploads ) );
			$this->assert_unchanged();
		}
		// A job that an earlier version queued without the root uses the current one.
		$apply                      = $this->site->start();
		$apply->data['uploads_dir'] = null;
		$this->site->jobs->save( $apply );
		self::assertSame( 3, $this->site->run_job( $apply )->result['files_changed'] );
	}

	public function test_a_browser_apply_whose_document_lost_its_user_is_refused_before_maintenance(): void {
		// The site's first account is an administrator too: a lost user must not become it.
		$this->site->administrators = [ 1, 7 ];
		$review                     = $this->site->changes->preview( $this->site->restore );
		$apply                      = $this->site->changes->start_apply( $this->site->restore, false, $review['digest'], hash( 'sha256', 'control token' ), null, 7 );
		$apply->data['user']        = '7';
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		$this->assert_unchanged();
	}

	public function test_a_browser_apply_confirmed_without_a_user_is_refused(): void {
		$this->site->administrators = [ 1, 7 ];
		$review                     = $this->site->changes->preview( $this->site->restore );
		try {
			$this->site->changes->start_apply( $this->site->restore, false, $review['digest'], hash( 'sha256', 'control token' ) );
			self::fail( 'A browser confirmation without the user who confirmed it was queued.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'file_writes_refused', $refused->context()['code'] ?? null );
		}
		self::assertNull( $this->site->jobs->active() );
	}

	public function test_the_maintenance_marker_of_a_browser_apply_admits_the_control_token_of_its_confirmation(): void {
		$grant  = hash( 'sha256', 'control token' );
		$review = $this->site->changes->preview( $this->site->restore );
		$apply  = $this->site->changes->start_apply( $this->site->restore, false, $review['digest'], $grant, null, 7 );
		self::assertSame( [ $this->site->clock->seconds, $grant ], [ $apply->created_at, $apply->data['maintenance_grant'] ] );
		$this->preflighted( $apply );
		// The administrator's progress and cancellation requests present the token.
		self::assertStringContainsString( var_export( $grant, true ), (string) file_get_contents( $this->site->live . '/.maintenance' ) );
		$this->site->run_job( $apply );
	}

	public function test_the_host_policy_is_checked_before_an_apply_enters_maintenance(): void {
		$apply               = $this->site->start();
		$this->site->allowed = false;
		$failed              = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( 'file_writes_refused', $failed->error?->code );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		// The site never entered maintenance: the proof of a marker stays with the job that wrote one.
		self::assertFileDoesNotExist( $this->site->jobs->directory( $apply ) . '/maintenance.sha256' );
		$this->assert_unchanged();
	}

	public function test_an_entry_that_the_site_maps_elsewhere_after_the_preflight_stops_the_apply(): void {
		$this->site->stage_fresh_uploads();
		$review = $this->site->changes->preview( $this->site->restore );
		$apply  = $this->site->changes->start_apply( $this->site->restore, false, $review['digest'] );
		// Queued as an earlier version queued it, without the uploads root of its review.
		$apply->data['uploads_dir'] = null;
		$this->site->jobs->save( $apply );
		$apply = $this->preflighted( $apply );
		// The site's uploads root changes before the first entry.
		$previous            = $this->site->uploads;
		$this->site->uploads = $this->site->live . '/elsewhere';
		mkdir( $this->site->uploads );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'The destination mapping changed after confirmation.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( [ 0, 0 ], self::entries( $failed ) );
		self::assertDirectoryDoesNotExist( $previous . '/album' );
		self::assertDirectoryDoesNotExist( $this->site->uploads . '/album' );
	}

	public function test_a_large_file_is_copied_over_several_ticks_and_published_once_it_is_complete(): void {
		$big     = $this->site->live . '/big.bin';
		$restore = $this->site->second_restore( [ $big => str_repeat( 'b', 2 * 1048576 + 1 ) ] );
		$review  = $this->site->changes->preview( $restore );
		$apply   = $this->site->changes->start_apply( $restore, false, $review['digest'] );
		$this->site->runner->tick( 0.0 ); // Preflight.
		// A tick without budget copies one block of the three and goes no further.
		$this->site->runner->tick( 0.0 );
		$saved = $this->site->saved( $apply );
		self::assertSame( [ 'applying', 0 ], [ $saved->step, $saved->progress['done'] ?? 0 ] );
		self::assertFileDoesNotExist( $big );
		$done = $this->site->run_job( $apply );
		self::assertSame( 1, $done->result['files_changed'] );
		self::assertSame( hash( 'sha256', str_repeat( 'b', 2 * 1048576 + 1 ) ), hash_file( 'sha256', $big ) );
	}

	public function test_an_apply_whose_staged_report_is_gone_stops_before_maintenance_and_is_not_confirmed_again(): void {
		$review = $this->site->changes->preview( $this->site->restore );
		$apply  = $this->site->changes->start_apply( $this->site->restore, false, $review['digest'] );
		// The report of the staged files goes away between the confirmation and the worker.
		unlink( dirname( $this->site->files ) . '/entries.jsonl' );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_staging_gone', 'The complete staging report is unavailable.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		self::assertFileDoesNotExist( $this->site->live . '/.maintenance' );
		$this->assert_unchanged();
		// The review that confirmed it no longer queues an apply, and says why.
		try {
			$this->site->changes->start_apply( $this->site->restore, false, $review['digest'] );
			self::fail( 'An apply was queued without the report of its staged files.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( [ 'The complete staging report is unavailable.', 'file_staging_gone' ], [ $refused->getMessage(), $refused->context()['code'] ?? null ] );
		}
		self::assertNull( $this->site->jobs->active() );
	}
}
