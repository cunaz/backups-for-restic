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

/** A validated operation belongs to a single MySQL server, schema and random namespace. */
final readonly class DatabaseGeneration {

	public const int WINDOW = 86400;

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
	 *
	 * @param Connection $connection Current SQL connection.
	 * @param Job        $job Owning apply.
	 * @return self Validated ownership binding.
	 * @throws FormatException If the filesystem document is corrupted.
	 * @throws DatabaseFailed If this is a different server, database or object map.
	 */
	public static function load( Connection $connection, Job $job ): self {
		$data = $job->data['database_owner'] ?? null;
		if ( JobType::DatabaseApply !== $job->type || ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! is_string( $data['prefix'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $data['prefix'] ) || ! is_string( $data['fingerprint'] ?? null ) || ! is_string( $data['token'] ?? null ) || ! is_int( $data['count'] ?? null ) || $data['count'] < 1 || $data['count'] > SwapCatalog::MAX_TABLES ) {
			throw new FormatException( 'The database restore lost its saved table ownership.' );
		}
		$facts = ( new SwapCatalog( $connection ) )->facts();
		if ( $facts['mariadb'] || '' === $facts['uuid'] || $facts['uuid'] !== ( $data['uuid'] ?? null ) || $facts['database'] !== ( $data['database'] ?? null ) ) {
			throw new DatabaseFailed( 'The database restore belongs to another server or database. Reconnect its original database to recover its private tables.' );
		}
		$owned = new OwnedTables( $connection, $data['prefix'], $data['fingerprint'], $data['token'] );
		$plan  = isset( $job->data['exchange_plan'] ) ? ExchangePlan::load( $job->data['exchange_plan'], $owned, $data['prefix'] ) : null;
		if ( null !== $plan && ( count( $plan->document['pairs'] ) !== $data['count'] || $plan->document['database'] !== $facts['database'] || $plan->document['uuid'] !== $facts['uuid'] ) ) {
			throw new DatabaseFailed( 'The saved physical exchange plan has a conflicting ownership binding.' );
		}
		return new self( $owned, $data['count'], $data['prefix'], $plan );
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
		$owner->data['database_generation']    = $state;
		$owner->data['retained_until']         = $at + self::WINDOW;
		$worker->result['database_generation'] = $state;
		$worker->result['retained_until']      = $at + self::WINDOW;
		$worker->result['database_tables']     = is_array( $owner->data['database_owner'] ?? null ) && is_int( $owner->data['database_owner']['count'] ?? null ) ? $owner->data['database_owner']['count'] : 0;
		$worker->result['restore']             = is_string( $owner->data['restore'] ?? null ) ? $owner->data['restore'] : '';
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
			$phase    = $job->data['database_cleanup_generation'] ?? null;
			if ( null === $phase ) {
				$phase = $exchange->status( $binding->plan );
				if ( 'prepared' !== $phase ) {
					if ( 'applied' === $phase && ! isset( $job->data['database_generation'] ) ) {
						self::record( $job, $job, $phase );
						$jobs->save( $job );
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
				$jobs->save( $job );
			}
			if ( ! is_string( $phase ) ) {
				throw new FormatException( 'The database cleanup generation is unreadable.' );
			}
			$next = $exchange->clean( $binding->plan, $phase, $cursor, $deadline );
		}
		$job->data['database_cleanup'] = $next;
		if ( $next <= $binding->count ) {
			return false;
		}
		unset( $job->data['database_owner'], $job->data['database_cleanup'], $job->data['database_cleanup_generation'], $job->data['exchange_plan'], $job->data['database_generation'], $job->data['retained_until'] );
		return true;
	}

	/**
	 * Excludes verified private generations from all-tables SQL dumps. If a DBA has
	 * replaced a generated name with their own table, that replacement stays in the backup.
	 *
	 * @param Connection $connection Current SQL session.
	 * @param Job        $job Owning job.
	 * @return list<string> Verified private names.
	 * @throws DatabaseFailed If a remaining generation cannot safely be distinguished.
	 */
	public static function exclusions( Connection $connection, Job $job ): array {
		$binding = self::load( $connection, $job );
		$plan    = $binding->exchange();
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
