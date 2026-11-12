<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;

final class DumpColumnsTest extends TestCase {

	public function test_a_default_expression_is_not_a_generated_column(): void {
		$db = new class() implements Connection {
			public function rows( string $sql ): array {
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
				if ( ++$this->page > 1 ) {
					return [];
				}
				// SELECT * follows the server's real rule: invisible columns are absent.
				return str_starts_with( $sql, 'SELECT *' ) ? [ [ 'id' => '1' ] ] : [
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
}
