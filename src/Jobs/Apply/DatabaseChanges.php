<?php
/**
 * Confirmed database apply and undo jobs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\SessionDrain;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\ClosureStep;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\StagedDatabase;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Each transition from a harmless import into a live operation binds durable filesystem
 * state first. The MySQL witness records the outcome even if the request is then killed.
 *
 * @phpstan-import-type Preview from DatabaseReview
 */
final readonly class DatabaseChanges implements StepProvider {

	/** Client errors after which a session is gone and its last statement's outcome unknown. */
	private const array LOST = [ 2006, 2013, 2055 ];

	/**
	 * Checked source and live review builder.
	 *
	 * @var DatabaseReview
	 */
	private DatabaseReview $reviews;

	/**
	 * Jobs whose database session was lost in this request, by job ID. Their cleanup waits
	 * for a later tick, which works on a new session.
	 *
	 * @var \ArrayObject<string,true>
	 */
	private \ArrayObject $interrupted;

	/**
	 * Builds a worker on the checked target and directory-local stores.
	 *
	 * @param JobStore                   $jobs Directory-local jobs.
	 * @param Staging                    $staging Retained restore source.
	 * @param Clock                      $clock Current time.
	 * @param RandomSource               $random Private namespace allocation.
	 * @param int                        $hours Staging TTL.
	 * @param string                     $prefix Current site prefix.
	 * @param \Closure():Connection      $connection Non-reconnecting SQL handle.
	 * @param \Closure(int):list<string> $policy Current site, cache and initiating user eligibility.
	 * @param FileMaintenance            $maintenance Scoped WordPress maintenance marker.
	 * @param \Closure():void            $refresh Clear WordPress caches and reschedule heartbeat.
	 * @param SessionDrain               $drain Wait for requests from before maintenance.
	 */
	public function __construct(
		private JobStore $jobs,
		private Staging $staging,
		private Clock $clock,
		private RandomSource $random,
		private int $hours,
		private string $prefix,
		private \Closure $connection,
		private \Closure $policy,
		private FileMaintenance $maintenance,
		private \Closure $refresh,
		private SessionDrain $drain = new SessionDrain()
	) {
		/**
		 * No session was lost yet in this request.
		 *
		 * @var \ArrayObject<string,true> $interrupted
		 */
		$interrupted       = new \ArrayObject();
		$this->reviews     = new DatabaseReview( $jobs, $staging, $hours, $prefix );
		$this->interrupted = $interrupted;
	}

	/**
	 * Previews an unexpired, completed database rehearsal for one administrator.
	 *
	 * @param string $restore Restore job ID.
	 * @param int    $user Confirming user.
	 * @return Preview Review.
	 */
	public function preview( string $restore, int $user ): array {
		$connection = ( $this->connection )();
		return $this->reviews->read( $connection, $restore, $user, $this->blockers( $connection, $user ), false )['preview'];
	}

	/**
	 * Site blockers of a new database apply. A binding whose private tables are on another
	 * server or database waits for the administrator's decision first.
	 *
	 * @param Connection $connection Current SQL session.
	 * @param int        $user Confirming user.
	 * @return list<string> Blocker codes.
	 */
	private function blockers( Connection $connection, int $user ): array {
		$blockers = ( $this->policy )( $user );
		foreach ( $this->jobs->database_owners() as $owner ) {
			if ( ! $owner->state->is_terminal() ) {
				continue;
			}
			if ( isset( $owner->data['database_foreign'] ) ) {
				$blockers[] = 'database_exchange_foreign';
				continue;
			}
			try {
				DatabaseGeneration::load( $connection, $owner );
			} catch ( DatabaseFailed $error ) {
				if ( 'database_exchange_foreign' === ( $error->context()['code'] ?? null ) ) {
					$blockers[] = 'database_exchange_foreign';
				}
			} catch ( FormatException ) {
				// The heartbeat reports a damaged ownership record on its own job.
				continue;
			}
		}
		return array_values( array_unique( $blockers ) );
	}

	/**
	 * Queues only the exact user-bound review. Retention cannot remove staging between
	 * the successful check and the durable job pin.
	 *
	 * @param string $restore Rehearsal ID.
	 * @param int    $user Confirming WordPress user.
	 * @param string $digest Confirmed digest.
	 * @return Job Queued job.
	 */
	public function start_apply( string $restore, int $user, string $digest ): Job {
		return $this->staging->guard(
			function () use ( $restore, $user, $digest ): Job {
				self::confirmed( $this->preview( $restore, $user ), $digest );
				return $this->jobs->create(
					JobType::DatabaseApply,
					'manual',
					$this->clock->now()[0],
					[
						'restore' => $restore,
						'user'    => $user,
						'digest'  => $digest,
					]
				);
			}
		);
	}

	/**
	 * Lists valid undo windows without visiting SQL or staging on ordinary admin polls.
	 *
	 * @return list<array{job:string,restore:string,expires:int}> Candidates checked again on review.
	 */
	public function undoable(): array {
		$rows = [];
		$now  = $this->clock->now()[0];
		foreach ( $this->jobs->database_owners() as $job ) {
			if ( $this->eligible( $job, $now ) && is_int( $job->data['retained_until'] ) ) {
				$rows[] = [
					'job'     => $job->id,
					'restore' => self::field( $job, 'restore' ),
					'expires' => $job->data['retained_until'],
				];
			}
		}
		return $rows;
	}

	/**
	 * Validates the native generation and administrator again before reviewing an undo.
	 *
	 * @param string $apply Original apply job.
	 * @param int    $user Confirming administrator.
	 * @return Preview Current undo plan.
	 */
	public function preview_undo( string $apply, int $user ): array {
		return $this->undo_review( $this->original( $apply ), $user, false )['preview'];
	}

	/**
	 * Queues the reviewed undo under the same guard used for expiry.
	 *
	 * @param string $apply Original apply ID.
	 * @param int    $user Confirming administrator.
	 * @param string $digest Confirmed digest.
	 * @return Job Queued undo.
	 */
	public function start_undo( string $apply, int $user, string $digest ): Job {
		return $this->staging->guard(
			function () use ( $apply, $user, $digest ): Job {
				$preview = $this->preview_undo( $apply, $user );
				self::confirmed( $preview, $digest );
				return $this->jobs->create(
					JobType::DatabaseUndo,
					'manual',
					$this->clock->now()[0],
					[
						'apply'   => $apply,
						'restore' => $preview['restore'],
						'user'    => $user,
						'digest'  => $digest,
					]
				);
			}
		);
	}

	/**
	 * Describes a binding whose private tables the connected server positively identifies
	 * as another server's or another database's. Read-only; nothing is asked of the
	 * database beyond its identity.
	 *
	 * @param string $apply Owning database apply.
	 * @return array{job:string,database:string,server:string,tables:list<string>} Where the tables are and their names.
	 * @throws JobConflict If no finished job holds such a binding, or it belongs to this database.
	 */
	public function foreign_binding( string $apply ): array {
		return $this->foreign_owner( $apply )[1];
	}

	/**
	 * Forgets a binding whose private tables are on another server or in another database,
	 * after the administrator confirmed it. Nothing is dropped anywhere: the result names the
	 * tables to remove by hand where they are. The caller holds the runner lock; staging's
	 * guard keeps an undo of this binding from being queued at the same time.
	 *
	 * @param string $apply Owning database apply.
	 * @param int    $user Confirming WordPress user, 0 from WP-CLI without a user.
	 * @param string $via Where it was confirmed: rest or wp-cli.
	 * @return array{job:string,database:string,server:string,tables:list<string>} The discarded binding.
	 * @throws JobConflict If no finished job holds such a binding, or it belongs to this database.
	 */
	public function discard( string $apply, int $user, string $via ): array {
		return $this->staging->guard(
			function () use ( $apply, $user, $via ): array {
				$active = $this->jobs->active();
				if ( JobType::DatabaseUndo === $active?->type && $apply === ( $active->data['apply'] ?? null ) ) {
					JobConflict::raise( 'An undo of this database restore is still queued or running.', [ 'code' => 'job_conflict' ] );
				}
				[ $owner, $binding ] = $this->foreign_owner( $apply );
				DatabaseGeneration::forget( $owner );
				$owner->data['database_discarded'] = [
					'at'   => $this->clock->now()[0],
					'user' => $user,
					'via'  => $via,
				] + $binding;
				$owner->warn( sprintf( 'The binding to %1$d private table names in the database %2$s on the server %3$s was discarded. Nothing was dropped; remove the tables that exist there by hand.', count( $binding['tables'] ), $binding['database'], $binding['server'] ) );
				$this->jobs->save( $owner );
				$this->jobs->log( $owner )->write(
					'warning',
					'Database restore binding discarded.',
					[
						'user' => $user,
						'via'  => $via,
						'code' => 'database_exchange_foreign',
					]
				);
				return $binding;
			}
		);
	}

	/**
	 * Finds a finished apply whose binding the connected server positively disowns.
	 *
	 * @param string $apply Owning database apply.
	 * @return array{Job,array{job:string,database:string,server:string,tables:list<string>}} The job and its binding.
	 * @throws JobConflict If no finished job holds such a binding, or it belongs to this database.
	 * @throws DatabaseFailed If the connected server cannot be identified.
	 */
	private function foreign_owner( string $apply ): array {
		$owner = $this->jobs->get( $apply );
		if ( null === $owner || JobType::DatabaseApply !== $owner->type || ! isset( $owner->data['database_owner'] ) ) {
			JobConflict::raise( 'This job holds no database restore binding.', [ 'code' => 'database_binding_missing' ] );
		}
		if ( ! $owner->state->is_terminal() ) {
			JobConflict::raise( 'The database restore that holds this binding is still running.', [ 'code' => 'job_conflict' ] );
		}
		$connection = ( $this->connection )();
		try {
			DatabaseGeneration::load( $connection, $owner );
		} catch ( DatabaseFailed $error ) {
			if ( 'database_exchange_foreign' !== ( $error->context()['code'] ?? null ) ) {
				throw $error;
			}
			return [ $owner, DatabaseGeneration::names( $connection, $owner ) ];
		}
		JobConflict::raise( 'This binding belongs to the connected database. The heartbeat removes its private tables when they expire.', [ 'code' => 'database_binding_verified' ] );
	}

	/**
	 * Revalidates the current objects and opening of the undo window.
	 *
	 * The confirmation covers the identities observed now. After a rebuild of a live table
	 * or of the witness these differ from the recorded ones; the undo job keeps the change.
	 *
	 * @param Job  $owner Owning apply.
	 * @param int  $user Initiating administrator.
	 * @param bool $pinned A queued undo pins the original window.
	 * @return array{preview:Preview,authority:string,rebound:array{after:array<int,string>,witness:string|null}} Undo review and private binding.
	 * @throws JobConflict If the recovery window expired.
	 */
	private function undo_review( Job $owner, int $user, bool $pinned ): array {
		if ( ! $this->eligible( $owner, $pinned ? 0 : $this->clock->now()[0] ) ) {
			JobConflict::raise( 'This database undo window is no longer available.', [ 'code' => 'database_exchange_changed' ] );
		}
		$connection = ( $this->connection )();
		$recorded   = DatabaseGeneration::load( $connection, $owner )->exchange();
		$plan       = $recorded;
		$entries    = [];
		foreach ( $plan->document['pairs'] as $pair ) {
			$entries[] = [
				'table'  => $pair['name'],
				'action' => null === $pair['before'] ? 'remove_from_live' : 'replace',
			];
		}
		$blockers = ( $this->policy )( $user );
		if ( $this->prefix !== $plan->document['prefix'] ) {
			$blockers[] = 'wordpress_environment';
		}
		$authority = '';
		if ( [] === $blockers ) {
			try {
				$authority = ( new WordPressState( $connection ) )->authority( $this->prefix, $user );
				$plan      = ( new TableExchange( $connection ) )->review_undo( $recorded, $user );
			} catch ( DatabaseFailed | FormatException $error ) {
				$code       = $error->context()['code'] ?? null;
				$blockers[] = is_string( $code ) ? $code : 'database_exchange_changed';
			}
		}
		$after = [];
		foreach ( $plan->document['pairs'] as $number => $pair ) {
			if ( $pair['after'] !== $recorded->document['pairs'][ $number ]['after'] ) {
				$after[ $number ] = $pair['after'];
			}
		}
		return [
			'preview'   => DatabaseReview::summary( self::field( $owner, 'restore' ), $user, $entries, $blockers, [], [ 'undo', $owner->id, $owner->data['retained_until'], $plan->document, $authority ] ),
			'authority' => $authority,
			'rebound'   => [
				'after'   => $after,
				'witness' => $plan->document['witness'] !== $recorded->document['witness'] ? $plan->document['witness'] : null,
			],
		];
	}

	/**
	 * Applies the identities that the administrator confirmed for an undo to the recorded
	 * plan. Only identities of the applied generation and of the witness can change.
	 *
	 * @param Job          $undo Database undo.
	 * @param ExchangePlan $plan Recorded plan of the owning apply.
	 * @return ExchangePlan The plan to exchange.
	 * @throws FormatException If the confirmed identities are unreadable.
	 */
	private static function confirmed_plan( Job $undo, ExchangePlan $plan ): ExchangePlan {
		$rebound = $undo->data['rebound'] ?? null;
		$witness = is_array( $rebound ) ? ( $rebound['witness'] ?? null ) : null;
		if ( ! is_array( $rebound ) || ! is_array( $rebound['after'] ?? null ) || ( null !== $witness && ! is_string( $witness ) ) ) {
			throw new FormatException( 'The confirmed database undo lost its table identities.' );
		}
		$after = [];
		foreach ( $rebound['after'] as $number => $id ) {
			if ( ! is_int( $number ) || ! is_string( $id ) ) {
				throw new FormatException( 'The confirmed database undo has an invalid table identity.' );
			}
			$after[ $number ] = $id;
		}
		return $plan->rebind( $after, $witness );
	}

	/**
	 * Routes one runner step; SQL diagnostics are safe and classified as restore errors.
	 *
	 * @param JobType $type Database apply or undo.
	 * @param string  $name Step name.
	 * @return Step Runnable operation.
	 */
	public function step( JobType $type, string $name ): Step {
		$error_code = 'cleaning_up' === $name ? 'database_exchange_cleanup' : 'database_exchange_failed';
		return new ClosureStep(
			function ( Job $job, EventLog $log, float $deadline ) use ( $name, $error_code ): bool {
				try {
					return match ( $name ) {
						'preflight'          => $this->preflight( $job ),
						'importing_database' => $this->import( $job, $deadline ),
						'preparing_database' => $this->prepare( $job, $deadline ),
						'swapping_database'  => $this->swap( $job, $deadline ),
						'recording'          => true,
						'cleaning_up'        => $this->cleanup( $job, $deadline ),
						default              => throw new InvalidArgument( 'Unknown database change step.' ),
					};
				} catch ( DatabaseFailed | FormatException $error ) {
					$code = $error->context()['code'] ?? null;
					if ( in_array( $code, self::LOST, true ) ) {
						$this->interrupted[ $job->id ] = true;
						$this->renew();
					}
					if ( in_array( $code, [ 1205, 1213, 2006, 2013, 2055 ], true ) ) {
						DatabaseFailed::raise( 'The database restore is waiting for a lock or a fresh connection.', [ 'code' => 'database_restore_busy' ] );
					}
					if ( is_string( $code ) ) {
						throw $error;
					}
					DatabaseFailed::raise( $error->getMessage(), [ 'code' => $error_code ] );
				}
			}
		);
	}

	/**
	 * Asks for a session again after a lost one, so that WordPress can go on in this
	 * request. The cleanup still waits for a later tick before it reads the outcome.
	 */
	private function renew(): void {
		try {
			( $this->connection )();
		} catch ( PluginException ) {
			// The next tick tries again with the connection WordPress has then.
			return;
		}
	}

	/**
	 * Validates the original review and saves the SQL ownership token before the first
	 * CREATE. No queued-and-cancelled job has a cleanup obligation.
	 *
	 * @param Job $job Current job.
	 * @return bool Whether preflight finished.
	 */
	private function preflight( Job $job ): bool {
		$user   = self::user( $job );
		$digest = self::field( $job, 'digest' );
		if ( JobType::DatabaseUndo === $job->type ) {
			$review = $this->undo_review( $this->original( self::field( $job, 'apply' ) ), $user, true );
			self::confirmed( $review['preview'], $digest );
			$job->data['authority'] = $review['authority'];
			$job->data['rebound']   = $review['rebound'];
			return true;
		}
		$connection = ( $this->connection )();
		$review     = $this->reviews->read( $connection, self::field( $job, 'restore' ), $user, $this->blockers( $connection, $user ), true );
		self::confirmed( $review['preview'], $digest );
		if ( ! isset( $job->data['database_owner'] ) ) {
			$job->data['database_owner'] = [
				'version'     => 1,
				'prefix'      => $this->prefix,
				'fingerprint' => $review['source']->manifest->fingerprint,
				'count'       => count( $review['source']->manifest->tables ),
				'token'       => bin2hex( $this->random->bytes( 16 ) ),
				'database'    => $review['target']['database'],
				'uuid'        => $review['target']['uuid'],
			];
			$job->data['authority']      = $review['authority'];
			$job->data['source_path']    = $review['path'];
			$job->data['content']        = $review['content'];
			$job->data['ids']            = $review['target']['ids'];
			$job->data['schemas']        = $review['target']['schemas'];
			$this->jobs->save( $job );
		}
		return true;
	}

	/**
	 * Opens only this job's pinned and originally signed inventory.
	 *
	 * @param Job $job Database apply.
	 * @return StagedDatabase Source.
	 * @throws JobConflict If staging or its binding was lost.
	 */
	private function source( Job $job ): StagedDatabase {
		$files = $this->staging->files_directory( self::field( $job, 'restore' ), $this->hours, true );
		if ( null === $files ) {
			JobConflict::raise( 'The staged database required for the apply is unavailable.', [ 'code' => 'database_review_required' ] );
		}
		$source = StagedDatabase::open( $files, self::field( $job, 'source_path' ), $this->prefix );
		$owner  = $job->data['database_owner'] ?? null;
		if ( ! is_array( $owner ) || $source->manifest->fingerprint !== ( $owner['fingerprint'] ?? null ) || $this->prefix !== ( $owner['prefix'] ?? null ) ) {
			JobConflict::raise( 'The database source changed after the approved review.', [ 'code' => 'database_exchange_changed' ] );
		}
		return $source;
	}

	/**
	 * Imports a bounded batch of rows into the durable operation-owned namespace.
	 *
	 * @param Job   $job Current apply.
	 * @param float $deadline End of tick.
	 * @return bool Whether the full import has committed.
	 */
	private function import( Job $job, float $deadline ): bool {
		$connection = ( $this->connection )();
		DatabaseGeneration::load( $connection, $job );
		$source                           = $this->source( $job );
		$owner                            = $job->data['database_owner'];
		$importer                         = new TableImporter( $connection, $source->manifest, $source->directory, is_array( $owner ) && is_string( $owner['token'] ?? null ) ? $owner['token'] : '' );
		$progress                         = $importer->tick( 16, max( 0.001, min( 20.0, $deadline - microtime( true ) ) ) );
		$job->progress['database_tables'] = $progress['tables'];
		$job->progress['database_total']  = $progress['total_tables'];
		return $progress['done'];
	}

	/**
	 * Matches the newly imported transcript to the rehearsal and persists native identities
	 * before making any shadow indistinguishable by its original table comment.
	 *
	 * @param Job   $job Current apply job.
	 * @param float $deadline End of this tick.
	 * @return bool Whether all comments are finalized.
	 * @throws FormatException If a saved binding is incomplete.
	 */
	private function prepare( Job $job, float $deadline ): bool {
		$connection = ( $this->connection )();
		$generation = DatabaseGeneration::load( $connection, $job );
		$plan       = $generation->plan;
		if ( null === $plan ) {
			$source                     = $this->source( $job );
			$ids                        = self::strings( $job->data['ids'] ?? null );
			$schemas                    = self::strings( $job->data['schemas'] ?? null );
			$plan                       = ExchangePlan::prepare( $connection, $source->manifest, $generation->owned, self::field( $job, 'content' ), $ids, $schemas, self::user( $job ) );
			$job->data['exchange_plan'] = $plan->document;
			$this->jobs->save( $job );
		}
		$cursor = $job->data['database_finalized'] ?? 0;
		if ( ! is_int( $cursor ) || $cursor < 0 || $cursor > $generation->count ) {
			throw new FormatException( 'The database preparation cursor is unreadable.' );
		}
		$worker = new TableExchange( $connection );
		$end    = min( $cursor + 8, $generation->count );
		while ( $cursor < $end ) {
			$worker->finalize( $plan, $cursor );
			$job->data['database_finalized'] = ++$cursor;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		return $cursor === $generation->count;
	}

	/**
	 * Checks the worker's current authorization, publishes maintenance, waits for requests
	 * that started before it and performs the one live RENAME. Retry a committed operation
	 * by refreshing caches and recording its witness. A lost SQL reply triggers the same
	 * reconciliation in cleanup.
	 *
	 * @param Job   $job Active apply or undo.
	 * @param float $deadline End of this tick.
	 * @return bool Whether the swap has finished.
	 * @throws JobConflict If the user or host no longer permits the operation.
	 */
	private function swap( Job $job, float $deadline ): bool {
		$undo       = JobType::DatabaseUndo === $job->type;
		$owner      = $undo ? $this->original( self::field( $job, 'apply' ) ) : $job;
		$connection = ( $this->connection )();
		$plan       = DatabaseGeneration::load( $connection, $owner )->exchange();
		$plan       = $undo ? self::confirmed_plan( $job, $plan ) : $plan;
		$exchange   = new TableExchange( $connection );
		$user       = self::user( $job );
		$state      = $undo ? 'undone' : 'applied';
		$directory  = $this->jobs->directory( $job );
		$committed  = $exchange->status( $plan ) === $state;
		if ( ! $committed && $deadline - microtime( true ) < $this->drain->budget() && true !== ( $job->data['database_swap_deferred'] ?? false ) ) {
			// Maintenance starts with a fresh tick, unless every tick is this short.
			$job->data['database_swap_deferred'] = true;
			return false;
		}
		unset( $job->data['database_swap_deferred'] );
		$this->maintenance->enter( $job->id, $directory, '' );
		$entered = microtime( true );
		try {
			if ( ! $committed ) {
				if ( $plan->document['prefix'] !== $this->prefix || [] !== ( $this->policy )( $user ) ) {
					JobConflict::raise( 'The destination or confirming administrator no longer permits this exchange.', [ 'code' => 'database_exchange_changed' ] );
				}
				$this->drain->wait( $connection, $plan->document['database'], $entered, $deadline );
				$job->data['database_swapping_at'] = $this->clock->now()[0];
				$this->jobs->save( $job );
				if ( $undo ) {
					// The heartbeat can then recognise a committed undo after a lost reply, and
					// every later step works with the identities that the undo moves.
					$owner->data['exchange_plan']         = $plan->document;
					$owner->data['database_undo_attempt'] = [
						'job' => $job->id,
						'at'  => $job->data['database_swapping_at'],
					];
					$this->jobs->save( $owner );
				}
				$exchange->run( $plan, $user, $undo, self::field( $job, 'authority' ) );
			}
			DatabaseGeneration::record( $owner, $job, $state );
			$this->jobs->save( $owner );
			( $this->refresh )();
		} finally {
			$this->maintenance->release( $job->id, $directory );
		}
		return true;
	}

	/**
	 * Reconciles an unknown RENAME outcome before cancellation or error cleanup. A committed
	 * exchange is recorded as completed, including its original 24-hour retention window.
	 * After a lost session the reconciliation waits for a later tick and a new session, and
	 * while the old session still holds its locks, for at most ten minutes. The heartbeat
	 * reconciles from the durable records after that.
	 *
	 * @param Job   $job Current worker.
	 * @param float $deadline End of tick.
	 * @return bool Whether cleanup is complete.
	 * @throws DatabaseFailed If the connected server cannot be identified.
	 * @throws JobConflict If a lock stays held beyond the bound.
	 */
	private function cleanup( Job $job, float $deadline ): bool {
		$this->maintenance->release( $job->id, $this->jobs->directory( $job ) );
		if ( $this->interrupted->offsetExists( $job->id ) ) {
			$this->interrupted->offsetUnset( $job->id );
			return false;
		}
		try {
			return $this->reconcile( $job, $deadline );
		} catch ( DatabaseFailed | JobConflict $error ) {
			$code = $error->context()['code'] ?? null;
			if ( ! in_array( $code, [ ...self::LOST, 1205, 1213, 'database_restore_busy' ], true ) ) {
				throw $error;
			}
			$now   = $this->clock->now()[0];
			$until = $job->data['database_reconcile_until'] ?? null;
			if ( ! is_int( $until ) ) {
				$until                                 = $now + 600;
				$job->data['database_reconcile_until'] = $until;
			}
			if ( $now >= $until ) {
				throw $error;
			}
			return false;
		}
	}

	/**
	 * Records a committed exchange of this job, or removes an unfinished import.
	 *
	 * @param Job   $job Current worker.
	 * @param float $deadline End of tick.
	 * @return bool Whether cleanup is complete.
	 * @throws DatabaseFailed If the connected server cannot be identified.
	 * @throws JobConflict If the owning apply is gone or another session holds its tables.
	 */
	private function reconcile( Job $job, float $deadline ): bool {
		$undo  = JobType::DatabaseUndo === $job->type;
		$owner = $undo ? $this->original( self::field( $job, 'apply' ) ) : $job;
		if ( ! isset( $owner->data['database_owner'] ) ) {
			return true;
		}
		$connection = ( $this->connection )();
		try {
			$generation = DatabaseGeneration::load( $connection, $owner );
		} catch ( DatabaseFailed $error ) {
			if ( 'database_exchange_foreign' !== ( $error->context()['code'] ?? null ) ) {
				throw $error;
			}
			// Nothing on this server belongs to the binding, so nothing is removed or recorded
			// here. A retained window stays as it is; an unfinished import awaits a decision.
			if ( ! isset( $owner->data['retained_until'] ) ) {
				$this->unverified( $owner );
			}
			return true;
		}
		if ( null !== $generation->plan && isset( $job->data['database_swapping_at'] ) && ! isset( $owner->data['database_cleanup_generation'] ) ) {
			$state = $undo ? 'undone' : 'applied';
			if ( ( new TableExchange( $connection ) )->status( $generation->plan ) === $state ) {
				if ( ( $job->result['database_generation'] ?? null ) !== $state || null !== $job->outcome ) {
					$this->swap( $job, $deadline );
					$job->outcome = JobState::Succeeded;
					$job->error   = null;
				}
				return true;
			}
		}
		return $undo || DatabaseGeneration::clean( $connection, $this->jobs, $owner, $deadline, $this->clock->now()[0], false );
	}

	/**
	 * Expires database generations under the directory-specific runner lock. Staging's
	 * short guard serializes undo creation and the durable start of expiry. A generation
	 * whose cleanup fails is reported on its job, and the older ones are still expired.
	 *
	 * @param bool  $uninstall Remove owned generations, including active ones.
	 * @param float $deadline End of request.
	 * @return bool Whether all cleanup due in this directory has completed.
	 * @throws PluginException The first cleanup failure, after every other due generation had its turn.
	 */
	public function expire( bool $uninstall, float $deadline ): bool {
		return $this->staging->guard(
			function () use ( $uninstall, $deadline ): bool {
				$active  = $this->jobs->active();
				$failure = null;
				foreach ( $this->jobs->database_owners() as $job ) {
					if ( ! $uninstall && ( ! $job->state->is_terminal() || ( JobType::DatabaseUndo === $active?->type && ( $active->data['apply'] ?? null ) === $job->id ) ) ) {
						continue;
					}
					$now = $this->clock->now()[0];
					if ( ! $uninstall && is_int( $job->data['retained_until'] ?? null ) && $job->data['retained_until'] > $now && ! isset( $job->data['database_cleanup_generation'] ) ) {
						continue;
					}
					try {
						do {
							$done = DatabaseGeneration::clean( ( $this->connection )(), $this->jobs, $job, $deadline, $now, $uninstall );
							unset( $job->data['database_foreign'] );
							if ( $done ) {
								unset( $job->result['database_cleanup_pending'] );
							}
							$this->jobs->save( $job );
						} while ( ! $done && microtime( true ) < $deadline );
					} catch ( DatabaseFailed $error ) {
						if ( 'database_exchange_foreign' !== ( $error->context()['code'] ?? null ) ) {
							$this->deferred( $job );
							$failure ??= $error;
						} elseif ( $uninstall ) {
							// The tables are not on this server: uninstall must neither drop nor wait for them.
							DatabaseGeneration::forget( $job );
							$this->jobs->save( $job );
						} else {
							$this->unverified( $job );
						}
					} catch ( PluginException $error ) {
						$this->deferred( $job );
						$failure ??= $error;
					}
					if ( microtime( true ) >= $deadline ) {
						return null === $failure ? false : throw $failure;
					}
				}
				return null === $failure ? true : throw $failure;
			}
		);
	}

	/**
	 * Reports once on the owning job that its cleanup failed and the heartbeat retries.
	 * One stuck generation must not keep older ones from expiring.
	 *
	 * @param Job $job Owning apply.
	 */
	private function deferred( Job $job ): void {
		if ( true !== ( $job->result['database_cleanup_pending'] ?? false ) ) {
			$job->result['database_cleanup_pending'] = true;
			$job->warn( 'Database generation cleanup is overdue. Restore the original database connection and permissions so that the heartbeat can finish.' );
			$this->jobs->save( $job );
			$this->jobs->log( $job )->write( 'warning', 'Database generation cleanup was deferred.', [ 'code' => 'database_exchange_cleanup' ] );
		}
	}

	/**
	 * Records once that the connected server positively is not the one holding the owning
	 * job's private tables. Other jobs go on; only a new database apply waits for the
	 * administrator to remove those tables there and discard the binding.
	 *
	 * @param Job $owner Owning apply.
	 */
	private function unverified( Job $owner ): void {
		if ( isset( $owner->data['database_foreign'] ) ) {
			return;
		}
		$owner->data['database_foreign'] = $this->clock->now()[0];
		$owner->warn( sprintf( 'The private tables of this database restore are on another database server or in another database. Drop them there by hand, then discard the binding with: wp backups-for-restic database-discard %s', $owner->id ) );
		$this->jobs->save( $owner );
		$this->jobs->log( $owner )->write( 'warning', 'The database restore binding belongs to another server or database.', [ 'code' => 'database_exchange_foreign' ] );
	}

	/**
	 * Checks whether the owning apply exposes an undo window at this time.
	 *
	 * @param Job $job Candidate apply.
	 * @param int $now Eligibility time.
	 * @return bool Whether its original generation is still available for review.
	 */
	private function eligible( Job $job, int $now ): bool {
		return $job->state->is_terminal() && 'applied' === ( $job->data['database_generation'] ?? null ) && is_int( $job->data['retained_until'] ?? null ) && $job->data['retained_until'] > $now && ! isset( $job->data['database_cleanup_generation'] );
	}

	/**
	 * Loads the owning apply by its validated job identifier.
	 *
	 * @param string $id Owner ID.
	 * @return Job Original apply.
	 * @throws JobConflict If the owner is unavailable.
	 */
	private function original( string $id ): Job {
		$owner = $this->jobs->get( $id );
		if ( null === $owner || JobType::DatabaseApply !== $owner->type ) {
			JobConflict::raise( 'The database apply that owns the recovery window is unavailable.', [ 'code' => 'database_exchange_changed' ] );
		}
		return $owner;
	}

	/**
	 * Requires approval of this exact applicable plan.
	 *
	 * @param Preview $preview Current preview.
	 * @param string  $digest User's confirmed SHA-256.
	 * @throws JobConflict If staging, policy, live schema or the account changed.
	 */
	private static function confirmed( array $preview, string $digest ): void {
		if ( ! $preview['applicable'] || ! hash_equals( $preview['digest'], $digest ) ) {
			JobConflict::raise( 'The reviewed database plan changed or has unresolved compatibility blockers.', [ 'code' => 'database_exchange_changed' ] );
		}
	}

	/**
	 * Reads a required private job field.
	 *
	 * @param Job    $job Database worker.
	 * @param string $name Required key.
	 * @return string Stored string.
	 * @throws FormatException If required state was lost.
	 */
	private static function field( Job $job, string $name ): string {
		$value = $job->data[ $name ] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			throw new FormatException( 'The database restore job lost a required field.' );
		}
		return $value;
	}

	/**
	 * Reads the original confirming WordPress user identifier.
	 *
	 * @param Job $job Worker job.
	 * @return int Authenticated administrator that confirmed this job.
	 * @throws FormatException If the administrator binding is absent.
	 */
	private static function user( Job $job ): int {
		$user = $job->data['user'] ?? null;
		if ( ! is_int( $user ) || $user < 1 ) {
			throw new FormatException( 'The database restore requires a specific confirming administrator.' );
		}
		return $user;
	}

	/**
	 * Validates the stored schema or physical ID mapping.
	 *
	 * @param mixed $data Decoded site names mapped to native IDs or schema digests.
	 * @return array<string,string> Bindings.
	 * @throws FormatException If the stored target mapping is malformed.
	 */
	private static function strings( mixed $data ): array {
		if ( ! is_array( $data ) ) {
			throw new FormatException( 'The saved database review has no live identity mapping.' );
		}
		$out = [];
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				throw new FormatException( 'The saved database review contains an invalid live identity.' );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
