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
		// Each differs in one field from the cursor that test_a_cursor_of_this_version_continues_after_its_key() continues.
		yield 'an earlier version' => [ self::cursor( [ 'v' => null ] ) ];
		yield 'not JSON' => [ 'damaged' ];
		yield 'a key value of another type' => [ self::cursor( [ 'after' => [ 'old-text-key' ] ] ) ];
		yield 'too few key values' => [ self::cursor( [ 'after' => [] ] ) ];
		yield 'too many key values' => [ self::cursor( [ 'after' => [ '17', '18' ] ] ) ];
		yield 'a key value that is no string' => [ self::cursor( [ 'after' => [ 17 ] ] ) ];
		yield 'a count below zero' => [ self::cursor( [ 'rows' => -1 ] ) ];
		yield 'a missing count' => [ self::cursor( [ 'largest' => null ] ) ];
		yield 'another definition' => [ self::cursor( [ 'digest' => hash( 'sha256', 'another' ) ] ) ];
		yield 'a definition that changed during its tick' => [ self::cursor( [ 'changed' => true ] ) ];
		yield 'rows written without the key values that follow them' => [ self::cursor( [ 'after' => null ] ) ];
	}

	/**
	 * The cursor of a table that wrote 17 rows, with some fields changed.
	 *
	 * @param array<string, mixed> $changes Fields to change; null leaves a field out.
	 * @return string Cursor.
	 */
	private static function cursor( array $changes = [] ): string {
		$cursor = array_filter(
			$changes + [
				'v'        => 2,
				'after'    => [ '17' ],
				'offset'   => 17,
				'rows'     => 17,
				'bytes'    => 900,
				'page'     => 64,
				'views'    => 1,
				'restarts' => 0,
				'digest'   => '',
				'changed'  => false,
				'largest'  => 0,
			],
			static fn ( mixed $value ): bool => null !== $value
		);
			return (string) json_encode( $cursor );
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
		self::assertSame( [ 'SELECT `id` FROM `wp_rows` ORDER BY `wp_rows`.`id` LIMIT 500' ], $db->queries, 'The first page starts at the beginning of the key.' );
	}

	public function test_a_cursor_before_the_first_page_of_a_keyed_table_starts_at_its_first_key(): void {
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				return [];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ] );
		$source = new TableSource(
			$db,
			$table,
			'',
			self::cursor(
				[
					'after'  => null,
					'offset' => 0,
					'rows'   => 0,
					'bytes'  => 112,
				]
			),
			static function (): void {}
		);
		self::assertFalse( $source->restarted(), 'Only the header was written.' );
		self::assertSame( "-- end table rows=0 bytes=112\n", $source->read( 10000 ) );
		self::assertSame( [ 'SELECT `id` FROM `wp_rows` ORDER BY `wp_rows`.`id` LIMIT 64' ], $db->queries );
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
		$source = new TableSource( $db, $table, '', self::cursor(), static function (): void {} );
		self::assertFalse( $source->restarted() );
		self::assertSame( "-- end table rows=17 bytes=900\n", $source->read( 10000 ) );
		self::assertSame( [ 'SELECT `id` FROM `wp_rows` WHERE (`id` > 17) ORDER BY `wp_rows`.`id` LIMIT 64' ], $db->queries );
	}

	public function test_a_float_column_is_read_as_the_double_it_holds_and_written_with_every_digit(): void {
		self::assertSame( SqlFormatter::FLOAT, SqlFormatter::kind( 'float' ) );
		self::assertSame( SqlFormatter::FLOAT, SqlFormatter::kind( 'float(7,4) unsigned' ) );
		self::assertSame( SqlFormatter::NUMERIC, SqlFormatter::kind( 'double' ) );
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				return 1 === count( $this->queries ) ? [
					[
						'id'          => '1',
						'ratio'       => '0.10000000149011612',
						'__bfr_key_0' => '0.10000000149011612',
					],
				] : [];
			}
		};
		$table  = new Table(
			'wp_rows',
			'CREATE TABLE `wp_rows` (`id` int, `ratio` float)',
			[
				'id'    => SqlFormatter::NUMERIC,
				'ratio' => SqlFormatter::FLOAT,
			],
			'ratio',
			[ new KeyColumn( 'ratio', KeyColumn::FLOAT ) ]
		);
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		self::assertStringContainsString( '(`id`,`ratio`) VALUES (1,0.10000000149011612);', $source->read( 10000 ) );
		self::assertSame( 'SELECT `id`, `ratio` + 0e0 AS `ratio`, `ratio` + 0e0 AS `__bfr_key_0` FROM `wp_rows` ORDER BY `wp_rows`.`ratio` LIMIT 500', $db->queries[0], 'ORDER BY names the column, not the alias.' );
	}

	/** @return iterable<string, array{int, bool}> */
	public static function reads(): iterable {
		yield 'one page that ends the table' => [ 499, true ];
		yield 'a full page and a read that finds nothing more' => [ 500, false ];
		yield 'two pages' => [ 501, false ];
	}

	#[DataProvider( 'reads' )]
	public function test_outside_a_snapshot_every_read_is_a_moment_of_its_own( int $rows, bool $snapshot ): void {
		$db      = new class( $rows ) implements Connection {
			public function __construct( private readonly int $total ) {
			}

			public function rows( string $sql ): array {
				$after = 1 === preg_match( '/`id` > (\d+)/', $sql, $match ) ? (int) $match[1] : 0;
				$limit = 1 === preg_match( '/LIMIT (\d+)/', $sql, $match ) ? (int) $match[1] : 0;
				return array_map( static fn ( int $id ): array => [ 'id' => (string) $id ], $after >= $this->total ? [] : range( $after + 1, min( $this->total, $after + $limit ) ) );
			}
		};
		$table   = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ], 'InnoDB' );
		$results = [];
		$source  = new TableSource(
			$db,
			$table,
			'',
			null,
			static function ( array $result ) use ( &$results ): void {
				$results[] = $result;
			}
		);
		while ( '' !== $source->read( 65536 ) ) {
			continue;
		}
		self::assertSame( $rows, $results[0]['rows'] ?? null );
		self::assertSame( $snapshot, $results[0]['snapshot'] ?? null, 'Rows read at two moments are no snapshot, even if the second read finds nothing.' );
	}

	public function test_a_string_key_continues_from_its_stored_bytes_without_hex_in_any_query(): void {
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				// The page that ends with a name whose stored bytes are C3 A9 20 78.
				return 1 === count( $this->queries ) ? array_map(
					static fn ( int $row ): array => [
						'name'        => 500 === $row ? "\u{e9} x" : 'n' . $row,
						'__bfr_key_0' => 500 === $row ? "\xc3\xa9 x" : 'n' . $row,
					],
					range( 1, 500 )
				) : [];
			}
		};
		$table  = new Table( 'wp_names', 'CREATE TABLE `wp_names` (`name` varchar(191))', [ 'name' => SqlFormatter::TEXT ], 'PRIMARY', [ new KeyColumn( 'name', KeyColumn::STRING, 'utf8mb4_unicode_520_ci' ) ], 'InnoDB' );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		while ( '' !== $source->read( 65536 ) ) {
			continue;
		}
		self::assertSame( 'SELECT `name`, CAST(`name` AS BINARY) AS `__bfr_key_0` FROM `wp_names` ORDER BY `wp_names`.`name` LIMIT 500', $db->queries[0] );
		self::assertStringContainsString( "WHERE (`name` > _utf8mb4 X'c3a92078' COLLATE utf8mb4_unicode_520_ci)", $db->queries[1] );
		// MariaDB returns NULL for HEX() of a value longer than half of max_allowed_packet.
		self::assertSame( [], array_filter( $db->queries, static fn ( string $query ): bool => str_contains( $query, 'HEX(' ) ) );
	}

	public function test_columns_named_with_digits_only_are_selected_inserted_and_paged_by_their_names(): void {
		$db    = new class() implements Connection {
			/** @var list<string> */
			public array $queries = [];

			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT `0`' ) ) {
					$this->queries[] = $sql;
				}
				return match ( true ) {
					str_starts_with( $sql, 'SELECT @@SESSION.sql_quote_show_create' ) => [
						[
							'quote_names' => '1',
							'mode'        => 'NO_AUTO_VALUE_ON_ZERO',
						],
					],
					str_starts_with( $sql, 'SHOW CREATE TABLE' ) => [ [ 'Create Table' => "CREATE TABLE `wp_digits` (\n  `0` int NOT NULL,\n  `123` varchar(20) NOT NULL,\n  `-1` float DEFAULT NULL,\n  PRIMARY KEY (`0`,`123`)\n) ENGINE=InnoDB" ] ],
					str_starts_with( $sql, 'SHOW FULL COLUMNS' ) => [
						self::column( '0', 'int', null, 'NO' ),
						self::column( '123', 'varchar(20)', 'utf8mb4_bin', 'NO' ),
						self::column( '-1', 'float', null, 'YES' ),
					],
					str_starts_with( $sql, 'SHOW KEYS' ) => [
						self::part( '1', '0' ),
						self::part( '2', '123' ),
					],
					// Rows arrive as mysqli returns them: a name of digits is an integer key.
					1 === count( $this->queries ) => array_map(
						static fn ( int $row ): array => [
							'0'           => (string) $row,
							'123'         => 'r' . $row,
							'-1'          => '0.5',
							'__bfr_key_1' => 'r' . $row,
						],
						range( 1, 500 )
					),
					default => [],
				};
			}

			/** @return array<string, string|null> */
			private static function column( string $field, string $type, ?string $collation, string $nullable ): array {
				return [
					'Field'     => $field,
					'Type'      => $type,
					'Collation' => $collation,
					'Null'      => $nullable,
					'Extra'     => '',
				];
			}

			/** @return array<string, string|null> */
			private static function part( string $position, string $column ): array {
				return [
					'Key_name'     => 'PRIMARY',
					'Non_unique'   => '0',
					'Seq_in_index' => $position,
					'Column_name'  => $column,
					'Sub_part'     => null,
				];
			}
		};
		$table = ( new Catalog( $db ) )->table( 'wp_digits' );
		self::assertSame( [ 0, 123, -1 ], array_keys( $table->columns ), 'PHP turns a name of digits into an integer key.' );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		$dump   = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			$dump .= $block;
		}
		self::assertSame( 'SELECT `0`, `123`, `-1` + 0e0 AS `-1`, CAST(`123` AS BINARY) AS `__bfr_key_1` FROM `wp_digits` ORDER BY `wp_digits`.`0`, `wp_digits`.`123` LIMIT 500', $db->queries[0] );
		self::assertStringContainsString( "WHERE (`0` > 500) OR (`0` = 500 AND `123` > _utf8mb4 X'72353030' COLLATE utf8mb4_bin)", $db->queries[1] );
		$statement = strstr( (string) strstr( $dump, 'INSERT INTO' ), "\n", true );
		self::assertIsString( $statement );
		self::assertStringStartsWith( "INSERT INTO `wp_digits` (`0`,`123`,`-1`) VALUES (1,'r1',0.5),", $statement );
		$batch = InsertStatement::parse( $statement, 'wp_digits', 'shadow' );
		self::assertSame( [ '0', '123', '-1' ], $batch->columns );
		self::assertSame( 500, $batch->rows );
	}

	/** @return iterable<string, array{int, ?string}> */
	public static function page_failures(): iterable {
		yield 'a lock that another connection holds' => [ 1205, 'database_dump_locked' ];
		yield 'any other failure' => [ 1146, null ];
	}

	#[DataProvider( 'page_failures' )]
	public function test_a_page_that_waited_too_long_for_a_lock_names_its_table_for_a_later_try( int $errno, ?string $code ): void {
		$db     = new class( $errno ) implements Connection {
			public function __construct( private readonly int $errno ) {
			}

			public function rows( string $sql ): array {
				DatabaseFailed::raise( 'A query of the database dump failed.', [ 'errno' => $this->errno ] );
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ] );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		try {
			$source->read( 65536 );
			self::fail( 'The page cannot be read.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( $code, $error->context()['code'] ?? null );
			self::assertSame( null === $code ? null : 'wp_rows', $error->context()['table'] ?? null );
			self::assertSame( $errno, ( null === $code ? $error : $error->getPrevious() )?->context()['errno'] ?? null, 'The server\'s error stays with the failure.' );
		}
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
