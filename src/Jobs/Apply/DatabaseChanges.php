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

	/**
	 * Checked source and live review builder.
	 *
	 * @var DatabaseReview
	 */
	private DatabaseReview $reviews;

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
		$this->reviews = new DatabaseReview( $jobs, $staging, $hours, $prefix );
	}

	/**
	 * Previews an unexpired, completed database rehearsal for one administrator.
	 *
	 * @param string $restore Restore job ID.
	 * @param int    $user Confirming user.
	 * @return Preview Review.
	 */
	public function preview( string $restore, int $user ): array {
		return $this->reviews->read( ( $this->connection )(), $restore, $user, ( $this->policy )( $user ), false )['preview'];
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
	 * Revalidates the current objects and opening of the undo window.
	 *
	 * @param Job  $owner Owning apply.
	 * @param int  $user Initiating administrator.
	 * @param bool $pinned A queued undo pins the original window.
	 * @return array{preview:Preview,authority:string} Undo review and private binding.
	 * @throws JobConflict If the recovery window expired.
	 */
	private function undo_review( Job $owner, int $user, bool $pinned ): array {
		if ( ! $this->eligible( $owner, $pinned ? 0 : $this->clock->now()[0] ) ) {
			JobConflict::raise( 'This database undo window is no longer available.', [ 'code' => 'database_exchange_changed' ] );
		}
		$connection = ( $this->connection )();
		$plan       = DatabaseGeneration::load( $connection, $owner )->exchange();
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
				( new TableExchange( $connection ) )->review_undo( $plan, $user );
			} catch ( DatabaseFailed | FormatException $error ) {
				$code       = $error->context()['code'] ?? null;
				$blockers[] = is_string( $code ) ? $code : 'database_exchange_changed';
			}
		}
		return [
			'preview'   => DatabaseReview::summary( self::field( $owner, 'restore' ), $user, $entries, $blockers, [], [ 'undo', $owner->id, $owner->data['retained_until'], $plan->document, $authority ] ),
			'authority' => $authority,
		];
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
			return true;
		}
		$review = $this->reviews->read( ( $this->connection )(), self::field( $job, 'restore' ), $user, ( $this->policy )( $user ), true );
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
	 *
	 * @param Job   $job Current worker.
	 * @param float $deadline End of tick.
	 * @return bool Whether cleanup is complete.
	 */
	private function cleanup( Job $job, float $deadline ): bool {
		$this->maintenance->release( $job->id, $this->jobs->directory( $job ) );
		$undo  = JobType::DatabaseUndo === $job->type;
		$owner = $undo ? $this->original( self::field( $job, 'apply' ) ) : $job;
		if ( ! isset( $owner->data['database_owner'] ) ) {
			return true;
		}
		$connection = ( $this->connection )();
		$generation = DatabaseGeneration::load( $connection, $owner );
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
							if ( $done ) {
								unset( $job->result['database_cleanup_pending'] );
							}
							$this->jobs->save( $job );
						} while ( ! $done && microtime( true ) < $deadline );
					} catch ( PluginException $error ) {
						// One stuck generation must not keep older ones from expiring.
						if ( true !== ( $job->result['database_cleanup_pending'] ?? false ) ) {
							$job->result['database_cleanup_pending'] = true;
							$job->warn( 'Database generation cleanup is overdue. Restore the original database connection and permissions so that the heartbeat can finish.' );
							$this->jobs->save( $job );
							$this->jobs->log( $job )->write( 'warning', 'Database generation cleanup was deferred.', [ 'code' => 'database_exchange_cleanup' ] );
						}
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
