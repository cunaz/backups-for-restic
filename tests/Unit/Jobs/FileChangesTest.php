<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;
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

	protected function setUp(): void {
		$this->directory = TempDir::create( 'apply-job' );
		$this->live      = $this->directory . '/live';
		mkdir( $this->live );
		$this->clock         = new FrozenClock();
		$random              = new SystemRandom();
		$this->jobs          = new JobStore( $this->directory . '/jobs', new Redactor(), $random );
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
				$layout = SiteLayout::from_job( $restore, $this->live, $uploads ?? $this->uploads );
				return new ApplyPlan( [ $this->live ], [ $this->live . '/.maintenance', $this->live . '/plugins/active' ], [ $this->live . '/wp-config.php' ], $config, $layout );
			},
			new FileMaintenance( $this->live ),
			fn (): bool => $this->allowed,
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

	private function undo( Job $apply ): void {
		$plan = $this->changes->preview_undo( $apply->id );
		self::assertTrue( $plan['applicable'] );
		$this->run_job( $this->changes->start_undo( $apply->id, $plan['digest'] ) );
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
		self::assertFalse( $this->changes->preview_undo( $job->id )['applicable'] );
	}

	public function test_edited_files_and_additional_directory_children_block_undo(): void {
		$job      = $this->run_job( $this->start() );
		$approved = $this->changes->preview_undo( $job->id );
		file_put_contents( $this->live . '/b-new/new-work', 'keep external changes' );
		self::assertFalse( $this->changes->preview_undo( $job->id )['applicable'] );
		unlink( $this->live . '/b-new/new-work' );
		file_put_contents( $this->live . '/a-existing', 'keep edit' );
		$this->expectException( JobConflict::class );
		$this->changes->start_undo( $job->id, $approved['digest'] );
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
		$this->run_job( $job, JobState::Failed );
		self::assertSame( 'original', file_get_contents( $this->live . '/a-existing' ) );
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
		self::assertFalse( $this->changes->preview_undo( $job->id )['applicable'] );
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

	public function test_background_undo_preflight_preserves_new_directory_children_before_its_first_write(): void {
		$plan   = $this->run_job( $this->queue_large_preview() );
		$apply  = $this->run_job( $this->changes->start_apply( $this->restore, false, $plan->result['digest'], '', $plan->id ) );
		$review = $this->changes->request_preview( $apply->id, true, false );
		self::assertInstanceOf( Job::class, $review );
		$review = $this->run_job( $review );
		$undo   = $this->changes->start_undo( $apply->id, $review->result['digest'], '', $review->id );
		file_put_contents( $this->live . '/b-new/added-after-review', 'independent data' );
		$this->run_job( $undo, JobState::Failed );
		self::assertFileExists( $this->live . '/b-new/new.txt' );
		self::assertSame( 'independent data', file_get_contents( $this->live . '/b-new/added-after-review' ) );
		self::assertSame( 'replacement', file_get_contents( $this->live . '/a-existing' ) );
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
}
