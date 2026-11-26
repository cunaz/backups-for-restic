<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\WordPress\Messages;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Every code that ends a job has a message and a hint of its own, and the texts of file
 * previews, applies and undos say what happened to the files and what to do next. Messages
 * calls WordPress's __(), so each test loads a stub in a process of its own.
 */
final class JobMessagesTest extends TestCase {

	/**
	 * Message and hint of a job error, joined.
	 *
	 * @param string $code Job error code.
	 */
	private static function text( string $code ): string {
		require_once dirname( __DIR__, 2 ) . '/Fixtures/WordPress/translate.php';
		$texts = Messages::job_error( $code );
		return $texts['message'] . ' ' . $texts['hint'];
	}

	/**
	 * Every code that JobError can give: the codes of the exception classes, the codes it
	 * takes from a context, and the codes of a failed file job.
	 *
	 * @return list<string> Codes.
	 */
	private static function codes(): array {
		$codes = array_values( JobError::FILE_JOB_CODES );
		foreach ( (array) ( new \ReflectionClassConstant( JobError::class, 'CONTEXT_CODES' ) )->getValue() as $code ) {
			$codes[] = (string) $code;
		}
		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/src/Exception/*.php' ) as $file ) {
			$class = new \ReflectionClass( 'BackupsForRestic\\Exception\\' . basename( $file, '.php' ) );
			if ( $class->isSubclassOf( PluginException::class ) && ! $class->isAbstract() ) {
				$codes[] = JobError::from_throwable( $class->newInstance( 'Some failure.' ) )->code;
			}
		}
		return array_values( array_diff( array_unique( $codes ), [ 'internal' ] ) );
	}

	#[RunInSeparateProcess]
	public function test_every_code_of_a_job_error_has_a_message_and_a_hint_of_its_own(): void {
		$unexpected = self::text( 'internal' );
		$codes      = self::codes();
		self::assertGreaterThan( 50, count( $codes ) );
		foreach ( $codes as $code ) {
			$texts = Messages::job_error( $code );
			self::assertNotSame( $unexpected, $texts['message'] . ' ' . $texts['hint'], $code . ' has no text of its own.' );
			self::assertNotSame( '', $texts['hint'], $code );
		}
	}

	#[RunInSeparateProcess]
	public function test_file_jobs_say_what_happened_to_the_files_and_never_speak_of_a_backup(): void {
		foreach ( [ 'file_changed_after_review', 'file_apply_failed', 'file_undo_failed', 'file_preview_failed', 'file_staging_gone', 'file_preview_expired', 'undo_unavailable', 'maintenance_busy', 'maintenance_failed', 'maintenance_stuck' ] as $code ) {
			$text = self::text( $code );
			self::assertDoesNotMatchRegularExpression( '/backup job|run the backup/i', $text, $code );
		}
		self::assertStringContainsString( 'changed after the review, so the apply stopped', self::text( 'file_changed_after_review' ) );
		self::assertStringContainsString( 'Open a new preview and confirm it again.', self::text( 'file_changed_after_review' ) );
		self::assertStringContainsString( 'can be undone on the Restores tab', self::text( 'file_changed_after_review' ) );
		self::assertStringContainsString( 'The log of the job names the reason.', self::text( 'file_apply_failed' ) );
		self::assertStringContainsString( 'can be undone on the Restores tab', self::text( 'file_apply_failed' ) );
		self::assertStringContainsString( 'What the undo reverted stays reverted.', self::text( 'file_undo_failed' ) );
		self::assertStringContainsString( 'Nothing on the live site was changed.', self::text( 'file_preview_failed' ) );
		self::assertStringContainsString( 'Restore the snapshot to staging again', self::text( 'file_staging_gone' ) );
		self::assertStringContainsString( 'one hour after it was finished', self::text( 'file_preview_expired' ) );
		self::assertStringContainsString( '24 hours after it ended', self::text( 'undo_unavailable' ) );
		self::assertStringContainsString( 'did not start', self::text( 'maintenance_busy' ) );
		self::assertStringContainsString( 'delete the file .maintenance', self::text( 'maintenance_busy' ) );
		self::assertStringContainsString( 'could not leave maintenance mode', self::text( 'maintenance_stuck' ) );
		self::assertStringContainsString( 'at most ten minutes', self::text( 'maintenance_stuck' ) );
	}

	#[RunInSeparateProcess]
	public function test_a_locked_or_unreachable_repository_promises_no_retry_that_the_runner_does_not_make(): void {
		foreach ( [ 'repository_locked', 'repository_unreachable' ] as $code ) {
			// A job's error is shown once the runner has stopped: after its last attempt, at once
			// in a cleanup step, and never while it waits for the next attempt.
			$job = self::text( $code );
			self::assertDoesNotMatchRegularExpression( '/tries again automatically|will try again|next attempt/i', $job, $code );
			self::assertStringContainsString( 'The job has stopped and does not try again by itself.', $job, $code );
			// A request, such as reading the snapshots or testing a server, is not tried again either.
			$request = Messages::snapshot_error( $code );
			$request = $request['message'] . ' ' . $request['hint'];
			self::assertDoesNotMatchRegularExpression( '/tries again automatically|The job/i', $request, $code );
			self::assertStringContainsStringIgnoringCase( 'try again', $request, $code );
		}
		self::assertStringContainsString( 'a stale lock expires 30 minutes after it was last refreshed', self::text( 'repository_locked' ) );
		self::assertStringContainsString( 'outgoing HTTPS connections to the server and its port', self::text( 'repository_unreachable' ) );
	}

	/**
	 * The codes that each kind of job can end with, by what its steps can raise: the table of
	 * the codes against the kinds of jobs. A file preview, apply and undo end with codes of
	 * their context or their own (JobError::FILE_JOB_CODES), never with a code of a class; a
	 * database exchange wraps the database's errors in codes of its own.
	 */
	private const array KINDS = [
		'backup'         => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'repository_locked', 'repository_changed', 'index_too_large', 'quota_exceeded', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_exists', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'database_dump_failed', 'configuration', 'repository_damaged', 'decompression_limit', 'key_missing', 'database_dump_session', 'database_dump_unstable', 'database_dump_row_memory', 'database_dump_locked', 'internal' ],
		'unlock'         => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'configuration', 'repository_damaged', 'internal' ],
		'forget'         => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'repository_locked', 'index_too_large', 'quota_exceeded', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_exists', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'configuration', 'repository_damaged', 'decompression_limit', 'key_missing', 'internal' ],
		'prune'          => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'repository_locked', 'repository_changed', 'no_snapshots', 'index_too_large', 'quota_exceeded', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_exists', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'configuration', 'repository_damaged', 'decompression_limit', 'key_missing', 'internal' ],
		'check'          => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'repository_locked', 'repository_changed', 'index_too_large', 'quota_exceeded', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_exists', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'configuration', 'repository_damaged', 'decompression_limit', 'key_missing', 'internal' ],
		'restore'        => [ 'wrong_password', 'authentication_failed', 'repository_unsupported_version', 'repository_locked', 'repository_changed', 'index_too_large', 'quota_exceeded', 'upload_rejected', 'unauthorized', 'repository_missing', 'repository_exists', 'repository_unreachable', 'secrets_undecryptable', 'environment', 'database_dump_failed', 'configuration', 'repository_damaged', 'decompression_limit', 'key_missing', 'setup_incomplete', 'restore_snapshot_missing', 'staging_unverified', 'staging_space', 'staging_write', 'restore_path_missing', 'database_preview_missing', 'database_restore_prefix', 'database_restore_collation', 'database_restore_privilege', 'database_restore_format', 'database_restore_failed', 'database_restore_packet', 'database_restore_busy', 'internal' ],
		'file_plan'      => [ 'file_preview_failed', 'file_staging_gone', 'file_plan_too_large', 'file_folder_changing', 'protected_path_invalid', 'internal' ],
		'undo_review'    => [ 'file_preview_failed', 'file_staging_gone', 'file_plan_too_large', 'protected_path_invalid', 'undo_unavailable', 'undo_records_unreadable', 'internal' ],
		'apply'          => [ 'file_apply_failed', 'file_changed_after_review', 'file_staging_gone', 'file_plan_too_large', 'file_folder_changing', 'file_space', 'file_writes_refused', 'maintenance_opcache', 'maintenance_busy', 'maintenance_failed', 'maintenance_stuck', 'protected_path_invalid', 'internal' ],
		'undo'           => [ 'file_undo_failed', 'file_staging_gone', 'file_writes_refused', 'maintenance_opcache', 'maintenance_busy', 'maintenance_failed', 'maintenance_stuck', 'protected_path_invalid', 'undo_unavailable', 'undo_records_unreadable', 'undo_review_required', 'internal' ],
		'database_apply' => [ 'database_exchange_failed', 'database_exchange_cleanup', 'database_exchange_changed', 'database_exchange_sessions', 'performance_schema', 'database_exchange_foreign', 'database_review_required', 'database_restore_packet', 'database_restore_busy', 'database_restore_prefix', 'database_preview_missing', 'database_admin_collision', 'database_admin_capabilities', 'database_admin_capabilities_unreadable', 'database_roles_invalid', 'database_roles_too_large', 'database_plugins_invalid', 'database_plugins_too_large', 'database_wordpress_version', 'environment', 'configuration', 'internal' ],
		'database_undo'  => [ 'database_exchange_failed', 'database_exchange_cleanup', 'database_exchange_changed', 'database_exchange_sessions', 'performance_schema', 'database_exchange_foreign', 'database_restore_busy', 'database_admin_collision', 'database_admin_capabilities', 'database_admin_capabilities_unreadable', 'database_roles_invalid', 'database_roles_too_large', 'database_plugins_invalid', 'database_plugins_too_large', 'environment', 'configuration', 'internal' ],
	];

