<?php
/**
 * A bounded MySQL write-lock interval on one non-reconnecting session.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/** The caller already established an idle Session. Starting a transaction here would unlock the tables. */
final readonly class WriteBarrier {

	/**
	 * Creates the barrier for this request.
	 *
	 * @param Connection $connection Non-reconnecting SQL session.
	 */
	public function __construct( private Connection $connection ) {
	}

	/**
	 * Locks every source for WRITE in one statement. Metadata, DML and the atomic rename
	 * run on this same session until UNLOCK. Absent destinations are protected by RENAME's
	 * atomic collision check. A crash releases the locks on server disconnect.
	 *
	 * @template T
	 * @param list<string> $names Existing source tables.
	 * @param \Closure():T $work Callback that explicitly commits completed work.
	 * @return T Result.
	 * @throws DatabaseFailed If the connection cannot establish bounded InnoDB table locks.
	 * @throws \Throwable If locking, work or session cleanup fails.
	 */
	public function hold( array $names, \Closure $work ): mixed {
		$row = $this->connection->rows( 'SELECT @@SESSION.autocommit AS auto_mode,@@SESSION.innodb_table_locks AS table_locks,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.innodb_lock_wait_timeout AS row_wait' )[0] ?? [];
		if ( [] === $names || count( $names ) > 2 * SwapCatalog::MAX_TABLES + 1 || '1' !== ( $row['auto_mode'] ?? '' ) || '1' !== ( $row['table_locks'] ?? '' ) || ! ctype_digit( $row['metadata_wait'] ?? '' ) || ! ctype_digit( $row['row_wait'] ?? '' ) ) {
			throw new DatabaseFailed( 'The database exchange requires an idle connection with InnoDB table locks enabled.' );
		}
		$quoted = array_map( static fn ( string $name ): string => SqlFormatter::identifier( $name ) . ' WRITE', $names );
		$locked = false;
		$failed = false;
		try {
			$this->connection->execute( 'SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3, autocommit=0' );
			$this->connection->execute( 'LOCK TABLES ' . implode( ',', $quoted ) );
			$locked = true;
			return $work();
		} catch ( \Throwable $error ) {
			$failed = true;
			throw $error;
		} finally {
			try {
				// Never UNLOCK or enable autocommit over uncommitted partial state.
				$this->connection->execute( 'ROLLBACK' );
				if ( $locked ) {
					$this->connection->execute( 'UNLOCK TABLES' );
				}
				$this->connection->execute( 'SET SESSION autocommit=1,lock_wait_timeout=' . $row['metadata_wait'] . ',innodb_lock_wait_timeout=' . $row['row_wait'] );
			} catch ( \Throwable $error ) {
				if ( $this->connection instanceof MysqliConnection ) {
					$this->connection->discard();
				}
				if ( ! $failed ) {
					throw $error;
				}
			}
		}
	}
}
