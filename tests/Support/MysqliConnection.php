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
		// mysqli accepts host:port; the client needs the port on its own, and TCP for localhost.
		$port = null;
		if ( 1 === preg_match( '/^([^:]+):(\d+)$/', $host, $match ) ) {
			[ , $host, $port ] = $match;
		}
		if ( 'localhost' !== $host || null !== $port ) {
			array_push( $command, '-h', $host );
		}
		if ( null !== $port ) {
			array_push( $command, '-P', $port, '--protocol=TCP' );
		}
		if ( '' !== $password ) {
			$command[] = '-p' . $password;
		}
		$command[] = $database;
		return $command;
	}

	/**
	 * Imports SQL files in name order with the command-line client, as the disaster-recovery
	 * guide describes. The files are streamed to the client, which sends one statement at a
	 * time, so neither their size nor the server's max_allowed_packet limits a dump.
	 *
	 * @param list<string> $files SQL files.
	 * @throws \RuntimeException If the client cannot be started or reports an error.
	 */
	public static function import( string $database, array $files ): void {
		sort( $files );
		$output  = (string) tempnam( sys_get_temp_dir(), 'bfr-import-' );
		$process = proc_open( self::client( $database ), [ [ 'pipe', 'r' ], [ 'file', $output, 'w' ], [ 'redirect', 1 ] ], $pipes );
		if ( ! is_resource( $process ) ) {
			unlink( $output );
			throw new \RuntimeException( 'The database client could not be started.' );
		}
		foreach ( $files as $file ) {
			$source = fopen( $file, 'rb' );
			// A client that stopped at an error closes the pipe; its message is reported below.
			if ( false === $source || false === @stream_copy_to_stream( $source, $pipes[0] ) ) {
				break;
			}
			fclose( $source );
		}
		fclose( $pipes[0] );
		$code    = proc_close( $process );
		$message = (string) file_get_contents( $output );
		unlink( $output );
		if ( 0 !== $code ) {
			throw new \RuntimeException( 'Import into ' . $database . ' failed: ' . $message );
		}
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
