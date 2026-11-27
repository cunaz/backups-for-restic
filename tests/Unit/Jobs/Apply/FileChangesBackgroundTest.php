<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs\Apply;

use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\ApplySite;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an apply reviewed in the background accepts from its sealed plan: the checks of its
 * preflight before maintenance, and what it trusts of the plan between its entries.
 */
final class FileChangesBackgroundTest extends TestCase {

	private ApplySite $site;

	protected function setUp(): void {
		$this->site = new ApplySite( 'apply-background' );
	}

	protected function tearDown(): void {
		$this->site->remove();
	}

	/** Builds the background review of the restore. */
	private function plan(): Job {
		return $this->site->run_job( $this->site->queue_large_preview() );
	}

	/** Queues the apply of a background review. */
	private function apply( Job $plan, bool $config = false ): Job {
		return $this->site->changes->start_apply( $this->site->restore, $config, $plan->result['digest'], '', $plan->id );
	}

	/** The private directory of a sealed plan. */
	private function directory( Job $plan ): string {
		return dirname( $this->site->files ) . '/plans/' . $plan->id;
	}

	/**
	 * Changes the first row of a sealed plan that has the given action, writes the page that
	 * holds it again and seals the plan with the header it had.
	 *
	 * @param \Closure(array<string, mixed>): array<string, mixed> $change Changes the row.
	 * @return string Digest of the plan sealed again.
	 */
	private function reseal_row( Job $plan, string $action, \Closure $change ): string {
		$disk  = new DiskFilePlan( $this->directory( $plan ) );
		$head  = $disk->open( $plan->result['digest'] );
		$index = 0;
		while ( $index < $head['entries'] && $action !== $disk->row( $index )['action'] ) {
			++$index;
		}
		self::assertLessThan( $head['entries'], $index, 'The plan has no row to ' . $action . '.' );
		$first = intdiv( $index, DiskFilePlan::PAGE ) * DiskFilePlan::PAGE;
		$rows  = $disk->page( $first );
		// A record put at a position drops the rest of its page, which is put again after it.
		foreach ( $rows as $offset => $row ) {
			$disk->put( 'rows', $first + $offset, $first + $offset === $index ? $change( $row ) : $row );
		}
		return $disk->seal( $head['subject'], $head['restore'], $head['undo'], $head['config'], $head['counts'], $head['changes'], $head['refused'], $head['required_bytes'], $head['actions'] );
	}

	/** Checks that the live files of the site are as before the apply. */
	private function assert_unchanged(): void {
		self::assertSame( 'original', file_get_contents( $this->site->live . '/a-existing' ) );
		self::assertDirectoryDoesNotExist( $this->site->live . '/b-new' );
	}

