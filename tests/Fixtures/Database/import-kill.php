<?php
// CI-only worker. The parent sends SIGKILL after the chosen real database operation.
declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\MysqliConnection;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Tests\Support\MysqliConnection as TestConnection;

[ , $database, $directory, $token, $point ] = $argv;
$db = TestConnection::connect( $database );
if ( null === $db ) {
	throw new RuntimeException( 'The worker database is unavailable.' );
}
$connection = new class( new MysqliConnection( $db->mysqli ), $directory, $point ) implements Connection {
	public function __construct( private Connection $inner, private string $directory, private string $point ) {}
	public function rows( string $sql ): array { return $this->inner->rows( $sql ); }
	public function execute( string $sql ): int {
		if ( 'COMMIT' === $sql && 'before-commit' === $this->point ) {
			$this->stop();
		}
		$rows = $this->inner->execute( $sql );
		$point = match ( true ) {
			str_starts_with( $sql, 'CREATE TABLE' ) && str_contains( $sql, '_p`' ) => 'ledger-created',
			str_starts_with( $sql, 'CREATE TABLE' ) && str_contains( $sql, '_t0`' ) => 'shadow-created',
			str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, '_t0`' ) => 'rows-inserted',
			str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, ' SET byte_offset = ' ) => 'progress-written',
			'COMMIT' === $sql => 'committed',
			default => '',
		};
		if ( $point === $this->point ) {
			$this->stop();
		}
		return $rows;
	}
	private function stop(): never {
		file_put_contents( $this->directory . '/kill-point', $this->point );
		while ( true ) { usleep( 100000 ); }
	}
};
$manifest = Manifest::parse( file_get_contents( $directory . '/manifest.json' ), 'wp_' );
$importer = new TableImporter( $connection, $manifest, $directory, $token );
while ( ! $importer->tick( 1000, 20.0 )['done'] ) {}
throw new RuntimeException( 'The worker completed without reaching its kill point.' );
