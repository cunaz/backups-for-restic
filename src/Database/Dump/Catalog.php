<?php
/**
 * Lists what to dump.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

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
		$columns = [];
		foreach ( $this->connection->rows( 'SHOW COLUMNS FROM ' . $quoted ) as $column ) {
			// Generated columns cannot be inserted; the server computes them again on import.
			$extra = strtoupper( $column['Extra'] ?? '' );
			if ( ! str_contains( $extra, 'VIRTUAL GENERATED' ) && ! str_contains( $extra, 'STORED GENERATED' ) ) {
				$columns[ (string) $column['Field'] ] = SqlFormatter::kind( (string) $column['Type'] );
			}
		}
		$primary = [];
		foreach ( $this->connection->rows( 'SHOW KEYS FROM ' . $quoted . " WHERE Key_name = 'PRIMARY'" ) as $key ) {
			$primary[ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
		}
		ksort( $primary );
		$primary = array_values( $primary );
		$key     = 1 === count( $primary ) && SqlFormatter::NUMERIC === ( $columns[ $primary[0] ] ?? '' ) ? $primary[0] : null;
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
	 * Requests quoted identifiers without changing the site's session setting permanently.
	 *
	 * @param string $type TABLE or VIEW, selected by the catalog itself.
	 * @param string $name Quoted object name.
	 * @return list<array<string, string|null>> SHOW CREATE rows.
	 * @throws DatabaseFailed If the session setting cannot be read, set or restored.
	 */
	private function show_create( string $type, string $name ): array {
		$setting = $this->connection->rows( 'SELECT @@SESSION.sql_quote_show_create AS quote_names' );
		$quoted  = $setting[0]['quote_names'] ?? null;
		if ( '0' !== $quoted && '1' !== $quoted ) {
			throw new DatabaseFailed( 'The identifier quoting setting cannot be read.' );
		}
		$sql = 'SHOW CREATE ' . $type . ' ' . SqlFormatter::identifier( $name );
		if ( '1' === $quoted ) {
			return $this->connection->rows( $sql );
		}
		try {
			$this->connection->rows( 'SET SESSION sql_quote_show_create = 1' );
			return $this->connection->rows( $sql );
		} finally {
			$this->connection->rows( 'SET SESSION sql_quote_show_create = 0' );
		}
	}

	/**
	 * Time zone offset of the session, so that TIMESTAMP values import unchanged.
	 *
	 * @return string Offset such as +02:00.
	 * @throws DatabaseFailed If the server does not answer.
	 */
	public function time_zone(): string {
		$rows = $this->connection->rows( "SELECT TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), '%H:%i') AS tz_offset" );
		$diff = $rows[0]['tz_offset'] ?? '00:00';
		return str_starts_with( $diff, '-' ) ? $diff : '+' . $diff;
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
