<?php
/**
 * Database adapter on $wpdb.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\DedicatedConnection;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the dump's reads and scoped session settings through WordPress' own connection, so
 * the dump sees the same server, character set and credentials as the site. dedicated()
 * opens a connection of the dump's own with the same settings, when it can verify one.
 */
final class WpdbConnection implements Connection {

	/**
	 * Uses the WordPress connection.
	 *
	 * @param \wpdb $wpdb Database object.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
	}

	/**
	 * Runs a query.
	 *
	 * @param string $sql Query with quoted identifiers and numeric literals only.
	 * @return list<array<string, string|null>> Rows.
	 * @throws DatabaseFailed If the query fails.
	 */
	public function rows( string $sql ): array {
		$suppressed = $this->wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The dump builders quote identifiers, validate numeric seek keys and hex-encode string literals before passing their SQL to this adapter.
		$result = $this->wpdb->get_results( $sql, ARRAY_A );
		$error  = $this->wpdb->last_error;
		$errno  = '' === $error ? 0 : self::errno( $this->wpdb );
		$this->wpdb->suppress_errors( $suppressed );
		$this->wpdb->flush();
		if ( '' !== $error || ! is_array( $result ) ) {
			DatabaseFailed::raise(
				'A database query failed.',
				[
					'error' => $error,
					'errno' => $errno,
				]
			);
		}

		$rows = [];
		foreach ( $result as $row ) {
			$fields = [];
			foreach ( $row as $column => $value ) {
				$fields[ (string) $column ] = is_scalar( $value ) ? (string) $value : null;
			}
			$rows[] = $fields;
		}
		return $rows;
	}

