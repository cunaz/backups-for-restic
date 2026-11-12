<?php
/**
 * Restore queries without automatic reconnection or query filters.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the existing site's mysqli session; never opens or reconnects a connection.
 * The WordPress adapter supplies its handle only for the duration of an import tick.
 */
final readonly class MysqliConnection implements Connection {

	/**
	 * Captures a connected handle, without changing its global mysqli reporting mode.
	 *
	 * @param \mysqli $mysqli Existing database connection.
	 */
	public function __construct( private \mysqli $mysqli ) {
	}

	/**
	 * Reads bounded metadata or changes scoped settings on the same session as the writes.
	 *
	 * @param string $sql Query made by the importer.
	 * @return list<array<string, string|null>> String-valued metadata, or an empty list for SET.
	 * @throws DatabaseFailed If the server fails or warns.
	 */
	public function rows( string $sql ): array {
		$result = $this->query( $sql );
		if ( ! $result instanceof \mysqli_result ) {
			return [];
		}
		try {
			$rows = [];
			for ( $row = $result->fetch_assoc(); null !== $row && false !== $row; $row = $result->fetch_assoc() ) {
				$fields = [];
				foreach ( $row as $column => $value ) {
					$fields[ $column ] = null === $value ? null : (string) $value;
				}
				$rows[] = $fields;
			}
			return $rows;
		} finally {
			$result->free();
		}
	}

	/**
	 * Executes a statement once, with a checked row count and no silent conversions.
	 *
	 * @param string $sql Checked statement.
	 * @return int Affected rows.
	 * @throws DatabaseFailed If the statement fails, warns or unexpectedly returns rows.
	 */
	public function execute( string $sql ): int {
		$result = $this->query( $sql );
		if ( $result instanceof \mysqli_result ) {
			$result->free();
			throw new DatabaseFailed( 'A database restore statement unexpectedly returned rows.' );
		}
		$rows = $this->mysqli->affected_rows;
		if ( ! is_int( $rows ) || $rows < 0 ) {
			throw new DatabaseFailed( 'A database restore statement has no valid affected row count.' );
		}
		return $rows;
	}

	/**
	 * Keeps server error text, which may quote imported private data, out of exceptions.
	 *
	 * @param string $sql Validated statement.
	 * @return \mysqli_result|true Result of the one attempt.
	 * @throws DatabaseFailed If the statement fails or warns.
	 */
	private function query( string $sql ): \mysqli_result|true {
		try {
			$result = @$this->mysqli->query( $sql ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Report only a numeric error code below, even if another plugin enabled mysqli warning output.
		} catch ( \mysqli_sql_exception $error ) {
			DatabaseFailed::raise( 'The database refused a restore statement.', [ 'code' => $error->getCode() ] );
		} catch ( \Error $error ) {
			throw new DatabaseFailed( 'The database restore connection is no longer usable.' );
		}
		if ( false === $result ) {
			DatabaseFailed::raise( 'The database refused a restore statement.', [ 'code' => $this->mysqli->errno ] );
		}
		if ( $this->mysqli->warning_count > 0 ) {
			if ( $result instanceof \mysqli_result ) {
				$result->free();
			}
			DatabaseFailed::raise( 'The database reported a warning while restoring; the batch was refused.', [ 'warnings' => $this->mysqli->warning_count ] );
		}
		return $result;
	}
}
