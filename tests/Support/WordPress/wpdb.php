<?php
/**
 * A stand-in for WordPress' wpdb, for the database suite, which runs without WordPress.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps, PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.Files.FileName -- It stands in for the class WordPress calls wpdb.

/**
 * What WpdbConnection uses of wpdb, over a mysqli connection: queries with get_results(), the
 * connection settings through __get() and the host parsing of wpdb::parse_db_host().
 */
final class wpdb {

	public string $last_error = '';

	public function __construct( public \mysqli $dbh, protected string $dbhost, protected string $dbuser, protected string $dbpassword, protected string $dbname ) {
	}

	public function __get( string $name ): mixed {
		return $this->$name;
	}

	public function suppress_errors( bool $suppress = true ): bool {
		return true;
	}

	public function flush(): void {
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		$this->last_error = '';
		$result           = $this->dbh->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->dbh->error;
			return null;
		}
		if ( true === $result ) {
			return [];
		}
		$rows = $result->fetch_all( MYSQLI_ASSOC );
		$result->free();
		return array_values( $rows );
	}

	/**
	 * The forms of WordPress: host, host:port, host:/socket and [IPv6]:port.
	 *
	 * @return array{string, int|null, string|null, bool}|false
	 */
	public function parse_db_host( string $host ): array|false {
		$socket = null;
		$at     = strpos( $host, ':/' );
		if ( false !== $at ) {
			$socket = substr( $host, $at + 1 );
			$host   = substr( $host, 0, $at );
		}
		$ipv6    = substr_count( $host, ':' ) > 1;
		$pattern = $ipv6 ? '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#' : '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
		if ( 1 !== preg_match( $pattern, $host, $matches ) ) {
			return false;
		}
		return [ $matches['host'] ?? '', empty( $matches['port'] ) ? null : (int) $matches['port'], $socket, $ipv6 ];
	}
}
