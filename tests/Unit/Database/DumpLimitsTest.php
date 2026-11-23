<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\KeyColumn;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Database\Restore\SqlLexer;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobError;

/**
 * Rows above the importer's statement limit: the backup keeps them, as statements of their
 * own, and records the largest statement; a row that cannot be encoded within the memory
 * limit stops the dump with a code of its own.
 */
final class DumpLimitsTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_a_row_above_the_import_statement_limit_is_written_as_a_statement_of_its_own(): void {
		$db      = new class() implements Connection {
			public function rows( string $sql ): array {
				// A small row, a row of 16 MiB and another small row, then the end of the table.
				$rows = [
					[
						'id'   => '1',
						'body' => 'before',
					],
					[
						'id'   => '2',
						'body' => str_repeat( 'x', SqlLexer::MAX_BYTES ),
					],
					[
						'id'   => '3',
						'body' => "after'",
					],
				];
				preg_match( '/LIMIT (\d+) OFFSET (\d+)\z/', $sql, $match );
				$page = array_slice( $rows, (int) ( $match[2] ?? 0 ), (int) ( $match[1] ?? 0 ) );
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return array_map( static fn ( array $row ): array => [ 'size' => (string) ( strlen( $row['id'] ) + strlen( $row['body'] ) ) ], $page );
				}
				return $page;
			}
		};
		$table   = new Table(
			'wp_rows',
			'CREATE TABLE `wp_rows` (`id` int, `body` longtext) ENGINE=InnoDB',
			[
				'id'   => SqlFormatter::NUMERIC,
				'body' => SqlFormatter::TEXT,
			],
			null,
			[],
			'InnoDB',
			'',
			true
		);
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
		$lines   = [];
		$bytes   = 0;
		$pending = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			$pending .= $block;
			for ( $end = strpos( $pending, "\n" ); false !== $end; $end = strpos( $pending, "\n" ) ) {
				$line    = substr( $pending, 0, $end );
				$pending = substr( $pending, $end + 1 );
				if ( strlen( $line ) > 100 ) {
					self::assertStringStartsWith( "INSERT INTO `wp_rows` (`id`,`body`) VALUES (2,'xxx", $line );
					self::assertStringEndsWith( "xxx');", $line );
					$line = strlen( $line ) . ' bytes';
				}
				$lines[] = $line;
				if ( ! str_starts_with( $line, '-- end table' ) ) {
					$bytes += ( str_ends_with( $line, ' bytes' ) ? (int) $line : strlen( $line ) ) + 1;
				}
			}
		}

		$largest = strlen( "INSERT INTO `wp_rows` (`id`,`body`) VALUES (2,'');" ) + SqlLexer::MAX_BYTES;
		self::assertSame(
			[
				'-- backups-for-restic-dump/1 table `wp_rows`',
				'DROP TABLE IF EXISTS `wp_rows`;',
				'CREATE TABLE `wp_rows` (`id` int, `body` longtext) ENGINE=InnoDB;',
				"INSERT INTO `wp_rows` (`id`,`body`) VALUES (1,'before');",
				$largest . ' bytes',
				"INSERT INTO `wp_rows` (`id`,`body`) VALUES (3,'after\\'');",
				'-- end table rows=3 bytes=' . $bytes,
			],
			$lines,
			'Above the limit, the row is a statement of its own, and the rows around it are kept.'
		);
		self::assertSame( 3, $results[0]['rows'] ?? null );
		self::assertSame( $largest, $results[0]['largest'] ?? null, 'The manifest can tell an importer the size it needs.' );
	}

	#[RunInSeparateProcess]
	public function test_a_large_value_is_written_within_the_memory_that_its_statement_was_checked_for(): void {
		$blob = str_repeat( "\xa5\x00\xff\x10", 8 * 1048576 );
		// Room for the row, its statement of twice its size and the reserve, not for a second
		// copy of the statement: hex with its prefix built as a whole would need one.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 122 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process measures the memory that one large row takes.
		$db     = new class( $blob ) implements Connection {
			private bool $fetched = false;

			public function __construct( private readonly string $blob ) {
			}

			public function rows( string $sql ): array {
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return $this->fetched ? [] : [ [ 'size' => (string) strlen( $this->blob ) ] ];
				}
				if ( $this->fetched ) {
					return [];
				}
				$this->fetched = true;
				return [ [ 'body' => $this->blob ] ];
			}
		};
		$table  = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`body` longblob) ENGINE=InnoDB', [ 'body' => SqlFormatter::BINARY ], null, [], 'InnoDB', '', true );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		$digest = hash_init( 'sha256' );
		$length = 0;
		$tail   = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			hash_update( $digest, $block );
			$length += strlen( $block );
			$tail    = substr( $tail . $block, -200 );
		}

		$header   = "-- backups-for-restic-dump/1 table `wp_rows`\nDROP TABLE IF EXISTS `wp_rows`;\nCREATE TABLE `wp_rows` (`body` longblob) ENGINE=InnoDB;\n";
		$trailer  = '-- end table rows=1 bytes=' . ( strlen( $header ) + strlen( 'INSERT INTO `wp_rows` (`body`) VALUES (0x);' ) + 2 * strlen( $blob ) + 1 ) . "\n";
		$expected = hash_init( 'sha256' );
		hash_update( $expected, $header . 'INSERT INTO `wp_rows` (`body`) VALUES (0x' );
		$size = strlen( $blob );
		for ( $at = 0; $at < $size; $at += 1048576 ) {
			hash_update( $expected, bin2hex( substr( $blob, $at, 1048576 ) ) );
		}
		hash_update( $expected, ");\n" . $trailer );
		self::assertStringEndsWith( $trailer, $tail );
		self::assertSame( strlen( $header ) + 44 + 2 * strlen( $blob ) + strlen( $trailer ), $length );
		self::assertSame( hash_final( $expected ), hash_final( $digest ) );
	}

	#[RunInSeparateProcess]
	public function test_a_row_that_cannot_be_encoded_within_the_memory_limit_stops_the_dump_with_its_own_code(): void {
		ini_set( 'memory_limit', '96M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process checks the refusal under a limit like the plugin's minimum.
		$db       = new class() implements Connection {
			public function rows( string $sql ): array {
				// On the site's connection a row can grow between the read of its size and its own.
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return [ [ 'size' => '1' ] ];
				}
				return [ [ 'body' => str_repeat( "\0", 40 * 1048576 ) ] ];
			}
		};
		$table    = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`body` longblob) ENGINE=InnoDB', [ 'body' => SqlFormatter::BINARY ], null, [], 'InnoDB', '', true );
		$complete = false;
		$source   = new TableSource(
			$db,
			$table,
			'',
			null,
			static function () use ( &$complete ): void {
				$complete = true;
			}
		);
		try {
			while ( '' !== $source->read( 65536 ) ) {
				continue;
			}
			self::fail( 'An 80 MiB statement cannot be built within 96 MiB.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_row_memory', JobError::from_throwable( $error )->code );
			self::assertSame( 'wp_rows', $error->context()['table'] );
			self::assertFalse( $complete );
		}
	}

	#[RunInSeparateProcess]
	public function test_a_page_of_large_rows_fetches_no_more_of_them_than_fit_into_memory(): void {
		// Twenty rows of 3 MiB: fifty to a page would be 60 MiB of rows and 120 MiB of SQL.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 64 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process measures what one page fetches.
		$db     = new class() implements Connection {
			/** @var list<string> */
			public array $fetches = [];

			public function rows( string $sql ): array {
				$after = 1 === preg_match( '/WHERE \(`id` > (\d+)\)/', $sql, $match ) ? (int) $match[1] : 0;
				$limit = 1 === preg_match( '/LIMIT (\d+)\z/', $sql, $match ) ? (int) $match[1] : 0;
				$ids   = $after >= 20 || 0 === $limit ? [] : range( $after + 1, min( 20, $after + $limit ) );
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return array_map( static fn ( int $id ): array => [ 'size' => (string) ( strlen( (string) $id ) + 3 * 1048576 ) ], $ids );
				}
				$this->fetches[] = $sql;
				return array_map(
					static fn ( int $id ): array => [
						'id'   => (string) $id,
						'body' => str_repeat( chr( 64 + $id ), 3 * 1048576 ),
					],
					$ids
				);
			}
		};
		$table  = new Table(
			'wp_blobs',
			'CREATE TABLE `wp_blobs` (`id` int NOT NULL, `body` longblob, PRIMARY KEY (`id`)) ENGINE=InnoDB',
			[
				'id'   => SqlFormatter::NUMERIC,
				'body' => SqlFormatter::BINARY,
			],
			'PRIMARY',
			[ new KeyColumn( 'id', KeyColumn::INTEGER ) ],
			'InnoDB',
			'',
			true
		);
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		$lines  = 0;
		$tail   = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			$lines += substr_count( $block, "\n" );
			$tail   = substr( $tail . $block, -200 );
		}

		self::assertSame( 3 + 20 + 1, $lines, 'The header, one statement per row and the trailer.' );
		self::assertMatchesRegularExpression( '/\n-- end table rows=20 bytes=\d+\n\z/', $tail );
		self::assertCount( 20, $db->fetches );
		foreach ( $db->fetches as $sql ) {
			self::assertStringEndsWith( ' LIMIT 1', $sql, 'A row of 6 MiB of SQL is a page of its own.' );
		}
	}

	#[RunInSeparateProcess]
	public function test_a_row_that_grows_after_its_size_was_read_is_never_fetched_beyond_the_memory_left(): void {
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 160 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process checks what a page fetches.
		// On the site's connection the sizes and the page are two reads: the second row has
		// grown to 200 MiB in between, more than PHP could hold.
		$db     = new class() implements Connection {
			public bool $grown = false;

			public bool $fetched_whole = false;

			public function rows( string $sql ): array {
				$after = 1 === preg_match( '/WHERE \(`id` > (\d+)\)/', $sql, $match ) ? (int) $match[1] : 0;
				$limit = 1 === preg_match( '/LIMIT (\d+)\z/', $sql, $match ) ? (int) $match[1] : 0;
				$ids   = $after >= 3 || 0 === $limit ? [] : range( $after + 1, min( 3, $after + $limit ) );
				$size  = fn ( int $id ): int => 2 === $id && $this->grown ? 200 * 1048576 : 1024;
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					$sizes       = array_map( static fn ( int $id ): array => [ 'size' => (string) ( 1 + $size( $id ) ) ], $ids );
					$this->grown = true;
					return $sizes;
				}
				// As the server answers: a value larger than the cap of the query does not come.
				$cap = 1 === preg_match( '/ <= (\d+), `body`, NULL\)/', $sql, $match ) ? (int) $match[1] : PHP_INT_MAX;
				return array_map(
					function ( int $id ) use ( $size, $cap ): array {
						$bytes               = 1 + $size( $id );
						$this->fetched_whole = $this->fetched_whole || ( $bytes > 1048576 && $bytes <= $cap );
						return [
							'id'         => (string) $id,
							'body'       => $bytes > $cap ? null : str_repeat( 'g', $bytes - 1 ),
							'__bfr_size' => (string) $bytes,
						];
					},
					$ids
				);
			}
		};
		$table  = new Table(
			'wp_rows',
			'CREATE TABLE `wp_rows` (`id` int NOT NULL, `body` longblob, PRIMARY KEY (`id`)) ENGINE=InnoDB',
			[
				'id'   => SqlFormatter::NUMERIC,
				'body' => SqlFormatter::BINARY,
			],
			'PRIMARY',
			[ new KeyColumn( 'id', KeyColumn::INTEGER ) ],
			'InnoDB',
			'',
			true
		);
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		try {
			while ( '' !== $source->read( 65536 ) ) {
				continue;
			}
			self::fail( 'A row of 200 MiB does not fit into 160 MiB.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_row_memory', JobError::from_throwable( $error )->code );
		}
		self::assertFalse( $db->fetched_whole, 'The grown row never reached PHP.' );
	}

	#[RunInSeparateProcess]
	public function test_a_page_of_long_varchars_fetches_no_more_rows_than_fit_at_their_largest(): void {
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 48 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process checks what one page fetches.
		// No TEXT or BLOB, but a latin1 VARCHAR(65000) can arrive as 195,000 bytes of utf8mb4.
		$create  = "CREATE TABLE `wp_long` (\n  `id` int NOT NULL,\n  `title` varchar(65000) CHARACTER SET latin1 DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
		$columns = [
			[
				'Field'     => 'id',
				'Type'      => 'int',
				'Collation' => null,
				'Null'      => 'NO',
				'Extra'     => '',
			],
			[
				'Field'     => 'title',
				'Type'      => 'varchar(65000)',
				'Collation' => 'latin1_swedish_ci',
				'Null'      => 'YES',
				'Extra'     => '',
			],
		];
		$db      = new class( $create, $columns ) implements Connection {
			/** @var list<string> */
			public array $pages = [];

			/**
			 * @param list<array<string, string|null>> $columns Rows of SHOW FULL COLUMNS.
			 */
			public function __construct( private readonly string $create, private readonly array $columns ) {
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
				if ( str_starts_with( $sql, 'SHOW CREATE TABLE' ) ) {
					return [ [ 'Create Table' => $this->create ] ];
				}
				if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) {
					return $this->columns;
				}
				if ( str_starts_with( $sql, 'SHOW KEYS' ) ) {
					return [
						[
							'Key_name'     => 'PRIMARY',
							'Non_unique'   => '0',
							'Seq_in_index' => '1',
							'Column_name'  => 'id',
							'Sub_part'     => null,
						],
					];
				}
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return [ [ 'size' => '10' ], [ 'size' => '10' ] ];
				}
				if ( str_starts_with( $sql, 'SELECT ' ) ) {
					$this->pages[] = $sql;
				}
				return [];
			}
		};
		$table   = ( new Catalog( $db ) )->table( 'wp_long' );
		self::assertFalse( $table->wide );
		self::assertGreaterThanOrEqual( 260000, $table->bound, 'Four bytes for each of 65,000 characters.' );
		$source = new TableSource( $db, $table, '', null, static function (): void {} );
		while ( '' !== $source->read( 65536 ) ) {
			continue;
		}

		self::assertCount( 1, $db->pages );
		self::assertMatchesRegularExpression( '/ LIMIT (\d+)\z/', $db->pages[0] );
		preg_match( '/ LIMIT (\d+)\z/', $db->pages[0], $match );
		// 32 MiB for rows of up to 260 KB, with twice that as SQL: about 43, not 500.
		self::assertLessThanOrEqual( 50, (int) $match[1] );
		self::assertGreaterThanOrEqual( 30, (int) $match[1] );
	}

	#[RunInSeparateProcess]
	public function test_a_row_that_cannot_fit_into_memory_on_its_own_is_refused_before_it_is_fetched(): void {
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- This separate process checks the refusal under a known limit.
		$db    = new class() implements Connection {
			/** @var list<string> */
			public array $fetches = [];

			public function rows( string $sql ): array {
				if ( str_contains( $sql, ' AS size FROM ' ) ) {
					return [ [ 'size' => (string) ( 300 * 1048576 ) ] ];
				}
				$this->fetches[] = $sql;
				return [];
			}
		};
		$table = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`body` longblob) ENGINE=InnoDB', [ 'body' => SqlFormatter::BINARY ], null, [], 'InnoDB', '', true );
		try {
			( new TableSource( $db, $table, '', null, static function (): void {} ) )->read( 65536 );
			self::fail( 'A row of 300 MiB does not fit into 256 MiB.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_row_memory', JobError::from_throwable( $error )->code );
			self::assertSame( 'wp_rows', $error->context()['table'] );
		}
		self::assertSame( [], $db->fetches, 'The row was never fetched, so PHP did not run out of memory.' );
	}
}
