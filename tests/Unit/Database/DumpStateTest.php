<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Dump\DedicatedConnection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Support\ByteSource;
use BackupsForRestic\Tests\Support\TempDir;
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

			public function rows( string $sql ): array {
				$this->statements[] = $sql;
				if ( 1 === preg_match( '/\ASET @bfr_dump_probe = 0x([0-9a-f]+)\z/', $sql, $match ) ) {
					$this->probe = strtoupper( $match[1] );
					return [];
				}
				return match ( true ) {
					'SELECT HEX(@bfr_dump_probe) AS probe' === $sql => [ [ 'probe' => $this->probe ] ],
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
