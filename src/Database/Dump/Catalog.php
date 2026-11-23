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
		$columns   = [];
		$usable    = [];
		$wide      = false;
		$mosts     = [];
		$converted = [];
		foreach ( $this->connection->rows( 'SHOW FULL COLUMNS FROM ' . $quoted ) as $column ) {
			// Generated columns cannot be inserted; the server computes them again on import.
			// Their values are not read, so they cannot order pages either.
			$extra = strtoupper( $column['Extra'] ?? '' );
			if ( str_contains( $extra, 'VIRTUAL GENERATED' ) || str_contains( $extra, 'STORED GENERATED' ) ) {
				continue;
			}
			$field             = (string) $column['Field'];
			$type              = (string) $column['Type'];
			$columns[ $field ] = SqlFormatter::kind( $type );
			$mosts[ $field ]   = self::most( $type );
			$wide              = $wide || null === $mosts[ $field ];
			// The dump reads utf8mb4: text in another character set arrives converted, and
			// latin1 "é" or "€" takes two or three bytes there instead of one.
			$charset = is_string( $column['Collation'] ?? null ) ? strtolower( (string) strtok( $column['Collation'], '_' ) ) : '';
			if ( '' !== $charset && ! in_array( $charset, [ 'utf8mb4', 'utf8mb3', 'utf8', 'ascii', 'binary' ], true ) ) {
				$converted[] = $field;
			}
			if ( 'NO' === ( $column['Null'] ?? null ) ) {
				$usable[ $field ] = KeyColumn::of( $field, $type, $column['Collation'] ?? null );
			}
		}
		[ $key, $order ] = $this->key( $quoted, $usable );
		$bound           = array_sum( $mosts );
		foreach ( $order as $column ) {
			// A key value that needs an expression of its own arrives a second time.
			$bound += $column->computed() ? $mosts[ $column->name ] ?? 0 : 0;
		}
		$raw = $create[0]['Create Table'];
		// The table options follow the line that closes the column list; no literal can start a
		// line, and the table comment comes after AUTO_INCREMENT, which changes with every insert.
		$engine     = 1 === preg_match( '/^\) ENGINE=([A-Za-z0-9_]+)/m', $raw, $match ) ? $match[1] : '';
		$definition = (string) preg_replace( '/^(\)[^\'\n]*?) AUTO_INCREMENT=[0-9]+/m', '$1', $raw );
		$parts      = [ $definition, (string) $key ];
		foreach ( $columns as $field => $kind ) {
			$parts[] = $field . "\t" . $kind;
		}
		foreach ( $order as $column ) {
			$parts[] = $column->name . "\t" . $column->kind . "\t" . $column->collation;
		}
		return new Table( $name, SqlFormatter::one_line( $raw ), $columns, $key, $order, $engine, hash( 'sha256', implode( "\n", $parts ) ), $wide, $converted, $wide ? 0 : $bound );
	}

	/**
	 * The most bytes in which a value of a column type arrives, converted to utf8mb4: four per
	 * character of text, whatever its character set, one per byte of a binary string, and the
	 * longest text of a number, date or time.
	 *
	 * @param string $type Column type from SHOW FULL COLUMNS.
	 * @return int|null Bytes, or null for TEXT, BLOB, JSON, spatial and unknown types, whose values can have any size.
	 */
	private static function most( string $type ): ?int {
		return match ( true ) {
			1 === preg_match( '/\A(?:var)?char\((\d+)\)/i', $type, $match )   => 4 * (int) $match[1],
			1 === preg_match( '/\A(?:var)?binary\((\d+)\)/i', $type, $match ) => (int) $match[1],
			// The names of the members are part of the type.
			1 === preg_match( '/\A(?:enum|set)\(/i', $type )                    => 4 * strlen( $type ),
			1 === preg_match( '/\A(?:tinyint|smallint|mediumint|int|integer|bigint)\b(?:\((\d+)\))?/i', $type, $match ) => max( 21, (int) ( $match[1] ?? 0 ) + 1 ),
			1 === preg_match( '/\A(?:decimal|numeric|dec|fixed)\b(?:\((\d+))?/i', $type, $match ) => max( 12, (int) ( $match[1] ?? 0 ) + 2 ),
			1 === preg_match( '/\A(?:float|double|real|bit|year|date|datetime|timestamp|time|uuid|inet4|inet6)\b/i', $type ) => 48,
			default                                                              => null,
		};
	}


	/**
	 * The key whose columns order the pages: the primary key, otherwise a unique key whose
	 * columns are all NOT NULL, stored and of a type that compares exactly (KeyColumn). Among
	 * unique keys, one that the server uses and that indexes whole values comes first, so that
	 * every page is a range of the index.
	 *
	 * @param string                        $quoted Quoted table name.
	 * @param array<string, KeyColumn|null> $usable NOT NULL stored columns by name; null for a type that cannot order pages.
	 * @return array{0: string|null, 1: list<KeyColumn>} Key name and its columns, or null and none.
	 * @throws DatabaseFailed If the keys cannot be read.
	 */
	private function key( string $quoted, array $usable ): array {
		$keys = [];
		foreach ( $this->connection->rows( 'SHOW KEYS FROM ' . $quoted ) as $part ) {
			if ( '0' !== ( $part['Non_unique'] ?? null ) ) {
				continue;
			}
			$name = $part['Key_name'] ?? '';
			$key  = $keys[ $name ] ?? [
				'name'   => $name,
				'rank'   => 'PRIMARY' === $name ? 0 : 1,
				'parts'  => [],
				'usable' => true,
			];
			// A functional key part has no column; its key cannot be compared column by column.
			$column = $part['Column_name'] ?? null;
			$found  = null === $column ? null : ( $usable[ $column ] ?? null );
			if ( null === $found ) {
				$key['usable'] = false;
			} else {
				$key['parts'][ (int) ( $part['Seq_in_index'] ?? 0 ) ] = $found;
			}
			// A prefix part or a key the server does not use makes every page a sort.
			if ( null !== ( $part['Sub_part'] ?? null ) || 'NO' === ( $part['Visible'] ?? 'YES' ) || 'YES' === ( $part['Ignored'] ?? 'NO' ) ) {
				$key['rank'] = 'PRIMARY' === $name ? 2 : 3;
			}
			$keys[ $name ] = $key;
		}
		$best = null;
		foreach ( $keys as $key ) {
			if ( $key['usable'] && ( null === $best || $key['rank'] < $best['rank'] ) ) {
				$best = $key;
			}
		}
		if ( null === $best ) {
			return [ null, [] ];
		}
		ksort( $best['parts'] );
		return [ $best['name'], array_values( $best['parts'] ) ];
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
