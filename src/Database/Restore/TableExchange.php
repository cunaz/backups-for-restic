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
	 * @param Continuity   $continuity What the generation that becomes live must leave the administrator and this plugin.
	 * @param string|null  $authority Reviewed administrative credential and role binding.
	 * @throws DatabaseFailed If any occupied source, schema, dependency or witness changed.
	 */
	public function run( ExchangePlan $plan, int $admin, bool $undo, Continuity $continuity, ?string $authority = null ): void {
		$plan->owned->locked(
			null,
			function () use ( $plan, $admin, $undo, $continuity, $authority ): void {
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
					function () use ( $plan, $admin, $undo, $continuity, $facts, $from, $to, $authority ): void {
						[ $targets, $rename ] = $this->moves( $plan, $facts, $from, $undo );
						$state                = new WordPressState( $this->connection );
						if ( null !== $authority && ! hash_equals( $authority, $state->authority( $plan->document['prefix'], $admin ) ) ) {
							DatabaseFailed::raise( 'The confirming administrator, their permissions or the live roles or active plugins changed after the database review.', [ 'code' => 'database_exchange_changed' ] );
						}
						$state->copy( $plan->document['prefix'], $targets, $admin, $continuity );
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
	 * Adds this plugin to the list of active plugins of the generation that the exchange will
	 * bring live, in that generation's private options table: the imported table for an apply,
	 * the retained one for an undo. It runs before the exchange takes its write barrier over
	 * the live tables. A write lock on the private table alone keeps a replacement out between
	 * its identity check and the write. Once the exchange has moved, nothing is written.
	 *
	 * @param ExchangePlan $plan Persisted plan, for an undo with its confirmed identities.
	 * @param bool         $undo Whether the retained generation comes back.
	 * @param string       $plugin This plugin's path in active_plugins.
	 * @return bool Whether the entry was added.
	 * @throws DatabaseFailed If the private options table was replaced, or with the code database_plugins_invalid if its list is not one that WordPress writes.
	 */
	public function activate( ExchangePlan $plan, bool $undo, string $plugin ): bool {
		return $plan->owned->locked(
			null,
			function () use ( $plan, $undo, $plugin ): bool {
				$facts = $this->destination( $plan );
				if ( $this->witness( $plan, $facts ) !== ( $undo ? 'applied' : 'prepared' ) ) {
					return false;
				}
				$number = array_search( $plan->document['prefix'] . 'options', array_column( $plan->document['pairs'], 'name' ), true );
				if ( ! is_int( $number ) ) {
					throw new DatabaseFailed( 'The database exchange has no WordPress options table.' );
				}
				$table = $undo ? $plan->owned->retained( $number ) : $plan->owned->shadow( $number );
				$id    = $undo ? $plan->document['pairs'][ $number ]['before'] : $plan->document['pairs'][ $number ]['after'];
				return $this->barrier->hold(
					[ $table ],
					function () use ( $facts, $table, $id, $plugin ): bool {
						if ( null === $id || ( $this->catalog->identities( $facts, [ $table ] )[ $table ] ?? null ) !== $id ) {
							throw new DatabaseFailed( 'The private options table of the database exchange was replaced.' );
						}
						return ( new WordPressState( $this->connection ) )->activate( $table, $plugin );
					}
				);
			}
		);
	}

	/**
	 * Reads the same object and WordPress account bindings used at commit. A fresh check
	 * under write locks remains necessary because this preview does not lock live tables.
	 * The undo binds to the tables that hold the applied generation's names now: a rebuild
	 * of a live table renumbers it without replacing it, and the administrator confirms
	 * the identities observed here. The retained generation must still be the recorded one.
	 * Its stored roles and list of active plugins are what the undo brings back.
	 *
	 * @param ExchangePlan $plan Stored owning apply.
	 * @param int          $admin Proposed undo administrator.
	 * @return array{ExchangePlan,array{roles:string|int|null,plugins:string|int|null}} The plan with the observed identities, for the confirmation, and the retained generation's stored role map and list of active plugins, each its length if too long to read, null if absent.
	 * @throws DatabaseFailed With the code database_exchange_rebuilt if a retained table was rebuilt.
	 */
	public function review_undo( ExchangePlan $plan, int $admin ): array {
		return $plan->owned->locked(
			null,
			function () use ( $plan, $admin ): array {
				$facts       = $this->destination( $plan );
				$current     = $this->observed( $this->rebuilt_witness( $plan, $facts ) ?? $plan, $facts );
				[ $targets ] = $this->moves( $current, $facts, 'applied', true );
				$prefix      = $plan->document['prefix'];
				$state       = new WordPressState( $this->connection );
				$state->check( $prefix, $targets, $admin );
				return [
					$current,
					[
						'roles'   => $state->option( $targets[ $prefix . 'options' ], $prefix . 'user_roles', RoleMap::MAX_BYTES ),
						'plugins' => $state->option( $targets[ $prefix . 'options' ], 'active_plugins', PluginList::MAX_BYTES ),
					],
				];
			}
		);
	}

	/**
	 * Recognises this operation's witness after a rebuild gave it a new identity.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @return ExchangePlan|null The plan with the witness's new identity, or null if it was not rebuilt.
	 * @throws DatabaseFailed If the witness name is occupied by a table this operation does not own.
	 */
	public function rebound_witness( ExchangePlan $plan ): ?ExchangePlan {
		return $plan->owned->locked( null, fn (): ?ExchangePlan => $this->rebuilt_witness( $plan, $this->destination( $plan ) ) );
	}

	/**
	 * Names the private tables of a generation that still exist but no longer have their
	 * recorded identity, mostly after OPTIMIZE TABLE. They cannot be proven to be this
	 * operation's copies, so cleanup keeps them and reports them instead.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param string       $generation prepared, applied or undone: the generation to expire.
	 * @return list<string> Private table names.
	 */
	public function rebuilt( ExchangePlan $plan, string $generation ): array {
		return $plan->owned->locked(
			null,
			function () use ( $plan, $generation ): array {
				$ids   = $this->catalog->identities( $this->destination( $plan ), $plan->all_names() );
				$names = [];
				foreach ( $plan->document['pairs'] as $number => $pair ) {
					$name = 'applied' === $generation ? $plan->owned->retained( $number ) : $plan->owned->shadow( $number );
					$id   = 'applied' === $generation ? $pair['before'] : $pair['after'];
					if ( null !== $id && isset( $ids[ $name ] ) && $ids[ $name ] !== $id ) {
						$names[] = $name;
					}
				}
				return $names;
			}
		);
	}

	/**
	 * Finds the witness by its ownership comment when its recorded identity is gone from all
	 * three witness names and exactly one of them holds an owned table. The comment binds
	 * the table to this operation's random token, as for every other table of an import.
	 *
	 * @param ExchangePlan $plan Persisted plan.
	 * @param Facts        $facts Verified current database.
	 * @return ExchangePlan|null The plan with the witness's new identity, or null if unchanged or lost.
	 * @throws DatabaseFailed If the witness name is occupied by a table this operation does not own.
	 */
	private function rebuilt_witness( ExchangePlan $plan, array $facts ): ?ExchangePlan {
		$ids  = $this->catalog->identities( $facts, array_map( $plan->owned->marker( ... ), [ 'prepared', 'applied', 'undone' ] ) );
		$name = array_key_first( $ids );
		if ( null === $name || 1 !== count( $ids ) || in_array( $plan->document['witness'], $ids, true ) || ! $plan->owned->exists( $name ) ) {
			return null;
		}
		return $plan->rebind( [], $ids[ $name ] );
	}

	/**
	 * Binds the applied generation to the tables now under its live names.
	 *
	 * @param ExchangePlan $plan Plan with the current witness.
	 * @param Facts        $facts Verified current database.
	 * @return ExchangePlan The plan with the observed live identities.
	 * @throws DatabaseFailed If a retained table was rebuilt or replaced, or a live table is missing.
	 */
	private function observed( ExchangePlan $plan, array $facts ): ExchangePlan {
		$ids   = $this->catalog->identities( $facts, $plan->all_names() );
		$after = [];
		foreach ( $plan->document['pairs'] as $number => $pair ) {
			$retained = $plan->owned->retained( $number );
			if ( null !== $pair['before'] && ( $ids[ $retained ] ?? null ) !== $pair['before'] ) {
				if ( isset( $ids[ $retained ] ) ) {
					DatabaseFailed::raise( 'A retained database table was rebuilt and cannot be proven to be the original generation.', [ 'code' => 'database_exchange_rebuilt' ] );
				}
				throw new DatabaseFailed( 'A retained database table is missing.' );
			}
			$live = $ids[ $pair['name'] ] ?? null;
			if ( null === $live ) {
				throw new DatabaseFailed( 'A table of the applied database generation is missing.' );
			}
			if ( $live !== $pair['after'] ) {
				$after[ $number ] = $live;
			}
		}
		return [] === $after ? $plan : $plan->rebind( $after, null );
	}

	/**
	 * Builds the exact move list after checking every schema, identity and dependency.
	 *
	 * @param ExchangePlan $plan Validated persisted plan.
	 * @param Facts        $facts Current server identity.
	 * @param string       $from Current witness state.
	 * @param bool         $undo Whether the prior generation is returning.
	 * @return array{array<string,string>,list<string>} Private protected-state destinations and RENAME pairs.
	 * @throws DatabaseFailed If any proposed table or its dependencies have changed.
	 */
	private function moves( ExchangePlan $plan, array $facts, string $from, bool $undo ): array {
		$expected = $plan->positions( $from );
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
		return [ $targets, $rename ];
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
				if ( [] !== array_diff( $this->catalog->inspect( [ $name ] )['blockers'], SwapCatalog::EXCHANGE_ONLY ) ) {
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
