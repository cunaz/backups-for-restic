<?php
/**
 * Live database facts needed before an atomic, reversible table exchange.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Privileges;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * The InnoDB dictionary identifies a physical table across a RENAME and exposes incoming
 * foreign keys even from another database. Without dictionary visibility a site can still
 * rehearse, but cannot safely assign live tables to an undo generation.
 *
 * @phpstan-type Facts array{database:string,server:string,mariadb:bool,read_only:bool,atomic:bool}
 * @phpstan-type Inspection array{facts:Facts,ids:array<string,string>,blockers:list<string>,missing_privileges:list<string>}
 */
final readonly class SwapCatalog {

	/** Keep the single RENAME and its metadata locks within a shared-host request budget. */
	public const int MAX_TABLES = 128;

	/** Direct database-wide permissions needed to see triggers and exchange site tables. */
	public const array PRIVILEGES = [ 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'LOCK TABLES', 'TRIGGER' ];

	/**
	 * Creates the catalog for this destination.
	 *
	 * @param Connection $connection Non-reconnecting session.
	 */
	public function __construct( private Connection $connection ) {
	}

	/**
	 * Obtains the current schema, version and server features. Checking the native family
	 * also prevents a MySQL-compatible server's unrelated version from implying atomic DDL.
	 *
	 * @return Facts Server facts safe to persist in a private plan.
	 * @throws DatabaseFailed If the site connection does not identify its current database.
	 */
	public function facts(): array {
		$row     = $this->connection->rows( 'SELECT DATABASE() AS db, VERSION() AS version, @@GLOBAL.read_only AS read_only, @@GLOBAL.lower_case_table_names AS name_case' )[0] ?? [];
		$db      = $row['db'] ?? '';
		$version = $row['version'] ?? '';
		if ( '' === $db || '' === $version ) {
			throw new DatabaseFailed( 'The current database could not be identified.' );
		}
		$maria  = str_contains( strtolower( $version ), 'mariadb' );
		$atomic = 1 === preg_match( '/\A(?:5\.5\.5-)?(\d+\.\d+\.\d+)/', $version, $match )
			&& version_compare( $match[1], $maria ? '10.6.1' : '8.0.13', '>=' )
			&& ! str_contains( strtolower( $version ), 'tidb' ) && '0' === ( $row['name_case'] ?? '' );
		if ( $maria && 'ON' === ( $this->connection->rows( "SHOW VARIABLES LIKE 'wsrep_on'" )[0]['Value'] ?? '' ) ) {
			$atomic = false;
		}
		return [
			'database'  => $db,
			'server'    => $version,
			'mariadb'   => $maria,
			'read_only' => '0' !== ( $row['read_only'] ?? '' ),
			'atomic'    => $atomic,
		];
	}

	/**
	 * Reports missing permissions and database constructs that prevent a live exchange.
	 * Names come from the validated inventory. The private numeric identities never enter
	 * an administrator-facing report. No SQL data or schema is changed by this method.
	 *
	 * @param list<string> $names Proposed site tables, including names absent on the target.
	 * @return Inspection Preflight results.
	 */
	public function inspect( array $names ): array {
		$facts   = $this->facts();
		$missing = array_values( array_diff( self::PRIVILEGES, Privileges::database( $this->connection ) ) );
		$issues  = [];
		if ( ! $facts['atomic'] || $facts['read_only'] ) {
			$issues[] = 'server_configuration';
		}
		if ( [] !== $missing ) {
			$issues[] = 'database_privileges';
		}
		$ids = [];
		if ( [] === $names || count( $names ) > self::MAX_TABLES ) {
			$issues[] = 'table_count';
		} elseif ( ! self::simple( $facts['database'] ) || [] !== array_filter( $names, static fn ( string $name ): bool => ! self::simple( $name ) ) ) {
			// The engine encodes other characters in dictionary names. Guessing that
			// encoding could associate a retained name with the wrong physical table.
			$issues[] = 'dictionary_names';
		} elseif ( $facts['atomic'] ) {
			try {
				$ids       = $this->identities( $facts, $names );
				$qualified = self::qualified( $facts['database'], $names );
				$catalog   = $facts['mariadb'] ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN';
				$foreign   = $this->connection->rows( 'SELECT 1 AS present FROM information_schema.' . $catalog . ' WHERE FOR_NAME IN (' . $qualified . ') OR REF_NAME IN (' . $qualified . ') LIMIT 1' );
				if ( [] !== $foreign ) {
					$issues[] = 'foreign_keys';
				}
			} catch ( DatabaseFailed $error ) {
				if ( ! in_array( $error->context()['code'] ?? null, [ 1044, 1142, 1227 ], true ) ) {
					throw $error;
				}
				$issues[]  = 'dictionary_privilege';
				$missing[] = 'PROCESS';
			}
			$list     = implode( ',', array_map( Session::literal( ... ), $names ) );
			$triggers = $this->connection->rows( 'SELECT 1 AS present FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN (' . $list . ') LIMIT 1' );
			if ( [] !== $triggers ) {
				$issues[] = 'live_triggers';
			}
			// There is no dictionary row for a view, a partitioned table's logical name
			// or a non-InnoDB table. A missing name, however, is a valid new destination.
			$live = $this->connection->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $list . ')' );
			if ( ! in_array( 'dictionary_privilege', $issues, true ) && [] !== array_diff( array_column( $live, 'TABLE_NAME' ), array_keys( $ids ) ) ) {
				$issues[] = 'live_object_identity';
			}
		}
		return [
			'facts'              => $facts,
			'ids'                => $ids,
			'blockers'           => $issues,
			'missing_privileges' => $missing,
		];
	}

	/**
	 * Reads physical identities for a bounded set of validated logical names.
	 * Missing objects are absent from the result. The numbers remain strings on 32-bit PHP.
	 *
	 * @param Facts        $facts Current database and server family.
	 * @param list<string> $names Site or generated table names.
	 * @return array<string,string> Physical InnoDB identifiers by table name.
	 * @throws FormatException If a name cannot be represented unambiguously in the dictionary.
	 * @throws DatabaseFailed If the engine returns an invalid or ambiguous identity.
	 */
	public function identities( array $facts, array $names ): array {
		if ( count( $names ) > 3 * self::MAX_TABLES + 3 || ! self::simple( $facts['database'] ) ) {
			throw new FormatException( 'The database ownership lookup exceeds its limits.' );
		}
		foreach ( $names as $name ) {
			if ( ! self::simple( $name ) ) {
				throw new FormatException( 'A database table name cannot be matched to a physical identity.' );
			}
		}
		if ( [] === $names ) {
			return [];
		}
		$catalog = $facts['mariadb'] ? 'INNODB_SYS_TABLES' : 'INNODB_TABLES';
		$rows    = $this->connection->rows( 'SELECT NAME,TABLE_ID FROM information_schema.' . $catalog . ' WHERE NAME IN (' . self::qualified( $facts['database'], $names ) . ') LIMIT ' . ( count( $names ) + 1 ) );
		$wanted  = array_fill_keys( $names, true );
		$ids     = [];
		foreach ( $rows as $row ) {
			$full = $row['NAME'] ?? '';
			$name = substr( $full, strlen( $facts['database'] ) + 1 );
			$id   = $row['TABLE_ID'] ?? '';
			if ( ! str_starts_with( $full, $facts['database'] . '/' ) || ! isset( $wanted[ $name ] ) || isset( $ids[ $name ] ) || 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $id ) ) {
				throw new DatabaseFailed( 'The physical database table identity is ambiguous.' );
			}
			$ids[ $name ] = $id;
		}
		ksort( $ids, SORT_STRING );
		return $ids;
	}

	/**
	 * Checks names for which the SQL and InnoDB dictionary spellings agree exactly.
	 *
	 * @param string $name Table or database name.
	 * @return bool Whether directly comparable.
	 */
	private static function simple( string $name ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $name );
	}

	/**
	 * Builds a list of binary string literals for dictionary metadata, never identifiers.
	 *
	 * @param string       $database Current database.
	 * @param list<string> $names Table names.
	 * @return string Comma-separated literals.
	 */
	private static function qualified( string $database, array $names ): string {
		return implode( ',', array_map( static fn ( string $name ): string => Session::literal( $database . '/' . $name ), $names ) );
	}
}
