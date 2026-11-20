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
use BackupsForRestic\Exception\JobConflict;

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
		$this->tables    = new OwnedTables( $connection, $manifest, $token );
	}

	/**
	 * Performs bounded units of work and returns only committed database progress.
	 *
	 * @param int   $maximum_units Maximum CREATE, INSERT or completion units in this call.
	 * @param float $maximum_seconds Time budget, checked between units; at least one unit can run.
	 * @return array{tables: int, total_tables: int, rows: int, bytes: int, done: bool} Committed progress.
	 * @throws \Throwable If a file, SQL statement, transaction or ownership check fails.
	 */
	public function tick( int $maximum_units = 10, float $maximum_seconds = 5.0 ): array {
		if ( $maximum_units < 1 || $maximum_units > 1000 || ! is_finite( $maximum_seconds ) || $maximum_seconds <= 0.0 || $maximum_seconds > 20.0 ) {
			throw new FormatException( 'The database import work budget is invalid.' );
		}
		$session = new Session( $this->connection, $this->manifest );
		$lock    = 'bfr-restore-' . hash( 'sha256', $this->tables->progress() );
		// MySQL limits advisory lock names to 64 characters.
		$lock   = substr( $lock, 0, 64 );
		$locked = false;
		$error  = null;
		$state  = null;
		try {
			$acquired = $this->connection->rows( 'SELECT GET_LOCK(' . Session::literal( $lock ) . ', 0) AS acquired' );
			if ( ( $acquired[0]['acquired'] ?? null ) !== '1' ) {
				throw new JobConflict( 'Another request is importing this staged database.' );
			}
			$locked   = true;
			$ledger   = new Ledger( $this->connection, $this->tables, $this->manifest, $this->directory );
			$state    = $ledger->read();
			$deadline = microtime( true ) + $maximum_seconds;
			for ( $unit = 0; $unit < $maximum_units && 'done' !== $state['phase']; ++$unit ) {
				$this->unit( $ledger, $state );
				$state = $ledger->read();
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		} catch ( \Throwable $failure ) {
			$error = $failure;
		} finally {
			try {
				// Roll back before releasing the lock, including when COMMIT's outcome is unknown.
				$session->close();
			} catch ( \Throwable $failure ) {
				$error ??= $failure;
			}
			if ( $locked ) {
				try {
					$released = $this->connection->rows( 'SELECT RELEASE_LOCK(' . Session::literal( $lock ) . ') AS released' );
					if ( ( $released[0]['released'] ?? null ) !== '1' ) {
						throw new DatabaseFailed( 'The database import lock could not be released.' );
					}
				} catch ( \Throwable $failure ) {
					$error ??= $failure;
				}
			}
		}
		if ( null !== $error ) {
			throw $error;
		}
		if ( null === $state ) {
			throw new DatabaseFailed( 'The database import did not return committed progress.' );
		}
		return [
			'tables'       => $state['table'],
			'total_tables' => count( $this->manifest->tables ),
			'rows'         => $state['rows'],
			'bytes'        => $state['offset'],
			'done'         => 'done' === $state['phase'],
		];
	}

	/**
	 * Prepares one owned table or commits one row batch/completion record.
	 *
	 * @param Ledger $ledger Authoritative progress in the target database.
	 * @param Cursor $state Committed cursor, under the advisory lock.
	 * @throws DatabaseFailed If an owned table, inserted batch or cursor cannot be verified.
	 * @throws \Throwable If a source, ownership check or database write fails.
	 */
	private function unit( Ledger $ledger, array $state ): void {
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
			$this->connection->execute( 'START TRANSACTION' );
			if ( null === $batch ) {
				$ledger->complete( $state, $file );
			} else {
				if ( $this->connection->execute( $batch->sql ) !== $batch->rows ) {
					throw new DatabaseFailed( 'The database did not insert the complete restore batch.' );
				}
				$file->unchanged();
				$ledger->advance( $file->position(), $state['rows'] + $batch->rows );
			}
			$this->connection->execute( 'COMMIT' );
		} finally {
			$file->close();
		}
	}
}
