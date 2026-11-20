<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\SqlFormatter;
use PHPUnit\Framework\TestCase;

final class DumpFormatterTest extends TestCase {

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
