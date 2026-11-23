<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Settings\Settings;

/**
 * Step lists of every job type and the error codes jobs report.
 */
final class JobTypeAndErrorTest extends TestCase {

	/**
	 * @return iterable<string, array{JobType}>
	 */
	public static function types(): iterable {
		foreach ( JobType::cases() as $type ) {
			yield $type->value => [ $type ];
		}
	}

	#[DataProvider( 'types' )]
	public function test_every_type_ends_with_cleanup_and_walks_its_steps_in_order( JobType $type ): void {
		$all = array_merge( $type->steps(), $type->cleanup_steps() );
		self::assertSame( 'cleaning_up', end( $all ) );
		self::assertCount( count( $all ), array_unique( $all ), 'No step appears twice.' );
		$walk = [ $all[0] ];
		$next = $type->next_step( $all[0] );
		while ( null !== $next ) {
			$walk[] = $next;
			$next   = $type->next_step( $next );
		}
		self::assertSame( $all, $walk );
	}

	public function test_maintenance_types_detect_append_only_before_deleting(): void {
		self::assertSame( [ 'preflight', 'unlocking', 'detecting', 'forgetting', 'recording' ], JobType::Forget->steps() );
		self::assertSame( [ 'preflight', 'unlocking', 'detecting', 'pruning', 'recording' ], JobType::Prune->steps() );
		self::assertSame( [ 'preflight', 'unlocking', 'checking', 'recording' ], JobType::Check->steps() );
		self::assertTrue( JobType::Check->is_maintenance() );
		self::assertFalse( JobType::Backup->is_maintenance() );
		self::assertContains( 'releasing', JobType::Prune->cleanup_steps(), 'An aborted prune releases its exclusive lock.' );
	}

	public function test_backups_and_maintenance_wait_for_the_completed_setup(): void {
		$repository = [ 'repository.url' => 'https://backup.example.net/' ];
		$during     = Settings::from_stored( $repository );
		foreach ( [ JobType::Backup, JobType::Forget, JobType::Prune, JobType::Check ] as $type ) {
			self::assertSame( 'setup_incomplete', $type->refusal( $during ), $type->value . ': no job before the recovery kit is acknowledged.' );
		}
		self::assertNull( JobType::Unlock->refusal( $during ), 'The wizard unlocks an existing repository before the setup is complete.' );

		$after = Settings::from_stored( $repository + [ 'setup.completed' => true ] );
		foreach ( JobType::cases() as $type ) {
			self::assertNull( $type->refusal( $after ), $type->value );
		}
	}

	public function test_a_completed_setup_still_needs_a_repository_and_respects_external_retention(): void {
		self::assertSame( 'configuration', JobType::Backup->refusal( Settings::from_stored( [ 'setup.completed' => true ] ) ) );

		$external = Settings::from_stored(
			[
				'repository.url'  => 'https://backup.example.net/',
				'setup.completed' => true,
				'retention.mode'  => 'external',
			]
		);
		self::assertSame( 'retention_external', JobType::Forget->refusal( $external ) );
		self::assertSame( 'retention_external', JobType::Prune->refusal( $external ) );
		self::assertNull( JobType::Check->refusal( $external ), 'The check never deletes.' );
		self::assertNull( JobType::Backup->refusal( $external ) );
	}

	/**
	 * @return iterable<string, array{\Throwable, string}>
	 */
	public static function errors(): iterable {
		yield 'append-only' => [ new AppendOnlyRefused( 'refused' ), 'repository_append_only' ];
		yield 'an index beyond the memory limit' => [ new IndexTooLarge( 'too large' ), 'index_too_large' ];
		yield 'damage' => [ new FormatException( 'damaged', [ 'code' => 'repository_damaged' ] ), 'repository_damaged' ];
		yield 'online file review capacity' => [ new InvalidArgument( 'too large', [ 'code' => 'file_plan_too_large' ] ), 'file_plan_too_large' ];
		yield 'no snapshots' => [ new InvalidArgument( 'empty', [ 'code' => 'no_snapshots' ] ), 'no_snapshots' ];
		yield 'a protected location without a plain path' => [ new InvalidArgument( 'invalid', [ 'code' => 'protected_path_invalid' ] ), 'protected_path_invalid' ];
		yield 'other context codes do not override' => [ new InvalidArgument( 'bad', [ 'code' => 'something_else' ] ), 'configuration' ];
	}

	#[DataProvider( 'errors' )]
	public function test_error_codes( \Throwable $error, string $code ): void {
		self::assertSame( $code, JobError::from_throwable( $error )->code );
	}

