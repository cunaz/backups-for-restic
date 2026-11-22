<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\WordPress\Downloads;

/**
 * Which downloads count as restored content, and how the audit of their grants keeps a
 * path. The routes and the audit entry itself are tested on a real site (AccessTest,
 * RestoreStagingTest).
 */
final class DownloadsTest extends TestCase {

	public function test_only_the_two_reports_are_metadata_and_every_other_kind_is_content(): void {
		self::assertTrue( Downloads::is_metadata( 'report' ) );
		self::assertTrue( Downloads::is_metadata( 'database_report' ) );
		foreach ( [ 'file', 'archive', '', 'Report', 'report ', 'database', 'database_dump' ] as $kind ) {
			self::assertFalse( Downloads::is_metadata( $kind ), 'A kind that is not known is content: ' . $kind );
		}
	}

	public function test_the_audit_keeps_a_path_that_cannot_break_a_line_or_a_list(): void {
		self::assertSame( '/var/www/html/wp-config.php', Downloads::shown( '/var/www/html/wp-config.php' ) );
		self::assertSame( '', Downloads::shown( '' ), 'An archive has no path.' );
		// A line break, an escape sequence, a byte that is not UTF-8, quotes and a backslash
		// as Go quotes them; a right-to-left override would reorder what an administrator reads.
		self::assertSame( '/up/a\\nb\\x1b[2Jc\\xe9\\"d\\"\\\\e\\u202ef', Downloads::shown( "/up/a\nb\e[2Jc\xe9\"d\"\\e\u{202E}f" ) );
		self::assertSame( '/uploads/Fotos/Größe.jpg', Downloads::shown( '/uploads/Fotos/Größe.jpg' ) );
	}

	public function test_a_long_path_is_cut_on_a_character_boundary_and_marked(): void {
		$shown = Downloads::shown( '/' . str_repeat( 'ü', 2000 ) );

		self::assertTrue( mb_check_encoding( $shown, 'UTF-8' ) );
		self::assertStringEndsWith( '...', $shown );
		self::assertLessThanOrEqual( 1027, strlen( $shown ) );
		self::assertSame( 1024 + 3 - 1, strlen( $shown ), 'One byte short of 1024: the next character has two.' );
	}
}
