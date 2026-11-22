<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Connection;
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
			public int $queries = 0;

			public function rows( string $sql ): array {
				// A small row, a row of 16 MiB and another small row, then the end of the table.
				if ( 1 !== ++$this->queries ) {
					return [];
				}
				return [
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
}
