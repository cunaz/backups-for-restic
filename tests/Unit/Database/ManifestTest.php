<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Exception\FormatException;

final class ManifestTest extends TestCase {

	/** @return array<string, mixed> */
	private static function data(): array {
		return [
			'format'    => 'backups-for-restic-dump/1',
			'created'   => '2026-11-12T00:00:00Z',
			'server'    => '8.4.0',
			'charset'   => 'utf8mb4',
			'time_zone' => '+02:00',
			'prefix'    => 'wp_',
			'tables'    => [
				[
					'name'  => 'wp_posts',
					'file'  => '010-wp_posts.sql',
					'rows'  => 123,
					'bytes' => 4096,
				],
			],
			'views'     => [ 'wp_titles' ],
		];
	}

	public function test_complete_metadata_keeps_counts_and_binds_resumption_to_its_bytes(): void {
		$json     = json_encode( self::data(), JSON_THROW_ON_ERROR );
		$manifest = Manifest::parse( $json, 'wp_' );
		self::assertSame( self::data()['tables'], $manifest->tables );
		self::assertSame( [ 'wp_titles' ], $manifest->views );
		self::assertSame( '+02:00', $manifest->time_zone );
		self::assertSame( hash( 'sha256', $json ), $manifest->fingerprint );
	}

	/** @return iterable<string, array{string}> */
	public static function rejected(): iterable {
		foreach ( [
			'format'    => 'backups-for-restic-dump/2',
			'created'   => 'yesterday',
			'charset'   => 'latin1',
			'time_zone' => "+00:00'; DROP TABLE wp_users; --",
			'prefix'    => 'other_',
			'tables'    => [
				[
					'name'  => 'wp_posts',
					'file'  => '../wp-config.php',
					'rows'  => 1,
					'bytes' => 1,
				],
			],
			'views'     => [ 'other_view' ],
		] as $field => $value ) {
			yield $field => [ json_encode( array_replace( self::data(), [ $field => $value ] ), JSON_THROW_ON_ERROR ) ];
		}
		foreach ( [
			'missing row count'  => [ 'rows' => null ],
			'negative rows'      => [ 'rows' => -1 ],
			'missing byte count' => [ 'bytes' => null ],
			'zero bytes'         => [ 'bytes' => 0 ],
			'foreign table'      => [
				'name' => 'other_posts',
				'file' => '010-other_posts.sql',
			],
			'qualified table'    => [
				'name' => 'wp_other.table',
				'file' => '010-wp_other%2Etable.sql',
			],
		] as $name => $change ) {
			$data              = self::data();
			$data['tables'][0] = array_replace( $data['tables'][0], $change );
			yield $name => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
		}
		$data             = self::data();
		$data['tables'][] = $data['tables'][0];
		yield 'duplicate tables' => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
		$data            = self::data();
		$data['views'][] = 'wp_posts';
		yield 'view collides with table' => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
	}

	#[DataProvider( 'rejected' )]
	public function test_partial_or_misdirected_inventory_is_refused( string $json ): void {
		$this->expectException( FormatException::class );
		Manifest::parse( $json, 'wp_' );
	}

	public function test_how_the_dump_read_each_table_is_kept_by_ordinal_and_absent_from_an_earlier_manifest(): void {
		$read             = [
			'key'               => [ 'object_id', 'term_taxonomy_id' ],
			'snapshot'          => false,
			'restarts'          => 1,
			'largest_statement' => 4000,
		];
		$data             = self::data();
		$data['tables'][] = [
			'name'  => 'wp_term_relationships',
			'file'  => '010-wp_term_relationships.sql',
			'rows'  => 2,
			'bytes' => 8192,
		] + $read;
		$manifest         = Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
		self::assertSame( [ null, $read ], $manifest->reads );
		// The other fields of the table stay what they were.
		self::assertSame( array_slice( $data['tables'][1], 0, 4 ), $manifest->tables[1] );
		$data['tables'][1]['key'] = [];
		self::assertSame( [], Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' )->reads[1]['key'] ?? null );
	}

	/** @return iterable<string, array{array<string,mixed>}> */
	public static function invalid_reads(): iterable {
		$read = [
			'key'               => [ 'id' ],
			'snapshot'          => true,
			'restarts'          => 0,
			'largest_statement' => 100,
		];
		foreach ( array_keys( $read ) as $field ) {
			$partial = $read;
			unset( $partial[ $field ] );
			yield 'without ' . $field => [ $partial ];
		}
		yield 'a key that is no list' => [ [ 'key' => [ 'a' => 'id' ] ] + $read ];
		yield 'a key of a number' => [ [ 'key' => [ 1 ] ] + $read ];
		yield 'a key with an empty name' => [ [ 'key' => [ '' ] ] + $read ];
		yield 'a key that is a name' => [ [ 'key' => 'id' ] + $read ];
		yield 'a snapshot of a number' => [ [ 'snapshot' => 1 ] + $read ];
		yield 'negative restarts' => [ [ 'restarts' => -1 ] + $read ];
		yield 'restarts as text' => [ [ 'restarts' => '0' ] + $read ];
		yield 'a negative statement' => [ [ 'largest_statement' => -1 ] + $read ];
		yield 'a statement as long as the file' => [ [ 'largest_statement' => 4096 ] + $read ];
		yield 'a statement as a number with a fraction' => [ [ 'largest_statement' => 1.5 ] + $read ];
	}

	/** @param array<string,mixed> $read */
	#[DataProvider( 'invalid_reads' )]
	public function test_a_record_of_how_a_table_was_read_is_complete_and_of_its_kinds_or_refused( array $read ): void {
		$data              = self::data();
		$data['tables'][0] = $data['tables'][0] + $read;
		try {
			Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
			self::fail( 'An incomplete or invalid record of how the dump read a table was accepted.' );
		} catch ( FormatException $refused ) {
			self::assertSame( 'database_restore_format', $refused->context()['code'] ?? null );
		}
	}
}