	/**
	 * One exception of every concrete class of the plugin, without a code in its context.
	 *
	 * @return list<PluginException>
	 */
	private static function every_exception(): array {
		$found = [];
		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/src/Exception/*.php' ) as $file ) {
			$class = 'BackupsForRestic\\Exception\\' . basename( $file, '.php' );
			$type  = new \ReflectionClass( $class );
			if ( $type->isSubclassOf( PluginException::class ) && ! $type->isAbstract() ) {
				$found[] = $type->newInstance( 'Some failure.' );
			}
		}
		self::assertGreaterThan( 20, count( $found ) );
		return $found;
	}

	public function test_no_exception_without_a_code_ends_a_file_job_with_the_text_of_a_backup_failure(): void {
		$expected = [
			'file_plan' => 'file_preview_failed',
			'apply'     => 'file_apply_failed',
			'undo'      => 'file_undo_failed',
		];
		self::assertSame( $expected, JobError::FILE_JOB_CODES );
		foreach ( [ JobType::FilePlan, JobType::Apply, JobType::Undo ] as $type ) {
			foreach ( self::every_exception() as $error ) {
				self::assertSame( $expected[ $type->value ], JobError::from_throwable( $error, $type )->code, $type->value . ': ' . $error::class );
			}
		}
		// Before: "Another backup job is still running" and "This server cannot run the backup".
		self::assertSame( 'job_conflict', JobError::from_throwable( new JobConflict( 'changed' ) )->code );
		self::assertSame( 'environment', JobError::from_throwable( new StorageFailed( 'changed' ) )->code );
		// The other jobs keep the codes of the classes.
		foreach ( [ JobType::Backup, JobType::Restore, JobType::DatabaseApply ] as $type ) {
			self::assertSame( 'job_conflict', JobError::from_throwable( new JobConflict( 'busy' ), $type )->code, $type->value );
			self::assertSame( 'environment', JobError::from_throwable( new StorageFailed( 'full' ), $type )->code, $type->value );
		}
		// An error that is no exception of the plugin is a bug, in any job.
		self::assertSame( 'internal', JobError::from_throwable( new \TypeError( 'bug' ), JobType::Apply )->code );
	}

	/**
	 * @return iterable<string, array{PluginException, JobType, string}>
	 */
	public static function file_job_codes(): iterable {
		yield 'a file changed after the review' => [ new JobConflict( 'A destination or staged file changed after confirmation.', [ 'code' => 'file_changed_after_review' ] ), JobType::Apply, 'file_changed_after_review' ];
		yield 'a live file changed during the apply' => [ new StorageFailed( 'The live file no longer matches the reviewed original.', [ 'code' => 'file_changed_after_review' ] ), JobType::Apply, 'file_changed_after_review' ];
		yield 'the staging is gone' => [ new InvalidArgument( 'This restore has no eligible staged files.', [ 'code' => 'file_staging_gone' ] ), JobType::FilePlan, 'file_staging_gone' ];
		yield 'the undo window has ended' => [ new JobConflict( 'This apply has no available undo.', [ 'code' => 'undo_unavailable' ] ), JobType::FilePlan, 'undo_unavailable' ];
		yield 'another updater holds the maintenance mode' => [ new StorageFailed( 'Another updater owns the maintenance marker.', [ 'code' => 'maintenance_busy' ] ), JobType::Apply, 'maintenance_busy' ];
		yield 'the maintenance mode cannot be kept' => [ new StorageFailed( 'The active WordPress maintenance marker has changed.', [ 'code' => 'maintenance_failed' ] ), JobType::Undo, 'maintenance_failed' ];
		yield 'the site stays in maintenance mode' => [ new StorageFailed( 'The site could not leave maintenance mode.', [ 'code' => 'maintenance_stuck' ] ), JobType::Undo, 'maintenance_stuck' ];
		yield 'a preview that expired' => [ new JobConflict( 'This file preview is unavailable, expired or has a different scope.', [ 'code' => 'file_preview_expired' ] ), JobType::Apply, 'file_preview_expired' ];
		yield 'the file modification policy' => [ new JobConflict( 'refused', [ 'code' => 'file_writes_refused' ] ), JobType::Undo, 'file_writes_refused' ];
	}

	#[DataProvider( 'file_job_codes' )]
	public function test_a_code_in_the_context_names_what_ended_a_file_job( PluginException $error, JobType $type, string $code ): void {
		$classified = JobError::from_throwable( $error, $type );
		self::assertSame( $code, $classified->code );
		self::assertFalse( $classified->retryable );
		self::assertSame( $error->getMessage(), $classified->message );
	}

	public function test_the_code_of_a_file_job_does_not_change_whether_the_runner_tries_again(): void {
		foreach ( [ new BackendException( 'timeout' ), new LockConflict( 'held' ) ] as $error ) {
			$plain = JobError::from_throwable( $error );
			$file  = JobError::from_throwable( $error, JobType::Apply );
			self::assertTrue( $plain->retryable );
			self::assertSame( $plain->retryable, $file->retryable, $error::class );
			self::assertSame( 'file_apply_failed', $file->code );
		}
	}

	public function test_sessions_that_outlast_maintenance_are_retried_but_a_hidden_session_list_is_not(): void {
		$sessions = JobError::from_throwable( new DatabaseFailed( 'open', [ 'code' => 'database_exchange_sessions' ] ) );
		self::assertSame( 'database_exchange_sessions', $sessions->code );
		self::assertTrue( $sessions->retryable );
		$hidden = JobError::from_throwable( new DatabaseFailed( 'hidden', [ 'code' => 'performance_schema' ] ) );
		self::assertSame( 'performance_schema', $hidden->code );
		self::assertFalse( $hidden->retryable );
	}
}
