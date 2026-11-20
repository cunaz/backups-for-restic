<?php
/**
 * Atomic, journaled MySQL table exchange for one reviewed site restore.
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
 * The ledger's own physical ID moves alongside the selected tables in one RENAME. Never
 * infer an outcome from a PHP step name, a prefix match or the existence of an old name.
 *
 * @phpstan-import-type Facts from SwapCatalog
 */
final readonly class TableExchange {

	/**
	 * Physical dictionary for the target database.
	 *
	 * @var SwapCatalog
	 */
	private SwapCatalog $catalog;

	/**
	 * Transaction and metadata locks on that same connection.
	 *
	 * @var WriteBarrier
	 */
	private WriteBarrier $barrier;

	/**
	 * Creates an exchange on an explicitly non-reconnecting session.
	 *
	 * @param Connection $connection SQL session.
	 */
	public function __construct( private Connection $connection ) {
		$this->catalog = new SwapCatalog( $connection );
		$this->barrier = new WriteBarrier( $connection );
	}

	/**
	 * Retains exact original comments on tables about to become live. A durable plan must
	 * already exist: the physical ID remains the ownership proof after the comment changes.
	 * Replaying a finished ordinal changes nothing. There is no DDL on a live site table.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param int          $number Table ordinal.
	 * @throws DatabaseFailed If a private shadow or its ledger has been replaced.
	 * @throws FormatException If the ordinal or stored comment is invalid.
	 */
	public function finalize( ExchangePlan $plan, int $number ): void {
		$pair = $plan->document['pairs'][ $number ] ?? null;
		if ( null === $pair ) {
			throw new FormatException( 'The exchange preparation has an invalid table ordinal.' );
		}
		$plan->owned->locked(
			null,
			function () use ( $plan, $pair, $number ): void {
				$facts  = $this->destination( $plan );
				$shadow = $plan->owned->shadow( $number );
				$ledger = $plan->owned->progress();
				$this->barrier->hold(
					[ $shadow, $ledger ],
					function () use ( $facts, $plan, $pair, $number, $shadow, $ledger ): void {
						$ids = $this->catalog->identities( $facts, [ $shadow, $ledger ] );
						if ( ( $ids[ $shadow ] ?? null ) !== $pair['after'] || ( $ids[ $ledger ] ?? null ) !== $plan->document['witness'] || ! $plan->owned->exists( $ledger ) ) {
							throw new DatabaseFailed( 'A database exchange shadow or its preparation witness was replaced.' );
						}
						$comment = $this->connection->rows( 'SELECT original_comment FROM ' . SqlFormatter::identifier( $ledger ) . ' WHERE id=' . ( $number + 1 ) . " AND phase='done'" )[0]['original_comment'] ?? '';
						$lexer   = new SqlLexer( $comment );
						$token   = $lexer->next();
						if ( $token?->kind !== SqlToken::STRING || $token->text !== $comment || null !== $lexer->next() ) {
							throw new FormatException( 'The database table comment is not one validated literal.' );
						}
						$current = $this->catalog->definition( $shadow );
						if ( $current->structure !== $pair['after_schema'] ) {
							throw new DatabaseFailed( 'The database shadow definition changed after import.' );
						}
						if ( $current->comment !== $comment ) {
							if ( ! $plan->owned->exists( $shadow ) ) {
								throw new DatabaseFailed( 'The database shadow lost its import ownership before finalization.' );
							}
							$this->connection->execute( 'ALTER TABLE ' . SqlFormatter::identifier( $shadow ) . ' COMMENT=' . $comment . ', ALGORITHM=INPLACE' );
							if ( ( $this->catalog->identities( $facts, [ $shadow ] )[ $shadow ] ?? null ) !== $pair['after'] ) {
								throw new DatabaseFailed( 'The server rebuilt a database shadow during comment finalization.' );
							}
						}
					}
				);
			}
		);
	}

	/**
	 * Reads only this operation's versioned witness and physical identity. This remains
	 * usable while expiring individual old tables or after an unknown RENAME outcome.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @return string prepared, applied or undone.
	 */
	public function status( ExchangePlan $plan ): string {
		return $plan->owned->locked( null, fn (): string => $this->witness( $plan, $this->destination( $plan ) ) );
	}

	/**
	 * Exchanges live and private tables, or returns the already-committed outcome. The
	 * caller keeps WordPress in maintenance mode through this call and the cache flush.
	 * Before undo, every current live table is kept intact under its own private name.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param int          $admin Confirming current WordPress user.
	 * @param bool         $undo Reverse an applied exchange.
	 * @throws DatabaseFailed If any occupied source, schema, dependency or witness changed.
	 */
	public function run( ExchangePlan $plan, int $admin, bool $undo ): void {
		$plan->owned->locked(
			null,
			function () use ( $plan, $admin, $undo ): void {
				$facts  = $this->destination( $plan );
				$from   = $undo ? 'applied' : 'prepared';
				$to     = $undo ? 'undone' : 'applied';
				$status = $this->witness( $plan, $facts );
				if ( $to === $status ) {
					return;
				}
				if ( $from !== $status ) {
					throw new DatabaseFailed( 'The database exchange has already moved to another generation.' );
				}
				$expected = $plan->positions( $from );
				$this->barrier->hold(
					array_keys( $expected ),
					function () use ( $plan, $admin, $undo, $facts, $from, $to, $expected ): void {
						if ( $this->catalog->identities( $facts, $plan->all_names() ) !== $expected || ! $plan->owned->exists( $plan->owned->marker( $from ) ) ) {
							throw new DatabaseFailed( 'A table was replaced or a reserved exchange name is occupied.' );
						}
						// Recheck dependencies under the same table locks that RENAME will use.
						// An incoming FK from an unrelated schema is included by the engine dictionary.
						foreach ( array_chunk( array_keys( $expected ), SwapCatalog::MAX_TABLES ) as $names ) {
							if ( [] !== $this->catalog->inspect( $names )['blockers'] ) {
								throw new DatabaseFailed( 'A current database dependency or grant prevents the reviewed exchange.' );
							}
						}
						$ledger   = SqlFormatter::identifier( $plan->owned->marker( $from ) );
						$metadata = $this->connection->rows( 'SELECT table_number,original_comment FROM ' . $ledger . " WHERE id>0 AND phase='done' ORDER BY table_number LIMIT " . ( count( $plan->document['pairs'] ) + 1 ) );
						$targets  = [];
						$rename   = [];
						foreach ( $plan->document['pairs'] as $number => $pair ) {
							$live   = $pair['name'];
							$old    = $plan->owned->retained( $number );
							$shadow = $plan->owned->shadow( $number );
							$new    = $this->catalog->definition( $undo ? $live : $shadow );
							if ( $new->structure !== $pair['after_schema'] || ( $metadata[ $number ]['table_number'] ?? null ) !== (string) $number || ( $metadata[ $number ]['original_comment'] ?? null ) !== $new->comment ) {
								throw new DatabaseFailed( 'The imported database generation changed its schema.' );
							}
							if ( null !== $pair['before'] ) {
								$prior = $this->catalog->definition( $undo ? $old : $live );
								if ( hash( 'sha256', $prior->structure . $prior->comment ) !== $pair['before_schema'] ) {
									throw new DatabaseFailed( 'The prior database generation changed its schema.' );
								}
							}
							if ( $undo ) {
								$rename[] = self::move( $live, $shadow );
								if ( null !== $pair['before'] ) {
									$targets[ $live ] = $old;
									$rename[]         = self::move( $old, $live );
								}
							} else {
								if ( null !== $pair['before'] ) {
									$rename[] = self::move( $live, $old );
								}
								$targets[ $live ] = $shadow;
								$rename[]         = self::move( $shadow, $live );
							}
						}
						( new WordPressState( $this->connection ) )->copy( $plan->document['prefix'], $targets, $admin );
						$this->connection->execute( 'COMMIT' );
						$rename[] = self::move( $plan->owned->marker( $from ), $plan->owned->marker( $to ) );
						$this->connection->execute( 'RENAME TABLE ' . implode( ',', $rename ) );
						if ( $this->catalog->identities( $facts, $plan->all_names() ) !== $plan->positions( $to ) ) {
							throw new DatabaseFailed( 'The server could not verify the committed database exchange.' );
						}
					}
				);
			}
		);
	}

	/**
	 * Drops a bounded number of retired physical tables, then the owned witness last. Its
	 * phase is recorded by the job before the first DROP. A lost cursor can replay each
	 * ordinal. A same-named replacement belongs to another owner and is left in place.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param string       $generation Durable generation selected for cleanup.
	 * @param int          $cursor Next ordinal, with the witness after all pairs.
	 * @param float        $deadline End of tick.
	 * @return int Next ordinal, greater than the table count when complete.
	 * @throws FormatException If the cleanup phase or cursor is invalid.
	 * @throws DatabaseFailed If the witness state changed or cleanup lost its ownership proof.
	 */
	public function clean( ExchangePlan $plan, string $generation, int $cursor, float $deadline ): int {
		$count = count( $plan->document['pairs'] );
		if ( ! in_array( $generation, [ 'prepared', 'applied', 'undone' ], true ) || $cursor < 0 || $cursor > $count + 1 ) {
			throw new FormatException( 'The database exchange cleanup cursor is invalid.' );
		}
		return $plan->owned->locked(
			null,
			function () use ( $plan, $generation, $count, $cursor, $deadline ): int {
				$facts  = $this->destination( $plan );
				$marker = $plan->owned->marker( $generation );
				$ids    = $this->catalog->identities( $facts, $plan->all_names() );
				if ( ! isset( $ids[ $marker ] ) ) {
					// The final witness DROP might have committed before the job-file save.
					// Verify that no owned physical table remains under *any* private name.
					$live = array_column( $plan->document['pairs'], 'name' );
					$kept = array_intersect( array_diff_key( $ids, array_fill_keys( $live, true ) ), [ $plan->document['witness'], ...array_column( $plan->document['pairs'], 'before' ), ...array_column( $plan->document['pairs'], 'after' ) ] );
					if ( [] !== $kept ) {
						throw new DatabaseFailed( 'The exchange witness is missing while owned private data remains.' );
					}
					return $count + 1;
				}
				if ( $generation !== $this->witness( $plan, $facts ) ) {
					throw new DatabaseFailed( 'The database exchange moved to another generation before cleanup.' );
				}
				$end = min( $count + 1, $cursor + 8 );
				while ( $cursor < $end ) {
					if ( $cursor === $count ) {
						$this->drop( $facts, $marker, $plan->document['witness'] );
					} else {
						$pair = $plan->document['pairs'][ $cursor ];
						$name = 'applied' === $generation ? $plan->owned->retained( $cursor ) : $plan->owned->shadow( $cursor );
						$id   = 'applied' === $generation ? $pair['before'] : $pair['after'];
						if ( null !== $id && ( $ids[ $name ] ?? null ) === $id ) {
							$this->drop( $facts, $name, $id );
						}
					}
					++$cursor;
					if ( microtime( true ) >= $deadline ) {
						break;
					}
				}
				return $cursor;
			}
		);
	}

	/**
	 * Drops only the expected physical object under a WRITE lock that prevents replacement
	 * between the ownership check and DROP. A temporary table must not hide that object.
	 *
	 * @param Facts  $facts Verified current database.
	 * @param string $name Private table name.
	 * @param string $id Expected native identity.
	 * @throws DatabaseFailed If cleanup cannot verify a permanent object without dependencies.
	 */
	private function drop( array $facts, string $name, string $id ): void {
		$this->barrier->hold(
			[ $name ],
			function () use ( $facts, $name, $id ): void {
				if ( ( $this->catalog->identities( $facts, [ $name ] )[ $name ] ?? null ) !== $id ) {
					return;
				}
				if ( [] !== $this->catalog->inspect( [ $name ] )['blockers'] ) {
					throw new DatabaseFailed( 'A retained database table has dependencies or could not be verified for expiry.' );
				}
				$this->catalog->definition( $name );
				$this->connection->execute( 'DROP TABLE ' . SqlFormatter::identifier( $name ) );
			}
		);
	}

	/**
	 * Identifies only the supported server and database bound to the plan.
	 *
	 * @param ExchangePlan $plan Stored plan.
	 * @return Facts Fresh server binding.
	 * @throws DatabaseFailed If this is another database or the server cannot honor the contract.
	 */
	private function destination( ExchangePlan $plan ): array {
		$facts = $this->catalog->facts();
		if ( $facts['uuid'] !== $plan->document['uuid'] || $facts['database'] !== $plan->document['database'] || $facts['read_only'] || ! $facts['atomic'] || $facts['mariadb'] ) {
			throw new DatabaseFailed( 'This server cannot exchange the reviewed database under MySQL write locks.' );
		}
		return $facts;
	}

	/**
	 * Requires exactly one marker of the expected physical generation.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param Facts        $facts Verified current database.
	 * @return string Durable state.
	 * @throws DatabaseFailed If the witness is absent, ambiguous or foreign.
	 */
	private function witness( ExchangePlan $plan, array $facts ): string {
		$states = [ 'prepared', 'applied', 'undone' ];
		$names  = array_map( $plan->owned->marker( ... ), $states );
		$ids    = $this->catalog->identities( $facts, $names );
		foreach ( $states as $i => $state ) {
			if ( [ $names[ $i ] => $plan->document['witness'] ] === $ids && $plan->owned->exists( $names[ $i ] ) ) {
				return $state;
			}
		}
		throw new DatabaseFailed( 'The database exchange lost its versioned physical state witness.' );
	}

	/**
	 * One generated pair for the multi-table RENAME statement.
	 *
	 * @param string $from Validated permanent name.
	 * @param string $to Generated or reviewed site name.
	 * @return string SQL fragment containing two quoted identifiers.
	 */
	private static function move( string $from, string $to ): string {
		return SqlFormatter::identifier( $from ) . ' TO ' . SqlFormatter::identifier( $to );
	}
}
