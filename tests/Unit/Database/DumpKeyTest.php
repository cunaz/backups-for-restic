<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\KeyColumn;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which key orders a table's pages, and how its values are written into the next page's
 * condition.
 */
final class DumpKeyTest extends TestCase {

	/** @return iterable<string, array{string, string, ?string, string, string, string}> */
	public static function literals(): iterable {
		yield 'unsigned 64-bit above PHP_INT_MAX' => [ 'bigint unsigned', '18446744073709551615', null, '`k`', '18446744073709551615', '`k`' ];
		yield 'negative integer' => [ 'int', '-2147483648', null, '`k`', '-2147483648', '`k`' ];
		yield 'zero-filled integer' => [ 'int(10) unsigned zerofill', '0000000042', null, '`k`', '0000000042', '`k`' ];
		yield 'year' => [ 'year', '0000', null, '`k`', '0000', '`k`' ];
		yield 'decimal' => [ 'decimal(20,6)', '-12.000100', null, '`k`', '-12.000100', '`k`' ];
		yield 'decimal with the most digits MySQL allows after the point' => [ 'decimal(65,30)', '-' . str_repeat( '9', 35 ) . '.' . str_repeat( '1', 30 ), null, '`k`', '-' . str_repeat( '9', 35 ) . '.' . str_repeat( '1', 30 ), '`k`' ];
		yield 'decimal with the most digits MariaDB allows after the point' => [ 'decimal(65,38)', '-' . str_repeat( '9', 27 ) . '.' . str_repeat( '1', 38 ), null, '`k`', '-' . str_repeat( '9', 27 ) . '.' . str_repeat( '1', 38 ), '`k`' ];
		yield 'float as its exact double' => [ 'float', '0.10000000149011612', null, '`k` + 0e0', '0.10000000149011612', '`k`' ];
		yield 'double in exponent notation' => [ 'double', '1.0000000031710769e-30', null, '`k` + 0e0', '1.0000000031710769e-30', '`k`' ];
		yield 'enum by its index' => [ "enum('zeta','alpha')", '2', null, '`k` + 0', '2', '`k`' ];
		yield 'set by its bits' => [ "set('b','a')", '3', null, '`k` + 0', '3', '`k`' ];
		yield 'bit as a number' => [ 'bit(12)', '4095', null, '`k` + 0', '4095', '`k`' ];
		yield 'datetime with fractions' => [ 'datetime(6)', '2026-11-20 12:34:56.000001', null, '`k`', "'2026-11-20 12:34:56.000001'", '`k`' ];
		yield 'negative time' => [ 'time(3)', '-838:59:59.000', null, '`k`', "'-838:59:59.000'", '`k`' ];
		yield 'MariaDB inet6 with an IPv4 tail' => [ 'inet6', '::ffff:192.0.2.1', null, '`k`', "'::ffff:192.0.2.1'", '`k`' ];
		yield 'string in its own collation' => [ 'varchar(191)', 'c3a920', 'utf8mb4_unicode_520_ci', 'CAST(`k` AS BINARY)', "_utf8mb4 X'c3a920' COLLATE utf8mb4_unicode_520_ci", '`k`' ];
		yield 'latin1 string' => [ 'char(10)', 'e5', 'latin1_swedish_ci', 'CAST(`k` AS BINARY)', "_latin1 X'e5' COLLATE latin1_swedish_ci", '`k`' ];
		yield 'empty string' => [ 'varchar(10)', '', 'utf8mb4_bin', 'CAST(`k` AS BINARY)', "_utf8mb4 X'' COLLATE utf8mb4_bin", '`k`' ];
		yield 'binary with zero bytes' => [ 'binary(4)', '00ff0000', null, 'CAST(`k` AS BINARY)', "X'00ff0000'", '`k`' ];
	}

	#[DataProvider( 'literals' )]
	public function test_cursor_values_are_read_and_written_in_the_form_that_orders_like_the_index( string $type, string $value, ?string $collation, string $read, string $literal, string $column ): void {
		$key = KeyColumn::of( 'k', $type, $collation );
		self::assertInstanceOf( KeyColumn::class, $key );
		self::assertSame( $read, $key->value() );
		self::assertSame( '`k`' !== $read, $key->computed() );
		self::assertSame( $literal, $key->literal( $value ) );
		self::assertSame( '(' . $column . ' > ' . $literal . ')', KeyColumn::after( [ $key ], [ $value ] ) );
	}