	/**
	 * Headers of a sealed plan that do not match the apply that confirmed it, in the field
	 * that differs, and plans whose rows were changed.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function foreign_plans(): array {
		return [
			'another restore'             => [ 'restore', '20260101T000000Z-000000' ],
			'another subject'             => [ 'subject', '20260101T000000Z-000000' ],
			'an undo review'              => [ 'undo', true ],
			'a review with configuration' => [ 'config', true ],
			'no change'                   => [ 'changes', 0 ],
			'a refused entry'             => [ 'refused', 1 ],
		];
	}

	#[DataProvider( 'foreign_plans' )]
	public function test_an_apply_starts_only_with_a_sealed_plan_that_matches_its_confirmation( string $field, mixed $value ): void {
		$plan                  = $this->plan();
		$apply                 = $this->apply( $plan );
		$disk                  = new DiskFilePlan( $this->directory( $plan ) );
		$header                = $disk->open( $plan->result['digest'] );
		$sealed                = [
			'subject' => $header['subject'],
			'restore' => $header['restore'],
			'undo'    => $header['undo'],
			'config'  => $header['config'],
			'changes' => $header['changes'],
			'refused' => $header['refused'],
		];
		$sealed[ $field ]      = $value;
		$apply->data['digest'] = $disk->seal( $sealed['subject'], $sealed['restore'], $sealed['undo'], $sealed['config'], $header['counts'], $sealed['changes'], $sealed['refused'], $header['required_bytes'], $header['actions'] );
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'The sealed file preview does not match the approved operation.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		$this->assert_unchanged();
	}

	public function test_a_background_apply_of_a_single_change_is_applied(): void {
		// The same files as the site has, and one that it has not.
		file_put_contents( $this->site->files . $this->site->live . '/a-existing', 'original' );
		TempDir::remove( $this->site->files . $this->site->live . '/b-new' );
		file_put_contents( $this->site->files . $this->site->live . '/c-new.txt', 'created' );
		$plan = $this->plan();
		self::assertSame( [ 1, 0 ], [ $plan->result['changes'], $plan->result['refused'] ] );
		self::assertSame( 1, $this->site->run_job( $this->apply( $plan ) )->result['files_changed'] );
		self::assertSame( 'created', file_get_contents( $this->site->live . '/c-new.txt' ) );
	}

	public function test_a_row_of_a_sealed_plan_that_names_no_destination_stops_the_apply_before_maintenance(): void {
		$plan                  = $this->plan();
		$apply                 = $this->apply( $plan );
		$apply->data['digest'] = $this->reseal_row(
			$plan,
			'create',
			static fn ( array $row ): array => [ 'destination' => null ] + $row
		);
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'The confirmed file change has no live destination.' ], [ $failed->error?->code, $failed->error?->message ] );
		$this->assert_unchanged();
	}

	public function test_a_background_apply_with_the_configuration_applies_it_and_one_that_lost_the_choice_applies_without(): void {
		$plan = $this->site->run_job( $this->site->queue_large_preview( true ) );
		$done = $this->site->run_job( $this->apply( $plan, true ) );
		self::assertSame( 4, $done->result['files_changed'] );
		self::assertSame( 'never publish', file_get_contents( $this->site->live . '/wp-config.php' ) );
		$this->site->undo_background( $done );
		self::assertFileDoesNotExist( $this->site->live . '/wp-config.php' );
		// A document without the choice is checked as one without the configuration.
		$plan  = $this->plan();
		$apply = $this->apply( $plan );
		unset( $apply->data['include_config'] );
		$this->site->jobs->save( $apply );
		self::assertSame( 3, $this->site->run_job( $apply )->result['files_changed'] );
		self::assertFileDoesNotExist( $this->site->live . '/wp-config.php' );
	}

	public function test_a_background_apply_uses_its_staging_after_the_hour_of_the_staging_has_passed(): void {
		$apply = $this->apply( $this->plan() );
		$this->site->clock->advance( 3601 );
		self::assertSame( 3, $this->site->run_job( $apply )->result['files_changed'] );
		self::assertSame( 'replacement', file_get_contents( $this->site->live . '/a-existing' ) );
	}

	/**
	 * Damaged values of the preflight's checkpoint in the document of a background apply, and
	 * how the preflight refuses each.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed, 3: string}>
	 */
	public static function damaged_checkpoints(): array {
		$cursor = 'The sealed file preview verification cursor is invalid.';
		$total  = 'The verified file-space total is invalid.';
		return [
			'a cursor below zero'             => [ 'progress', 'verified', -1, $cursor ],
			'a cursor beyond the plan'        => [ 'progress', 'verified', 'beyond', $cursor ],
			'a cursor that is no number'      => [ 'progress', 'verified', '1', $cursor ],
			'a space total below zero'        => [ 'data', 'verified_bytes', -1, $total ],
			'a space total that is no number' => [ 'data', 'verified_bytes', '0', $total ],
		];
	}

	#[DataProvider( 'damaged_checkpoints' )]
	public function test_a_background_preflight_refuses_a_damaged_checkpoint( string $part, string $key, mixed $value, string $message ): void {
		$plan  = $this->plan();
		$apply = $this->apply( $plan );
		$this->site->runner->tick( 0.0 ); // The first row of the plan is verified.
		$apply = $this->site->saved( $apply );
		self::assertSame( [ 'preflight', 1 ], [ $apply->step, $apply->progress['verified'] ] );
		$apply->{$part}[ $key ] = 'beyond' === $value ? $plan->result['entries'] + 1 : $value;
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', $message ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		$this->assert_unchanged();
	}

	public function test_a_background_preflight_checks_one_row_per_tick_that_has_used_its_budget_and_then_enters_maintenance(): void {
		$plan  = $this->plan();
		$apply = $this->apply( $plan );
		$this->site->runner->tick( 0.0 );
		self::assertSame( [ 'preflight', 1 ], [ $this->site->saved( $apply )->step, $this->site->saved( $apply )->progress['verified'] ] );
		$this->site->runner->tick( 0.0 );
		self::assertSame( [ 'preflight', 2 ], [ $this->site->saved( $apply )->step, $this->site->saved( $apply )->progress['verified'] ] );
		self::assertFileDoesNotExist( $this->site->live . '/.maintenance' );
		// A tick with time checks the rest, and the site is in maintenance before the next one.
		$this->site->runner->tick( 2.0 );
		$saved = $this->site->saved( $apply );
		self::assertSame( [ 'applying', $plan->result['entries'] ], [ $saved->step, $saved->progress['verified'] ] );
		self::assertFileExists( $this->site->live . '/.maintenance' );
		$this->assert_unchanged();
		$this->site->run_job( $apply );
	}