	/**
	 * What the text of a code must not say for a kind of job: the backup or another job, or an
	 * action that the job does not have.
	 *
	 * @return list<string> Phrases, compared without case.
	 */
	private static function foreign( string $kind ): array {
		$phrases = [ 'creating a new one' ];
		if ( 'restore' !== $kind ) {
			$phrases[] = 'and restore the snapshot';
		}
		if ( 'backup' !== $kind ) {
			array_push( $phrases, 'the backup is not configured', 'cannot run the backup', 'the next backup', 'another backup job' );
		}
		if ( 'restore' === $kind ) {
			$phrases[] = 'started over';
		}
		if ( 'undo' === $kind ) {
			array_push( $phrases, 'changes already made can be undone', 'so the apply stopped', 'restore the snapshot to staging again' );
		}
		if ( 'undo_review' === $kind ) {
			array_push( $phrases, 'restore the snapshot to staging again', 'review a smaller selection' );
		}
		if ( 'database_undo' === $kind ) {
			array_push( $phrases, 'staged sql files', 'manual sql restore', 'before another database restore', 'new rehearsal' );
		}
		return $phrases;
	}

	#[RunInSeparateProcess]
	public function test_the_text_of_a_failed_job_names_that_job_and_no_other(): void {
		self::text( 'internal' );
		$kinds = array_map( static fn ( JobType $type ): string => $type->value, JobType::cases() );
		self::assertSame( [], array_values( array_diff( [ ...$kinds, 'undo_review' ], array_keys( self::KINDS ) ) ), 'Every kind of job is in the table.' );
		$unexpected = Messages::job_error( 'internal' );
		$wrong      = [];
		foreach ( self::KINDS as $kind => $codes ) {
			foreach ( $codes as $code ) {
				$texts = Messages::job_error( $code, $kind );
				$text  = strtolower( $texts['message'] . ' ' . $texts['hint'] );
				if ( 'internal' !== $code && $texts === $unexpected ) {
					$wrong[] = $kind . ' × ' . $code . ': no text of its own';
				}
				foreach ( self::foreign( $kind ) as $phrase ) {
					if ( str_contains( $text, $phrase ) ) {
						$wrong[] = $kind . ' × ' . $code . ': "' . $phrase . '"';
					}
				}
			}
		}
		self::assertSame( [], $wrong );
		// Without a kind of job, the texts of a request and of a backup stay as they were.
		self::assertSame( 'The backup is not configured completely.', Messages::job_error( 'configuration' )['message'] );
		self::assertSame( Messages::job_error( 'environment' ), Messages::job_error( 'environment', 'backup' ) );
		self::assertStringContainsString( 'Connect to it with its password instead of creating a new one.', Messages::job_error( 'repository_exists' )['hint'] );
	}

