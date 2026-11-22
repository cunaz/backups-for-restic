<?php
/**
 * Live database facts needed before an atomic, reversible table exchange.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Privileges;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * The InnoDB dictionary identifies a physical table across a RENAME and exposes incoming
 * foreign keys even from another database. Without dictionary visibility a site can still
 * rehearse, but cannot safely assign live tables to an undo generation.
 *
 * @phpstan-type Facts array{database:string,uuid:string,server:string,mariadb:bool,read_only:bool,atomic:bool}
 * @phpstan-type Inspection array{facts:Facts,ids:array<string,string>,blockers:list<string>,missing_privileges:list<string>}
 */
final readonly class SwapCatalog {

	/** Keep the single RENAME and its metadata locks within a shared-host request budget. */
	public const int MAX_TABLES = 128;

	/** Direct database-wide permissions needed to see triggers and exchange site tables. */
	public const array PRIVILEGES = [ 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'LOCK TABLES', 'TRIGGER' ];

	/** At most this many other sessions of the site's account can be waited for. */
	public const int MAX_SESSIONS = 4096;

	/**
	 * Blockers of a live exchange that do not concern dropping an expired private table.
	 * Disabled InnoDB table locks are not among them: every DROP runs in WriteBarrier.
	 */
	public const array EXCHANGE_ONLY = [ 'performance_schema', 'binlog_format', 'wsrep_on' ];

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
		$uuid = $maria ? '' : ( $this->connection->rows( "SHOW VARIABLES LIKE 'server_uuid'" )[0]['Value'] ?? '' );
		if ( ! $maria && 1 !== preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $uuid ) ) {
			$atomic = false;
		}
		return [
			'uuid'      => $uuid,
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
	 * @throws DatabaseFailed If an unexpected server error prevents a metadata check.
	 */
	public function inspect( array $names ): array {
		$facts   = $this->facts();
		$grants  = Privileges::database( $this->connection );
		$missing = array_values( array_diff( self::PRIVILEGES, $grants ) );
		// A schema-wide SELECT sees every local view name. MySQL can still hide its
		// dependencies when another account defined the view and SHOW VIEW is absent.
		if ( ! $facts['mariadb'] && ! in_array( 'SHOW VIEW', $grants, true ) && [] !== $this->connection->rows( "SELECT 1 AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='VIEW' LIMIT 1" ) ) {
			$missing[] = 'SHOW VIEW';
		}
		$issues = [];
		if ( ! $facts['atomic'] || $facts['read_only'] ) {
			$issues[] = 'server_configuration';
		}
		if ( $facts['mariadb'] ) {
			$issues[] = 'write_locked_rename';
		} else {
			array_push( $issues, ...$this->settings() );
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
			$list = implode( ',', array_map( Session::literal( ... ), $names ) );
			if ( ! $facts['mariadb'] && [] !== $this->connection->rows( 'SELECT 1 AS present FROM information_schema.VIEW_TABLE_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $list . ') LIMIT 1' ) ) {
				$issues[] = 'live_views';
			}
			$triggers = $this->connection->rows( 'SELECT 1 AS present FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN (' . $list . ') LIMIT 1' );
			if ( [] !== $triggers ) {
				$issues[] = 'live_triggers';
			}
			$checks = $this->connection->rows( "SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK' AND TABLE_NAME IN (" . $list . ') LIMIT 1' );
			if ( [] !== $checks ) {
				$issues[] = 'check_constraints';
			}
			// There is no dictionary row for a view, a partitioned table's logical name
			// or a non-InnoDB table. A missing name, however, is a valid new destination.
			$live = $this->connection->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $list . ')' );
			if ( ! in_array( 'dictionary_privilege', $issues, true ) && [] !== array_diff( array_column( $live, 'TABLE_NAME' ), array_keys( $ids ) ) ) {
				$issues[] = 'live_object_identity';
			}
		}
		if ( ! $facts['mariadb'] ) {
			// Without PROCESS other accounts' sessions stay hidden, which dictionary_privilege reports.
			array_push( $issues, ...$this->visibility( $facts['database'], ! in_array( 'dictionary_privilege', $issues, true ) ) );
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
	 * Names the settings of this session under which the exchange would fail only after the
	 * full import, or would not hold its locks on every node. MySQL 8.4 warns when
	 * binlog_format is read as a variable, so the values come from SHOW SESSION VARIABLES.
	 *
	 * @return list<string> Blocker codes, each named after its setting.
	 */
	public function settings(): array {
		$values = array_column( $this->connection->rows( "SHOW SESSION VARIABLES WHERE Variable_name IN ('binlog_format','innodb_table_locks','log_bin','sql_log_bin','wsrep_on')" ), 'Value', 'Variable_name' );
		$issues = [];
		if ( 'ON' !== ( $values['innodb_table_locks'] ?? null ) ) {
			// LOCK TABLES then takes no InnoDB table locks, and WriteBarrier refuses to start.
			$issues[] = 'innodb_table_locks';
		}
		if ( 'ON' === ( $values['log_bin'] ?? null ) && 'ON' === ( $values['sql_log_bin'] ?? null ) && 'STATEMENT' === strtoupper( $values['binlog_format'] ?? '' ) ) {
			// The protected copy's INSERT ... SELECT is unsafe for statement-based logging and
			// returns note 1592, which this port refuses like any warning.
			$issues[] = 'binlog_format';
		}
		if ( 'ON' === ( $values['wsrep_on'] ?? null ) ) {
			// A Galera node takes LOCK TABLES for itself only.
			$issues[] = 'wsrep_on';
		}
		return $issues;
	}

	/**
	 * Lists the other sessions of this session's account whose default database is the
	 * site's. WordPress selects that database right after connecting, so every request that
	 * started before maintenance and still runs is listed. MySQL 8.4 warns about the
	 * deprecated INFORMATION_SCHEMA.PROCESSLIST, also behind SHOW PROCESSLIST, and this port
	 * refuses warnings. The Performance Schema table lists an account's own sessions even
	 * without PROCESS, and nothing at all while the Performance Schema is disabled.
	 *
	 * @param string $database Site database.
	 * @return list<string>|null Connection IDs in ascending order, without this session, or
	 *                           null while the list cannot be shown to be complete.
	 * @throws DatabaseFailed If the sessions cannot be listed, with the code performance_schema.
	 */
	public function sessions( string $database ): ?array {
		$account = $this->account( $database );
		if ( null === $account ) {
			DatabaseFailed::raise( 'The Performance Schema does not list the sessions of the site database account.', [ 'code' => 'performance_schema' ] );
		}
		if ( ! $this->complete() ) {
			return null;
		}
		$rows = $this->connection->rows( 'SELECT ID FROM performance_schema.processlist WHERE USER=' . Session::literal( $account ) . ' AND DB=' . Session::literal( $database ) . ' AND ID<>CONNECTION_ID() ORDER BY ID LIMIT ' . ( self::MAX_SESSIONS + 1 ) );
		if ( count( $rows ) > self::MAX_SESSIONS ) {
			DatabaseFailed::raise( 'Too many other sessions use the site database account.', [ 'code' => 'database_exchange_sessions' ] );
		}
		$ids = [];
		foreach ( $rows as $row ) {
			$id = $row['ID'] ?? '';
			if ( 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $id ) ) {
				throw new DatabaseFailed( 'The database session list is unreadable.' );
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * The preflight's view of the session list that the drain relies on: this session must
	 * be listed with the site's database, and the list must show every connection.
	 *
	 * @param string $database Site database.
	 * @param bool   $all Whether this account can see other accounts' sessions.
	 * @return list<string> The blocker performance_schema, or nothing.
	 * @throws DatabaseFailed If an unexpected server error prevents the lookup.
	 */
	public function visibility( string $database, bool $all ): array {
		return null === $this->account( $database ) || ( $all && ! $this->complete() ) ? [ 'performance_schema' ] : [];
	}

	/**
	 * Whether the Performance Schema lists every connection of the server. A server out of
	 * thread instances leaves later connections out of performance_schema.processlist while
	 * Threads_connected counts them; on MySQL 8.4.11 such a session was missing for every
	 * other session too, and FLUSH STATUS reset Performance_schema_thread_instances_lost, so
	 * that counter proves nothing. A connection that opens or closes between the two counts
	 * can spoil one reading, so three are taken. Needs PROCESS to count other accounts.
	 *
	 * @return bool Whether at least as many sessions are listed as are connected.
	 * @throws DatabaseFailed If the server reports unreadable counts.
	 */
	private function complete(): bool {
		for ( $reading = 0; $reading < 3; ++$reading ) {
			$row       = $this->connection->rows( "SELECT (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Threads_connected') AS connected,(SELECT COUNT(*) FROM performance_schema.processlist WHERE COMMAND<>'Daemon') AS listed" )[0] ?? [];
			$connected = $row['connected'] ?? '';
			$listed    = $row['listed'] ?? '';
			if ( 1 !== preg_match( '/\A[0-9]{1,9}\z/', $connected ) || 1 !== preg_match( '/\A[0-9]{1,9}\z/', $listed ) ) {
				throw new DatabaseFailed( 'The database server reported no readable session counts.' );
			}
			if ( (int) $listed >= (int) $connected ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Finds this session in the Performance Schema. Its database must be listed as well,
	 * or a filter on the site's database would wait for nothing.
	 *
	 * @param string $database Site database.
	 * @return string|null Account user name, or null if the sessions are not visible.
	 * @throws DatabaseFailed If an unexpected server error prevents the lookup.
	 */
	private function account( string $database ): ?string {
		try {
			$own = $this->connection->rows( 'SELECT USER AS account,DB AS current_database FROM performance_schema.processlist WHERE ID=CONNECTION_ID()' );
		} catch ( DatabaseFailed $error ) {
			// MySQL before 8.0.22 has no such table; a server can also deny access to it.
			if ( ! in_array( $error->context()['code'] ?? null, [ 1044, 1142, 1146 ], true ) ) {
				throw $error;
			}
			return null;
		}
		$account = $own[0]['account'] ?? '';
		return 1 === count( $own ) && '' !== $account && $database === ( $own[0]['current_database'] ?? null ) ? $account : null;
	}

	/**
	 * Fingerprints the logical definition of a verified permanent table, with its comment.
	 * The changing auto-increment counter is data, so it does not invalidate a review.
	 * A temporary table hiding a live object is refused before copying from it.
	 * The caller uses a normalized SQL session for quoting and the dump's SQL mode.
	 *
	 * @param string $table Known table name.
	 * @return CreateStatement Validated live definition with a canonical comparison name.
	 * @throws FormatException If the server cannot expose a supported permanent table definition.
	 */
	public function definition( string $table ): CreateStatement {
		if ( ! self::simple( $table ) ) {
			throw new FormatException( 'A database table name cannot be checked for the exchange.' );
		}
		$name = SqlFormatter::identifier( $table );
		$sql  = $this->connection->rows( 'SHOW CREATE TABLE ' . $name )[0]['Create Table'] ?? '';
		if ( ! str_starts_with( $sql, 'CREATE TABLE ' . $name . ' (' ) ) {
			throw new FormatException( 'A database exchange target is hidden by a session object or is not a permanent table.' );
		}
		return CreateStatement::parse( SqlFormatter::one_line( $sql ) . ';', $table, 'bfr_schema', '' );
	}

	/**
	 * Binds a review to the current identities and logical definitions without publishing
	 * schema contents. Physical row counts and auto-increment counters may change normally.
	 *
	 * @param list<string> $names Logical site tables in the snapshot.
	 * @return array{inspection:Inspection,schemas:array<string,string>} Current live bindings.
	 */
	public function review( array $names ): array {
		$inspection = $this->inspect( $names );
		$schemas    = [];
		if ( [] === $inspection['blockers'] ) {
			$session = new Session( $this->connection, null );
			try {
				foreach ( array_keys( $inspection['ids'] ) as $name ) {
					$definition       = $this->definition( $name );
					$schemas[ $name ] = hash( 'sha256', $definition->structure . $definition->comment );
				}
			} catch ( FormatException ) {
				$inspection['blockers'][] = 'live_definition';
			} finally {
				$session->close();
			}
		}
		return [
			'inspection' => $inspection,
			'schemas'    => $schemas,
		];
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