	public function test_string_and_binary_keys_keep_their_stored_bytes_as_hex_and_other_values_as_sent(): void {
		// MariaDB returns NULL for HEX() of a value longer than half of max_allowed_packet:
		// the stored bytes come back whole from CAST(... AS BINARY) and PHP writes the hex.
		self::assertSame( 'c3a9200078', KeyColumn::of( 'k', 'varchar(191)', 'utf8mb4_unicode_520_ci' )?->cursor( "\xc3\xa9 \x00x" ) );
		self::assertSame( '00ff', KeyColumn::of( 'k', 'varbinary(8)', null )?->cursor( "\x00\xff" ) );
		self::assertSame( '', KeyColumn::of( 'k', 'binary(0)', null )?->cursor( '' ) );
		self::assertSame( '-12.5', KeyColumn::of( 'k', 'decimal(10,1)', null )?->cursor( '-12.5' ) );
		self::assertSame( '2026-11-20 12:34:56', KeyColumn::of( 'k', 'datetime', null )?->cursor( '2026-11-20 12:34:56' ) );
		self::assertNull( KeyColumn::of( 'k', 'varchar(10)', 'utf8mb4_bin' )?->cursor( null ), 'A missing value stays missing, so that literal() refuses it.' );
	}

	/** @return iterable<string, array{string, mixed}> */
	public static function values_of_another_form(): iterable {
		yield 'text in an integer key' => [ 'int', '12abc' ];
		yield 'an integer with too many digits' => [ 'bigint', '123456789012345678901' ];
		yield 'a quote in a date' => [ 'date', "2026-01-01' OR '1" ];
		yield 'a backslash in a time' => [ 'time', '12:00:00\\' ];
		yield 'an empty date' => [ 'date', '' ];
		yield 'odd hex' => [ 'binary(2)', 'ABC' ];
		yield 'not hex' => [ 'binary(2)', 'XY' ];
		yield 'a negative enum index' => [ "enum('a')", '-1' ];
		yield 'a number that is no string' => [ 'int', 17 ];
		yield 'null' => [ 'int', null ];
		yield 'an exponent in a decimal' => [ 'decimal(10,2)', '1e5' ];
		yield 'a decimal with more digits after the point than any server allows' => [ 'decimal(65,38)', '0.' . str_repeat( '1', 39 ) ];
	}

	#[DataProvider( 'values_of_another_form' )]
	public function test_a_value_of_another_form_never_reaches_a_query( string $type, mixed $value ): void {
		$key = KeyColumn::of( 'k', $type, null );
		self::assertInstanceOf( KeyColumn::class, $key );
		$this->expectException( DatabaseFailed::class );
		$key->literal( $value );
	}

	public function test_types_that_cannot_order_pages_exactly_are_not_key_columns(): void {
		foreach ( [ 'text', 'longtext', 'tinyblob', 'longblob', 'json', 'geometry', 'point', 'vector(3)' ] as $type ) {
			self::assertNull( KeyColumn::of( 'k', $type, 'utf8mb4_bin' ), $type );
		}
		self::assertNull( KeyColumn::of( 'k', 'varchar(10)', null ), 'A string without a collation cannot be compared exactly.' );
		self::assertNull( KeyColumn::of( 'k', 'varchar(10)', 'bad collation' ) );
	}

	public function test_the_condition_is_the_row_value_comparison_written_for_index_ranges(): void {
		$key = [ new KeyColumn( 'a', KeyColumn::INTEGER ), new KeyColumn( 'b', KeyColumn::QUOTED ), new KeyColumn( 'c', KeyColumn::BINARY ) ];
		self::assertSame( "(`a` > 1) OR (`a` = 1 AND `b` > '2026-01-01') OR (`a` = 1 AND `b` = '2026-01-01' AND `c` > X'00')", KeyColumn::after( $key, [ '1', '2026-01-01', '00' ] ) );
		$this->expectException( DatabaseFailed::class );
		KeyColumn::after( $key, [ '1', '2026-01-01' ] );
	}

