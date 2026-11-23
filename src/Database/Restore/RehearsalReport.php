<?php
/**
 * Metadata and compatibility findings from a completed database rehearsal.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the completed ledger and catalogs. It never scans live data. Of the snapshot's rows it
 * returns two option values only, the role map and the list of active plugins, which the review
 * of a live exchange compares with the running site (ADR 0043, decision 5).
 */
final readonly class RehearsalReport {

	/**
	 * Opens the metadata service on the same SQL connection as the import.
	 *
	 * @param Connection $connection Non-reconnecting session.
	 */
	public function __construct( private Connection $connection ) {
	}

	/**
	 * Compares a fully verified snapshot with the current site's tables and views. These
	 * findings describe the rehearsal only. An apply needs its own fresh live preflight.
	 *
	 * @param Manifest    $manifest Validated dump.
	 * @param OwnedTables $owned Completed rehearsal's private objects.
	 * @return array{tables:list<array<string,int|string|null>>,views_not_imported:list<string>,snapshot_server:string,destination_server:string,findings:list<array{code:string,table:string}>,rows:int,content_digest:string|null,swap_preflight:array{blockers:list<string>,missing_privileges:list<string>,required_database_privileges:list<string>,required_global_privileges:list<string>},wordpress_state:array{user_roles:string|null,active_plugins:string|null}}
	 * @throws FormatException If the live inventory exceeds the same table limit as the dump.
	 * @throws DatabaseFailed If the completed ledger and imported shadows do not match the inventory.
	 */
	public function read( Manifest $manifest, OwnedTables $owned ): array {
		return $owned->locked(
			$manifest,
			function () use ( $manifest, $owned ): array {
				if ( ! $owned->exists( $owned->progress() ) ) {
					throw new DatabaseFailed( 'The completed database rehearsal lost its import ledger.' );
				}
				$server = $this->connection->rows( 'SELECT VERSION() AS version' )[0]['version'] ?? null;
				if ( null === $server ) {
					throw new DatabaseFailed( 'The destination database version could not be determined.' );
				}
				$fields = 't.TABLE_NAME, t.TABLE_TYPE, t.ENGINE, t.TABLE_ROWS, t.TABLE_COLLATION, c.CHARACTER_SET_NAME';
				// MariaDB's UCA 1400 collations have a generic name in COLLATIONS and a
				// charset-specific name in TABLES. Resolve the documented charset prefix.
				$charset = " LEFT JOIN information_schema.CHARACTER_SETS c ON c.CHARACTER_SET_NAME=SUBSTRING_INDEX(t.TABLE_COLLATION,'_',1)";
				$tables  = 'information_schema.TABLES t';
				$live    = $this->connection->rows( 'SELECT ' . $fields . ' FROM ' . $tables . $charset . ' WHERE t.TABLE_SCHEMA=DATABASE() AND CAST(LEFT(t.TABLE_NAME,' . strlen( $manifest->prefix ) . ') AS BINARY)=' . Session::literal( $manifest->prefix ) . ' ORDER BY t.TABLE_NAME LIMIT ' . ( Manifest::MAX_TABLES + 1 ) );
				if ( count( $live ) > Manifest::MAX_TABLES ) {
					throw new FormatException( 'The live database inventory exceeds the preview limit.' );
				}
				$rows = [];
				foreach ( $live as $entry ) {
					$name          = (string) $entry['TABLE_NAME'];
					$rows[ $name ] = [
						'name'               => $name,
						'live_type'          => $entry['TABLE_TYPE'],
						'live_engine'        => $entry['ENGINE'],
						'live_charset'       => $entry['CHARACTER_SET_NAME'],
						'live_collation'     => $entry['TABLE_COLLATION'],
						'live_rows_estimate' => null === $entry['TABLE_ROWS'] ? null : (int) $entry['TABLE_ROWS'],
						'snapshot_rows'      => null,
						'comparison'         => 'live_only',
					];
				}
				$ledger   = SqlFormatter::identifier( $owned->progress() );
				$prefix   = Session::literal( substr( $owned->shadow( 0 ), 0, -1 ) );
				$imported = $this->connection->rows( 'SELECT p.table_number,p.row_count,p.original_engine,t.TABLE_COLLATION,c.CHARACTER_SET_NAME FROM ' . $ledger . ' p JOIN ' . $tables . ' ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=CONCAT(' . $prefix . ',p.table_number)' . $charset . ' WHERE p.id>0 AND p.phase=\'done\' AND t.TABLE_COMMENT=' . Session::literal( $owned->owner ) . ' ORDER BY p.table_number LIMIT ' . ( count( $manifest->tables ) + 1 ) );
				if ( count( $imported ) !== count( $manifest->tables ) ) {
					throw new DatabaseFailed( 'The database rehearsal did not retain every completed shadow table.' );
				}
				$count    = 0;
				$findings = [];
				if ( null === $manifest->trigger_tables ) {
					$findings[] = [
						'code'  => 'snapshot_triggers_unknown',
						'table' => '',
					];
				}
				foreach ( $manifest->trigger_tables ?? [] as $name ) {
					$findings[] = [
						'code'  => 'snapshot_triggers',
						'table' => $name,
					];
				}
				foreach ( $manifest->views as $name ) {
					$findings[] = [
						'code'  => 'snapshot_view',
						'table' => $name,
					];
				}
				foreach ( $manifest->tables as $number => $table ) {
					$record = $imported[ $number ];
					if ( (string) $number !== $record['table_number'] || (string) $table['rows'] !== $record['row_count'] || null === $record['TABLE_COLLATION'] || null === $record['CHARACTER_SET_NAME'] ) {
						throw new DatabaseFailed( 'The database rehearsal metadata differs from the verified snapshot inventory.' );
					}
					$name = $table['name'];
					if ( $table['rows'] > PHP_INT_MAX - $count ) {
						throw new FormatException( 'The total restored row count exceeds this host.' );
					}
					$count        += $table['rows'];
					$rows[ $name ] = [
						'name'               => $name,
						'comparison'         => isset( $rows[ $name ] ) ? 'both' : 'snapshot_only',
						'snapshot_rows'      => $table['rows'],
						'snapshot_bytes'     => $table['bytes'],
						'snapshot_engine'    => $record['original_engine'],
						'snapshot_charset'   => $record['CHARACTER_SET_NAME'],
						'snapshot_collation' => $record['TABLE_COLLATION'],
					] + ( $rows[ $name ] ?? [
						'live_type'          => null,
						'live_rows_estimate' => null,
					] );
					if ( 'INNODB' !== $record['original_engine'] ) {
						$findings[] = [
							'code'  => 'snapshot_engine',
							'table' => $name,
						];
					}
					if ( null !== $rows[ $name ]['live_type'] && 'BASE TABLE' !== $rows[ $name ]['live_type'] ) {
						$findings[] = [
							'code'  => 'live_object_type',
							'table' => $name,
						];
					} elseif ( isset( $rows[ $name ]['live_engine'] ) && 'INNODB' !== strtoupper( $rows[ $name ]['live_engine'] ) ) {
						$findings[] = [
							'code'  => 'live_engine',
							'table' => $name,
						];
					}
				}
				$checks = $this->connection->rows( 'SELECT DISTINCT p.table_number FROM ' . $ledger . ' p JOIN information_schema.TABLE_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=DATABASE() AND c.TABLE_NAME=CONCAT(' . $prefix . ",p.table_number) AND c.CONSTRAINT_TYPE='CHECK' WHERE p.id>0" );
				foreach ( $checks as $check ) {
					$table = $manifest->tables[ (int) $check['table_number'] ] ?? null;
					if ( null !== $table ) {
						$findings[] = [
							'code'  => 'snapshot_check',
							'table' => $table['name'],
						];
					}
				}
				foreach ( WordPressState::TABLES as $suffix ) {
					$name = $manifest->prefix . $suffix;
					if ( 'both' !== ( $rows[ $name ]['comparison'] ?? null ) ) {
						$findings[] = [
							'code'  => 'wordpress_tables',
							'table' => $name,
						];
					}
				}
				$inspection = ( new SwapCatalog( $this->connection ) )->inspect( array_column( $manifest->tables, 'name' ) );
				return [
					'wordpress_state'    => $this->wordpress_state( $manifest, $owned ),
					'content_digest'     => Ledger::content_digest( $this->connection, $owned, $manifest ),
					'swap_preflight'     => [
						'blockers'                     => array_values( array_unique( [ ...$inspection['blockers'], ...array_column( $findings, 'code' ) ] ) ),
						'missing_privileges'           => $inspection['missing_privileges'],
						'required_database_privileges' => SwapCatalog::PRIVILEGES,
						'required_global_privileges'   => [ 'PROCESS' ],
					],
					'tables'             => array_values( $rows ),
					'views_not_imported' => $manifest->views,
					'snapshot_server'    => $manifest->server,
					'destination_server' => $server,
					'findings'           => $findings,
					'rows'               => $count,
				];
			}
		);
	}

	/**
	 * The snapshot's role map and list of active plugins as they are stored, base64-encoded,
	 * or null where the snapshot's options table lacks the option, holds it more than once,
	 * holds a longer value than the review reads, or lacks the standard columns.
	 *
	 * @param Manifest    $manifest Validated dump.
	 * @param OwnedTables $owned Completed rehearsal's private objects.
	 * @return array{user_roles:string|null,active_plugins:string|null} Stored values.
	 */
	private function wordpress_state( Manifest $manifest, OwnedTables $owned ): array {
		$number = array_search( $manifest->prefix . 'options', array_column( $manifest->tables, 'name' ), true );
		$values = [
			'user_roles'     => null,
			'active_plugins' => null,
		];
		if ( ! is_int( $number ) ) {
			return $values;
		}
		$table   = $owned->shadow( $number );
		$columns = array_column( $this->connection->rows( 'SHOW COLUMNS FROM ' . SqlFormatter::identifier( $table ) ), 'Field' );
		if ( ! in_array( 'option_name', $columns, true ) || ! in_array( 'option_value', $columns, true ) ) {
			return $values;
		}
		$state  = new WordPressState( $this->connection );
		$values = [
			'user_roles'     => $state->option( $table, $manifest->prefix . 'user_roles', RoleMap::MAX_BYTES ),
			'active_plugins' => $state->option( $table, 'active_plugins', PluginList::MAX_BYTES ),
		];
		return array_map( static fn ( ?string $value ): ?string => null === $value ? null : Bytes::base64_encode( $value ), $values );
	}
}
