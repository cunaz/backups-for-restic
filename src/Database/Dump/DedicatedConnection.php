<?php
/**
 * A database connection that only the dump uses.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * The dump's own session on the site's database server (ADR 0021). Because nothing else
 * uses it, the dump's settings are made once (utf8mb4, +00:00, the dump's SQL mode, the
 * largest sort length and no row limit), and the pages of a table can be read inside a read-only transaction:
 * InnoDB then serves every page of one tick from one consistent snapshot. The site's own
 * connection never sees a transaction or a changed setting of the dump.
 *
 * Each table's part of a tick starts with begin(): the transaction, and one read from the
 * table before its definition is read. That statement takes the table's metadata lock for the
 * rest of the transaction, so no ALTER can change the definition while the tick reads the
 * table. A DDL statement on that table waits until end(), at most until the end of the tick.
 *
 * A table without a key is read with one unbuffered statement (stream()). Closing such a
 * read before its end would make the client read the rest of the table first, so abandon()
 * closes the whole connection instead; the dump opens a new one when it needs one.
 */
final class DedicatedConnection implements Connection {

	/**
	 * Unbuffered result being read, if any.
	 *
	 * @var \mysqli_result|null
	 */
	private ?\mysqli_result $stream = null;

	/**
	 * Whether a transaction is open.
	 *
	 * @var bool
	 */
	private bool $transaction = false;

