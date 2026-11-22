<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Dump\DedicatedConnection;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\WarnedSource;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Support\ByteSource;
use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the dump's state file decides: the time zone of a dump that an earlier version started.
 */
final class DumpStateTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		$this->directory = TempDir::create( 'dump-state' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_a_dump_that_an_earlier_version_started_reads_in_the_offset_it_recorded(): void {
		file_put_contents( $this->directory . '/state.json', '{"tables":["wp_rows"],"views":[],"time_zone":"+05:30","server":"8.0.36","results":{}}' );
		$site   = $this->site();
		$zones  = [];
		$dump   = new DatabaseDump(
			$site,
			$this->directory . '/state.json',
			'wp_',
			[],
			1767323100,
			static function ( ?string $time_zone ) use ( &$zones ): ?DedicatedConnection {
				$zones[] = $time_zone;
				return null;
			}
		);
		$output = self::read( $dump->files()[1]->source );

		self::assertSame( [ '+05:30' ], $zones, 'A connection of its own would read in the recorded offset.' );
		self::assertStringContainsString( "SET SESSION time_zone = '+05:30';\n", $output );
		$settings = array_values( array_filter( $site->statements, static fn ( string $sql ): bool => str_starts_with( $sql, 'SET @bfr_dump_client' ) ) );
		self::assertNotSame( [], $settings );
		foreach ( $settings as $sql ) {
			self::assertStringContainsString( 'time_zone = 0x' . bin2hex( '+05:30' ), $sql, 'The site connection reads in the recorded offset, not in its own zone.' );
		}
	}

	public function test_a_recorded_time_zone_that_is_no_offset_stops_the_dump(): void {
		file_put_contents( $this->directory . '/state.json', '{"tables":["wp_rows"],"views":[],"time_zone":"SYSTEM","server":"8.0.36","results":{}}' );
		$this->expectException( DatabaseFailed::class );
		$this->expectExceptionMessage( 'not a numeric offset' );
		( new DatabaseDump( $this->site(), $this->directory . '/state.json', 'wp_', [], 1767323100 ) )->files();
	}

	public function test_the_manifest_limit_is_the_one_the_restore_reads(): void {
		self::assertSame( Manifest::MAX_BYTES, DatabaseDump::MANIFEST_BYTES );
	}

	/** @return iterable<string, array{int, int, string, bool, bool}> */
	public static function manifests(): iterable {
		// Tables, key columns per table, a key column name, line breaks kept, details kept.
		yield 'a site of a few tables' => [ 12, 2, 'id', true, true ];
		yield 'thousands of tables with long key names' => [ 4000, 4, str_repeat( 'k', 64 ), false, true ];
		yield 'thousands of tables with the longest keys' => [ 3000, 16, str_repeat( 'k"', 32 ), false, false ];
	}

	#[DataProvider( 'manifests' )]
	public function test_the_manifest_stays_within_what_the_restore_reads( int $count, int $columns, string $column, bool $pretty, bool $details ): void {
		$this->state( $count, array_fill( 0, $columns, $column ), static fn ( int $table ): string => sprintf( 'wp_t%05d', $table ) );
		$dump   = new DatabaseDump( $this->site(), $this->directory . '/state.json', 'wp_', [], 1767323100 );
		$files  = $dump->files();
		$source = ( end( $files )->source )( null );
		$json   = self::read( static fn (): ByteSource => $source );

		self::assertLessThanOrEqual( DatabaseDump::MANIFEST_BYTES, strlen( $json ) );
		self::assertNotInstanceOf( WarnedSource::class, $source );
		self::assertCount( $count, Manifest::parse( $json, 'wp_' )->tables, 'The restore reads it.' );
		self::assertSame( $pretty, substr_count( $json, "\n" ) > 1, 'Line breaks are the first to go.' );
		$manifest = json_decode( $json, true );
		self::assertIsArray( $manifest );
		self::assertSame( $details ? array_fill( 0, $columns, $column ) : null, $manifest['tables'][0]['key'] ?? null, 'Then how the rows were read.' );
	}

	public function test_a_table_list_larger_than_the_restore_reads_is_kept_with_a_warning(): void {
		// Ten thousand tables, as many as the restore accepts, with names of 64 characters
		// that JSON and the file names escape.
		$this->state( 10000, [ 'id' ], static fn ( int $table ): string => sprintf( 'wp_%05d', $table ) . str_repeat( '"', 30 ) . str_repeat( 'n', 26 ) );
		$dump   = new DatabaseDump( $this->site(), $this->directory . '/state.json', 'wp_', [], 1767323100 );
		$files  = $dump->files();
		$source = ( end( $files )->source )( null );
		$json   = self::read( static fn (): ByteSource => $source );

		self::assertGreaterThan( DatabaseDump::MANIFEST_BYTES, strlen( $json ) );
		self::assertInstanceOf( ResumableByteSource::class, $source );
		self::assertNull( $source->cursor() );
		self::assertCount( 1, $source->warnings() );
		self::assertStringContainsString( 'command-line client', $source->warnings()[0] );
		$manifest = json_decode( $json, true );
		self::assertIsArray( $manifest );
		self::assertCount( 10000, $manifest['tables'], 'Every table is still listed.' );
	}

	/**
	 * Writes the state of a dump whose tables are all done.
	 *
	 * @param list<string>          $key  Key columns of every table.
	 * @param \Closure(int): string $name Table name by number.
	 */
	private function state( int $count, array $key, \Closure $name ): void {
		$tables  = [];
		$results = [];
		for ( $table = 1; $table <= $count; ++$table ) {
			$tables[] = $name( $table );
			$results[ ( new Table( $name( $table ), '', [], null, [] ) )->file() ] = [
				'table'    => $name( $table ),
				'rows'     => 1000 + $table,
				'bytes'    => 90000 + $table,
				'snapshot' => true,
				'key'      => $key,
				'restarts' => 0,
				'largest'  => 1048576,
			];
		}
		file_put_contents(
			$this->directory . '/state.json',
			(string) json_encode(
				[
					'version'   => 2,
					'tables'    => $tables,
					'views'     => [],
					'time_zone' => '+00:00',
					'server'    => '8.4.0',
					'results'   => $results,
				]
			)
		);
	}

	/**
	 * Reads a source to its end.
	 *
	 * @param \Closure(?string): ByteSource $open Source factory.
	 */
	private static function read( \Closure $open ): string {
		$source = $open( null );
		$output = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			$output .= $block;
		}
		return $output;
	}

	/**
	 * A site connection with one empty table `wp_rows (id int primary key)` that records statements.
	 */
	private function site(): Connection {
		return new class() implements Connection {

			/** @var list<string> */
			public array $statements = [];

			private string $probe = '';

			private string $zone = 'SYSTEM';

			public function rows( string $sql ): array {
				$this->statements[] = $sql;
				if ( 1 === preg_match( '/\ASET @bfr_dump_probe = 0x([0-9a-f]+)\z/', $sql, $match ) ) {
					$this->probe = strtoupper( $match[1] );
					return [];
				}
				if ( 1 === preg_match( '/ time_zone = 0x([0-9a-f]+)/', $sql, $match ) ) {
					$this->zone = (string) hex2bin( $match[1] );
				}
				return match ( true ) {
					'SELECT HEX(@bfr_dump_probe) AS probe LIMIT 1' === $sql => [ [ 'probe' => $this->probe ] ],
					// One session: the read-back through a table sees the dump's settings.
					str_starts_with( $sql, 'SELECT HEX(@bfr_dump_probe) AS probe, ' ) => [
						[
							'probe'        => $this->probe,
							'results'      => 'utf8mb4',
							'zone'         => $this->zone,
							'sort_length'  => '8388608',
							'select_limit' => '18446744073709551615',
							'lock_wait'    => '5',
						],
					],
					str_starts_with( $sql, 'SELECT @@SESSION.sql_quote_show_create' ) => [
						[
							'quote_names' => '1',
							'mode'        => 'NO_AUTO_VALUE_ON_ZERO',
						],
					],
					str_starts_with( $sql, 'SHOW CREATE TABLE' ) => [ [ 'Create Table' => "CREATE TABLE `wp_rows` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB" ] ],
					str_starts_with( $sql, 'SHOW FULL COLUMNS' ) => [
						[
							'Field'     => 'id',
							'Type'      => 'int',
							'Collation' => null,
							'Null'      => 'NO',
							'Extra'     => '',
						],
					],
					str_starts_with( $sql, 'SHOW KEYS' ) => [
						[
							'Key_name'     => 'PRIMARY',
							'Non_unique'   => '0',
							'Seq_in_index' => '1',
							'Column_name'  => 'id',
							'Sub_part'     => null,
						],
					],
					default => [],
				};
			}
		};
	}
}
