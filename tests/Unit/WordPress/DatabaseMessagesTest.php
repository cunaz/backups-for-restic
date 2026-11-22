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
	public function test_the_packet_hint_says_how_to_get_the_sql_and_which_limits_count(): void {
		$text = self::text( 'database_restore_packet' );
		self::assertStringContainsString( 'stage the snapshot’s database directory again with the rehearsal switched off', $text );
		self::assertStringContainsString( 'the importing client and the database server both need', $text );
		self::assertStringContainsString( 'When the plugin refused a row batch before sending it, the job log names the limit', $text );
	}

	#[RunInSeparateProcess]
	public function test_an_incomplete_session_list_names_what_the_server_needs(): void {
		$text = self::text( 'performance_schema' );
		self::assertStringContainsString( 'does not list every connection', $text );
		self::assertStringContainsString( 'performance_schema_max_thread_instances', $text );
		self::assertStringContainsString( 'PROCESS', $text );
	}
}
