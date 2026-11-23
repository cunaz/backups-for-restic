<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Exception\FormatException;
use PHPUnit\Framework\TestCase;

/** The private names and the ownership comment of one restore, which need no database. */
final class OwnedTablesNamesTest extends TestCase {

	private const string TOKEN = '0123456789abcdef0123456789abcdef';

	private static function owned( string $prefix = 'wp_', string $token = self::TOKEN, ?string $fingerprint = null ): OwnedTables {
		$none = new class() implements Connection {
			public function rows( string $sql ): array {
				throw new \LogicException( 'Naming needs no database: ' . $sql );
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'Naming needs no database: ' . $sql );
			}
		};
		return new OwnedTables( $none, $prefix, $fingerprint ?? str_repeat( 'f', 64 ), $token );
	}

	public function test_the_ownership_comment_binds_the_token_and_the_manifest(): void {
		self::assertSame( 'backups-for-restic-restore/1:' . self::TOKEN . ':' . str_repeat( 'f', 64 ), self::owned()->owner );
	}

	public function test_names_stay_outside_the_site_prefix(): void {
		self::assertSame( '_bfr_r_' . self::TOKEN . '_t7', self::owned()->shadow( 7 ) );
		self::assertSame( 'bfr_r_' . self::TOKEN . '_b7', self::owned( '_wp_' )->retained( 7 ) );
		self::assertSame( '_bfr_r_' . self::TOKEN . '_p', self::owned()->marker( 'prepared' ) );
		self::assertSame( '_bfr_r_' . self::TOKEN . '_u', self::owned()->marker( 'undone' ) );
		self::assertSame( '_bfr_r_' . self::TOKEN . '_t' . ( Manifest::MAX_TABLES - 1 ), self::owned()->shadow( Manifest::MAX_TABLES - 1 ) );
	}

	public function test_an_invalid_token_or_fingerprint_is_refused_on_its_own(): void {
		foreach ( [ [ 'ABCDEF0123456789ABCDEF0123456789', null ], [ self::TOKEN . '0', null ], [ self::TOKEN, str_repeat( 'F', 64 ) ], [ self::TOKEN, str_repeat( 'f', 63 ) ] ] as [ $token, $fingerprint ] ) {
			try {
				self::owned( 'wp_', $token, $fingerprint );
				self::fail( 'An invalid ownership binding was accepted.' );
			} catch ( FormatException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_ordinals_outside_the_inventory_and_unknown_states_are_refused(): void {
		foreach ( [ -1, Manifest::MAX_TABLES ] as $number ) {
			try {
				self::owned()->shadow( $number );
				self::fail( 'An ordinal outside the inventory was named: ' . $number );
			} catch ( FormatException ) {
				self::assertTrue( true );
			}
		}
		$this->expectException( FormatException::class );
		self::owned()->marker( 'reverted' );
	}
}
