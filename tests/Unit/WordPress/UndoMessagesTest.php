<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use BackupsForRestic\WordPress\Messages;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Texts of a file undo. Messages calls WordPress's __(), so each test loads a stub in a
 * process of its own.
 */
final class UndoMessagesTest extends TestCase {

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
	public function test_an_undo_without_reviewed_decisions_asks_for_a_new_preview(): void {
		$text = self::text( 'undo_review_required' );
		self::assertStringContainsString( 'earlier version of the plugin', $text );
		self::assertStringContainsString( 'Preview the undo again and confirm the new preview within the recovery window.', $text );
		self::assertNotSame( self::text( 'internal' ), $text );
	}
}
