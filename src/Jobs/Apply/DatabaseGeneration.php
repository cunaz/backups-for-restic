<?php
/**
 * Durable ownership and retention of database restore generations.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;

defined( 'ABSPATH' ) || exit;

/**
 * A validated operation belongs to a single MySQL server, schema and random namespace.
 *
 * @phpstan-import-type Facts from SwapCatalog
 */
final readonly class DatabaseGeneration {

	public const int WINDOW = 86400;

	/** A MySQL server UUID as SHOW VARIABLES reports it. */
	public const string UUID = '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/';

	/**
	 * Keeps the validated durable ownership binding.
	 *
	 * @param OwnedTables       $owned Generated names.
	 * @param int               $count Verified source table count.
	 * @param string            $prefix Original WP prefix.
	 * @param ExchangePlan|null $plan Full native identity map, saved before changing comments.
	 */
	private function __construct( public OwnedTables $owned, public int $count, public string $prefix, public ?ExchangePlan $plan ) {
	}

	/**
	 * Verifies the server UUID before looking up *or forgetting* retained objects. A
	 * failover to an identically named empty database cannot complete cleanup by mistake.
	 * A server that names itself differently, a MariaDB server or another database is
	 * reported with the code database_exchange_foreign; a server without a readable UUID
	 * cannot be told apart and is reported without it. A binding whose saved identity is
	 * missing or malformed, whose token or fingerprint cannot name its tables, or whose saved
	 * exchange plan names another database, server or number of tables than the binding does,
	 * is reported with the code database_binding_unreadable, before the server is asked:
	 * nothing can tell where its tables are (readable()). Before, the plan was compared with
	 * the connected server, and only for a binding of that server: one whose owner named
	 * another database than its plan counted as another database's where the plan's was
	 * connected, and its discard would have forgotten both.
	 *
	 * @param Connection $connection Current SQL connection.
	 * @param Job        $job Owning apply.
	 * @return self Validated ownership binding.
	 * @throws FormatException If the filesystem document is corrupted.
	 * @throws DatabaseFailed If this is a different server or database, or the record contradicts itself.
	 */
	public static function load( Connection $connection, Job $job ): self {
		$data = self::binding( $job );
		if ( ! self::readable( $job ) ) {
			DatabaseFailed::raise( 'The record of the database restore does not say where its private tables are: the identity of their server and database or their names are lost, or its saved exchange plan names another database, server or number of tables.', [ 'code' => 'database_binding_unreadable' ] );
		}
		$facts = ( new SwapCatalog( $connection ) )->facts();
		$place = self::place( $facts, $job );
		if ( 'here' !== $place ) {
			DatabaseFailed::raise( 'The database restore belongs to another server or database. Reconnect its original database to recover its private tables.', 'foreign' === $place ? [ 'code' => 'database_exchange_foreign' ] : [] );
		}
		$owned = new OwnedTables( $connection, $data['prefix'], $data['fingerprint'], $data['token'] );
		// A plan that is there names this server, this database and the binding's count (planned()).
		return new self( $owned, $data['count'], $data['prefix'], isset( $job->data['exchange_plan'] ) ? ExchangePlan::load( $job->data['exchange_plan'], $owned, $data['prefix'] ) : null );
	}

	/**
	 * Where the private tables of a binding are, by the identity of the connected server, as
	 * load() decides it: on this server and in this database (`here`); positively elsewhere
	 * (`foreign`: a MariaDB server, another database name, or another valid server UUID); or
	 * not to be told (`unknown`: a server without a readable UUID cannot be told apart from the
	 * original one, and a binding whose saved identity is missing or malformed names no server
	 * to tell this one apart from). Nothing is asked of the database: one identity serves every
	 * binding.
	 *
	 * @param Facts $facts Identity of the connected server (SwapCatalog::facts()).
	 * @param Job   $job Owning apply.
	 * @return 'here'|'foreign'|'unknown' Place.
	 * @throws FormatException If the filesystem document is corrupted.
	 */
	public static function place( array $facts, Job $job ): string {
		$saved = self::identity( self::binding( $job ) );
		if ( null === $saved ) {
			return 'unknown';
		}
		if ( ! $facts['mariadb'] && $facts['uuid'] === $saved['uuid'] && $facts['database'] === $saved['database'] ) {
			return 'here';
		}
		// Not here: in the same database of a MySQL server, only another valid server UUID is a fact.
		return $facts['mariadb'] || $facts['database'] !== $saved['database'] || 1 === preg_match( self::UUID, $facts['uuid'] ) ? 'foreign' : 'unknown';
	}

	/**
	 * Whether the record of a binding can be read as the plugin saves it: its fields, the
	 * identity of the server and the database that held its private tables when it was made, a
	 * server UUID and a database name, the token and fingerprint that name those tables, and a
	 * saved exchange plan, if there is one, that names the same database, server and number of
	 * tables (planned()). Where it cannot, it was changed outside the plugin, and where its
	 * tables are cannot be told: load() refuses it with database_binding_unreadable, and place()
	 * calls one without its identity unknown.
	 *
	 * @param Job $job Owning apply.
	 * @return bool Whether the record can be read.
	 */
	public static function readable( Job $job ): bool {
		try {
			$data = self::binding( $job );
		} catch ( FormatException ) {
			return false;
		}
		$saved = self::identity( $data );
		return null !== $saved && OwnedTables::valid( $data['fingerprint'], $data['token'] ) && self::planned( $job, $saved, $data['count'] );
	}

	/**
	 * Whether the saved exchange plan of a binding, if there is one, names the database, the
	 * server and as many tables as the binding does. The plugin saves a plan only for a binding
	 * that load() accepted, with the identity of the server that it asked then. A plan that
	 * names another place was changed outside the plugin, and so was a binding beside it:
	 * neither says where the private tables are. Whether the plan is valid in everything else
	 * is for ExchangePlan::load(), where it is used.
	 *
	 * @param Job                                $job Owning apply.
	 * @param array{database:string,uuid:string} $saved Saved identity of the binding (identity()).
	 * @param int                                $count Saved number of tables of the binding.
	 * @return bool Whether there is no plan, or one that agrees with the binding.
	 */
	private static function planned( Job $job, array $saved, int $count ): bool {
		if ( ! isset( $job->data['exchange_plan'] ) ) {
			return true;
		}
		$plan = $job->data['exchange_plan'];
		return is_array( $plan ) && $saved['database'] === ( $plan['database'] ?? null ) && $saved['uuid'] === ( $plan['uuid'] ?? null ) && is_array( $plan['pairs'] ?? null ) && count( $plan['pairs'] ) === $count;
	}

	/**
	 * The saved identity of a binding, or null where it is missing or malformed.
	 *
	 * @param array<string,mixed> $data Saved binding (binding()).
	 * @return array{database:string,uuid:string}|null Database name and server UUID.
	 */
	private static function identity( array $data ): ?array {
		$database = $data['database'] ?? null;
		$uuid     = $data['uuid'] ?? null;
		if ( ! is_string( $database ) || '' === $database || ! is_string( $uuid ) || 1 !== preg_match( self::UUID, $uuid ) ) {
			return null;
		}
		return [
			'database' => $database,
			'uuid'     => $uuid,
		];
	}

	/**
	 * Validates the saved ownership fields without asking the database.
	 *
	 * @param Job $job Owning apply.
	 * @return array{version:1,prefix:string,fingerprint:string,token:string,count:int,database?:mixed,uuid?:mixed} Saved binding.
	 * @throws FormatException If the filesystem document is corrupted.
	 */
	private static function binding( Job $job ): array {
		$data = $job->data['database_owner'] ?? null;
		if ( JobType::DatabaseApply !== $job->type || ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! is_string( $data['prefix'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $data['prefix'] ) || ! is_string( $data['fingerprint'] ?? null ) || ! is_string( $data['token'] ?? null ) || ! is_int( $data['count'] ?? null ) || $data['count'] < 1 || $data['count'] > SwapCatalog::MAX_TABLES ) {
			throw new FormatException( 'The database restore lost its saved table ownership.' );
		}
		return $data;
	}

	/**
	 * Names every private table the binding can own, for a database administrator who has
	 * to remove them by hand on the server that holds them. Nothing is asked of the
	 * connected database; none of these names starts with the site's table prefix.
	 *
	 * @param Connection $connection Any SQL session; only the names are derived.
	 * @param Job        $job Owning apply.
	 * @return array{job:string,database:string,server:string,tables:list<string>} Where the tables are and their names.
	 * @throws FormatException If the filesystem document is corrupted.
	 */
	public static function names( Connection $connection, Job $job ): array {
		$data   = self::binding( $job );
		$owned  = new OwnedTables( $connection, $data['prefix'], $data['fingerprint'], $data['token'] );
		$tables = [ $owned->marker( 'prepared' ), $owned->marker( 'applied' ), $owned->marker( 'undone' ) ];
		for ( $number = 0; $number < $data['count']; ++$number ) {
			$tables[] = $owned->shadow( $number );
			$tables[] = $owned->retained( $number );
		}
		return [
			'job'      => $job->id,
			'database' => is_string( $data['database'] ?? null ) ? $data['database'] : '',
			'server'   => is_string( $data['uuid'] ?? null ) ? $data['uuid'] : '',
			'tables'   => $tables,
		];
	}

	/**
	 * Removes the binding and everything derived from it from the job document.
	 *
	 * @param Job $job Owning apply.
	 */
	public static function forget( Job $job ): void {
		unset( $job->data['database_owner'], $job->data['database_cleanup'], $job->data['database_cleanup_generation'], $job->data['exchange_plan'], $job->data['database_generation'], $job->data['retained_until'], $job->data['database_foreign'], $job->data['database_undo_attempt'] );
	}

	/**
	 * Obtains a verified swap plan for operations after shadow import.
	 *
	 * @return ExchangePlan Persisted physical identities.
	 * @throws FormatException If the apply did not finish preparing its tables.
	 */
	public function exchange(): ExchangePlan {
		return $this->plan ?? throw new FormatException( 'The database restore has no durable exchange plan.' );
	}

	/**
	 * Records the actual outcome, recovered from the physical witness. On undo the entire
	 * replaced live generation is retained, including intervening posts, users and options.
	 *
	 * @param Job    $owner Original apply, possibly the current worker.
	 * @param Job    $worker Apply or undo worker.
	 * @param string $state Applied or undone.
	 * @throws FormatException If the durable pre-exchange timestamp was lost.
	 */
	public static function record( Job $owner, Job $worker, string $state ): void {
		$at = $worker->data['database_swapping_at'] ?? null;
		if ( ! in_array( $state, [ 'applied', 'undone' ], true ) || ! is_int( $at ) || $at < 1 ) {
			throw new FormatException( 'The database restore lost its recovery timestamp.' );
		}
		if ( 'undone' === $state ) {
			unset( $owner->data['database_undo_attempt'] );
		}
		$owner->data['database_generation']    = $state;
		$owner->data['retained_until']         = $at + self::WINDOW;
		$worker->result['database_generation'] = $state;
		$worker->result['retained_until']      = $at + self::WINDOW;
		$worker->result['database_tables']     = is_array( $owner->data['database_owner'] ?? null ) && is_int( $owner->data['database_owner']['count'] ?? null ) ? $owner->data['database_owner']['count'] : 0;
		$worker->result['restore']             = is_string( $owner->data['restore'] ?? null ) ? $owner->data['restore'] : '';
	}

	/**
	 * Uses a rebuilt witness under its new identity, recognised by its ownership comment.
	 * Without this, every status read and the final DROP would refuse or miss it for ever.
	 *
	 * @param JobStore      $jobs Directory-local store.
	 * @param Job           $job Owning apply.
	 * @param TableExchange $exchange Exchange on the verified server.
	 * @param ExchangePlan  $plan Saved plan.
	 * @return ExchangePlan The plan to clean up with.
	 */
	private static function witness( JobStore $jobs, Job $job, TableExchange $exchange, ExchangePlan $plan ): ExchangePlan {
		$rebound = $exchange->rebound_witness( $plan );
		if ( null === $rebound ) {
			return $plan;
		}
		$job->data['exchange_plan'] = $rebound->document;
		$job->warn( 'The witness table of this database restore was rebuilt, for example by OPTIMIZE TABLE, and has a new physical identity. It was recognised by its ownership comment and is used under the new identity.' );
		$jobs->save( $job );
		$jobs->log( $job )->write( 'warning', 'The database exchange witness was rebuilt.', [ 'code' => 'database_exchange_rebuilt' ] );
		return $rebound;
	}

	/**
	 * Records an undo that committed although its request lost the reply, from the attempt
	 * that the undo saved on the owner before its RENAME. The undo job, if it still exists,
	 * shows the recovered outcome as well. Without that attempt nothing is recorded. The
	 * owner gives up the attempt only after the worker was saved, and a worker that already
	 * shows the outcome is left as it is, so a failed save repeats the whole recovery once.
	 *
	 * @param JobStore $jobs Directory-local store.
	 * @param Job      $owner Original apply.
	 */
	private static function undone( JobStore $jobs, Job $owner ): void {
		$attempt = $owner->data['database_undo_attempt'] ?? null;
		$at      = is_array( $attempt ) ? ( $attempt['at'] ?? null ) : null;
		if ( ! is_int( $at ) || $at < 1 ) {
			return;
		}
		$until  = $at + self::WINDOW;
		$worker = is_string( $attempt['job'] ?? null ) ? $jobs->get( $attempt['job'] ) : null;
		if ( null !== $worker && JobType::DatabaseUndo === $worker->type && $worker->state->is_terminal() && ( 'undone' !== ( $worker->result['database_generation'] ?? null ) || $until !== ( $worker->result['retained_until'] ?? null ) ) ) {
			$worker->result['database_generation'] = 'undone';
			$worker->result['retained_until']      = $until;
			$worker->warn( 'The database undo had committed although its request lost the reply; the heartbeat recorded it.' );
			$jobs->save( $worker );
		}
		$owner->data['database_generation'] = 'undone';
		$owner->data['retained_until']      = $until;
		unset( $owner->data['database_undo_attempt'] );
		$jobs->save( $owner );
	}

	/**
	 * Discards a failed import or expires a completed recovery window. Save the observed
	 * generation before any DROP. A missing final witness can then indicate a lost reply.
	 * Caller holds the runner lock and excludes a racing undo start through Staging::guard.
	 *
	 * @param Connection $connection Current, verified server.
	 * @param JobStore   $jobs Directory-local store.
	 * @param Job        $job Owning apply.
	 * @param float      $deadline End of tick.
	 * @param int        $now Current time.
	 * @param bool       $uninstall Also discard a still-valid retention window.
	 * @return bool Whether this request has finished all cleanup that is currently due.
	 * @throws FormatException If a persisted cursor or generation is invalid.
	 */
	public static function clean( Connection $connection, JobStore $jobs, Job $job, float $deadline, int $now, bool $uninstall ): bool {
		$binding = self::load( $connection, $job );
		$cursor  = $job->data['database_cleanup'] ?? 0;
		if ( ! is_int( $cursor ) ) {
			throw new FormatException( 'The database cleanup cursor is unreadable.' );
		}
		if ( null === $binding->plan ) {
			$next = $binding->owned->clean( $binding->count, $cursor, $deadline );
		} else {
			$exchange = new TableExchange( $connection );
			$plan     = self::witness( $jobs, $job, $exchange, $binding->plan );
			$phase    = $job->data['database_cleanup_generation'] ?? null;
			if ( null === $phase ) {
				$phase = $exchange->status( $plan );
				if ( 'prepared' !== $phase ) {
					if ( 'applied' === $phase && ! isset( $job->data['database_generation'] ) ) {
						self::record( $job, $job, $phase );
						$jobs->save( $job );
					}
					if ( 'undone' === $phase && 'applied' === ( $job->data['database_generation'] ?? null ) ) {
						self::undone( $jobs, $job );
					}
					if ( ! $uninstall && ( $job->data['database_generation'] ?? null ) !== $phase ) {
						throw new FormatException( 'The database undo result must be reconciled before its retention window can expire.' );
					}
					if ( ! $uninstall && ! is_int( $job->data['retained_until'] ?? null ) ) {
						throw new FormatException( 'The database undo deadline must be recovered before its tables can expire.' );
					}
					if ( ! $uninstall && $job->data['retained_until'] > $now ) {
						return true;
					}
				}
				$job->data['database_cleanup_generation'] = $phase;
				self::kept( $jobs, $job, $exchange->rebuilt( $plan, $phase ) );
				$jobs->save( $job );
			}
			if ( ! is_string( $phase ) ) {
				throw new FormatException( 'The database cleanup generation is unreadable.' );
			}
			$next = $exchange->clean( $plan, $phase, $cursor, $deadline );
			if ( $next > $binding->count ) {
				// A table rebuilt while the cleanup ran over several ticks is named before the
				// binding that leads to it is forgotten.
				self::kept( $jobs, $job, $exchange->rebuilt( $plan, $phase ) );
			}
		}
		$job->data['database_cleanup'] = $next;
		if ( $next <= $binding->count ) {
			return false;
		}
		self::forget( $job );
		return true;
	}

	/**
	 * Reports the rebuilt private tables that cleanup keeps, each name once.
	 *
	 * @param JobStore     $jobs Directory-local store.
	 * @param Job          $job Owning apply; the caller saves it.
	 * @param list<string> $rebuilt Rebuilt private tables found now.
	 */
	private static function kept( JobStore $jobs, Job $job, array $rebuilt ): void {
		$known = is_array( $job->data['database_rebuilt'] ?? null ) ? array_values( array_filter( $job->data['database_rebuilt'], 'is_string' ) ) : [];
		$new   = array_values( array_diff( $rebuilt, $known ) );
		if ( [] === $new ) {
			return;
		}
		$job->data['database_rebuilt'] = [ ...$known, ...$new ];
		// What is left to do by hand: the limit of a job's warnings must not drop it.
		$job->warn_kept( sprintf( '%1$d private tables of this database restore were rebuilt after the exchange, for example by OPTIMIZE TABLE, and have new physical identities. They cannot be proven to be its copies and are kept; check them and drop them by hand: %2$s', count( $new ), implode( ', ', $new ) ) );
		$jobs->log( $job )->write( 'warning', 'Rebuilt private tables were kept.', [ 'code' => 'database_exchange_rebuilt' ] );
	}

	/**
	 * Excludes verified private generations from all-tables SQL dumps. If a DBA has
	 * replaced a generated name with their own table, that replacement stays in the backup.
	 * A binding of another server or database proves nothing about the tables here, even
	 * if a migration copied tables with these names, and neither does one whose saved identity
	 * is missing or malformed: they all stay in the backup.
	 *
	 * @param Connection $connection Current SQL session.
	 * @param Job        $job Owning job.
	 * @return list<string> Verified private names.
	 * @throws DatabaseFailed If a remaining generation cannot safely be distinguished.
	 */
	public static function exclusions( Connection $connection, Job $job ): array {
		try {
			$binding = self::load( $connection, $job );
		} catch ( DatabaseFailed $error ) {
			// Neither a binding of another server or database nor one whose saved identity is gone.
			if ( ! in_array( $error->context()['code'] ?? null, [ 'database_exchange_foreign', 'database_binding_unreadable' ], true ) ) {
				throw $error;
			}
			return [];
		}
		if ( null === $binding->plan ) {
			// An unfinished import: shadows and its ledger still carry the ownership comment.
			$private = [];
			foreach ( [ $binding->owned->progress(), ...array_map( $binding->owned->shadow( ... ), range( 0, $binding->count - 1 ) ) ] as $name ) {
				if ( $binding->owned->exists( $name, true ) ) {
					$private[] = $name;
				}
			}
			return $private;
		}
		$plan    = $binding->plan;
		$catalog = new SwapCatalog( $connection );
		$facts   = $catalog->facts();
		$ids     = $catalog->identities( $facts, $plan->all_names() );
		$private = [];
		foreach ( [ 'prepared', 'applied', 'undone' ] as $phase ) {
			$name = $binding->owned->marker( $phase );
			if ( ( $ids[ $name ] ?? null ) === $plan->document['witness'] ) {
				$private[] = $name;
			}
		}
		foreach ( $plan->document['pairs'] as $number => $pair ) {
			$shadow = $binding->owned->shadow( $number );
			$old    = $binding->owned->retained( $number );
			if ( ( $ids[ $shadow ] ?? null ) === $pair['after'] ) {
				$private[] = $shadow;
			}
			if ( null !== $pair['before'] && ( $ids[ $old ] ?? null ) === $pair['before'] ) {
				$private[] = $old;
			}
		}
		return $private;
	}
}