	#[RunInSeparateProcess]
	public function test_an_apply_whose_undo_records_cannot_be_read_is_not_said_to_be_past_its_window(): void {
		self::text( 'internal' );
		$texts = Messages::job_error( 'undo_records_unreadable' );
		self::assertSame( 'The plugin cannot tell whether this apply can still be undone.', $texts['message'] );
		self::assertStringContainsString( 'keeps the saved original files and the staged files', $texts['hint'] );
		self::assertStringContainsString( 'offers the undo again once it can read the records', $texts['hint'] );
		self::assertNotSame( Messages::job_error( 'undo_unavailable' ), $texts );
		self::assertSame( $texts, Messages::job_error( 'undo_records_unreadable', 'undo' ) );
	}

	#[RunInSeparateProcess]
	public function test_a_stored_job_that_the_php_user_cannot_open_is_named_with_its_cause_and_way_out(): void {
		self::text( 'internal' );
		$id    = '20260101T000000Z-0a1b2c';
		$named = Messages::stored_job( 'job_inaccessible', $id );
		// Before, this job was refused as job_unreadable, which neither named it nor said what to do.
		self::assertSame( 'The PHP user cannot open the stored job 20260101T000000Z-0a1b2c in the private working directory.', $named['message'] );
		foreach ( [ 'belongs to another user or lacks read or search permission', 'WP-CLI command that ran as root', 'Give the PHP user back ownership of that folder', 'a link or something else that the plugin did not write', 'new jobs and repository changes stay blocked' ] as $part ) {
			self::assertStringContainsString( $part, $named['hint'] );
		}
		self::assertStringNotContainsString( 'plugin version', $named['message'] . ' ' . $named['hint'] );
		$general = Messages::job_error( 'job_inaccessible' );
		self::assertSame( 'The PHP user cannot open a stored job in the private working directory.', $general['message'] );
		self::assertSame( $named['hint'], $general['hint'] );
		// A document that this version cannot parse keeps its texts, which ask for another version.
		self::assertSame( Messages::job_error( 'job_unreadable' ), Messages::stored_job( 'job_unreadable', $id ) );
		self::assertStringContainsString( 'Install a plugin version that can read the job.', Messages::stored_job( 'job_unreadable', $id )['hint'] );
	}

