<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\Restore\DatabasePreview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The codes a failed rehearsal statement is reported with. */
final class DatabasePreviewTest extends TestCase {

	/** @return iterable<string, array{int|string|null, string}> */
	public static function codes(): iterable {
		yield 'a batch refused before sending' => [ 'database_restore_packet', 'database_restore_packet' ];
		yield 'a dump refused before its first statement for the packet limit' => [ 'database_restore_packet_limit', 'database_restore_packet_limit' ];
		yield 'a dump refused before its first statement for its size' => [ 'database_restore_too_large', 'database_restore_too_large' ];
		yield 'a duplicate key that the importer explains' => [ 'database_restore_duplicate', 'database_restore_duplicate' ];
		yield 'a duplicate key that reached no explanation' => [ 1062, 'database_restore_failed' ];
		yield 'a packet the server refused' => [ 1153, 'database_restore_packet' ];
		yield 'a lock wait' => [ 1205, 'database_restore_busy' ];
		yield 'a deadlock' => [ 1213, 'database_restore_busy' ];
		yield 'server gone' => [ 2006, 'database_restore_busy' ];
		yield 'connection lost' => [ 2013, 'database_restore_busy' ];
		yield 'connection broken' => [ 2055, 'database_restore_busy' ];
		yield 'unknown character set' => [ 1115, 'database_restore_collation' ];
		yield 'collation mismatch' => [ 1253, 'database_restore_collation' ];
		yield 'unknown collation' => [ 1273, 'database_restore_collation' ];
		yield 'database denied' => [ 1044, 'database_restore_privilege' ];
		yield 'table denied' => [ 1142, 'database_restore_privilege' ];
		yield 'column denied' => [ 1143, 'database_restore_privilege' ];
		yield 'privilege missing' => [ 1227, 'database_restore_privilege' ];
		yield 'anything else' => [ 1064, 'database_restore_failed' ];
		yield 'no code' => [ null, 'database_restore_failed' ];
	}

	#[DataProvider( 'codes' )]
	public function test_a_failed_statement_gets_the_code_an_administrator_can_act_on( int|string|null $code, string $expected ): void {
		$error = new DatabaseFailed( 'The database refused a restore statement.', null === $code ? [] : [ 'code' => $code ] );
		self::assertSame( $expected, DatabasePreview::classified( $error )->context()['code'] ?? null );
	}

	public function test_the_packet_refusal_keeps_its_sizes(): void {
		$error = new DatabaseFailed( 'A row batch of 9 bytes exceeds the database server\'s max_allowed_packet of 8 bytes.', [ 'code' => 'database_restore_packet' ] );
		self::assertSame( $error, DatabasePreview::classified( $error ) );
		self::assertSame( 'The database refused a restore statement.', DatabasePreview::classified( new DatabaseFailed( 'The database refused a restore statement.', [ 'code' => 1064 ] ) )->getMessage() );
	}
}