	/**
	 * Whether the connection was closed.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Takes a connection that nothing else uses and makes the dump's settings.
	 *
	 * @param \mysqli     $mysqli    Connected handle of the dump's own.
	 * @param string|null $time_zone Time zone for the dump's reads, or null to keep the session's; see DatabaseDump.
	 * @throws DatabaseFailed If a setting is refused.
	 */
	public function __construct( private readonly \mysqli $mysqli, ?string $time_zone ) {
		try {
			if ( true !== $this->attempt( fn (): bool => $this->mysqli->set_charset( 'utf8mb4' ) ) ) {
				throw new DatabaseFailed( 'The character set of the dump connection could not be set.' );
			}
			$zone = null === $time_zone ? '' : ', time_zone = 0x' . bin2hex( $time_zone );
			// A new session inherits sql_select_limit from the server's settings, and the limit
			// cuts SELECT and, on MySQL 8, SHOW short without an error.
			$this->rows( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', sql_quote_show_create = 1, max_sort_length = 8388608, sql_select_limit = 18446744073709551615" . $zone );
			$this->rows( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		} catch ( DatabaseFailed $error ) {
			$this->close();
			throw $error;
		}
	}

	/**
	 * Runs a query and returns all rows.
	 *
	 * @param string $sql Query.
	 * @return list<array<string, string|null>> Rows, or an empty list for a statement without a result.
	 * @throws DatabaseFailed If the query fails or the connection is closed or busy with a stream.
	 */
	public function rows( string $sql ): array {
		if ( null !== $this->stream ) {
			throw new DatabaseFailed( 'The dump connection is still reading a table.' );
		}
		$result = $this->query( $sql, MYSQLI_STORE_RESULT );
		if ( ! $result instanceof \mysqli_result ) {
			return [];
		}
		try {
			$rows = [];
			for ( $row = $result->fetch_assoc(); null !== $row && false !== $row; $row = $result->fetch_assoc() ) {
				$rows[] = self::strings( $row );
			}
			return $rows;
		} finally {
			$result->free();
		}
	}

	/**
	 * Starts a read-only transaction for one table and takes the table's metadata lock, before
	 * the table's definition and rows are read.
	 *
	 * @param string $table Table name.
	 * @throws DatabaseFailed If the transaction cannot be started or the table cannot be read.
	 */
	public function begin( string $table ): void {
		$this->end();
		$this->rows( 'START TRANSACTION READ ONLY' );
		$this->transaction = true;
		$this->rows( 'SELECT 1 FROM ' . SqlFormatter::identifier( $table ) . ' LIMIT 0' );
	}

	/**
	 * Ends the transaction of begin(), which releases the snapshot and the metadata lock.
	 *
	 * @throws DatabaseFailed If the transaction cannot be ended.
	 */
	public function end(): void {
		if ( $this->transaction && ! $this->closed ) {
			$this->transaction = false;
			// A plain COMMIT follows completion_type, which the server's settings can make
			// RELEASE (close the connection) or CHAIN (start another read-only transaction).
			$this->rows( 'COMMIT AND NO CHAIN NO RELEASE' );
		}
	}

	/**
	 * Whether begin() started a transaction that has not ended.
	 *
	 * @return bool True inside a snapshot.
	 */
	public function in_snapshot(): bool {
		return $this->transaction && ! $this->closed;
	}

	/**
	 * Starts an unbuffered read of one statement.
	 *
	 * @param string $sql Query.
	 * @throws DatabaseFailed If the query fails.
	 */
	public function stream( string $sql ): void {
		if ( null !== $this->stream ) {
			throw new DatabaseFailed( 'The dump connection is still reading a table.' );
		}
		$result = $this->query( $sql, MYSQLI_USE_RESULT );
		if ( ! $result instanceof \mysqli_result ) {
			throw new DatabaseFailed( 'A table read of the dump returned no rows to read.' );
		}
		$this->stream = $result;
	}

	/**
	 * The next row of the stream.
	 *
	 * @return array<string, string|null>|null Row, or null after the last one.
	 * @throws DatabaseFailed If reading fails.
	 */
	public function next(): ?array {
		$stream = $this->stream;
		if ( null === $stream ) {
			return null;
		}
		$row = $this->attempt( static fn (): array|false|null => $stream->fetch_assoc() );
		if ( is_array( $row ) ) {
			return self::strings( $row );
		}
		$errno        = $this->mysqli->errno;
		$this->stream = null;
		$stream->free();
		if ( 0 !== $errno ) {
			DatabaseFailed::raise( 'A table read of the dump failed.', [ 'errno' => $errno ] );
		}
		return null;
	}

	/**
	 * Stops a stream before its end by closing the connection, which also ends a transaction.
	 */
	public function abandon(): void {
		if ( null !== $this->stream ) {
			$this->stream = null;
			$this->close();
		}
	}

	/**
	 * Whether the connection was closed and the dump needs a new one.
	 *
	 * @return bool True once closed.
	 */
	public function closed(): bool {
		return $this->closed;
	}

	/**
	 * Closes the connection; the server rolls back an open transaction.
	 */
	public function close(): void {
		if ( ! $this->closed ) {
			$this->closed      = true;
			$this->transaction = false;
			$this->attempt( fn (): bool => $this->mysqli->close() );
		}
	}

	/**
	 * Runs one statement; the error code goes into the exception, the server's text does not.
	 *
	 * @param string $sql  Statement.
	 * @param int    $mode MYSQLI_STORE_RESULT or MYSQLI_USE_RESULT.
	 * @return \mysqli_result|bool Result.
	 * @throws DatabaseFailed If the statement fails or the connection is closed.
	 */
	private function query( string $sql, int $mode ): \mysqli_result|bool {
		if ( $this->closed ) {
			throw new DatabaseFailed( 'The dump connection is closed.' );
		}
		$result = $this->attempt( fn (): \mysqli_result|bool => $this->mysqli->query( $sql, $mode ) );
		if ( false === $result ) {
			DatabaseFailed::raise( 'A query of the database dump failed.', [ 'errno' => $this->mysqli->errno ] );
		}
		return $result;
	}

	/**
	 * Calls mysqli with its warnings and exceptions turned into results: another plugin may
	 * have enabled exception reporting for mysqli.
	 *
	 * @template T
	 * @param \Closure(): T $call Call.
	 * @return T|false Its result, or false if it threw.
	 */
	private function attempt( \Closure $call ): mixed {
		try {
			return @$call(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed call is reported with its error code by the caller; PHP's warning would carry the server's text.
		} catch ( \mysqli_sql_exception ) {
			return false;
		}
	}

	/**
	 * Values as strings or null, as $wpdb returns them.
	 *
	 * @param array<int|string, mixed> $row Row from mysqli.
	 * @return array<string, string|null> Row.
	 */
	private static function strings( array $row ): array {
		$fields = [];
		foreach ( $row as $column => $value ) {
			$fields[ (string) $column ] = is_scalar( $value ) ? (string) $value : null;
		}
		return $fields;
	}
}
