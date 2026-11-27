<?php
/**
 * Scoped database settings for a restore tick.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses an active foreign transaction before any DDL could commit it. Settings come
 * from the server or the validated manifest, never from SQL commands in a dump file.
 *
 * The one place where the restore sets the session for its own statements (ADR 0043, "Session
 * settings"): the import, the rehearsal's report, the review's inspection, the exchange, its
 * undo and expiry all run in a Session. Besides the character set, SQL mode, time zone and
 * checks of the import, it lifts what the server's settings, the account's init_connect or
 * code that ran earlier in the request can leave on the site's session and what would change
 * a result without an error: a row limit, which cuts SELECT and, on MySQL 8, SHOW short; notes
 * that do not count as warnings, which the port refuses; the safe-updates mode, under which a
 * generated statement is refused; and a completion type under which COMMIT and ROLLBACK chain
 * a new transaction or close the connection. Its own ROLLBACK statements name the completion.
 */
final class Session {

	private const array VARIABLES = [ 'character_set_client', 'character_set_connection', 'character_set_results', 'collation_connection', 'time_zone', 'foreign_key_checks', 'unique_checks', 'sql_quote_show_create', 'sql_select_limit', 'sql_notes', 'sql_safe_updates', 'completion_type', 'sql_mode' ];
	private const array BOOLEANS  = [ 'foreign_key_checks', 'unique_checks', 'sql_quote_show_create', 'sql_notes', 'sql_safe_updates' ];

	/** Numeric settings, restored as decimal numbers: a hex literal there is a number of its own. */
	private const array NUMBERS = [ 'sql_select_limit' ];

	/**
	 * Ends the guard's transaction and an unfinished batch without chaining a new one or closing
	 * the connection, whatever completion_type the session has.
	 */
	private const string ROLLBACK = 'ROLLBACK AND NO CHAIN NO RELEASE';

	/**
	 * Exact previous session values; SQL mode is restored last.
	 *
	 * @var array<string, string|null>
	 */
	private readonly array $previous;

	/**
	 * Whether close already restored the session.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Takes exclusive use of the caller's connection until close, without a global change.
	 *
	 * @param Connection    $connection Non-reconnecting database session.
	 * @param Manifest|null $manifest Validated dump, or null during cleanup.
	 * @throws DatabaseFailed If settings cannot be read, a foreign transaction is active, or setup fails.
	 */
	public function __construct( private readonly Connection $connection, ?Manifest $manifest ) {
		$fields = array_map( static fn ( string $name ): string => '@@SESSION.' . $name . ' AS ' . $name, self::VARIABLES );
		$rows   = $connection->rows( 'SELECT @@SESSION.autocommit AS autocommit, ' . implode( ', ', $fields ) );
		if ( 1 !== count( $rows ) || ( $rows[0]['autocommit'] ?? null ) !== '1' ) {
			throw new DatabaseFailed( 'Database restore requires an idle connection with autocommit enabled.' );
		}
		$previous = [];
		foreach ( self::VARIABLES as $name ) {
			if ( ! array_key_exists( $name, $rows[0] ) || ( null === $rows[0][ $name ] && 'character_set_results' !== $name ) ) {
				throw new DatabaseFailed( 'A database session setting could not be read before restore.' );
			}
			$previous[ $name ] = $rows[0][ $name ];
			if ( in_array( $name, self::BOOLEANS, true ) && ! in_array( $previous[ $name ], [ '0', '1' ], true ) ) {
				throw new DatabaseFailed( 'A database boolean setting has an unexpected value.' );
			}
			if ( in_array( $name, self::NUMBERS, true ) && 1 !== preg_match( '/\A[0-9]{1,20}\z/', (string) $previous[ $name ] ) ) {
				throw new DatabaseFailed( 'A database numeric setting has an unexpected value.' );
			}
		}
		$this->previous = $previous;
		// Without SESSION, this fails inside an active transaction (MySQL and MariaDB).
		// Consume its one-transaction characteristic immediately, before any restore DDL.
		$connection->execute( 'SET TRANSACTION READ WRITE' );
		$connection->execute( 'START TRANSACTION' );
		$connection->execute( self::ROLLBACK );
		try {
			$connection->execute( 'SET NAMES utf8mb4' );
			// A session can inherit a row limit from the server or have one left by code that ran
			// before (MySQL 8 applies it to SHOW as well), notes that do not count as warnings,
			// the safe-updates mode and a completion type that chains or releases. The largest
			// limit is the server's default; DEFAULT would be the server's setting again.
			$connection->execute( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', time_zone = " . self::literal( $manifest->time_zone ?? '+00:00' ) . ', foreign_key_checks = 1, unique_checks = 1, sql_quote_show_create = 1, sql_select_limit = 18446744073709551615, sql_notes = 1, sql_safe_updates = 0, completion_type = 0' );
		} catch ( \Throwable $error ) {
			$this->close();
			throw $error;
		}
	}

	/**
	 * Rolls back an unfinished owned batch and restores each setting, attempting all of them.
	 *
	 * @throws DatabaseFailed If the server rejected or did not restore a session setting.
	 * @throws \Throwable If rollback or restoration fails; all settings are still attempted.
	 */
	public function close(): void {
		if ( $this->closed ) {
			return;
		}
		$first = null;
		try {
			$this->connection->execute( self::ROLLBACK );
		} catch ( \Throwable $error ) {
			$first = $error;
		}
		foreach ( $this->previous as $name => $value ) {
			try {
				// Boolean variables accept numeric 0/1 or the names OFF/ON, numeric ones the
				// number. Hex string literals containing the characters "0"/"1" are not those
				// numbers, and in a numeric setting a hex literal is the number of its bytes.
				$literal = in_array( $name, self::BOOLEANS, true ) || in_array( $name, self::NUMBERS, true ) ? $value : ( null === $value ? 'NULL' : self::literal( $value ) );
				try {
					$this->connection->execute( 'SET SESSION ' . $name . ' = ' . $literal );
				} catch ( DatabaseFailed $failure ) {
					// MySQL warns when WordPress's existing legacy SQL modes are set again.
					// Read back the exact setting before accepting that metadata-only warning.
					if ( 'sql_mode' !== $name || ! isset( $failure->context()['warnings'] ) || $value !== ( $this->connection->rows( 'SELECT @@SESSION.sql_mode AS mode' )[0]['mode'] ?? null ) ) {
						throw $failure;
					}
				}
			} catch ( \Throwable $error ) {
				$first ??= $error;
			}
		}
		if ( null !== $first ) {
			throw $first;
		}
		$this->closed = true;
	}

	/**
	 * Quotes trusted metadata independently of the session's current escaping mode.
	 *
	 * @param string $value String metadata, not SQL syntax.
	 * @return string Binary literal, valid also under NO_BACKSLASH_ESCAPES.
	 */
	public static function literal( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}
}
