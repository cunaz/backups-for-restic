<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Jobs\JobError;
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
}
