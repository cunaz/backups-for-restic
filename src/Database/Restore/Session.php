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
 */
final class Session {

	private const array VARIABLES = [ 'character_set_client', 'character_set_connection', 'character_set_results', 'collation_connection', 'time_zone', 'foreign_key_checks', 'unique_checks', 'sql_quote_show_create', 'sql_mode' ];
	private const array BOOLEANS  = [ 'foreign_key_checks', 'unique_checks', 'sql_quote_show_create' ];

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
	 * @param Connection $connection Non-reconnecting database session.
	 * @param Manifest   $manifest Validated dump with a numeric time-zone offset.
	 * @throws DatabaseFailed If settings cannot be read, a foreign transaction is active, or setup fails.
	 */
	public function __construct( private readonly Connection $connection, Manifest $manifest ) {
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
		}
		$this->previous = $previous;
		// Without SESSION, this fails inside an active transaction (MySQL and MariaDB).
		// Consume its one-transaction characteristic immediately, before any restore DDL.
		$connection->execute( 'SET TRANSACTION READ WRITE' );
		$connection->execute( 'START TRANSACTION' );
		$connection->execute( 'ROLLBACK' );
		try {
			$connection->execute( 'SET NAMES utf8mb4' );
			$connection->execute( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', time_zone = " . self::literal( $manifest->time_zone ) . ', foreign_key_checks = 1, unique_checks = 1, sql_quote_show_create = 1' );
		} catch ( \Throwable $error ) {
			$this->close();
			throw $error;
		}
	}

	/**
	 * Rolls back an unfinished owned batch and restores each setting, attempting all of them.
	 *
	 * @throws \Throwable If rollback or restoration fails; all settings are still attempted.
	 */
	public function close(): void {
		if ( $this->closed ) {
			return;
		}
		$first = null;
		try {
			$this->connection->execute( 'ROLLBACK' );
		} catch ( \Throwable $error ) {
			$first = $error;
		}
		foreach ( $this->previous as $name => $value ) {
			try {
				// Boolean variables accept numeric 0/1 or the names OFF/ON. Hex string
				// literals containing the characters "0"/"1" are not those numbers.
				$literal = in_array( $name, self::BOOLEANS, true ) ? $value : ( null === $value ? 'NULL' : self::literal( $value ) );
				$this->connection->execute( 'SET SESSION ' . $name . ' = ' . $literal );
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