	/** @return iterable<string, array{list<array<string, string|null>>, ?string, list<string>}> */
	public static function keys(): iterable {
		$part = static fn ( string $name, string $column, int $seq, string $non_unique = '0', ?string $sub = null, string $visible = 'YES' ): array => [
			'Key_name'     => $name,
			'Non_unique'   => $non_unique,
			'Seq_in_index' => (string) $seq,
			'Column_name'  => $column,
			'Sub_part'     => $sub,
			'Visible'      => $visible,
		];
		yield 'the composite primary key' => [ [ $part( 'PRIMARY', 'object_id', 1 ), $part( 'PRIMARY', 'term_taxonomy_id', 2 ), $part( 'term_taxonomy_id', 'term_taxonomy_id', 1, '1' ) ], 'PRIMARY', [ 'object_id', 'term_taxonomy_id' ] ];
		yield 'key parts in their order' => [ [ $part( 'PRIMARY', 'term_taxonomy_id', 2 ), $part( 'PRIMARY', 'object_id', 1 ) ], 'PRIMARY', [ 'object_id', 'term_taxonomy_id' ] ];
		yield 'the primary key before a unique key' => [ [ $part( 'option_name', 'name', 1 ), $part( 'PRIMARY', 'id', 1 ) ], 'PRIMARY', [ 'id' ] ];
		yield 'a unique key without a primary key' => [ [ $part( 'code', 'code', 1 ) ], 'code', [ 'code' ] ];
		yield 'not a nullable unique key' => [ [ $part( 'maybe', 'nullable', 1 ), $part( 'code', 'code', 1 ) ], 'code', [ 'code' ] ];
		yield 'not a unique key over a text column' => [ [ $part( 'body', 'body', 1, '0', '100' ) ], null, [] ];
		yield 'not a unique key over a generated column' => [ [ $part( 'generated', 'computed', 1 ), $part( 'code', 'code', 1 ) ], 'code', [ 'code' ] ];
		yield 'not a functional key' => [
			[
				[
					'Key_name'     => 'f',
					'Non_unique'   => '0',
					'Seq_in_index' => '1',
					'Column_name'  => null,
				],
				$part( 'code', 'code', 1 ),
			],
			'code',
			[ 'code' ],
		];
		yield 'a whole key before a prefix key' => [ [ $part( 'prefix', 'name', 1, '0', '10' ), $part( 'code', 'code', 1 ) ], 'code', [ 'code' ] ];
		yield 'a visible key before an invisible one' => [ [ $part( 'hidden', 'name', 1, '0', null, 'NO' ), $part( 'code', 'code', 1 ) ], 'code', [ 'code' ] ];
		yield 'a prefix key rather than none' => [ [ $part( 'prefix', 'name', 1, '0', '10' ) ], 'prefix', [ 'name' ] ];
		yield 'no unique key' => [ [ $part( 'k', 'code', 1, '1' ) ], null, [] ];
		yield 'no keys' => [ [], null, [] ];
	}

	/**
	 * @param list<array<string, string|null>> $keys Rows of SHOW KEYS.
	 * @param list<string>                     $columns Expected key columns.
	 */
	#[DataProvider( 'keys' )]
	public function test_pages_follow_the_primary_key_or_a_unique_key_over_not_null_columns( array $keys, ?string $name, array $columns ): void {
		$db    = new class( $keys ) implements Connection {
			/** @param list<array<string, string|null>> $keys */
			public function __construct( private readonly array $keys ) {
			}

			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT @@SESSION.sql_quote_show_create' ) ) {
					return [
						[
							'quote_names' => '1',
							'mode'        => 'NO_AUTO_VALUE_ON_ZERO',
						],
					];
				}
				if ( str_starts_with( $sql, 'SHOW CREATE' ) ) {
					return [ [ 'Create Table' => 'CREATE TABLE `t` (`id` int)' ] ];
				}
				if ( str_starts_with( $sql, 'SHOW KEYS' ) ) {
					return $this->keys;
				}
				$column = static fn ( string $field, string $type, string $nullable = 'NO', ?string $collation = null, string $extra = '' ): array => [
					'Field'     => $field,
					'Type'      => $type,
					'Collation' => $collation,
					'Null'      => $nullable,
					'Extra'     => $extra,
				];
				return [
					$column( 'id', 'bigint unsigned' ),
					$column( 'object_id', 'bigint unsigned' ),
					$column( 'term_taxonomy_id', 'bigint unsigned' ),
					$column( 'name', 'varchar(191)', 'NO', 'utf8mb4_unicode_520_ci' ),
					$column( 'code', 'char(10)', 'NO', 'latin1_swedish_ci' ),
					$column( 'nullable', 'int', 'YES' ),
					$column( 'body', 'text', 'NO', 'utf8mb4_bin' ),
					$column( 'computed', 'int', 'NO', null, 'STORED GENERATED' ),
				];
			}
		};
		$table = ( new Catalog( $db ) )->table( 't' );
		self::assertSame( $name, $table->key );
		self::assertSame( $columns, array_map( static fn ( KeyColumn $column ): string => $column->name, $table->order ) );
		self::assertArrayNotHasKey( 'computed', $table->columns, 'A generated column is neither inserted nor a key column.' );
	}
}
