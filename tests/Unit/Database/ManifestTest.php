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
}