	public function test_a_background_preflight_checks_the_host_policy_and_the_free_space_before_maintenance(): void {
		$plan                = $this->plan();
		$apply               = $this->apply( $plan );
		$this->site->allowed = false;
		$failed              = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_writes_refused', 'preflight' ], [ $failed->error?->code, $this->site->failed_step( $failed ) ] );
		$this->site->allowed = true;
		$apply               = $this->apply( $plan );
		$this->site->free    = 0;
		$failed              = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_space', 'preflight' ], [ $failed->error?->code, $this->site->failed_step( $failed ) ] );
		// Neither entered maintenance.
		self::assertFileDoesNotExist( $this->site->jobs->directory( $apply ) . '/maintenance.sha256' );
		$this->assert_unchanged();
	}

	public function test_an_entry_that_the_site_maps_elsewhere_after_its_review_stops_the_preflight(): void {
		$this->site->stage_fresh_uploads();
		$restore                  = $this->site->jobs->get( $this->site->restore ) ?? self::fail( 'The restore is gone.' );
		$restore->result['files'] = DiskFilePlan::PAGE + 1;
		$this->site->jobs->save( $restore );
		$plan = $this->site->run_job( $this->site->changes->request_preview( $this->site->restore, false, false ) );
		// The uploads root changes between the review and the confirmation.
		$previous            = $this->site->uploads;
		$this->site->uploads = $this->site->live . '/elsewhere';
		mkdir( $this->site->uploads );
		$failed = $this->site->run_job( $this->apply( $plan ), JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'The confirmed destination mapping changed before work began.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( 'preflight', $this->site->failed_step( $failed ) );
		self::assertDirectoryDoesNotExist( $previous . '/album' );
		self::assertDirectoryDoesNotExist( $this->site->uploads . '/album' );
	}

	public function test_a_file_whose_content_is_not_the_one_its_plan_sealed_stops_the_preflight(): void {
		$plan  = $this->plan();
		$apply = $this->apply( $plan );
		// The sealed digest of the staged replacement differs from the file while the file's
		// identity is as reviewed, as after a write in place of the same size in the same second.
		$apply->data['digest'] = $this->reseal_row(
			$plan,
			'replace',
			static function ( array $row ): array {
				$digest        = (string) $row['source'];
				$row['source'] = substr( $digest, 0, -1 ) . ( 'f' === substr( $digest, -1 ) ? 'e' : 'f' );
				return $row;
			}
		);
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'A confirmed file changed before work began.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertSame( [ 0, 0 ], [ $failed->result['entries_completed'] ?? null, $failed->result['entries_attempted'] ?? null ] );
		$this->assert_unchanged();
	}

	public function test_a_folder_claim_of_a_sealed_plan_counts_only_with_the_authenticated_row_of_that_folder(): void {
		// A second folder that the apply creates beside the first.
		mkdir( $this->site->files . $this->site->live . '/c-new', 0700 );
		$plan  = $this->plan();
		$apply = $this->apply( $plan );
		$disk  = new DiskFilePlan( $this->directory( $plan ) );
		$disk->open( $plan->result['digest'] );
		$other = $disk->lookup( 'mkdir', $this->site->live . '/c-new' );
		self::assertIsInt( $other );
		// The claims are not part of the seal: the claim of b-new names the row of c-new.
		$key    = hash( 'sha256', "mkdir\0" . $this->site->live . '/b-new' );
		$bucket = $this->directory( $plan ) . '/key-' . substr( $key, 0, 3 );
		$claims = json_decode( (string) file_get_contents( $bucket ), true, flags: JSON_THROW_ON_ERROR );
		self::assertArrayHasKey( $key, $claims );
		$claims[ $key ] = $other;
		file_put_contents( $bucket, (string) json_encode( $claims, JSON_THROW_ON_ERROR ) );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_changed_after_review', 'A parent directory created by this apply changed.' ], [ $failed->error?->code, $failed->error?->message ] );
		self::assertFileDoesNotExist( $this->site->live . '/b-new/new.txt' );
		self::assertFileDoesNotExist( $this->site->live . '/c-new/new.txt' );
	}

	public function test_a_background_apply_names_its_plan_by_the_id_of_a_job(): void {
		$plan                    = $this->plan();
		$apply                   = $this->apply( $plan );
		$apply->data['plan_job'] = '../../' . Bytes::base64url_encode( $plan->id );
		$this->site->jobs->save( $apply );
		$failed = $this->site->run_job( $apply, JobState::Failed );
		self::assertSame( [ 'file_apply_failed', 'This is not a file preview job ID.' ], [ $failed->error?->code, $failed->error?->message ] );
		$this->assert_unchanged();
	}
}
