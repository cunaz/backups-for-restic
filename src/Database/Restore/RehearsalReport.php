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

defined( 'ABSPATH' ) || exit;

/** Reads the completed ledger and catalogs. It never scans live data or returns row values. */
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
	 * @return array{tables:list<array<string,int|string|null>>,views_not_imported:list<string>,snapshot_server:string,destination_server:string,findings:list<array{code:string,table:string}>,rows:int,swap_preflight:array{blockers:list<string>,missing_privileges:list<string>,required_database_privileges:list<string>,required_global_privileges:list<string>}}
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
				$inspection = ( new SwapCatalog( $this->connection ) )->inspect( array_column( $manifest->tables, 'name' ) );
				return [
					'swap_preflight'     => [
						'blockers'                     => $inspection['blockers'],
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
}
