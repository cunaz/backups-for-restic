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

final class DumpLimitsTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_an_oversized_first_row_cannot_complete_a_dump_the_importer_would_refuse(): void {
		$db       = new class() implements Connection {
			public function rows( string $sql ): array {
				return [ [ 'body' => str_repeat( 'x', SqlLexer::MAX_BYTES ) ] ];
			}
		};
		$table    = new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`body` longtext) ENGINE=InnoDB', [ 'body' => SqlFormatter::TEXT ], null, [] );
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
			self::fail( 'A row larger than the import statement limit cannot be recorded as complete.' );
		} catch ( DatabaseFailed $error ) {
			self::assertStringContainsString( 'database row exceeds', $error->getMessage() );
			self::assertSame( 'wp_rows', $error->context()['table'] );
			self::assertFalse( $complete );
		}
	}
}
