<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Jobs\Apply\PlanBuild;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Tests\Support\ApplySite;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a background review writes into its sealed plan, as the apply or the undo that
 * confirms it reads it: the rows of the report, their digests across ticks, the decisions of
 * an undo review, and the checkpoint from which each tick resumes.
 */
final class PlanBuildReviewTest extends TestCase {

	/** Longest path that the review takes from a report. */
	private const int LONGEST_PATH = 4095;

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'plan-build' );
	}

	protected function tearDown(): void {
		$this->site->remove();
		unset( $this->site );
	}

	/** Ticks a job with the given budget until it ends, for at most ten seconds of the wall clock. */
	private function ticked( Job $job, float $budget ): Job {
		$until = microtime( true ) + 10;
		do {
			$run = $this->site->runner->tick( $budget )->job ?? self::fail( 'The job is gone.' );
			if ( $run->state->is_terminal() ) {
				return $run;
			}
			usleep( 0.0 === $budget ? 2000 : 0 );
		} while ( microtime( true ) < $until );
		self::fail( 'The job did not end within ten seconds.' );
	}

	/**
	 * The lines of the restore's report.
	 *
	 * @return list<string>
	 */
	private function report(): array {
		return array_values( array_filter( explode( "\n", (string) file_get_contents( dirname( $this->site->files ) . '/entries.jsonl' ) ) ) );
	}

	/**
	 * Writes the restore's report.
	 *
	 * @param list<string> $lines Entries.
	 */
	private function write_report( array $lines ): void {
		file_put_contents( dirname( $this->site->files ) . '/entries.jsonl', implode( "\n", $lines ) . "\n" );
	}

	/** A line of a report. */
	private static function line( string $path, string $type = 'file', int $mode = 0644 ): string {
		return (string) json_encode(
			[
				'path' => $path,
				'type' => $type,
				'mode' => $mode,
			],
			JSON_THROW_ON_ERROR
		);
	}

	/** Makes the restore one that needs a background review, and queues it. */
	private function queued(): Job {
		$restore                  = $this->site->jobs->get( $this->site->restore ) ?? self::fail( 'The restore is gone.' );
		$restore->result['files'] = DiskFilePlan::PAGE + 1;
		$this->site->jobs->save( $restore );
		$queued = $this->site->changes->request_preview( $this->site->restore, false, false );
		self::assertInstanceOf( Job::class, $queued );
		return $queued;
	}

	/**
	 * Checkpoints of a background review that it did not write, by the field of the
	 * checkpoint, its value, and how the review refuses it.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function damaged_checkpoints(): array {
		$invalid = 'The background file preview checkpoint is invalid.';
		$count   = 'The background file preview count is invalid.';
		$bytes   = 'The background file preview allocation total is invalid.';
		return [
			'counts that are no list'       => [ 'counts', 'rows', $invalid ],
			'an offset below zero'          => [ 'offset', -1, $invalid ],
			'an offset that is no number'   => [ 'offset', '5', $invalid ],
			'changes that are no number'    => [ 'changes', 'x', $invalid ],
			'refusals that are no number'   => [ 'refused', 'x', $invalid ],
			'a pending row that is no map'  => [ 'pending', 'x', $invalid ],
			'a stream without a name'       => [ 'counts', [ 1 ], $count ],
			'a count that is no number'     => [ 'counts', [ 'rows' => '5' ], $count ],
			'a count beyond a plan'         => [ 'counts', [ 'rows' => DiskFilePlan::MAX_ENTRIES + 1 ], $count ],
			'an estimate that is no number' => [ 'required_bytes', '5', $bytes ],
			'an estimate below zero'        => [ 'required_bytes', -1, $bytes ],
		];
	}

	#[DataProvider( 'damaged_checkpoints' )]
	public function test_a_background_review_refuses_a_checkpoint_that_it_did_not_write( string $field, mixed $value, string $message ): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 ); // Preflight.
		$this->site->runner->tick( 0.0 ); // The first row.
		$saved = $this->site->saved( $queued );
		self::assertSame( 1, $saved->data['plan_cursor']['counts']['rows'] );
		$saved->data['plan_cursor'][ $field ] = $value;
		$this->site->jobs->save( $saved );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( [ 'file_preview_failed', $message ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertArrayNotHasKey( 'digest', $failed->result );
	}

	public function test_an_undo_review_refuses_a_count_of_settled_rows_below_zero(): void {
		$plan   = $this->site->run_job( $this->site->queue_large_preview() );
		$apply  = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		$queued = $this->site->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $queued );
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		$saved                                 = $this->site->saved( $queued );
		$saved->data['plan_cursor']['settled'] = -1;
		$this->site->jobs->save( $saved );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( 'The background file preview checkpoint is invalid.', $failed->error?->message );
	}

	public function test_a_review_resumes_checkpoints_of_an_earlier_version_and_with_a_zero_count(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		$saved  = $this->site->saved( $queued );
		$cursor = $saved->data['plan_cursor'];
		self::assertGreaterThan( 0, $cursor['offset'] );
		// A checkpoint of a version without the estimate, and a total of an action with no row.
		unset( $cursor['required_bytes'] );
		$cursor['actions']['refuse'] = 0;
		$saved->data['plan_cursor']  = $cursor;
		$this->site->jobs->save( $saved );
		$plan = $this->site->run_job( $queued );
		$page = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		// The estimate is unknown, so the review takes no position on the space, and lets the apply check it.
		self::assertSame( [ null, null, true ], [ $page['required_bytes'], $page['enough_space'], $page['applicable'] ] );
		self::assertSame( 0, $page['actions']['refuse'] ?? null );
	}

	public function test_a_review_driven_by_ticks_without_budget_ends_at_the_end_of_its_report(): void {
		$plan = $this->ticked( $this->site->queue_large_preview(), 0.0 );
		self::assertSame( [ JobState::Succeeded, 3, count( $this->report() ) ], [ $plan->state, $plan->result['changes'], $plan->result['entries'] ] );
	}

	public function test_a_review_refuses_a_checkpoint_beyond_the_end_of_its_report(): void {
		$queued = $this->site->queue_large_preview();
		$this->site->runner->tick( 0.0 );
		$this->site->runner->tick( 0.0 );
		$saved                                = $this->site->saved( $queued );
		$saved->data['plan_cursor']['offset'] = filesize( dirname( $this->site->files ) . '/entries.jsonl' ) + 1;
		$this->site->jobs->save( $saved );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( 'The restore report cursor is outside the file.', $failed->error?->message );
	}

	/** A path outside the site of the given length. */
	private static function path_of( int $length ): string {
		$path = '/elsewhere' . str_repeat( '/' . str_repeat( 'p', 200 ), intdiv( $length - 11, 201 ) );
		return $path . '/' . str_repeat( 'q', $length - strlen( $path ) - 1 );
	}

	public function test_a_review_takes_a_path_of_the_longest_length_that_file_systems_take_and_refuses_a_longer_one(): void {
		$queued = $this->queued();
		$this->write_report( [ ...$this->report(), self::line( self::path_of( self::LONGEST_PATH ), 'symlink', 0777 ) ] );
		$plan = $this->site->run_job( $queued );
		self::assertSame( 3, $plan->result['changes'] );
		$queued = $this->site->changes->request_preview( $this->site->restore, false, false );
		self::assertInstanceOf( Job::class, $queued );
		$this->write_report( [ ...$this->report(), self::line( self::path_of( self::LONGEST_PATH + 1 ), 'symlink', 0777 ) ] );
		$failed = $this->site->run_job( $queued, JobState::Failed );
		self::assertSame( 'A file preview path exceeds the supported filesystem length.', $failed->error?->message );
	}

	public function test_a_review_refuses_a_report_that_names_a_path_twice(): void {
		$lines = $this->report();
		// The first entry again as the second one, and the first one again as the last one,
		// which a later tick reads.
		foreach ( [ [ $lines[0], $lines[0], ...array_slice( $lines, 1 ) ], [ ...$lines, $lines[0] ] ] as $number => $report ) {
			$queued = $this->queued();
			$this->write_report( $report );
			$failed = $this->ticked( $queued, 0.0 );
			self::assertSame( [ JobState::Failed, 'The staged report contains a duplicate path.' ], [ $failed->state, $failed->error?->message ], 'Report ' . $number );
		}
	}

	/** Stages a replacement for a-existing and, or, a live original of three blocks of the digest. */
	private function stage_large( bool $staged, bool $live ): string {
		$content = str_repeat( 'l', 2 * 1048576 + 1 );
		if ( $staged ) {
			file_put_contents( $this->site->files . $this->site->live . '/a-existing', $content );
		}
		if ( $live ) {
			file_put_contents( $this->site->live . '/a-existing', $content );
		}
		$this->site->write_report( $this->site->live . '/skipped-link' );
		return hash( 'sha256', $content );
	}

	/**
	 * Large files on either side of a replacement, whose digests take several ticks without
	 * budget: the staged replacement, and the live original.
	 *
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function large_sides(): array {
		return [
			'a large replacement' => [ true, false ],
			'a large original'    => [ false, true ],
		];
	}

	#[DataProvider( 'large_sides' )]
	public function test_a_row_whose_digest_spans_ticks_is_sealed_with_its_digests_and_applied( bool $staged, bool $live ): void {
		$digest = $this->stage_large( $staged, $live );
		$plan   = $this->ticked( $this->queued(), 0.0 );
		self::assertSame( [ JobState::Succeeded, 3 ], [ $plan->state, $plan->result['changes'] ] );
		$apply = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		self::assertSame( 3, $apply->result['files_changed'] );
		self::assertSame( $staged ? $digest : hash( 'sha256', 'replacement' ), hash_file( 'sha256', $this->site->live . '/a-existing' ) );
		$this->site->undo_background( $apply );
		self::assertSame( $live ? $digest : hash( 'sha256', 'original' ), hash_file( 'sha256', $this->site->live . '/a-existing' ) );
	}

	public function test_a_row_whose_files_change_while_its_digest_spans_ticks_is_refused(): void {
		$this->stage_large( true, false );
		$queued = $this->queued();
		$until  = microtime( true ) + 10;
		do {
			$this->site->runner->tick( 0.0 );
			$saved = $this->site->saved( $queued );
		} while ( ! isset( $saved->data['plan_cursor']['pending']['signature'] ) && ! $saved->state->is_terminal() && microtime( true ) < $until );
		self::assertArrayHasKey( 'signature', $saved->data['plan_cursor']['pending'] ?? [], 'The review did not yield within the digest of the large file.' );
		// The live file of the row changes between two ticks of its digest.
		chmod( $this->site->live . '/a-existing', 0640 );
		$failed = $this->ticked( $queued, 0.0 );
		self::assertSame( [ JobState::Failed, 'A file changed while its background preview was being prepared.' ], [ $failed->state, $failed->error?->message ] );
	}

	/** Applies the restore with a background review and queues the background review of its undo. */
	private function undo_review(): Job {
		$plan   = $this->site->run_job( $this->site->queue_large_preview() );
		$apply  = $this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
		$queued = $this->site->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $queued );
		return $queued;
	}

	public function test_an_undo_review_counts_as_settled_only_kept_rows_with_nothing_left_to_revert(): void {
		$review = $this->site->run_job( $this->undo_review() );
		self::assertSame( [ 1, 2, 0, 0 ], [ $review->result['restore'], $review->result['remove'], $review->result['keep'], $review->result['settled'] ] );
	}

	public function test_an_undo_review_reviews_one_row_per_tick_that_has_used_its_budget_and_all_rows_in_one_with_time(): void {
		$queued = $this->undo_review();
		$this->site->runner->tick( 0.0 ); // Preflight.
		$this->site->runner->tick( 0.0 );
		self::assertSame( 1, $this->site->saved( $queued )->data['plan_cursor']['counts']['rows'] );
		$this->site->runner->tick( 2.0 );
		$review = $this->site->saved( $queued );
		self::assertSame( [ JobState::Succeeded, 3 ], [ $review->state, $review->result['entries'] ] );
	}

	public function test_an_undo_review_that_starts_over_from_an_earlier_checkpoint_resumes_a_row_that_needs_another_tick(): void {
		// The file that the apply creates deepest, which the undo review reviews first, is large.
		file_put_contents( $this->site->files . $this->site->live . '/b-new/new.txt', str_repeat( 'n', 2 * 1048576 + 1 ) );
		$queued = $this->undo_review();
		// A checkpoint of a version without the totals: the review starts over.
		$queued->data['plan_cursor'] = [ 'counts' => [ 'rows' => 1 ] ];
		$this->site->jobs->save( $queued );
		$review = $this->ticked( $queued, 0.0 );
		self::assertSame( [ JobState::Succeeded, 1, 2, 0 ], [ $review->state, $review->result['restore'], $review->result['remove'], $review->result['keep'] ] );
	}

	/** A created live folder with names that its review shows for removal. */
	private function created_folder( string ...$names ): array {
		$root = $this->site->directory . '/review';
		mkdir( $root . '/plan', 0700, true );
		$disk   = new DiskFilePlan( $root . '/plan' );
		$folder = $this->site->live . '/created';
		mkdir( $folder );
		foreach ( $names as $index => $name ) {
			touch( $folder . '/' . $name );
			$disk->claim( 'remove', $folder . '/' . $name, $index );
		}
		return [ $disk, $folder ];
	}

	/** The position after a name in a listing of the folder, as a reading counts it. */
	private static function position_after( string $folder, string $name ): int {
		$position = 0;
		foreach ( new \DirectoryIterator( $folder ) as $entry ) {
			++$position;
			if ( $entry->getFilename() === $name ) {
				return $position;
			}
		}
		self::fail( 'The folder has no ' . $name . '.' );
	}

	/**
	 * Positions of a resumed reading that do not belong to the folder as it is, and the
	 * reading's resumption after its boundary as the folder has it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function foreign_positions(): array {
		return [
			'an offset that is no number' => [ 'string offset' ],
			'an offset below zero'        => [ 'negative offset' ],
			'another identity'            => [ 'identity' ],
			'a boundary of another name'  => [ 'boundary' ],
		];
	}

	#[DataProvider( 'foreign_positions' )]
	public function test_a_resumed_reading_of_a_created_folder_whose_position_does_not_belong_to_it_keeps_the_folder( string $position ): void {
		[ $disk, $folder ] = $this->created_folder( 'first', 'second' );
		clearstatcache();
		$after  = self::position_after( $folder, 'first' );
		$cursor = [
			'offset'   => $after,
			'identity' => ApplyManifest::identity( $folder ),
			'stable'   => true,
			'last'     => hash( 'sha256', 'first' ),
		];
		// As it is, the position resumes the reading and finds only reviewed removals.
		$valid = $cursor;
		self::assertTrue( PlanBuild::children_removable( $disk, $folder, INF, $valid ) );
		$cursor = match ( $position ) {
			'string offset'   => [ 'offset' => (string) $after ] + $cursor,
			'negative offset' => [ 'offset' => -1 ] + $cursor,
			'identity'        => [ 'identity' => 'another' ] + $cursor,
			default           => [ 'last' => hash( 'sha256', 'second' ) ] + $cursor,
		};
		self::assertSame( 'new_children', PlanBuild::children_removable( $disk, $folder, INF, $cursor ) );
	}

	public function test_a_created_folder_that_a_link_took_the_place_of_is_kept_also_when_the_link_leads_to_an_empty_folder(): void {
		[ $disk, $folder ] = $this->created_folder();
		rmdir( $folder );
		mkdir( $this->site->directory . '/empty' );
		symlink( $this->site->directory . '/empty', $folder );
		$cursor = [];
		self::assertSame( 'new_children', PlanBuild::children_removable( $disk, $folder, INF, $cursor ) );
	}

	public function test_a_reading_resumed_from_a_checkpoint_without_a_boundary_starts_again(): void {
		[ $disk, $folder ] = $this->created_folder( 'first', 'second' );
		clearstatcache();
		// A checkpoint of a version that kept only the offset.
		$cursor = [
			'offset'   => 3,
			'identity' => ApplyManifest::identity( $folder ),
			'stable'   => true,
		];
		self::assertTrue( PlanBuild::children_removable( $disk, $folder, INF, $cursor ) );
	}

	public function test_a_reading_that_has_used_its_budget_reads_one_name_per_call(): void {
		[ $disk, $folder ] = $this->created_folder( 'first', 'second', 'third' );
		// A reading that begins after the second of the folder's last change does not wait.
		clearstatcache();
		$changed = filectime( $folder );
		while ( time() <= $changed ) {
			usleep( 10000 );
		}
		$cursor = [];
		$calls  = 0;
		do {
			$decision = PlanBuild::children_removable( $disk, $folder, 0.0, $cursor );
			++$calls;
		} while ( null === $decision && $calls < 20 );
		self::assertTrue( $decision );
		// The folder has its three names, '.' and '..': each call but the last stopped after one.
		self::assertSame( 5, $calls );
	}

	public function test_a_reading_begun_in_the_second_of_the_folders_last_change_waits_for_that_second_to_pass(): void {
		[ $disk, $folder ] = $this->created_folder( 'first', 'second' );
		// The folder changes in a new second, well after its start, as the clock of the
		// file system can lag PHP's: the reading below begins in that second.
		$second = time();
		while ( time() === $second ) {
			usleep( 10000 );
		}
		usleep( 250000 );
		touch( $folder . '/changed' );
		unlink( $folder . '/changed' );
		clearstatcache();
		$changed = filectime( $folder );
		self::assertSame( time(), $changed, 'The folder\'s change time is not the current second.' );
		$cursor = [];
		self::assertNull( PlanBuild::children_removable( $disk, $folder, 0.0, $cursor ) );
		self::assertFalse( $cursor['stable'] );
		// While that second lasts, the reading waits, whatever time is left.
		self::assertNull( PlanBuild::children_removable( $disk, $folder, INF, $cursor ) );
		self::assertSame( $changed, time(), 'The test took longer than the rest of a second.' );
		while ( time() <= $changed ) {
			usleep( 10000 );
		}
		// Then it starts again and decides.
		self::assertTrue( PlanBuild::children_removable( $disk, $folder, INF, $cursor ) );
	}

	public function test_a_background_review_refuses_a_second_claim_of_a_destination_wherever_it_comes_in_its_report(): void {
		// Two staged names that a case-insensitive file system takes for one.
		file_put_contents( $this->site->files . $this->site->live . '/Same.txt', 'upper' );
		file_put_contents( $this->site->files . $this->site->live . '/same.txt', 'lower' );
		$this->site->write_report( $this->site->live . '/skipped-link' );
		$lines = $this->report();
		$pair  = array_values( array_filter( $lines, static fn ( string $line ): bool => 0 === strcasecmp( basename( (string) json_decode( $line, true, flags: JSON_THROW_ON_ERROR )['path'] ), 'same.txt' ) ) );
		self::assertCount( 2, $pair );
		// In the order of the staged tree, and as the first two entries of the report.
		foreach ( [ $lines, [ ...$pair, ...array_values( array_diff( $lines, $pair ) ) ] ] as $number => $report ) {
			$queued = $this->queued();
			$this->write_report( $report );
			$plan = $this->site->run_job( $queued );
			self::assertSame( 2, $plan->result['refused'], 'Report ' . $number );
			$page = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
			self::assertIsArray( $page );
			// The site's policy refuses the first spelling it meets, and the claim of the
			// destination the second.
			$reasons = array_column( array_filter( $page['entries'], static fn ( array $entry ): bool => 'refuse' === $entry['action'] ), 'reason' );
			sort( $reasons, SORT_STRING );
			self::assertSame( [ 'ambiguous_source', 'overlapping_destinations' ], $reasons, 'Report ' . $number );
			self::assertFalse( $page['applicable'] );
		}
	}

	public function test_a_report_entry_of_another_type_than_its_staged_file_is_refused(): void {
		$queued = $this->queued();
		$this->write_report(
			array_map(
				fn ( string $line ): string => str_ends_with( (string) json_decode( $line, true, flags: JSON_THROW_ON_ERROR )['path'], '/b-new/new.txt' ) ? self::line( $this->site->live . '/b-new/new.txt', 'dir', 0755 ) : $line,
				$this->report()
			)
		);
		$plan = $this->site->run_job( $queued );
		$page = $this->site->changes->request_preview( $this->site->restore, false, false, $plan->id );
		self::assertIsArray( $page );
		self::assertSame( [ 'refuse', 'staged_type_changed' ], [ array_column( $page['entries'], 'action', 'path' )[ $this->site->live . '/b-new/new.txt' ], array_column( $page['entries'], 'reason', 'path' )[ $this->site->live . '/b-new/new.txt' ] ] );
		self::assertSame( 1, $plan->result['refused'] );
	}

	public function test_a_background_apply_gives_new_entries_the_permissions_of_the_snapshot(): void {
		$umask = umask( 0022 );
		try {
			$plan = $this->site->run_job( $this->queued() );
			$this->site->run_job( $this->site->changes->start_apply( $this->site->restore, false, $plan->result['digest'], '', $plan->id ) );
			clearstatcache();
			self::assertSame( [ 0755, 0644 ], [ fileperms( $this->site->live . '/b-new' ) & 0777, fileperms( $this->site->live . '/b-new/new.txt' ) & 0777 ] );
		} finally {
			umask( $umask );
		}
	}
}
