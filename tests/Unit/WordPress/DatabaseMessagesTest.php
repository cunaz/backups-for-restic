<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\WordPress\Messages;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Texts of the database restore that say what happened and what to do next. Messages calls
 * WordPress's __(), so each test loads a stub in a process of its own.
 */
final class DatabaseMessagesTest extends TestCase {

	/**
	 * Message and hint of a job error, joined.
	 *
	 * @param string $code Job error code.
	 */
	private static function text( string $code, string $type = '' ): string {
		require_once dirname( __DIR__, 2 ) . '/Fixtures/WordPress/translate.php';
		$texts = Messages::job_error( $code, $type );
		return $texts['message'] . ' ' . $texts['hint'];
	}

	#[RunInSeparateProcess]
	public function test_errors_that_are_retried_are_shown_only_after_the_last_attempt_and_say_so(): void {
		foreach ( [ 'database_exchange_sessions', 'database_restore_busy' ] as $code ) {
			$text = self::text( $code );
			self::assertStringContainsString( 'stopped', $text, $code );
			self::assertDoesNotMatchRegularExpression( '/tries again|retries|next attempt/', $text, $code );
		}
		self::assertStringContainsString( 'review and confirm the database change again', self::text( 'database_exchange_sessions' ) );
	}

	#[RunInSeparateProcess]
	public function test_the_packet_hint_says_how_to_get_the_sql_and_which_limits_count(): void {
		$text = self::text( 'database_restore_packet' );
		self::assertStringContainsString( 'stage the snapshot’s database directory again with the rehearsal switched off', $text );
		self::assertStringContainsString( 'the importing client and the database server both need', $text );
		self::assertStringContainsString( 'When the plugin refused a row batch before sending it, the job log names the limit', $text );
	}

	#[RunInSeparateProcess]
	public function test_the_foreign_binding_hint_names_the_command_first_and_says_it_drops_nothing(): void {
		$text = self::text( 'database_exchange_foreign' );
		self::assertStringContainsString( 'the database, the server UUID and the table names', $text );
		self::assertStringContainsString( 'without dropping anything', $text );
		self::assertLessThan( (int) strpos( $text, 'by hand only where you can verify them' ), (int) strpos( $text, 'database-discard' ) );
	}

	#[RunInSeparateProcess]
	public function test_an_incomplete_session_list_names_what_the_server_needs(): void {
		$text = self::text( 'performance_schema' );
		self::assertStringContainsString( 'does not list every connection', $text );
		self::assertStringContainsString( 'performance_schema_max_thread_instances', $text );
		self::assertStringContainsString( 'PROCESS', $text );
	}

	#[RunInSeparateProcess]
	public function test_an_undo_points_to_the_retained_tables_and_a_restore_to_the_staged_sql(): void {
		foreach ( [ 'database_admin_capabilities', 'database_roles_invalid', 'database_roles_too_large', 'database_plugins_invalid', 'database_plugins_too_large' ] as $code ) {
			$undo = self::text( $code, 'database_undo' );
			self::assertStringContainsString( 'the earlier database', $undo, $code );
			self::assertStringContainsString( 'Until the undo window ends, a database administrator can recover the earlier tables', $undo, $code );
			self::assertStringNotContainsString( 'staged SQL', $undo, $code );
			$apply = self::text( $code, 'database_apply' );
			self::assertStringContainsString( 'the backup', $apply, $code );
			self::assertStringContainsString( 'staged SQL files', $apply, $code );
			self::assertSame( $apply, self::text( $code ), $code );
		}
		self::assertStringContainsString( 'Give your account on this site a role that the earlier database also has', self::text( 'database_admin_capabilities', 'database_undo' ) );
		// Codes without an undo text of their own keep the restore's.
		self::assertSame( self::text( 'database_exchange_changed' ), self::text( 'database_exchange_changed', 'database_undo' ) );
	}

	#[RunInSeparateProcess]
	public function test_a_value_beyond_its_bound_is_named_with_the_bound(): void {
		foreach ( [ 'database_apply', 'database_undo' ] as $type ) {
			self::assertStringContainsString( 'larger than 1 MiB', self::text( 'database_roles_too_large', $type ) );
			self::assertStringContainsString( 'larger than 256 KiB', self::text( 'database_plugins_too_large', $type ) );
			// Also refused: a list that this plugin's entry would take beyond the bound.
			self::assertStringContainsString( 'or would be with Backups for restic added to it', self::text( 'database_plugins_too_large', $type ) );
		}
		$record = self::text( 'database_admin_capabilities_unreadable' );
		self::assertStringContainsString( 'stored more than once, longer than 64 KiB', $record );
		self::assertSame( $record, self::text( 'database_admin_capabilities_unreadable', 'database_undo' ) );
	}

	#[RunInSeparateProcess]
	public function test_every_answer_of_a_discard_has_its_own_text_and_only_a_database_failure_points_to_a_log(): void {
		require_once dirname( __DIR__, 2 ) . '/Fixtures/WordPress/translate.php';
		$general = Messages::job_error( 'internal' );
		foreach ( [ ...DatabaseChanges::DISCARD_REFUSALS, ...DatabaseChanges::DISCARD_FAILURES, 'database_discard_unexpected' ] as $code ) {
			$texts = Messages::job_error( $code );
			self::assertNotSame( $general, $texts, $code . ' has a text of its own.' );
			if ( 'database_discard_unexpected' !== $code ) {
				self::assertSame( $code, JobError::from_throwable( new JobConflict( 'Refused.', [ 'code' => $code ] ) )->code, $code . ' reaches the route and WP-CLI as it is.' );
			}
			if ( 'database_discard_failed' !== $code ) {
				self::assertStringNotContainsString( 'names the database error', $texts['message'] . ' ' . $texts['hint'], $code );
				self::assertStringNotContainsString( 'The log of', $texts['message'] . ' ' . $texts['hint'], $code );
			}
		}
		$unexpected = self::text( 'database_discard_unexpected' );
		self::assertStringContainsString( 'recorded nothing', $unexpected );
		self::assertStringContainsString( 'wp backups-for-restic database-discard', $unexpected );
		self::assertStringContainsString( 'Environment tab', self::text( 'database_discard_storage' ) );
	}
}
