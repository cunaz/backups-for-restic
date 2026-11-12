<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogQuotingTest extends TestCase {

	/** @return iterable<string, array{bool, string, string}> */
	public static function objects_and_settings(): iterable {
		foreach ( [ false, true ] as $view ) {
			foreach ( [ '0', '1' ] as $setting ) {
				foreach ( [ '', 'NO_AUTO_VALUE_ON_ZERO', 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES,NO_TABLE_OPTIONS' ] as $mode ) {
					yield ( $view ? 'view-' : 'table-' ) . $setting . '-' . $mode => [ $view, $setting, $mode ];
				}
			}
		}
	}

	#[DataProvider( 'objects_and_settings' )]
	public function test_show_create_uses_quoted_identifiers_and_restores_the_original_session( bool $view, string $setting, string $mode ): void {
		$db      = $this->connection( $setting, false, $mode );
		$catalog = new Catalog( $db );
		if ( $view ) {
			self::assertStringContainsString( 'VIEW `wp_view`', $catalog->view( 'wp_view' ) );
		} else {
			self::assertStringContainsString( 'TABLE `wp_rows`', $catalog->table( 'wp_rows' )->create );
		}
		self::assertSame( $setting, $db->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' )[0]['quote_names'] );
		self::assertSame( $mode, $db->rows( 'SELECT @@SESSION.sql_mode AS mode' )[0]['mode'] );
	}

	public function test_failed_show_create_still_restores_the_original_session(): void {
		$db = $this->connection( '0', true, 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES' );
		try {
			( new Catalog( $db ) )->table( 'wp_rows' );
			self::fail( 'The database error must stop the dump.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'SHOW CREATE failed.', $error->getMessage() );
		}
		self::assertSame( '0', $db->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' )[0]['quote_names'] );
		self::assertSame( 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES', $db->rows( 'SELECT @@SESSION.sql_mode AS mode' )[0]['mode'] );
	}

	public function test_unknown_setting_cannot_produce_an_unrestorable_complete_dump(): void {
		$this->expectExceptionMessage( 'quoting setting or SQL mode cannot be read' );
		( new Catalog( $this->connection( 'unknown' ) ) )->table( 'wp_rows' );
	}

	private function connection( string $setting, bool $fail = false, string $mode = 'NO_AUTO_VALUE_ON_ZERO' ): Connection {
		return new class( $setting, $fail, $mode ) implements Connection {
			public function __construct( private string $quoted, private readonly bool $fail, private string $mode ) {
			}
			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT @@SESSION.sql_quote_show_create AS quote_names' ) ) {
					return [
						[
							'quote_names' => $this->quoted,
							'mode'        => $this->mode,
						],
					];
				}
				if ( 'SELECT @@SESSION.sql_mode AS mode' === $sql ) {
					return [ [ 'mode' => $this->mode ] ];
				}
				if ( 1 === preg_match( '/\ASET SESSION sql_quote_show_create = ([01]), sql_mode = (.+)\z/', $sql, $matches ) ) {
					$this->quoted = $matches[1];
					$this->mode   = str_starts_with( $matches[2], '0x' ) ? hex2bin( substr( $matches[2], 2 ) ) : trim( $matches[2], "'" );
					return [];
				}
				if ( str_starts_with( $sql, 'SET SESSION sql_quote_show_create = ' ) ) {
					$this->quoted = substr( $sql, -1 );
					return [];
				}
				if ( str_starts_with( $sql, 'SHOW CREATE ' ) ) {
					TestCase::assertSame( '1', $this->quoted, 'The quoted form must be requested before schema is read.' );
					TestCase::assertSame( 'NO_AUTO_VALUE_ON_ZERO', $this->mode, 'Schema must use the dump SQL mode, including all table options and backslash escaping.' );
					if ( $this->fail ) {
						throw new DatabaseFailed( 'SHOW CREATE failed.' );
					}
					return [
						[
							'Create Table' => 'CREATE TABLE `wp_rows` (`id` INT)',
							'Create View'  => 'CREATE VIEW `wp_view` AS SELECT `id` FROM `wp_rows`',
						],
					];
				}
				return [];
			}
		};
	}
}
