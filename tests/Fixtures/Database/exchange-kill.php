<?php
// The parent kills this process while it still holds the real MySQL exchange locks.
declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\MysqliConnection;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Tests\Support\MysqliConnection as TestConnection;

[ , $database, $directory, $phase, $point ] = $argv;
if ( 1 !== preg_match( '/\Abfr_exchange_[a-f0-9]{12}\z/', $database ) || ! in_array( $phase, [ 'apply', 'undo' ], true ) || ! in_array( $point, [ 'before', 'after' ], true ) ) {
	throw new RuntimeException( 'Invalid disposable exchange fixture.' );
}
$db = TestConnection::connect( $database );
if ( null === $db ) {
	throw new RuntimeException( 'The worker database is unavailable.' );
}
$direct = new MysqliConnection( $db->mysqli );
$connection = new class( $direct, $directory . '/exchange-kill', $point ) implements Connection {
	public function __construct( private Connection $inner, private string $marker, private string $point ) {}

	public function rows( string $sql ): array {
		return $this->inner->rows( $sql );
	}

	public function execute( string $sql ): int {
		$rename = str_starts_with( $sql, 'RENAME TABLE ' );
		if ( $rename && 'before' === $this->point ) {
			$this->wait_for_kill();
		}
		$rows = $this->inner->execute( $sql );
		if ( $rename && 'after' === $this->point ) {
			$this->wait_for_kill();
		}
		return $rows;
	}

	private function wait_for_kill(): never {
		file_put_contents( $this->marker, $this->point );
		sleep( 45 );
		throw new RuntimeException( 'The parent did not kill the exchange worker.' );
	}
};
$saved = json_decode( (string) file_get_contents( $directory . '/exchange-plan.json' ), true, flags: JSON_THROW_ON_ERROR );
$owned = new OwnedTables( $connection, 'wp_', $saved['fingerprint'], $saved['token'] );
$plan  = ExchangePlan::load( $saved['plan'], $owned, 'wp_' );
( new TableExchange( $connection ) )->run( $plan, 1, 'undo' === $phase );
throw new RuntimeException( 'The exchange worker missed the requested RENAME boundary.' );
