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
use BackupsForRestic\Database\Restore\Continuity;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\SessionDrain;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
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
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Each transition from a harmless import into a live operation binds durable filesystem
 * state first. The MySQL witness records the outcome even if the request is then killed.
 *
 * @phpstan-import-type Preview from DatabaseReview
 * @phpstan-import-type Facts from SwapCatalog
 * @phpstan-type Binding array{job:string,database:string,server:string,tables:list<string>}
 * @phpstan-type Forgotten array{job:string,database:string,server:string,tables:list<string>,until:int}
 * @phpstan-type Discarded array{job:string,restore:string,at:int,via:string,database:string,server:string,tables:list<string>,until:int}
 * @phpstan-type Foreign array{job:string,restore:string,generation:'applied'|'undone'|null,retained_until:int|null}
 * @phpstan-type DiscardReview array{job:string,restore:string,database:string,server:string,connected:array{database:string,server:string},tables:list<string>,generation:'applied'|'undone'|null,retained_until:int|null,undo_until:int|null,kept:list<string>,digest:string,applicable:true}
 */
final readonly class DatabaseChanges implements StepProvider {

	/**
	 * What a discard of a binding leaves as it is: the database that the site uses now, whose
	 * tables it does not touch; the private tables wherever they are, of which it drops none; and
	 * the job of the database restore with its log, which record the discard.
	 */
	public const array DISCARD_KEPT = [ 'live_database', 'private_tables', 'job_record' ];

	/** Codes of a refused review or discard of a binding, or of the end of its listing, which say what is the case. */
	public const array DISCARD_REFUSALS = [ 'database_binding_missing', 'database_binding_discarded', 'database_binding_verified', 'database_binding_unverified', 'database_binding_busy', 'database_discard_changed', 'database_discarded_missing', 'job_unreadable', 'job_inaccessible' ];

	/**
	 * Codes of a review or discard of a binding that failed, which say what failed: any other
	 * failure is unexpected, and nothing records it.
	 */
	public const array DISCARD_FAILURES = [ 'database_binding_unreadable', 'database_discard_failed', 'database_discard_storage' ];

	/** Version of what the review of a discard shows, which its digest covers (DatabaseReview::digest()). */
	private const int DISCARD_VERSION = 1;

	/**
	 * Errors after which a session is gone and its last statement's outcome unknown: the
	 * client's lost connection, and the server closing it for a packet that is too large.
	 */
	private const array LOST = [ 1153, 2006, 2013, 2055 ];

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
	 * @param \Closure():void            $refresh Clear WordPress caches and roles and reschedule heartbeat.
	 * @param ExchangeSite               $site This plugin's path, the capabilities a restore needs and the plugin file check.
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
		private ExchangeSite $site,
		private SessionDrain $drain = new SessionDrain()
	) {
		/**
		 * No session was lost yet in this request.
		 *
		 * @var \ArrayObject<string,true> $interrupted
		 */
		$interrupted       = new \ArrayObject();
		$this->reviews     = new DatabaseReview( $jobs, $staging, $hours, $prefix, $site );
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
	 * Site blockers of a new database apply, from what the connected server says now about each
	 * finished binding, whatever an earlier request recorded of it (`database_foreign`), so that
	 * the block agrees with the list of bindings to discard and with the review of a discard:
	 * one of another server or database waits for the administrator's decision
	 * (`database_exchange_foreign`); one that a server without a readable UUID cannot place
	 * waits too (`database_binding_unverified`); an interrupted import on this server waits for
	 * the heartbeat to remove its tables (`database_exchange_cleanup`); and one whose record
	 * cannot be read, which no decision of the plugin can settle, waits for that record
	 * (`database_binding_unreadable`). One identity serves every binding, asked only where
	 * there is one; a server that cannot be asked ends the review, as the review itself would.
	 *
	 * @param Connection $connection Current SQL session.
	 * @param int        $user Confirming user.
	 * @return list<string> Blocker codes.
	 * @throws DatabaseFailed If the connected server cannot be identified.
	 */
	private function blockers( Connection $connection, int $user ): array {
		$blockers = ( $this->policy )( $user );
		$owners   = [];
		foreach ( $this->jobs->database_owners() as $owner ) {
			if ( $owner->state->is_terminal() ) {
				$owners[] = $owner;
			}
		}
		if ( [] === $owners ) {
			return array_values( array_unique( $blockers ) );
		}
		$facts = ( new SwapCatalog( $connection ) )->facts();
		foreach ( $owners as $owner ) {
			if ( ! DatabaseGeneration::readable( $owner ) ) {
				$blockers[] = 'database_binding_unreadable';
				continue;
			}
			$place = DatabaseGeneration::place( $facts, $owner );
			if ( 'foreign' === $place ) {
				$blockers[] = 'database_exchange_foreign';
			} elseif ( 'unknown' === $place ) {
				// The record names a server; this one does not say whether it is that one.
				$blockers[] = 'database_binding_unverified';
			} elseif ( ! isset( $owner->data['retained_until'] ) ) {
				$blockers[] = 'database_exchange_cleanup';
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
	 * The finished database applies whose binding the connected server positively disowns: what
	 * the Restores tab offers to discard. Each binding is asked about as its review asks
	 * (discard_review()). One whose server cannot be asked or identified now is listed if its job
	 * recorded that its tables were found on another server or in another database
	 * (`database_foreign`), and the list says that it may be incomplete. A binding whose record
	 * cannot be read is not listed: its review names that, and the heartbeat reports it on its
	 * job. Nothing is asked of the database beyond its identity, once for all of them.
	 *
	 * @return array{jobs:list<Foreign>,complete:bool} The bindings, and whether every one could be asked about.
	 */
	public function foreign_bindings(): array {
		$owners = [];
		foreach ( $this->jobs->database_owners() as $owner ) {
			if ( $owner->state->is_terminal() ) {
				$owners[] = $owner;
			}
		}
		$listed   = [];
		$complete = true;
		if ( [] === $owners ) {
			return [
				'jobs'     => $listed,
				'complete' => $complete,
			];
		}
		try {
			// One identity of the server for every binding: the list is read on each load of the page.
			$facts = ( new SwapCatalog( ( $this->connection )() ) )->facts();
		} catch ( DatabaseFailed ) {
			$facts = null;
		}
		foreach ( $owners as $owner ) {
			if ( ! DatabaseGeneration::readable( $owner ) ) {
				// Its review refuses it as a record that cannot be read, whatever the server says.
				continue;
			}
			$foreign = null;
			if ( null !== $facts ) {
				$place   = DatabaseGeneration::place( $facts, $owner );
				$foreign = 'unknown' === $place ? null : 'foreign' === $place;
			}
			if ( null === $foreign ) {
				$complete = false;
				$foreign  = isset( $owner->data['database_foreign'] );
			}
			if ( $foreign ) {
				$listed[] = [
					'job'            => $owner->id,
					'restore'        => self::text( $owner, 'restore' ),
					'generation'     => self::generation( $owner ),
					'retained_until' => is_int( $owner->data['retained_until'] ?? null ) ? $owner->data['retained_until'] : null,
				];
			}
		}
		return [
			'jobs'     => $listed,
			'complete' => $complete,
		];
	}

	/**
	 * Reviews the discard of a binding whose private tables the connected server positively
	 * identifies as another server's or another database's (ADR 0043): the job, the database
	 * and the server UUID that the binding names, the database and server that the site uses
	 * now, the private table names to remove by hand where they are, whether the undo of that
	 * exchange ends with it, and what stays. Its digest covers all of it and the confirming user,
	 * built as the digest of an exchange's review (DatabaseReview::digest()); the discard names
	 * it (discard()). Nothing is asked of the database beyond its identity, and nothing is
	 * changed. The discard has no job of its own: a database failure is written to the log of
	 * the job that holds the binding, which the refusal points to.
	 *
	 * @param string $apply Owning database apply.
	 * @param int    $user Confirming WordPress user, 0 for WP-CLI without a selected user.
	 * @return DiscardReview Review.
	 * @throws JobConflict With the code database_binding_missing if no database apply with this ID holds a binding, job_unreadable or job_inaccessible (JobStore::refuse_unreadable()) if the job's document or that of any other stored job cannot be read, database_binding_discarded if its binding was discarded already, database_binding_busy while the job or an undo of it is queued or running, database_binding_verified if the connected database holds its tables, database_binding_unverified if the connected server does not say which server it is, and database_binding_unreadable if the record of the binding cannot be read.
	 * @throws DatabaseFailed With the code database_discard_failed if the database server could not be asked.
	 * @throws StorageFailed With the code database_discard_storage if the jobs in the private directory could not be read.
	 */
	public function discard_review( string $apply, int $user ): array {
		try {
			return $this->discard_facts( $apply, $user )[1];
		} catch ( StorageFailed $error ) {
			self::storage_failed( $error );
		}
	}

	/**
	 * Forgets a binding whose private tables are on another server or in another database,
	 * after the administrator confirmed the review of its discard by that review's digest.
	 * Under staging's guard, which keeps an undo of this binding from being queued at the same
	 * time, the review is made again; a binding that changed since, or a digest of another
	 * review, is refused. Nothing is dropped anywhere: the job records the decision, who
	 * confirmed it, the digest and the table names to remove by hand where they are. The caller
	 * holds the runner lock.
	 *
	 * @param string $apply Owning database apply.
	 * @param int    $user Confirming WordPress user, 0 from WP-CLI without a user.
	 * @param string $via Where it was confirmed: rest or wp-cli.
	 * @param string $digest Digest of the confirmed review (discard_review()).
	 * @return Forgotten The discarded binding, and until when the Restores tab and WP-CLI list its tables (JobStore::discard_listed_until()).
	 * @throws JobConflict With the codes of discard_review(), and database_discard_changed if the binding is no longer as the confirmed review showed it.
	 * @throws DatabaseFailed With the code database_discard_failed if the database server could not be asked.
	 * @throws StorageFailed With the code database_discard_storage if the jobs could not be read or the decision could not be recorded; the binding stays.
	 */
	public function discard( string $apply, int $user, string $via, string $digest ): array {
		try {
			return $this->staging->guard(
				function () use ( $apply, $user, $via, $digest ): array {
					[ $owner, $review ] = $this->discard_facts( $apply, $user );
					if ( ! hash_equals( $review['digest'], $digest ) ) {
						JobConflict::raise( 'The binding is no longer as the confirmed review showed it.', [ 'code' => 'database_discard_changed' ] );
					}
					$binding = [
						'job'      => $review['job'],
						'database' => $review['database'],
						'server'   => $review['server'],
						'tables'   => $review['tables'],
					];
					DatabaseGeneration::forget( $owner );
					$at = $this->clock->now()[0];
					// The confirmed digest goes with the decision: the log is written at best effort.
					$owner->data['database_discarded'] = [
						'at'     => $at,
						'user'   => $user,
						'via'    => $via,
						'digest' => $digest,
					] + $binding;
					// The names are what is left to do by hand: the limit of a job's warnings must not drop them.
					$owner->warn_kept( sprintf( 'The binding to %1$d private table names in the database %2$s on the server %3$s was discarded. Nothing was dropped. Remove by hand only those that exist there and that you can verify as this restore\'s copies: a table with one of these names may be another one, such as a copy of the site\'s data on a new server: %4$s', count( $binding['tables'] ), self::printable( $binding['database'] ), self::printable( $binding['server'] ), implode( ', ', $binding['tables'] ) ) );
					$this->jobs->save( $owner );
					$this->jobs->log( $owner )->write(
						'warning',
						'Database restore binding discarded.',
						[
							'user'   => $user,
							'via'    => $via,
							'digest' => $digest,
							'code'   => 'database_exchange_foreign',
						]
					);
					return $binding + [ 'until' => $at + JobStore::DISCARD_LISTED_DAYS * 86400 ];
				}
			);
		} catch ( StorageFailed $error ) {
			// The jobs, staging's lock or the job document: the document still holds the binding.
			self::storage_failed( $error );
		}
	}

	/**
	 * Refuses a review, a discard, the list of discarded bindings or the end of a listing for
	 * which the private directory could not be read or written: the jobs could not be listed or
	 * read, staging's lock could not be taken, or a decision could not be recorded. The text
	 * says what failed and where to see what is recorded, as it fits each of them.
	 *
	 * @param StorageFailed $error What failed.
	 * @throws StorageFailed Always, with the code database_discard_storage.
	 */
	private static function storage_failed( StorageFailed $error ): never {
		StorageFailed::raise( 'The jobs in the private directory could not be read or written.', [ 'code' => 'database_discard_storage' ], $error );
	}

	/**
	 * The discarded bindings whose private tables are still listed for removal by hand, newest
	 * first: for JobStore::DISCARD_LISTED_DAYS after each discard, unless the administrator
	 * marked them as taken care of (discarded_done()). The job history keeps their jobs until
	 * then, whatever else it prunes; the Restores tab and WP-CLI read them here.
	 *
	 * @return list<Discarded> The discarded bindings with their table names.
	 * @throws StorageFailed With the code database_discard_storage if the jobs could not be read.
	 */
	public function discarded_bindings(): array {
		$now    = $this->clock->now()[0];
		$listed = [];
		try {
			foreach ( $this->jobs->file_changes() as $job ) {
				$entry = self::listed( $job, $now );
				if ( null !== $entry ) {
					$listed[] = $entry;
				}
			}
		} catch ( StorageFailed $error ) {
			self::storage_failed( $error );
		}
		return $listed;
	}

	/**
	 * Ends the listing of the private tables of a discarded binding: the administrator says
	 * that they are taken care of. The job records who said so and when; after that the job
	 * history prunes the job as any other, with its record of the discard and its log. The
	 * caller holds the runner lock, so that the pruning, which runs under it, cannot remove the
	 * job between the check and the save, which would leave a job folder with the document alone.
	 *
	 * @param string $apply Database apply whose binding was discarded.
	 * @param int    $user Confirming WordPress user, 0 from WP-CLI without a user.
	 * @param string $via Where it was confirmed: rest or wp-cli.
	 * @return Discarded The entry that is no longer listed.
	 * @throws JobConflict With the code database_discarded_missing if the job lists no such tables.
	 * @throws StorageFailed With the code database_discard_storage if the jobs could not be read or the decision could not be recorded.
	 */
	public function discarded_done( string $apply, int $user, string $via ): array {
		try {
			return $this->staging->guard(
				function () use ( $apply, $user, $via ): array {
					$job   = $this->jobs->get( $apply );
					$entry = null === $job ? null : self::listed( $job, $this->clock->now()[0] );
					if ( null === $job || null === $entry ) {
						JobConflict::raise( 'This job lists no private tables of a discarded binding.', [ 'code' => 'database_discarded_missing' ] );
					}
					$record                          = $job->data['database_discarded'];
					$job->data['database_discarded'] = [
						'done' => [
							'at'   => $this->clock->now()[0],
							'user' => $user,
							'via'  => $via,
						],
					] + ( is_array( $record ) ? $record : [] );
					$this->jobs->save( $job );
					$this->jobs->log( $job )->write(
						'info',
						'The private tables of the discarded binding were marked as taken care of.',
						[
							'user' => $user,
							'via'  => $via,
						]
					);
					return $entry;
				}
			);
		} catch ( StorageFailed $error ) {
			self::storage_failed( $error );
		}
	}

	/**
	 * The entry of a database apply in the list of discarded bindings: while it lists the tables
	 * of its discarded binding (JobStore::discard_listed_until()), and if its record names the
	 * database, the server and at least one table; an entry of the list that is no table name is
	 * left out.
	 *
	 * @param Job $job Database apply.
	 * @param int $now Unix time.
	 * @return Discarded|null The entry, or null if the job lists no such tables now.
	 */
	private static function listed( Job $job, int $now ): ?array {
		$until  = JobStore::discard_listed_until( $job );
		$record = $job->data['database_discarded'] ?? null;
		if ( null === $until || $until <= $now || ! is_array( $record ) || ! is_string( $record['database'] ?? null ) || ! is_string( $record['server'] ?? null ) || ! is_array( $record['tables'] ?? null ) ) {
			return null;
		}
		// A record that names no table leaves nothing to remove by hand and nothing to mark.
		$tables = array_values( array_filter( $record['tables'], static fn ( mixed $name ): bool => is_string( $name ) && '' !== $name ) );
		if ( [] === $tables ) {
			return null;
		}
		return [
			'job'      => $job->id,
			'restore'  => self::text( $job, 'restore' ),
			'at'       => $until - JobStore::DISCARD_LISTED_DAYS * 86400,
			'via'      => is_string( $record['via'] ?? null ) ? $record['via'] : '',
			'database' => $record['database'],
			'server'   => $record['server'],
			'tables'   => $tables,
			'until'    => $until,
		];
	}

	/**
	 * The finished database apply that holds a binding to another server or database, and the
	 * review of its discard (discard_review()).
	 *
	 * @param string $apply Owning database apply.
	 * @param int    $user Confirming WordPress user.
	 * @return array{Job,DiscardReview} The job and the review.
	 * @throws JobConflict With the codes of discard_review().
	 * @throws DatabaseFailed With the code database_discard_failed if the database server could not be asked.
	 * @throws StorageFailed If the jobs in the private directory cannot be listed.
	 */
	private function discard_facts( string $apply, int $user ): array {
		$owner = $this->jobs->get( $apply );
		if ( null === $owner && $this->jobs->has_document( $apply ) ) {
			// With its code, job_unreadable or job_inaccessible, and the job's ID.
			$this->jobs->refuse_unreadable( $apply );
		}
		if ( null === $owner || JobType::DatabaseApply !== $owner->type ) {
			JobConflict::raise( 'This job holds no database restore binding.', [ 'code' => 'database_binding_missing' ] );
		}
		if ( ! isset( $owner->data['database_owner'] ) ) {
			if ( isset( $owner->data['database_discarded'] ) ) {
				JobConflict::raise( 'The binding of this database restore was discarded already.', [ 'code' => 'database_binding_discarded' ] );
			}
			JobConflict::raise( 'This job holds no database restore binding.', [ 'code' => 'database_binding_missing' ] );
		}
		// A stored job whose state cannot be established may be a queued undo of this binding,
		// which the discard would take away: nothing is reviewed or discarded while there is one.
		$active = $this->jobs->active_or_refuse();
		if ( ! $owner->state->is_terminal() || ( JobType::DatabaseUndo === $active?->type && $apply === ( $active->data['apply'] ?? null ) ) ) {
			JobConflict::raise( 'The database restore that holds this binding, or an undo of it, is still queued or running.', [ 'code' => 'database_binding_busy' ] );
		}
		try {
			$connection = ( $this->connection )();
			$facts      = ( new SwapCatalog( $connection ) )->facts();
		} catch ( DatabaseFailed $error ) {
			$this->discard_failed( $owner, $error );
		}
		try {
			DatabaseGeneration::load( $connection, $owner );
		} catch ( DatabaseFailed $error ) {
			$code = $error->context()['code'] ?? null;
			if ( 'database_exchange_foreign' === $code ) {
				return [ $owner, $this->discard_summary( $owner, $user, $connection, $facts ) ];
			}
			if ( 'database_binding_unreadable' === $code ) {
				// The saved identity is gone: its tables may be on this server, so nothing is discarded.
				JobConflict::raise( 'The record of the private tables of this database restore cannot be read.', [ 'code' => 'database_binding_unreadable' ] );
			}
			if ( null !== $code ) {
				$this->discard_failed( $owner, $error );
			}
			if ( ! $facts['mariadb'] && 1 !== preg_match( DatabaseGeneration::UUID, $facts['uuid'] ) ) {
				// Without a server UUID the connected server cannot be told apart from the original one.
				JobConflict::raise( 'The connected database server does not report a server UUID.', [ 'code' => 'database_binding_unverified' ] );
			}
			// The recorded server answers, and the saved exchange plan contradicts the binding.
			JobConflict::raise( 'The record of the private tables of this database restore cannot be read.', [ 'code' => 'database_binding_unreadable' ] );
		} catch ( FormatException ) {
			JobConflict::raise( 'The record of the private tables of this database restore cannot be read.', [ 'code' => 'database_binding_unreadable' ] );
		}
		JobConflict::raise( 'This binding belongs to the connected database. The heartbeat removes its private tables when they expire.', [ 'code' => 'database_binding_verified' ] );
	}

	/**
	 * The review of a discard, with its digest.
	 *
	 * @param Job        $owner Owning database apply, whose binding the connected server disowns.
	 * @param int        $user Confirming WordPress user.
	 * @param Connection $connection Current SQL session; only names are derived from it.
	 * @param Facts      $facts Identity of the connected server.
	 * @return DiscardReview Review.
	 * @throws JobConflict With the code database_binding_unreadable if the binding's record cannot be read.
	 */
	private function discard_summary( Job $owner, int $user, Connection $connection, array $facts ): array {
		try {
			$binding = DatabaseGeneration::names( $connection, $owner );
		} catch ( FormatException ) {
			JobConflict::raise( 'The record of the private tables of this database restore cannot be read.', [ 'code' => 'database_binding_unreadable' ] );
		}
		$retained = is_int( $owner->data['retained_until'] ?? null ) ? $owner->data['retained_until'] : null;
		$review   = [
			'job'            => $owner->id,
			'restore'        => self::text( $owner, 'restore' ),
			'database'       => $binding['database'],
			'server'         => $binding['server'],
			'connected'      => [
				'database' => $facts['database'],
				'server'   => $facts['uuid'],
			],
			'tables'         => $binding['tables'],
			'generation'     => self::generation( $owner ),
			'retained_until' => $retained,
			// The undo that a discard ends for good: one that the site could still review once it
			// uses the database that holds these tables again.
			'undo_until'     => $this->eligible( $owner, $this->clock->now()[0] ) ? $retained : null,
			'kept'           => self::DISCARD_KEPT,
		];
		return $review + [
			'digest'     => DatabaseReview::digest( self::DISCARD_VERSION, [ 'discard', $user, $review ] ),
			'applicable' => true,
		];
	}

	/**
	 * Refuses a review or a discard whose database server could not be asked, after writing what
	 * the database raised to the log of the job that holds the binding: the discard has no job of
	 * its own, and the administrator reads the error there.
	 *
	 * @param Job             $owner Owning database apply.
	 * @param PluginException $error What the database raised.
	 * @throws DatabaseFailed Always, with the code database_discard_failed.
	 */
	private function discard_failed( Job $owner, PluginException $error ): never {
		$this->jobs->log( $owner )->write(
			'error',
			'The database server could not be asked whether it holds the private tables of this database restore, so their binding was not discarded.',
			[
				'code'  => 'database_discard_failed',
				'cause' => $error->getMessage(),
				'error' => $error->context()['code'] ?? null,
			]
		);
		DatabaseFailed::raise( 'The database server could not be asked whether it holds the private tables of this database restore.', [ 'code' => 'database_discard_failed' ], $error );
	}

	/**
	 * Which generation a database apply recorded as live: applied or undone, or none.
	 *
	 * @param Job $owner Database apply.
	 * @return 'applied'|'undone'|null Generation.
	 */
	private static function generation( Job $owner ): ?string {
		$generation = $owner->data['database_generation'] ?? null;
		return 'applied' === $generation || 'undone' === $generation ? $generation : null;
	}

	/**
	 * An optional text field of a job, empty if it has none.
	 *
	 * @param Job    $job Job.
	 * @param string $name Field.
	 * @return string Its value.
	 */
	private static function text( Job $job, string $name ): string {
		$value = $job->data[ $name ] ?? null;
		return is_string( $value ) ? $value : '';
	}

	/**
	 * A name that another database or server chose, as a log line or a warning shows it: as it is
	 * when every character prints, otherwise in double quotes with Go's escapes.
	 *
	 * @param string $name Name.
	 * @return string What to show.
	 */
	private static function printable( string $name ): string {
		$quoted = GoQuote::quote( $name );
		return $quoted === $name ? $name : '"' . $quoted . '"';
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
	 * @return array{preview:Preview,authority:string,rebound:array{after:array<int,string>,witness:string|null},capabilities:list<string>} Undo review, private binding and the capabilities checked.
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
		$blockers  = ( $this->policy )( $user );
		$authority = '';
		$state     = DatabaseReview::blank( $this->site->plugin );
		$required  = [];
		if ( $this->prefix !== $plan->document['prefix'] ) {
			$blockers[] = 'wordpress_environment';
		} else {
			// As in the apply review: the comparison first, then the authority if nothing blocks,
			// so that both reviews answer the same for the same account.
			try {
				[ $plan, $retained ] = ( new TableExchange( $connection ) )->review_undo( $recorded, $user );
				// The undo brings back the retained generation's own roles and active plugins.
				$facts    = $this->reviews->state( $connection, $user, $retained['roles'], $retained['plugins'] );
				$state    = $facts['state'];
				$required = $facts['capabilities'];
				$blockers = [ ...$blockers, ...$facts['blockers'] ];
				if ( [] === $blockers ) {
					$authority = ( new WordPressState( $connection ) )->authority( $this->prefix, $user );
				}
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
			'preview'      => DatabaseReview::summary( self::field( $owner, 'restore' ), $user, $entries, $blockers, [], [ 'undo', $owner->id, $owner->data['retained_until'], $plan->document, $authority, $required ], $state ),
			'authority'    => $authority,
			'rebound'      => [
				'after'   => $after,
				'witness' => $plan->document['witness'] !== $recorded->document['witness'] ? $plan->document['witness'] : null,
			],
			'capabilities' => $required,
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
					if ( 1153 === $code ) {
						DatabaseFailed::raise( 'A statement of the snapshot is larger than the database server\'s max_allowed_packet.', [ 'code' => 'database_restore_packet' ] );
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
			$job->data['authority']    = $review['authority'];
			$job->data['rebound']      = $review['rebound'];
			$job->data['capabilities'] = $review['capabilities'];
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
			$job->data['capabilities']   = $review['capabilities'];
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
	 * Adds this plugin to the generation that the exchange brings live, before maintenance,
	 * checks the worker's current authorization, publishes maintenance, waits for requests
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
		if ( ! $committed ) {
			$plugin = $this->continuity( $job )->plugin;
			if ( null !== $plugin ) {
				// On the private target only; the live tables are neither locked nor written.
				$exchange->activate( $plan, $undo, $plugin );
			}
		}
		$this->maintenance->enter( $job->id, $directory, '' );
		$entered = microtime( true );
		try {
			if ( ! $committed ) {
				if ( $plan->document['prefix'] !== $this->prefix || [] !== ( $this->policy )( $user ) ) {
					JobConflict::raise( 'The destination or confirming administrator no longer permits this exchange.', [ 'code' => 'database_exchange_changed' ] );
				}
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
				// Only a few statements separate the quiet interval from the table locks.
				$this->drain->wait( $connection, $plan->document['database'], $entered, $deadline );
				$exchange->run( $plan, $user, $undo, $this->continuity( $job ), self::field( $job, 'authority' ) );
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
		$owner->warn( sprintf( 'The private tables of this database restore are on another database server or in another database. Review the discard of its binding on the Restores tab, or run "wp backups-for-restic database-discard %s": both show the database, the server UUID and the table names, and confirming forgets the binding and drops nothing. Drop the tables by hand only where you can verify them.', $owner->id ) );
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
	 * The capabilities that the review checked for the approving administrator, from the job,
	 * with this plugin's path. A job confirmed before the review compared the roles and plugins
	 * of both generations has none and has to be reviewed again. So does one whose capabilities
	 * are no longer those that the site requires: a filter of the backup capability may have
	 * changed since the review, and the exchange checks the administrator under the other
	 * generation's roles with the capabilities that it gets here.
	 *
	 * @param Job $job Database apply or undo.
	 * @return Continuity What the exchange has to leave working.
	 * @throws JobConflict If the job lacks valid capabilities or the site requires others now.
	 */
	private function continuity( Job $job ): Continuity {
		try {
			$reviewed = Continuity::of( $job->data['capabilities'] ?? null, $this->site->plugin );
		} catch ( InvalidArgument ) {
			JobConflict::raise( 'This database change was confirmed before its review compared the roles and active plugins of both generations.', [ 'code' => 'database_exchange_changed' ] );
		}
		try {
			$required = $this->site->continuity()->capabilities;
		} catch ( InvalidArgument ) {
			$required = [];
		}
		sort( $required, SORT_STRING );
		$checked = $reviewed->capabilities;
		sort( $checked, SORT_STRING );
		if ( $required !== $checked ) {
			JobConflict::raise( 'The capabilities that a database restore requires changed after the review.', [ 'code' => 'database_exchange_changed' ] );
		}
		return $reviewed;
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
