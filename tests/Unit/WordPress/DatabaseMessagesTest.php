<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

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
	private static function text( string $code ): string {
		require_once dirname( __DIR__, 2 ) . '/Fixtures/WordPress/translate.php';
		$texts = Messages::job_error( $code );
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
}
