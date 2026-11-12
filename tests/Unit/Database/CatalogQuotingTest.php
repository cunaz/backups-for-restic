<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogQuotingTest extends TestCase {

	/** @return iterable<string, array{bool, string}> */
	public static function objects_and_settings(): iterable {
		foreach ( [ false, true ] as $view ) {
			foreach ( [ '0', '1' ] as $setting ) {
				yield ( $view ? 'view-' : 'table-' ) . $setting => [ $view, $setting ];
			}
		}
	}

	#[DataProvider( 'objects_and_settings' )]
	public function test_show_create_uses_quoted_identifiers_and_restores_the_original_session( bool $view, string $setting ): void {
		$db      = $this->connection( $setting );
		$catalog = new Catalog( $db );
		if ( $view ) {
			self::assertStringContainsString( 'VIEW `wp_view`', $catalog->view( 'wp_view' ) );
		} else {
			self::assertStringContainsString( 'TABLE `wp_rows`', $catalog->table( 'wp_rows' )->create );
		}
		self::assertSame( $setting, $db->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' )[0]['quote_names'] );
	}

	public function test_failed_show_create_still_restores_the_original_session(): void {
		$db = $this->connection( '0', true );
		try {
			( new Catalog( $db ) )->table( 'wp_rows' );
			self::fail( 'The database error must stop the dump.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'SHOW CREATE failed.', $error->getMessage() );
		}
		self::assertSame( '0', $db->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' )[0]['quote_names'] );
	}

	public function test_unknown_setting_cannot_produce_an_unrestorable_complete_dump(): void {
		$this->expectExceptionMessage( 'quoting setting cannot be read' );
		( new Catalog( $this->connection( 'unknown' ) ) )->table( 'wp_rows' );
	}

	private function connection( string $setting, bool $fail = false ): Connection {
		return new class( $setting, $fail ) implements Connection {
			public function __construct( private string $quoted, private readonly bool $fail ) {
			}
			public function rows( string $sql ): array {
				if ( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' === $sql ) {
					return [ [ 'quote_names' => $this->quoted ] ];
				}
				if ( str_starts_with( $sql, 'SET SESSION sql_quote_show_create = ' ) ) {
					$this->quoted = substr( $sql, -1 );
					return [];
				}
				if ( str_starts_with( $sql, 'SHOW CREATE ' ) ) {
					TestCase::assertSame( '1', $this->quoted, 'The quoted form must be requested before schema is read.' );
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
