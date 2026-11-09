<?php
/**
 * The dump's database port over mysqli.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;

/**
 * Test adapter for a local MySQL or MariaDB server. Like $wpdb, it returns every value as a
 * string or null.
 */
final class MysqliConnection implements Connection {

	public function __construct( public readonly \mysqli $mysqli ) {
		$mysqli->options( MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false );
		$mysqli->set_charset( 'utf8mb4' );
	}

	/**
	 * Connects as configured by BFR_TEST_MYSQL_* or as root over the local socket.
	 */
	public static function connect( string $database = '' ): ?self {
		mysqli_report( MYSQLI_REPORT_OFF );
		$host     = self::environment( 'BFR_TEST_MYSQL_HOST', 'localhost' );
		$user     = self::environment( 'BFR_TEST_MYSQL_USER', 'root' );
		$password = self::environment( 'BFR_TEST_MYSQL_PASSWORD', '' );
		$mysqli   = @new \mysqli( $host, $user, $password, $database );
		return 0 === $mysqli->connect_errno ? new self( $mysqli ) : null;
	}

	public function rows( string $sql ): array {
		$result = $this->mysqli->query( $sql );
		if ( false === $result ) {
			throw new DatabaseFailed( 'Query failed: ' . $this->mysqli->error );
		}
		if ( true === $result ) {
			return [];
		}
		$rows = [];
		for ( $row = $result->fetch_assoc(); null !== $row && false !== $row; $row = $result->fetch_assoc() ) {
			$fields = [];
			foreach ( $row as $column => $value ) {
				$fields[ (string) $column ] = null === $value ? null : (string) $value;
			}
			$rows[] = $fields;
		}
		$result->free();
		return $rows;
	}

	/**
	 * The command line of the mysql or mariadb client for the same server and account as
	 * connect(): root over the local socket, or BFR_TEST_MYSQL_*.
	 *
	 * @return list<string> Command and arguments.
	 */
	public static function client( string $database ): array {
		$command  = [ is_executable( '/usr/bin/mariadb' ) ? '/usr/bin/mariadb' : '/usr/bin/mysql', '-u', self::environment( 'BFR_TEST_MYSQL_USER', 'root' ) ];
		$host     = self::environment( 'BFR_TEST_MYSQL_HOST', 'localhost' );
		$password = self::environment( 'BFR_TEST_MYSQL_PASSWORD', '' );
		if ( 'localhost' !== $host ) {
			array_push( $command, '-h', $host );
		}
		if ( '' !== $password ) {
			$command[] = '-p' . $password;
		}
		$command[] = $database;
		return $command;
	}

	public static function environment( string $name, string $fallback ): string {
		$value = getenv( $name );
		return false === $value || '' === $value ? $fallback : $value;
	}

	public function execute( string $sql ): void {
		if ( false === $this->mysqli->query( $sql ) ) {
			throw new \RuntimeException( 'Statement failed: ' . $this->mysqli->error . ' in ' . substr( $sql, 0, 200 ) );
		}
	}
}
