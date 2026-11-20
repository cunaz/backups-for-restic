<?php
/**
 * Transactional progress for staged database imports.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Row zero is the cursor; completed tables retain a row of their own for later planning.
 * This InnoDB table is updated in the very same transaction as every inserted row batch.
 *
 * @phpstan-type Cursor array{table: int, phase: string, offset: int, rows: int, identity: string}
 */
final readonly class Ledger {

	/**
	 * Quoted, generated progress table name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Binding to the one canonical staging directory.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Opens or initializes progress while the caller holds the import's advisory lock.
	 *
	 * @param Connection  $connection Non-reconnecting session.
	 * @param OwnedTables $owned Generated names and ownership checks.
	 * @param Manifest    $manifest Validated table inventory.
	 * @param string      $directory Canonical staging directory.
	 * @throws DatabaseFailed If the table or its initial progress cannot be created or verified.
	 */
	public function __construct( private Connection $connection, OwnedTables $owned, private Manifest $manifest, string $directory ) {
		$this->name   = SqlFormatter::identifier( $owned->progress() );
		$this->source = hash( 'sha256', $directory );
		if ( ! $owned->exists( $owned->progress() ) ) {
			$connection->execute(
				'CREATE TABLE ' . $this->name . ' ('
				. 'id INT UNSIGNED NOT NULL PRIMARY KEY, source CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
				. 'table_number INT UNSIGNED NOT NULL, phase VARCHAR(16) CHARACTER SET ascii NOT NULL, '
				. 'byte_offset BIGINT UNSIGNED NOT NULL, row_count BIGINT UNSIGNED NOT NULL, '
				. 'file_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
				. 'original_engine VARCHAR(16) CHARACTER SET ascii NOT NULL, original_comment MEDIUMTEXT NOT NULL'
				. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=' . SqlFormatter::value( $owned->owner, SqlFormatter::TEXT )
			);
		}
		if ( ! $owned->exists( $owned->progress() ) ) {
			throw new DatabaseFailed( 'The database import progress table was not created.' );
		}
		if ( [] === $connection->rows( 'SELECT id FROM ' . $this->name . ' LIMIT 1' ) ) {
			$phase = [] === $manifest->tables ? 'done' : 'preparing';
			if ( 1 !== $connection->execute( 'INSERT INTO ' . $this->name . ' (id, source, table_number, phase, byte_offset, row_count, file_identity, original_engine, original_comment) VALUES (0, ' . Session::literal( $this->source ) . ', 0, ' . Session::literal( $phase ) . ", 0, 0, '', '', '')" ) ) {
				throw new DatabaseFailed( 'The initial database import progress was not written.' );
			}
		}
	}

	/**
	 * Reads and validates the authoritative database cursor.
	 *
	 * @return Cursor Committed cursor.
	 * @throws DatabaseFailed If a version, staging binding, phase or count is inconsistent.
	 */
	public function read(): array {
		$rows = $this->connection->rows( 'SELECT source, table_number, phase, byte_offset, row_count, file_identity FROM ' . $this->name . ' WHERE id = 0' );
		$row  = $rows[0] ?? [];
		if ( 1 !== count( $rows ) || ( $row['source'] ?? null ) !== $this->source ) {
			throw new DatabaseFailed( 'The database import progress belongs to another staging directory or is incomplete.' );
		}
		$table    = self::number( $row['table_number'] ?? null );
		$offset   = self::number( $row['byte_offset'] ?? null );
		$count    = self::number( $row['row_count'] ?? null );
		$phase    = $row['phase'] ?? '';
		$identity = $row['file_identity'] ?? '';
		if ( $table > count( $this->manifest->tables ) || ! in_array( $phase, [ 'preparing', 'importing', 'done' ], true ) || ( 'done' === $phase ) !== ( $table === count( $this->manifest->tables ) ) ) {
			throw new DatabaseFailed( 'The database import progress has an invalid table or phase.' );
		}
		if ( 'importing' === $phase ) {
			if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $identity ) || $offset >= $this->manifest->tables[ $table ]['bytes'] || $count > $this->manifest->tables[ $table ]['rows'] ) {
				throw new DatabaseFailed( 'The database import progress is outside its staged table.' );
			}
		} elseif ( 0 !== $offset || 0 !== $count || '' !== $identity ) {
			throw new DatabaseFailed( 'The database import progress contains an unexpected unfinished batch.' );
		}
		return [
			'table'    => $table,
			'phase'    => $phase,
			'offset'   => $offset,
			'rows'     => $count,
			'identity' => $identity,
		];
	}

	/**
	 * Records the first batch boundary after owned table creation succeeds.
	 *
	 * @param TableFile $file Checked source and definition.
	 * @throws DatabaseFailed If the cursor is not updated exactly once.
	 */
	public function prepared( TableFile $file ): void {
		$this->update( "phase = 'importing', byte_offset = " . $file->data_offset . ', row_count = 0, file_identity = ' . Session::literal( $file->identity ) );
	}

	/**
	 * Advances a batch inside the transaction that inserted its rows.
	 *
	 * @param int $offset Next byte position.
	 * @param int $rows Total committed rows for this table.
	 * @throws DatabaseFailed If the cursor is not updated exactly once.
	 */
	public function advance( int $offset, int $rows ): void {
		$this->update( 'byte_offset = ' . $offset . ', row_count = ' . $rows );
	}

	/**
	 * Retains completed-table metadata and advances the cursor within one transaction.
	 *
	 * @param Cursor    $state Validated current cursor.
	 * @param TableFile $file Source whose completion trailer has been verified.
	 * @throws DatabaseFailed If either completion write fails.
	 */
	public function complete( array $state, TableFile $file ): void {
		$next     = $state['table'] + 1;
		$affected = $this->connection->execute(
			'INSERT INTO ' . $this->name . ' (id, source, table_number, phase, byte_offset, row_count, file_identity, original_engine, original_comment) '
			. 'SELECT ' . $next . ", source, table_number, 'done', " . $file->position() . ', row_count, file_identity, '
			. Session::literal( $file->create->engine ) . ', ' . Session::literal( $file->create->comment ) . ' FROM ' . $this->name . ' WHERE id = 0'
		);
		if ( 1 !== $affected ) {
			throw new DatabaseFailed( 'The completed database table was not recorded.' );
		}
		$phase = $next === count( $this->manifest->tables ) ? 'done' : 'preparing';
		$this->update( 'table_number = ' . $next . ', phase = ' . Session::literal( $phase ) . ", byte_offset = 0, row_count = 0, file_identity = ''" );
	}

	/**
	 * Updates only the cursor row, under the import's exclusive advisory lock.
	 *
	 * @param string $assignments Constructed assignments, never arbitrary dump SQL.
	 * @throws DatabaseFailed If the cursor row is missing or did not advance.
	 */
	private function update( string $assignments ): void {
		if ( 1 !== $this->connection->execute( 'UPDATE ' . $this->name . ' SET ' . $assignments . ' WHERE id = 0' ) ) {
			throw new DatabaseFailed( 'The database import progress did not advance.' );
		}
	}

	/**
	 * Decodes a canonical nonnegative database integer without rounding or overflow.
	 *
	 * @param string|null $value Database value.
	 * @return int Checked integer.
	 * @throws DatabaseFailed If the cursor contains an invalid numeric value.
	 */
	private static function number( ?string $value ): int {
		if ( null === $value || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) || (string) (int) $value !== $value ) {
			throw new DatabaseFailed( 'A database import counter is invalid or exceeds this host.' );
		}
		return (int) $value;
	}
}
