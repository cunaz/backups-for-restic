<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\KeyColumn;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Database\Restore\InsertStatement;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\Attributes\DataProvider;

final class DumpColumnsTest extends TestCase {

	public function test_a_default_expression_is_not_a_generated_column(): void {
		$db = new class() implements Connection {
			public function rows( string $sql ): array {
				if ( 'SELECT @@SESSION.sql_quote_show_create AS quote_names, @@SESSION.sql_mode AS mode' === $sql ) {
					return [
						[
							'quote_names' => '1',
							'mode'        => 'NO_AUTO_VALUE_ON_ZERO',
						],
					];
				}
				if ( str_starts_with( $sql, 'SHOW CREATE' ) ) {
					return [ [ 'Create Table' => 'CREATE TABLE `wp_rows` (`id` int)' ] ];
				}
				if ( str_starts_with( $sql, 'SHOW KEYS' ) ) {
					return [];
				}
				return [
					[
						'Field' => 'created',
						'Type'  => 'timestamp',
						'Extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP',
					],
					[
						'Field' => 'hidden',
						'Type'  => 'int',
						'Extra' => 'INVISIBLE',
					],
					[
						'Field' => 'computed',
						'Type'  => 'int',
						'Extra' => 'STORED GENERATED',
					],
					[
						'Field' => 'virtual',
						'Type'  => 'int',
						'Extra' => 'VIRTUAL GENERATED',
					],
				];
			}
		};
		self::assertSame(
			[
				'created' => SqlFormatter::TEXT,
				'hidden'  => SqlFormatter::NUMERIC,
			],
			( new Catalog( $db ) )->table( 'wp_rows' )->columns
		);
	}

	public function test_table_pages_select_invisible_columns_explicitly(): void {
		$db     = new class() implements Connection {
			public int $page = 0;
			public function rows( string $sql ): array {
				TestCase::assertStringStartsWith( 'SELECT `id`, `hidden` FROM `wp_rows`', $sql );
				if ( ++$this->page > 1 ) {
					return [];
				}
				return [
					[
						'id'     => '1',
						'hidden' => '42',
					],
				];
			}
		};
		$table  = new Table(
			'wp_rows',
			'CREATE TABLE `wp_rows` (`id` int, `hidden` int INVISIBLE) ENGINE=InnoDB',
			[
				'id'     => SqlFormatter::NUMERIC,
				'hidden' => SqlFormatter::NUMERIC,
			],
			'PRIMARY',
			[ new KeyColumn( 'id', KeyColumn::INTEGER ) ]
		);
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		self::assertStringContainsString( '(`id`,`hidden`) VALUES (1,42);', $source->read( 10000 ) );
	}

	public function test_a_key_value_that_no_longer_fits_its_column_stops_the_dump_before_anything_is_written(): void {
		$db     = new class() implements Connection {
			public int $queries = 0;

			public function rows( string $sql ): array {
				++$this->queries;
				return [ [ 'id' => 'new-text-key' ] ];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ] );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		self::assertStringStartsWith( '-- backups-for-restic-dump/1 table `wp_rows`', $source->read( 100 ), 'The header comes before any page.' );
		try {
			$source->read( 10000 );
			self::fail( 'Paging continued with a key value of another type.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'A key value of the database dump does not have the form of its column.', $error->getMessage() );
			self::assertSame( 1, $db->queries );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function cursors_that_cannot_continue(): iterable {
		yield 'an earlier version' => [ '{"after":"17","offset":0,"rows":17,"bytes":900,"page":500}' ];
		yield 'not JSON' => [ 'damaged' ];
		yield 'a key value of another type' => [ '{"v":2,"after":["old-text-key"],"offset":0,"rows":17,"bytes":900,"page":500}' ];
		yield 'too few key values' => [ '{"v":2,"after":[],"offset":0,"rows":17,"bytes":900,"page":500}' ];
		yield 'a key value that is no string' => [ '{"v":2,"after":[17],"offset":0,"rows":17,"bytes":900,"page":500}' ];
	}

	#[DataProvider( 'cursors_that_cannot_continue' )]
	public function test_a_cursor_that_cannot_continue_starts_the_table_over( string $cursor ): void {
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				return [];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ] );
		$source = new TableSource( $db, $table, '', $cursor, static function (): void {} );
		self::assertTrue( $source->restarted() );
		self::assertSame( "-- backups-for-restic-dump/1 table `wp_rows`\nDROP TABLE IF EXISTS `wp_rows`;\nCREATE TABLE `wp_rows` (`id` int);\n-- end table rows=0 bytes=112\n", $source->read( 10000 ) );
		self::assertSame( [ 'SELECT `id` FROM `wp_rows` ORDER BY `id` LIMIT 500' ], $db->queries, 'The first page starts at the beginning of the key.' );
	}

	public function test_a_cursor_of_this_version_continues_after_its_key(): void {
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				return [];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ] );
		$source = new TableSource( $db, $table, '', '{"v":2,"after":["17"],"offset":17,"rows":17,"bytes":900,"page":64}', static function (): void {} );
		self::assertFalse( $source->restarted() );
		self::assertSame( "-- end table rows=17 bytes=900\n", $source->read( 10000 ) );
		self::assertSame( [ 'SELECT `id` FROM `wp_rows` WHERE (`id` > 17) ORDER BY `id` LIMIT 64' ], $db->queries );
	}

	public function test_generated_only_tables_keep_each_row_without_inserting_generated_values(): void {
		$db     = new class() implements Connection {
			public function rows( string $sql ): array {
				TestCase::assertStringStartsWith( 'SELECT 1 FROM `wp_rows` LIMIT ', $sql );
				return [ [ '1' => '1' ], [ '1' => '1' ] ];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`g` int AS (42) STORED) ENGINE=InnoDB', [], null, [] );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		$dump   = $source->read( 10000 );
		self::assertStringContainsString( 'INSERT INTO `wp_rows` () VALUES (),();', $dump );
		self::assertStringContainsString( '-- end table rows=2 ', $dump );
		$batch = InsertStatement::parse( 'INSERT INTO `wp_rows` () VALUES (),();', 'wp_rows', 'shadow' );
		self::assertSame( [], $batch->columns );
		self::assertSame( 2, $batch->rows );
	}
}
