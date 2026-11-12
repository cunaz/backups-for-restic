<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Database\Restore\InsertStatement;

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
			'id',
			[ 'id' ]
		);
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		self::assertStringContainsString( '(`id`,`hidden`) VALUES (1,42);', $source->read( 10000 ) );
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