	#[RunInSeparateProcess]
	public function test_a_folder_that_keeps_changing_is_named_in_the_log_and_paused_for_a_new_review(): void {
		self::text( 'internal' );
		$preview = Messages::job_error( 'file_folder_changing', 'file_plan' );
		self::assertSame( 'A folder kept changing while the preview read its names.', $preview['message'] );
		self::assertStringContainsString( 'The log of the job names the folder.', $preview['hint'] );
		self::assertStringContainsString( 'Nothing on the live site was changed.', $preview['hint'] );
		$apply = Messages::job_error( 'file_folder_changing', 'apply' );
		self::assertSame( 'A folder kept changing while the apply checked the reviewed changes again.', $apply['message'] );
		self::assertStringContainsString( 'open a new preview and confirm it again', $apply['hint'] );
		self::assertStringContainsString( 'Nothing on the live site was changed.', $apply['hint'] );
	}

	#[RunInSeparateProcess]
	public function test_a_restore_that_loses_its_key_its_snapshot_or_its_setup_says_so(): void {
		self::text( 'internal' );
		$key = Messages::job_error( 'key_missing', 'restore' );
		self::assertSame( 'The repository key that this job unlocked is no longer stored.', $key['message'] );
		self::assertStringContainsString( 'Start the job again: it unlocks the repository with the stored password.', $key['hint'] );
		self::assertSame( $key, Messages::job_error( 'key_missing', 'prune' ) );
		$snapshot = Messages::job_error( 'restore_snapshot_missing', 'restore' );
		self::assertSame( 'The snapshot of this restore is not in the repository.', $snapshot['message'] );
		self::assertStringContainsString( 'The restore stopped and kept no files.', $snapshot['hint'] );
		self::assertStringContainsString( 'Backups, restores and maintenance start after that.', Messages::job_error( 'setup_incomplete', 'restore' )['hint'] );
		// A restore takes up its work again after a lock that did not hold; it never starts over.
		self::assertStringContainsString( 'took up its work again three times', Messages::job_error( 'repository_changed', 'restore' )['hint'] );
		self::assertStringContainsString( 'started over three times', Messages::job_error( 'repository_changed', 'prune' )['hint'] );
		// A file of the private directory or the site's database, not a server that cannot run a backup.
		self::assertSame( 'This job could not store or read its working data.', Messages::job_error( 'environment', 'restore' )['message'] );
		self::assertSame( 'This server cannot run this job right now.', Messages::job_error( 'environment', 'check' )['message'] );
	}

	#[RunInSeparateProcess]
	public function test_a_failed_job_is_described_as_its_kind_and_a_background_undo_review_as_one(): void {
		self::text( 'internal' );
		$job        = new Job( '20261126T120000Z-0c0e10', JobType::FilePlan, 'manual', 1_800_000_000 );
		$job->state = JobState::Failed;
		$job->error = new JobError( 'file_staging_gone', 'This restore has no eligible staged files.', false );
		// The preview of an apply: restoring the snapshot again is the way on.
		self::assertSame( 'file_plan', Messages::kind( $job ) );
		self::assertStringContainsString( 'Restore the snapshot to staging again', Messages::job_failure( $job )['hint'] );
		// The review of an undo: a new restore would not be the one that the apply came from.
		$job->data = [ 'undo' => true ];
		self::assertSame( 'undo_review', Messages::kind( $job ) );
		self::assertStringContainsString( 'Without them this apply cannot be undone.', Messages::job_failure( $job )['hint'] );
		$job->error = null;
		self::assertSame( Messages::job_error( 'internal' ), Messages::job_failure( $job ) );
	}
}
