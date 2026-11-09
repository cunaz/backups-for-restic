<?php
/**
 * Database adapter on $wpdb.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the dump's read-only queries through WordPress' own connection, so the dump sees the
 * same server, character set and credentials as the site.
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The dump reads every table; identifiers are quoted by SqlFormatter and cursors are validated numbers.
		$result = $this->wpdb->get_results( $sql, ARRAY_A );
		$error  = $this->wpdb->last_error;
		$this->wpdb->suppress_errors( $suppressed );
		$this->wpdb->flush();
		if ( '' !== $error || ! is_array( $result ) ) {
			DatabaseFailed::raise( 'A database query failed.', [ 'error' => $error ] );
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
}
