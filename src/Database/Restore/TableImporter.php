<?php
/**
 * Resumable import of staged database rows into private shadow tables.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves the running site's tables alone. InnoDB shadows and a durable progress table are
 * prepared for a separate, confirmed apply step. Each batch and its cursor commit together;
 * DDL resumes by recreating only an owned shadow whose phase is still "preparing".
 *
 * @phpstan-import-type Cursor from Ledger
 */
final readonly class TableImporter {

	/**
	 * Generated table names and ownership marker.
	 *
	 * @var OwnedTables
	 */
	public OwnedTables $tables;

	/**
	 * Canonical private staging directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Binds a resumable import to a staged dump and the job's persisted random token.
	 *
	 * @param Connection $connection Non-reconnecting connection, exclusively used during tick.
	 * @param Manifest   $manifest Validated completed dump inventory.
	 * @param string     $directory Immutable private staging directory.
	 * @param string     $token Persisted 128-bit hexadecimal ownership token, generated before the first tick.
	 * @throws FormatException If the directory or token is invalid.
	 */
	public function __construct( private Connection $connection, private Manifest $manifest, string $directory, string $token ) {
		$directory = rtrim( $directory, '/\\' );
		if ( is_link( $directory ) || ! is_dir( $directory ) || realpath( $directory ) !== $directory ) {
			throw new FormatException( 'Database restore requires a resolved private staging directory.' );
		}
		$this->directory = $directory;
		$this->tables    = new OwnedTables( $connection, $manifest->prefix, $manifest->fingerprint, $token );
	}

	/**
	 * Performs bounded units of work and returns only committed database progress.
	 *
	 * @param int   $maximum_units Maximum CREATE, INSERT or completion units in this call.
	 * @param float $maximum_seconds Time budget, checked between units; at least one unit can run.
	 * @return array{tables: int, total_tables: int, rows: int, bytes: int, done: bool} Committed progress.
	 * @throws FormatException If the work budget is invalid.
	 * @throws \Throwable If a file, SQL statement, transaction or ownership check fails.
	 */
	public function tick( int $maximum_units = 10, float $maximum_seconds = 5.0 ): array {
		if ( $maximum_units < 1 || $maximum_units > 1000 || ! is_finite( $maximum_seconds ) || $maximum_seconds <= 0.0 || $maximum_seconds > 20.0 ) {
			throw new FormatException( 'The database import work budget is invalid.' );
		}
		// Before the first statement of the tick, the ledger's CREATE included.
		$packet = $this->packet();
		$this->admissible( $packet );
		$state = $this->tables->locked(
			$this->manifest,
			function () use ( $maximum_units, $maximum_seconds, $packet ): array {
				$ledger   = new Ledger( $this->connection, $this->tables, $this->manifest, $this->directory );
				$state    = $ledger->read();
				$deadline = microtime( true ) + $maximum_seconds;
				for ( $unit = 0; $unit < $maximum_units && 'done' !== $state['phase']; ++$unit ) {
					$this->unit( $ledger, $state, $packet );
					$state = $ledger->read();
					if ( microtime( true ) >= $deadline ) {
						break;
					}
				}
				return $state;
			}
		);
		return [
			'tables'       => $state['table'],
			'total_tables' => count( $this->manifest->tables ),
			'rows'         => $state['rows'],
			'bytes'        => $state['offset'],
			'done'         => 'done' === $state['phase'],
		];
	}

	/**
	 * Refuses an import that would stop at a statement it cannot send, before it sends any:
	 * the dump records each table's largest statement (ADR 0021), and the importer sends it
	 * with the shadow's generated name in place of the table's. A statement beyond the 16 MiB
	 * that the importer reads ends the import with database_restore_too_large, one beyond the
	 * session's max_allowed_packet with database_restore_packet_limit, which names the limit
	 * that the largest of them needs. A table that a version before these fields dumped, or of
	 * a manifest that left them out, is not known here; each of its batches is still checked
	 * before it is sent (InsertStatement::fits()).
	 *
	 * @param int $packet The session's max_allowed_packet.
	 * @throws DatabaseFailed With the table in its context if a recorded statement cannot be sent.
	 */
	private function admissible( int $packet ): void {
		$largest = null;
		$needed  = null;
		foreach ( $this->manifest->reads as $number => $read ) {
			if ( null === $read ) {
				continue;
			}
			$name   = $this->manifest->tables[ $number ]['name'];
			$length = $read['largest_statement'] - strlen( SqlFormatter::identifier( $name ) ) + strlen( SqlFormatter::identifier( $this->tables->shadow( $number ) ) );
			if ( null === $largest || $read['largest_statement'] > $largest[1] ) {
				$largest = [ $name, $read['largest_statement'] ];
			}
			if ( null === $needed || $length > $needed[1] ) {
				$needed = [ $name, $length ];
			}
		}
		if ( null !== $largest && $largest[1] > SqlLexer::MAX_BYTES ) {
			DatabaseFailed::raise(
				sprintf( 'The largest statement of the dump has %1$d bytes, more than the %2$d bytes that the plugin\'s import reads; it was not sent.', $largest[1], SqlLexer::MAX_BYTES ),
				[
					'code'  => 'database_restore_too_large',
					'table' => $largest[0],
				]
			);
		}
		if ( null !== $needed && $needed[1] + InsertStatement::PACKET_MARGIN > $packet ) {
			DatabaseFailed::raise(
				sprintf( 'The largest statement of the dump needs %1$d bytes of SQL and a max_allowed_packet of at least %2$d bytes; the database server allows %3$d bytes. No statement was sent.', $needed[1], intdiv( $needed[1] + InsertStatement::PACKET_MARGIN + 1023, 1024 ) * 1024, $packet ),
				[
					'code'  => 'database_restore_packet_limit',
					'table' => $needed[0],
				]
			);
		}
	}

	/**
	 * Reads the session's packet limit, which the server fixes when the session starts.
	 *
	 * @return int Bytes.
	 * @throws DatabaseFailed If the server reports no usable limit.
	 */
	private function packet(): int {
		$value = $this->connection->rows( 'SELECT @@SESSION.max_allowed_packet AS packet' )[0]['packet'] ?? '';
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,12}\z/', $value ) ) {
			throw new DatabaseFailed( 'The database server reported no usable max_allowed_packet.' );
		}
		return (int) $value;
	}

	/**
	 * Prepares one owned table or commits one row batch/completion record.
	 *
	 * @param Ledger $ledger Authoritative progress in the target database.
	 * @param Cursor $state Committed cursor, under the advisory lock.
	 * @param int    $packet The session's max_allowed_packet.
	 * @throws DatabaseFailed If an owned table, inserted batch or cursor cannot be verified.
	 * @throws \Throwable If a source, ownership check or database write fails.
	 */
	private function unit( Ledger $ledger, array $state, int $packet ): void {
		$shadow = $this->tables->shadow( $state['table'] );
		$file   = new TableFile( $this->directory, $this->manifest, $state['table'], $shadow, $this->tables->owner );
		try {
			$exists = $this->tables->exists( $shadow );
			if ( 'preparing' === $state['phase'] ) {
				if ( $exists ) {
					$this->connection->execute( 'DROP TABLE ' . SqlFormatter::identifier( $shadow ) );
				}
				$this->connection->execute( $file->create->sql );
				if ( ! $this->tables->exists( $shadow ) ) {
					throw new DatabaseFailed( 'The database shadow table was not created.' );
				}
				$file->unchanged();
				$ledger->prepared( $file );
				return;
			}
			if ( ! $exists || $file->identity !== $state['identity'] ) {
				throw new DatabaseFailed( 'The database shadow or its staged source changed since the last batch.' );
			}
			$batch = $file->batch( $state['offset'], $state['rows'] );
			$batch?->fits( $packet );
			$this->connection->execute( 'START TRANSACTION' );
			if ( null === $batch ) {
				$ledger->complete( $state, $file );
			} else {
				if ( $this->insert( $batch, $state['table'] ) !== $batch->rows ) {
					throw new DatabaseFailed( 'The database did not insert the complete restore batch.' );
				}
				$file->unchanged();
				$ledger->advance( $file->position(), $state['rows'] + $batch->rows, $file->transcript( $state['digest'] ) );
			}
			$this->connection->execute( 'COMMIT' );
		} finally {
			$file->close();
		}
	}

	/**
	 * Inserts one batch. Two of its rows, or one of them and a row already imported, with the
	 * same value in a unique key end the import with database_restore_duplicate, which says
	 * what the dump recorded about how it read the table: a table that it read at more than one
	 * moment ("snapshot": false) can hold a row twice that was deleted and inserted again with
	 * a new key in between (ADR 0021); one that it read in one read view cannot.
	 *
	 * @param InsertStatement $batch Checked batch.
	 * @param int             $number Table ordinal.
	 * @return int Inserted rows.
	 * @throws DatabaseFailed If the server refuses the batch, with database_restore_duplicate for a duplicate key.
	 */
	private function insert( InsertStatement $batch, int $number ): int {
		try {
			return $this->connection->execute( $batch->sql );
		} catch ( DatabaseFailed $error ) {
			if ( 1062 !== ( $error->context()['code'] ?? null ) ) {
				throw $error;
			}
			$read = $this->manifest->reads[ $number ] ?? null;
			$how  = match ( $read['snapshot'] ?? null ) {
				false   => 'the dump read it at more than one moment, so a row that was deleted and inserted again with a new key in between can be in it twice',
				true    => 'the dump read all of its rows from one read view, so its own reads did not repeat a row',
				default => 'the dump\'s manifest does not record how it read the table',
			};
			DatabaseFailed::raise(
				'Two rows of a table of the dump have the same value in a unique key; ' . $how . '.',
				[
					'code'  => 'database_restore_duplicate',
					'table' => $this->manifest->tables[ $number ]['name'],
				]
			);
		}
	}
}
