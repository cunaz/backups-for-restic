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
 * @phpstan-type Cursor array{table: int, phase: string, offset: int, rows: int, identity: string, digest: string}
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
	 * Older importer ledgers have no transcript column, but can still finish and clean up.
	 *
	 * @var bool
	 */
	private bool $transcripts;

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
				. "content_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', "
				. 'original_engine VARCHAR(16) CHARACTER SET ascii NOT NULL, original_comment MEDIUMTEXT NOT NULL'
				. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=' . SqlFormatter::value( $owned->owner, SqlFormatter::TEXT )
			);
		}
		if ( ! $owned->exists( $owned->progress() ) ) {
			throw new DatabaseFailed( 'The database import progress table was not created.' );
		}
		$this->transcripts = self::has_transcripts( $connection, $owned );
		if ( [] === $connection->rows( 'SELECT id FROM ' . $this->name . ' LIMIT 1' ) ) {
			$phase = [] === $manifest->tables ? 'done' : 'preparing';
			if ( 1 !== $connection->execute( 'INSERT INTO ' . $this->name . ' (id, source, table_number, phase, byte_offset, row_count, file_identity, original_engine, original_comment) VALUES (0, ' . Session::literal( $this->source ) . ', 0, ' . Session::literal( $phase ) . ", 0, 0, '', '', '')" ) ) {
				throw new DatabaseFailed( 'The initial database import progress was not written.' );
			}
		}
	}

	/**
	 * Checks for the content-binding field without altering a previous version's ledger.
	 *
	 * @param Connection  $connection Same site session.
	 * @param OwnedTables $owned Verified import owner.
	 * @return bool Whether new batches can produce a complete content binding.
	 */
	public static function has_transcripts( Connection $connection, OwnedTables $owned ): bool {
		return [] !== $connection->rows( 'SHOW COLUMNS FROM ' . SqlFormatter::identifier( $owned->progress() ) . " WHERE Field='content_digest'" );
	}

	/**
	 * Binds a second, independent import to precisely the bytes that were rehearsed.
	 * The manifest fixes table names, order, row counts and file lengths. The completed
	 * records additionally bind each parsed header, every inserted line and its trailer.
	 * Must be read under the owner's advisory lock.
	 *
	 * @param Connection  $connection Current session.
	 * @param OwnedTables $owned Verified private namespace.
	 * @param Manifest    $manifest Validated inventory.
	 * @return string|null Content binding, or null for a legacy ledger without transcripts.
	 * @throws DatabaseFailed If the owned ledger lacks a complete, verified transcript.
	 */
	public static function content_digest( Connection $connection, OwnedTables $owned, Manifest $manifest ): ?string {
		if ( ! $owned->exists( $owned->progress() ) ) {
			throw new DatabaseFailed( 'The database content binding lost its owned progress table.' );
		}
		if ( ! self::has_transcripts( $connection, $owned ) ) {
			return null;
		}
		$rows = $connection->rows( 'SELECT table_number,row_count,content_digest FROM ' . SqlFormatter::identifier( $owned->progress() ) . " WHERE id>0 AND phase='done' ORDER BY table_number LIMIT " . ( count( $manifest->tables ) + 1 ) );
		if ( count( $rows ) !== count( $manifest->tables ) ) {
			throw new DatabaseFailed( 'The database content binding is incomplete.' );
		}
		$hash = hash_init( 'sha256' );
		hash_update( $hash, 'backups-for-restic-database-content/1:' . $manifest->fingerprint );
		foreach ( $manifest->tables as $number => $table ) {
			$row = $rows[ $number ];
			if ( (string) $number !== $row['table_number'] || (string) $table['rows'] !== $row['row_count'] || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $row['content_digest'] ?? '' ) ) {
				throw new DatabaseFailed( 'The database content binding differs from the verified table inventory.' );
			}
			hash_update( $hash, $row['content_digest'] );
		}
		return hash_final( $hash );
	}

	/**
	 * Reads and validates the authoritative database cursor.
	 *
	 * @return Cursor Committed cursor.
	 * @throws DatabaseFailed If a version, staging binding, phase or count is inconsistent.
	 */
	public function read(): array {
		$digest = $this->transcripts ? 'content_digest' : "''";
		$rows   = $this->connection->rows( 'SELECT source, table_number, phase, byte_offset, row_count, file_identity,' . $digest . ' AS content_digest FROM ' . $this->name . ' WHERE id = 0' );
		$row    = $rows[0] ?? [];
		if ( 1 !== count( $rows ) || ( $row['source'] ?? null ) !== $this->source ) {
			throw new DatabaseFailed( 'The database import progress belongs to another staging directory or is incomplete.' );
		}
		$table    = self::number( $row['table_number'] ?? null );
		$offset   = self::number( $row['byte_offset'] ?? null );
		$count    = self::number( $row['row_count'] ?? null );
		$phase    = $row['phase'] ?? '';
		$identity = $row['file_identity'] ?? '';
		$content  = $row['content_digest'] ?? '';
		if ( $this->transcripts && ( 'importing' === $phase ? 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $content ) : '' !== $content ) ) {
			throw new DatabaseFailed( 'The database import transcript has an invalid checkpoint.' );
		}
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
			'digest'   => $content,
		];
	}

	/**
	 * Records the first batch boundary after owned table creation succeeds.
	 *
	 * @param TableFile $file Checked source and definition.
	 * @throws DatabaseFailed If the cursor is not updated exactly once.
	 */
	public function prepared( TableFile $file ): void {
		$this->update( "phase = 'importing', byte_offset = " . $file->data_offset . ', row_count = 0, file_identity = ' . Session::literal( $file->identity ) . $this->digest_assignment( $file->header_digest ) );
	}

	/**
	 * Advances a batch inside the transaction that inserted its rows.
	 *
	 * @param int    $offset Next byte position.
	 * @param int    $rows Total committed rows for this table.
	 * @param string $digest Content transcript through this batch.
	 * @throws DatabaseFailed If the cursor is not updated exactly once.
	 */
	public function advance( int $offset, int $rows, string $digest ): void {
		$this->update( 'byte_offset = ' . $offset . ', row_count = ' . $rows . $this->digest_assignment( $digest ) );
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
		$column   = $this->transcripts ? ',content_digest' : '';
		$digest   = $this->transcripts ? ',' . Session::literal( $file->transcript( $state['digest'] ) ) : '';
		$affected = $this->connection->execute(
			'INSERT INTO ' . $this->name . ' (id, source, table_number, phase, byte_offset, row_count, file_identity, original_engine, original_comment' . $column . ') '
			. 'SELECT ' . $next . ", source, table_number, 'done', " . $file->position() . ', row_count, file_identity, '
			. Session::literal( $file->create->engine ) . ', ' . Session::literal( $file->create->comment ) . $digest . ' FROM ' . $this->name . ' WHERE id = 0'
		);
		if ( 1 !== $affected ) {
			throw new DatabaseFailed( 'The completed database table was not recorded.' );
		}
		$phase = $next === count( $this->manifest->tables ) ? 'done' : 'preparing';
		$this->update( 'table_number = ' . $next . ', phase = ' . Session::literal( $phase ) . ", byte_offset = 0, row_count = 0, file_identity = ''" . $this->digest_assignment( '' ) );
	}

	/**
	 * Appends the content checkpoint only on ledgers with the current layout.
	 *
	 * @param string $digest Transcript to store.
	 * @return string Generated assignment.
	 */
	private function digest_assignment( string $digest ): string {
		return $this->transcripts ? ', content_digest = ' . Session::literal( $digest ) : '';
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
