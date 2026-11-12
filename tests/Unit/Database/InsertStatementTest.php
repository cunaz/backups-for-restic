<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Restore\InsertStatement;
use BackupsForRestic\Database\Restore\SqlLexer;
use BackupsForRestic\Exception\FormatException;

final class InsertStatementTest extends TestCase {

	public function test_only_the_target_identifier_changes_and_every_dump_literal_survives(): void {
		$table  = 'wp_weird`name';
		$values = [
			SqlFormatter::value( null, SqlFormatter::TEXT ),
			SqlFormatter::value( "text with 'quote', \\backslash, -- comment, ; and\nline\0😀", SqlFormatter::TEXT ),
			SqlFormatter::value( "\xff\x00\x01", SqlFormatter::BINARY ),
			SqlFormatter::value( '-12345678901234567890.123456', SqlFormatter::NUMERIC ),
			SqlFormatter::value( '1.234e-100', SqlFormatter::NUMERIC ),
			SqlFormatter::value( '1', SqlFormatter::BIT ),
		];
		$tail   = ' (`n`,`text`,`binary`,`decimal`,`float`,`bit`) VALUES (' . implode( ',', $values ) . '),(' . implode( ',', $values ) . ');';
		$read   = InsertStatement::parse( 'INSERT INTO ' . SqlFormatter::identifier( $table ) . $tail, $table, 'bfr_restore_0123456789abcdef_t0' );

		self::assertSame( 2, $read->rows );
		self::assertSame( [ 'n', 'text', 'binary', 'decimal', 'float', 'bit' ], $read->columns );
		self::assertSame( 'INSERT INTO `bfr_restore_0123456789abcdef_t0`' . $tail, $read->sql );
	}

	/** @return iterable<string, array{string}> */
	public static function rejected(): iterable {
		foreach ( [
			'wrong table'                => 'INSERT INTO `wp_other` (`a`) VALUES (1);',
			'qualified table'            => 'INSERT INTO `other`.`wp_posts` (`a`) VALUES (1);',
			'ignore'                     => 'INSERT IGNORE INTO `wp_posts` (`a`) VALUES (1);',
			'replace'                    => 'REPLACE INTO `wp_posts` (`a`) VALUES (1);',
			'select'                     => 'INSERT INTO `wp_posts` (`a`) SELECT 1;',
			'function'                   => 'INSERT INTO `wp_posts` (`a`) VALUES (SLEEP(10));',
			'load file'                  => "INSERT INTO `wp_posts` (`a`) VALUES (LOAD_FILE('/etc/passwd'));",
			'subquery'                   => 'INSERT INTO `wp_posts` (`a`) VALUES ((SELECT 1));',
			'expression'                 => 'INSERT INTO `wp_posts` (`a`) VALUES (1+1);',
			'variable'                   => 'INSERT INTO `wp_posts` (`a`) VALUES (@secret);',
			'identifier as value'        => 'INSERT INTO `wp_posts` (`a`) VALUES (`a`);',
			'placeholder'                => 'INSERT INTO `wp_posts` (`a`) VALUES (?);',
			'less values'                => 'INSERT INTO `wp_posts` (`a`,`b`) VALUES (1);',
			'more values'                => 'INSERT INTO `wp_posts` (`a`) VALUES (1,2);',
			'duplicate columns'          => 'INSERT INTO `wp_posts` (`a`,`a`) VALUES (1,2);',
			'value without columns'      => 'INSERT INTO `wp_posts` () VALUES (1);',
			'trailing column comma'      => 'INSERT INTO `wp_posts` (`a`,) VALUES (1);',
			'update clause'              => 'INSERT INTO `wp_posts` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE a=2;',
			'following command'          => 'INSERT INTO `wp_posts` (`a`) VALUES (1); DROP TABLE `wp_users`;',
			'executable comment'         => 'INSERT INTO `wp_posts` (`a`) VALUES (1) /*!50000 ON DUPLICATE KEY UPDATE a=2 */;',
			'MariaDB executable comment' => 'INSERT INTO `wp_posts` (`a`) VALUES (1) /*M!100000 RETURNING a */;',
			'line comment'               => 'INSERT INTO `wp_posts` (`a`) VALUES (1); -- hidden tail',
			'hash comment'               => 'INSERT INTO `wp_posts` (`a`) VALUES (1); # hidden tail',
			'unclosed quote'             => "INSERT INTO `wp_posts` (`a`) VALUES ('unfinished);",
			'escaped final quote'        => "INSERT INTO `wp_posts` (`a`) VALUES ('unfinished\\');",
			'no terminator'              => 'INSERT INTO `wp_posts` (`a`) VALUES (1)',
			'bare column'                => 'INSERT INTO `wp_posts` (a) VALUES (1);',
			'raw newline'                => "INSERT INTO `wp_posts` (`a`) VALUES ('line\nbreak');",
			'raw NUL'                    => "INSERT INTO `wp_posts` (`a`) VALUES ('bad\0value');",
			'hex tail'                   => 'INSERT INTO `wp_posts` (`a`) VALUES (0x123nothex);',
			'number tail'                => 'INSERT INTO `wp_posts` (`a`) VALUES (123abc);',
		] as $name => $sql ) {
			yield $name => [ $sql ];
		}
	}

	#[DataProvider( 'rejected' )]
	public function test_only_literal_rows_for_the_expected_table_are_accepted( string $sql ): void {
		$this->expectException( FormatException::class );
		InsertStatement::parse( $sql, 'wp_posts', 'bfr_restore_test_t0' );
	}

	public function test_comments_and_semicolons_inside_quoted_data_are_not_executed(): void {
		$sql  = "INSERT INTO `wp_posts` (`a`) VALUES ('; DROP TABLE `wp_users`; -- '),('/*!50000 SELECT 1 */'),('it''s data'),('\\\\'),('');";
		$read = InsertStatement::parse( $sql, 'wp_posts', 'shadow' );
		self::assertSame( 5, $read->rows );
		self::assertSame( str_replace( 'INSERT INTO `wp_posts`', 'INSERT INTO `shadow`', $sql ), $read->sql );
	}

	public function test_the_writer_row_batch_limit_is_enforced(): void {
		$sql = 'INSERT INTO `wp_posts` (`a`) VALUES ' . implode( ',', array_fill( 0, InsertStatement::MAX_ROWS, '(1)' ) );
		self::assertSame( InsertStatement::MAX_ROWS, InsertStatement::parse( $sql . ';', 'wp_posts', 'shadow' )->rows );
		$this->expectException( FormatException::class );
		InsertStatement::parse( $sql . ',(1);', 'wp_posts', 'shadow' );
	}

	public function test_an_oversized_statement_is_refused_before_tokenizing(): void {
		$this->expectException( FormatException::class );
		InsertStatement::parse( str_repeat( ' ', SqlLexer::MAX_BYTES + 1 ), 'wp_posts', 'shadow' );
	}
}
