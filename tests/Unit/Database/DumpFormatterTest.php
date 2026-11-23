<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\SqlFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DumpFormatterTest extends TestCase {

	/** @return iterable<string, array{string|null, string}> */
	public static function values(): iterable {
		yield 'null' => [ null, SqlFormatter::TEXT ];
		yield 'binary' => [ "\x00\xff\x10" . str_repeat( 'b', 20 ), SqlFormatter::BINARY ];
		yield 'empty binary' => [ '', SqlFormatter::BINARY ];
		yield 'text with an escape at every slice end' => [ "ab'cd\\ef\0gh\nij\rkl\x1amn" . str_repeat( "'", 7 ), SqlFormatter::TEXT ];
		yield 'multibyte text' => [ str_repeat( 'Åх内😀', 9 ), SqlFormatter::TEXT ];
		yield 'text that is no UTF-8' => [ str_repeat( "caf\xe9 ", 9 ), SqlFormatter::TEXT ];
		yield 'number' => [ '-12345678901234567890.5e-3', SqlFormatter::NUMERIC ];
		yield 'bit' => [ '4095', SqlFormatter::BIT ];
		yield 'text in a numeric column' => [ str_repeat( 'x1', 20 ), SqlFormatter::NUMERIC ];
	}

	#[DataProvider( 'values' )]
	public function test_a_value_written_in_pieces_is_the_same_literal( ?string $value, string $kind ): void {
		foreach ( [ 1, 3, 7, 1048576 ] as $slice ) {
			$pieces = iterator_to_array( SqlFormatter::pieces( $value, $kind, $slice ), false );
			self::assertSame( SqlFormatter::value( $value, $kind ), implode( '', $pieces ), 'Slices of ' . $slice . ' bytes.' );
			// A value longer than a slice comes in pieces of at most two slices of hex or escapes.
			foreach ( count( $pieces ) > 1 ? $pieces : [] as $piece ) {
				self::assertLessThanOrEqual( 2 * $slice + 2, strlen( $piece ) );
			}
		}
	}

	public function test_one_line_joins_line_breaks_and_keeps_every_byte_of_multibyte_characters(): void {
		// "х" is D1 85, "内" is E5 86 85 and "Å" is C3 85: 0x85 is NEL for \R in 8-bit mode.
		// "à" (C3 A0) and the no-break space (C2 A0) end in 0xA0, which some locales count as \s.
		$create = "CREATE TABLE `wp_archive` (\n  `id` int NOT NULL COMMENT 'Архив',\n  `Åtgärd` enum('内容','à la carte') NOT NULL DEFAULT '内容',\n  `note` varchar(20) DEFAULT 'a\u{a0}b',\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Архив 内容'";

		self::assertSame(
			"CREATE TABLE `wp_archive` ( `id` int NOT NULL COMMENT 'Архив', `Åtgärd` enum('内容','à la carte') NOT NULL DEFAULT '内容', `note` varchar(20) DEFAULT 'a\u{a0}b', PRIMARY KEY (`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Архив 内容'",
			SqlFormatter::one_line( $create )
		);
	}

	public function test_one_line_accepts_every_line_break_form_and_leaves_other_spacing_alone(): void {
		self::assertSame( "CREATE TABLE `t` ( `a` int COMMENT 'two  spaces' ) ENGINE=InnoDB", SqlFormatter::one_line( "\r\nCREATE TABLE `t` (\r\n\t`a` int COMMENT 'two  spaces'\r  \n\n) ENGINE=InnoDB\n" ) );
	}

	public function test_one_line_keeps_bytes_that_are_not_utf8(): void {
		$statement = "CREATE VIEW `v` AS SELECT '\x85\xa0\xff' AS `x`\nFROM `t`";
		self::assertSame( "CREATE VIEW `v` AS SELECT '\x85\xa0\xff' AS `x` FROM `t`", SqlFormatter::one_line( $statement ) );
	}
}
