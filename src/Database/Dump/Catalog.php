<?php
/**
 * Lists what to dump.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Database\Privileges;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Reads tables, columns, keys and views from the server.
 */
final class Catalog {

	/**
	 * Creates the catalog.
	 *
	 * @param Connection $connection Database.
	 */
	public function __construct( private readonly Connection $connection ) {
	}

	/**
	 * Names of the base tables to dump, sorted.
	 *
	 * @param string       $prefix  Only tables starting with this prefix; empty for all.
	 * @param list<string> $exclude Table names to leave out.
	 * @return list<string> Table names.
	 * @throws DatabaseFailed If the server cannot list tables.
	 */
	public function table_names( string $prefix, array $exclude ): array {
		return $this->names( 'BASE TABLE', $prefix, $exclude );
	}

	/**
	 * Names of the views, sorted.
	 *
	 * @param string $prefix Only views starting with this prefix; empty for all.
	 * @return list<string> View names.
	 * @throws DatabaseFailed If the server cannot list views.
	 */
	public function view_names( string $prefix ): array {
		return $this->names( 'VIEW', $prefix, [] );
	}

	/**
	 * Records selected tables that depend on triggers. The dump has no trigger bodies,
	 * so a future swap needs to distinguish a verified empty set from hidden metadata.
	 *
	 * @param list<string> $tables Selected tables.
	 * @return list<string>|null Table names, or null when discovery is incomplete.
	 */
	public function trigger_tables( array $tables ): ?array {
		if ( ! in_array( 'TRIGGER', Privileges::database( $this->connection ), true ) ) {
			return null;
		}
		try {
			$rows = $this->connection->rows( 'SELECT DISTINCT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY EVENT_OBJECT_TABLE LIMIT 10001' );
			if ( count( $rows ) > 10000 ) {
				return null;
			}
			return array_values( array_intersect( $tables, array_column( $rows, 'EVENT_OBJECT_TABLE' ) ) );
		} catch ( DatabaseFailed ) {
			return null;
		}
	}

	/**
	 * Reads a table's structure.
	 *
	 * @param string $name Table name.
	 * @return Table Table.
	 * @throws DatabaseFailed If the table cannot be described.
	 */
	public function table( string $name ): Table {
		$quoted = SqlFormatter::identifier( $name );
		$create = $this->show_create( 'TABLE', $name );
		if ( ! isset( $create[0]['Create Table'] ) ) {
			DatabaseFailed::raise( 'The table structure cannot be read.', [ 'table' => $name ] );
		}
		$columns      = [];
		$integer_keys = [];
		foreach ( $this->connection->rows( 'SHOW COLUMNS FROM ' . $quoted ) as $column ) {
			// Generated columns cannot be inserted; the server computes them again on import.
			$extra = strtoupper( $column['Extra'] ?? '' );
			if ( ! str_contains( $extra, 'VIRTUAL GENERATED' ) && ! str_contains( $extra, 'STORED GENERATED' ) ) {
				$field             = (string) $column['Field'];
				$type              = (string) $column['Type'];
				$columns[ $field ] = SqlFormatter::kind( $type );
				if ( 1 === preg_match( '/\A(tinyint|smallint|mediumint|int|integer|bigint)\b/i', $type ) ) {
					$integer_keys[] = $field;
				}
			}
		}
		$primary = [];
		foreach ( $this->connection->rows( 'SHOW KEYS FROM ' . $quoted . " WHERE Key_name = 'PRIMARY'" ) as $key ) {
			$primary[ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
		}
		ksort( $primary );
		$primary = array_values( $primary );
		$key     = 1 === count( $primary ) && in_array( $primary[0], $integer_keys, true ) ? $primary[0] : null;
		return new Table( $name, SqlFormatter::one_line( $create[0]['Create Table'] ), $columns, $key, $primary );
	}

	/**
	 * The CREATE statement of a view, without DEFINER so that it imports on other servers.
	 *
	 * @param string $name View name.
	 * @return string One-line CREATE OR REPLACE VIEW statement.
	 * @throws DatabaseFailed If the view cannot be read.
	 */
	public function view( string $name ): string {
		$rows = $this->show_create( 'VIEW', $name );
		if ( ! isset( $rows[0]['Create View'] ) ) {
			DatabaseFailed::raise( 'The view cannot be read.', [ 'view' => $name ] );
		}
		$create = SqlFormatter::one_line( $rows[0]['Create View'] );
		$create = (string) preg_replace( '/\ACREATE\s+(ALGORITHM=\S+\s+)?DEFINER=\S+\s+/i', 'CREATE $1', $create );
		return (string) preg_replace( '/\ACREATE\s+/i', 'CREATE OR REPLACE ', $create, 1 );
	}


	/**
	 * Reads quoted, complete schema under the dump's SQL mode, restoring the site session.
	 *
	 * @param string $type TABLE or VIEW, selected by the catalog itself.
	 * @param string $name Quoted object name.
	 * @return list<array<string, string|null>> SHOW CREATE rows.
	 * @throws DatabaseFailed If the session setting cannot be read, set or restored.
	 */
	private function show_create( string $type, string $name ): array {
		$setting = $this->connection->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names, @@SESSION.sql_mode AS mode' );
		$quoted  = $setting[0]['quote_names'] ?? null;
		$mode    = $setting[0]['mode'] ?? null;
		if ( ( '0' !== $quoted && '1' !== $quoted ) || ! is_string( $mode ) ) {
			throw new DatabaseFailed( 'The schema quoting setting or SQL mode cannot be read.' );
		}
		$sql = 'SHOW CREATE ' . $type . ' ' . SqlFormatter::identifier( $name );
		if ( '1' === $quoted && 'NO_AUTO_VALUE_ON_ZERO' === $mode ) {
			return $this->connection->rows( $sql );
		}
		try {
			$this->connection->rows( "SET SESSION sql_quote_show_create = 1, sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
			return $this->connection->rows( $sql );
		} finally {
			// A binary literal preserves the saved mode even under NO_BACKSLASH_ESCAPES.
			$previous = '' === $mode ? "''" : '0x' . bin2hex( $mode );
			$this->connection->rows( 'SET SESSION sql_quote_show_create = ' . $quoted . ', sql_mode = ' . $previous );
		}
	}

	/**
	 * Server version, for the manifest.
	 *
	 * @return string Version string.
	 * @throws DatabaseFailed If the server does not answer.
	 */
	public function server_version(): string {
		$rows = $this->connection->rows( 'SELECT VERSION() AS version' );
		return $rows[0]['version'] ?? '';
	}

	/**
	 * Lists tables or views.
	 *
	 * @param string       $type    BASE TABLE or VIEW.
	 * @param string       $prefix  Name prefix.
	 * @param list<string> $exclude Names to leave out.
	 * @return list<string> Names, sorted.
	 * @throws DatabaseFailed If the server cannot list tables.
	 */
	private function names( string $type, string $prefix, array $exclude ): array {
		$names = [];
		foreach ( $this->connection->rows( 'SHOW FULL TABLES' ) as $row ) {
			$values = array_values( $row );
			$name   = $values[0] ?? '';
			if ( $type === ( $values[1] ?? '' ) && '' !== $name && str_starts_with( $name, $prefix ) && ! in_array( $name, $exclude, true ) ) {
				$names[] = $name;
			}
		}
		sort( $names, SORT_STRING );
		return $names;
	}
}