	/**
	 * Opens a connection that only the database dump uses, so that it can read each table of a
	 * tick in one snapshot without a transaction on the site's connection (ADR 0021). It uses
	 * the host, port or socket, user, password, database and client flags that $wpdb connected
	 * with, and is used only after checks that it reaches the same server and database as the
	 * site's connection, and is encrypted if that one is. Where the site's connection is
	 * encrypted, it is not even opened unless the client flags ask for encryption from the first
	 * packet, as they do for wpdb: otherwise the account would authenticate in the clear before
	 * the check could refuse the connection. Otherwise, for example behind a
	 * database drop-in with connection settings of its own, the dump reads through the site's
	 * connection, without snapshots.
	 *
	 * @param \wpdb        $wpdb      Database object.
	 * @param RandomSource $random    Randomness for the name of the lock that identifies the server.
	 * @param string|null  $time_zone Time zone for the dump's reads, or null to keep the server's.
	 * @return DedicatedConnection|null Connection, or null if none could be opened and verified.
	 */
	public static function dedicated( \wpdb $wpdb, RandomSource $random, ?string $time_zone ): ?DedicatedConnection {
		$settings = self::settings( $wpdb );
		if ( null === $settings ) {
			return null;
		}
		[ $host, $user, $password, $name ] = $settings;
		$address                           = $wpdb->parse_db_host( $host );
		if ( false === $address ) {
			return null;
		}
		[ $hostname, $port, $socket, $ipv6 ] = $address;
		// As wpdb::db_connect(): mysqlnd needs an IPv6 address in brackets, and the port an integer.
		$hostname = $ipv6 && extension_loaded( 'mysqlnd' ) ? '[' . $hostname . ']' : $hostname;
		$port     = is_numeric( $port ) ? (int) $port : null;
		$flags    = defined( 'MYSQL_CLIENT_FLAGS' ) ? constant( 'MYSQL_CLIENT_FLAGS' ) : 0;
		$flags    = is_int( $flags ) ? $flags : 0;
		$site     = new self( $wpdb );
		try {
			$cipher = self::cipher( $site );
		} catch ( DatabaseFailed ) {
			return null;
		}
		// Unknown when the session shows no row: an inherited sql_select_limit of 0 hides it,
		// also on an encrypted connection. A database drop-in can encrypt the site's
		// connection with settings of its own.
		if ( null === $cipher || ( '' !== $cipher && 0 === ( $flags & MYSQLI_CLIENT_SSL ) ) ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- The dump's own connection, with $wpdb's settings, holds the read-only snapshot that the site's connection must not (ADR 0021).
			$mysqli = mysqli_init();
			if ( false === $mysqli ) {
				return null;
			}
			$mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
			$mysqli->options( MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 0 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed connection only means that the dump reads through $wpdb; PHP's warning would name the account.
			if ( ! @$mysqli->real_connect( $hostname, $user, $password, $name, $port, $socket, $flags ) ) {
				return null;
			}
			$dedicated = new DedicatedConnection( $mysqli, $time_zone );
		} catch ( \mysqli_sql_exception | DatabaseFailed ) {
			return null;
		}
		try {
			if ( self::same_database( $site, $cipher, $dedicated, $random ) ) {
				return $dedicated;
			}
		} catch ( DatabaseFailed ) {
			// A check that cannot run counts as failed: the dump reads through $wpdb.
			$dedicated->close();
			return null;
		}
		$dedicated->close();
		return null;
	}

	/**
	 * The server's error number of the last query, where $wpdb connects with mysqli: the dump
	 * tells a lock wait timeout from other failures by it, as the text of the error can be in
	 * any language.
	 *
	 * @param \wpdb $wpdb Database object.
	 * @return int Error number, or 0 if unknown.
	 */
	private static function errno( \wpdb $wpdb ): int {
		$handle = $wpdb->__get( 'dbh' );
		return $handle instanceof \mysqli ? $handle->errno : 0;
	}

	/**
	 * The connection settings of $wpdb, or of wp-config.php for a drop-in that keeps none.
	 *
	 * @param \wpdb $wpdb Database object.
	 * @return array{string, string, string, string}|null Host, user, password and database.
	 */
	private static function settings( \wpdb $wpdb ): ?array {
		$values = [];
		foreach ( [
			'dbhost'     => 'DB_HOST',
			'dbuser'     => 'DB_USER',
			'dbpassword' => 'DB_PASSWORD',
			'dbname'     => 'DB_NAME',
		] as $property => $constant ) {
			$value = $wpdb->__get( $property );
			if ( ! is_string( $value ) && defined( $constant ) ) {
				$value = constant( $constant );
			}
			if ( ! is_string( $value ) ) {
				return null;
			}
			$values[] = $value;
		}
		return [ $values[0], $values[1], $values[2], $values[3] ];
	}

	/**
	 * Whether the dump's connection reaches the server and database of the site's connection,
	 * and is encrypted if that one is. The site's connection takes a lock with a random name;
	 * locks belong to the server, so only the same server sees it taken, and a replica or a
	 * second server with a copy of the database does not.
	 *
	 * @param Connection          $site      The site's connection.
	 * @param string              $cipher    TLS cipher of the site's connection, empty if it is not encrypted.
	 * @param DedicatedConnection $dedicated The dump's connection.
	 * @param RandomSource        $random    Randomness for the lock name.
	 * @return bool True if both reach the same database.
	 * @throws DatabaseFailed If a check cannot run.
	 */
	private static function same_database( Connection $site, string $cipher, DedicatedConnection $dedicated, RandomSource $random ): bool {
		$lock = '0x' . bin2hex( 'bfr_dump_' . bin2hex( $random->bytes( 8 ) ) );
		$site->rows( 'DO GET_LOCK(' . $lock . ', 0)' );
		try {
			$holder = $dedicated->rows( 'SELECT IS_USED_LOCK(' . $lock . ') AS holder' )[0]['holder'] ?? null;
		} finally {
			$site->rows( 'DO RELEASE_LOCK(' . $lock . ')' );
		}
		$database = $site->rows( 'SELECT DATABASE() AS name' )[0]['name'] ?? null;
		$own      = '' === $cipher ? '' : self::cipher( $dedicated );
		return null !== $holder && null !== $database && $database === ( $dedicated->rows( 'SELECT DATABASE() AS name' )[0]['name'] ?? null ) && ( '' === $cipher || ( null !== $own && '' !== $own ) );
	}

	/**
	 * The TLS cipher of a connection, as its session reports it.
	 *
	 * @param Connection $connection Connection.
	 * @return string|null Cipher; empty if the connection is not encrypted, null if the session showed no value.
	 * @throws DatabaseFailed If it cannot be read.
	 */
	private static function cipher( Connection $connection ): ?string {
		$value = $connection->rows( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" )[0]['Value'] ?? null;
		return is_string( $value ) ? $value : null;
	}
}
