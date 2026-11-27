<?php
/**
 * Jobs as files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each job in its own directory under jobs/ (ADR 0014): job.json, written atomically,
 * events.log and the step's working files. IDs sort by creation time.
 *
 * A job document is on disk when save() returns: it is synchronised before its rename, so a
 * crash cannot leave it empty, and its directory after it, so a power loss cannot bring back
 * an older document than the one that a step's next change, such as a live file apply or a
 * table exchange, relies on. A new job directory is synchronised in jobs/ before its first
 * document is written.
 *
 * After its creation a job document has one writer at a time: whoever holds the runner lock,
 * as a rule a tick. A request to cancel therefore is a file of its own next to the document.
 */
final class JobStore {

	/** Held in jobs/ while create() checks for an active job and writes the new one. */
	public const string CREATE_LOCK = 'create.lock';

	/** In a job's directory once an administrator asked to cancel it. */
	public const string CANCEL_MARKER = 'cancel-requested';

	/** What a job ID looks like: the time of its creation in UTC and six random characters. */
	public const string ID_PATTERN = '/\A\d{8}T\d{6}Z-[0-9a-f]{6}\z/';

	/**
	 * Whether while_idle() holds the lock of create() in this process.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Opens the store.
	 *
	 * @param string                                $directory The private directory's jobs/ folder.
	 * @param Redactor                              $redactor  Removes secrets from everything written.
	 * @param RandomSource                          $random    Randomness for IDs.
	 * @param (\Closure(string, string): void)|null $observer  Optional observer of each new job directory,
	 *                                                          synchronisation and document rename, with the
	 *                                                          path, in order; the tests check that order.
	 */
	public function __construct(
		private readonly string $directory,
		private readonly Redactor $redactor,
		private readonly RandomSource $random,
		private readonly ?\Closure $observer = null
	) {
	}

	/**
	 * Creates a queued job unless another one is active. The check and the write happen under
	 * an exclusive lock, so that two requests at the same moment cannot both find no job and
	 * create one each.
	 *
	 * @param JobType              $type    Type.
	 * @param string               $trigger manual, schedule, cli or setup.
	 * @param int                  $now     Unix time.
	 * @param array<string, mixed> $data    Step data the job starts with; never secrets.
	 * @return Job The new job.
	 * @throws JobConflict If a job is active or an existing document cannot be read.
	 * @throws StorageFailed If the jobs directory cannot be locked or written.
	 */
	public function create( JobType $type, string $trigger, int $now, array $data = [] ): Job {
		$lock = $this->held ? null : $this->lock();
		try {
			$this->require_idle( $type );
			$job       = new Job( $this->reserve_id( $now ), $type, $trigger, $now );
			$job->data = $data;
			try {
				$this->save( $job );
			} catch ( StorageFailed $error ) {
				// Only remove our empty reservation, while the creation lock still holds.
				@rmdir( $this->directory . '/' . $job->id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Preserve the write error and leave any unexpected contents intact.
				throw $error;
			}
		} finally {
			if ( null !== $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
		$this->log( $job )->write(
			'info',
			'Job created.',
			[
				'type'    => $type->value,
				'trigger' => $trigger,
			]
		);
		return $job;
	}

	/**
	 * Claims an unused ID under the creation lock. A retained staging directory can
	 * outlive its job history, particularly if the system clock moves backwards.
	 *
	 * @param int $now Unix time.
	 * @return string Reserved ID.
	 * @throws StorageFailed If an unused job directory cannot be created.
	 */
	private function reserve_id( int $now ): string {
		$prefix = gmdate( 'Ymd\THis\Z', $now ) . '-';
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$id      = $prefix . bin2hex( $this->random->bytes( 3 ) );
			$path    = $this->directory . '/' . $id;
			$staging = dirname( $this->directory ) . '/restores/' . $id;
			clearstatcache( true );
			if ( file_exists( $path ) || is_link( $path ) || file_exists( $staging ) || is_link( $staging ) ) {
				continue;
			}
			if ( ! @mkdir( $path, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Report storage exhaustion without PHP's path-bearing warning.
				throw new StorageFailed( 'The new job directory could not be reserved.' );
			}
			$this->created( $path );
			return $id;
		}
		throw new StorageFailed( 'An unused job ID could not be allocated.' );
	}

	/**
	 * Runs a change that no job may see half done, such as another server address or another
	 * repository password. The check for an active job and the change happen under the lock of
	 * create(), so no job can come to exist in between; create() may be called from within the
	 * change. Whoever wants to create a job meanwhile waits, so the change has to be short.
	 *
	 * @template T
	 * @param \Closure(): T $change The change.
	 * @param-immediately-invoked-callable $change
	 * @return T What the change returns.
	 * @throws JobConflict If a job is active or an existing document cannot be read.
	 * @throws StorageFailed If the jobs directory cannot be locked.
	 */
	public function while_idle( \Closure $change ): mixed {
		$lock       = $this->lock();
		$this->held = true;
		try {
			$this->require_idle();
			return $change();
		} finally {
			$this->held = false;
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * The job that is queued or running, if any.
	 *
	 * @return Job|null Active job.
	 */
	public function active(): ?Job {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null !== $job && ! $job->state->is_terminal() ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Loads a job. A request to cancel that the job document does not show yet
	 * (request_cancel()) is part of what is loaded.
	 *
	 * @param string $id Job ID.
	 * @return Job|null The job, or null if the ID is unknown, invalid, or its document is unreadable.
	 */
	public function get( string $id ): ?Job {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}
		$path = $this->directory . '/' . $id;
		$file = $path . '/job.json';
		if ( is_link( $this->directory ) || is_link( $path ) || is_link( $file ) ) {
			return null;
		}
		$json = is_file( $file ) ? file_get_contents( $file ) : false;
		if ( false === $json ) {
			return null;
		}
		$fields = json_decode( $json, true );
		try {
			$job = is_array( $fields ) ? Job::from_array( $fields ) : null;
		} catch ( FormatException ) {
			return null;
		}
		if ( null === $job || $job->id !== $id ) {
			return null;
		}
		if ( ! $job->cancel_requested ) {
			$job->cancel_requested = $this->cancel_requested( $job );
		}
		return $job;
	}

	/**
	 * Whether a document is stored for a job ID, readable by this version or not, or cannot be
	 * ruled out. Together with get() it tells a job that is gone from one whose state cannot be
	 * established. A document is gone only where its name is missing from the job's directory,
	 * or the directory is missing (Files::marker()); one that cannot be looked up, below a job
	 * directory that this process cannot search, counts as stored, and so does anything other
	 * than a regular file under its name. Before, is_file() counted them as gone.
	 *
	 * @param string $id Job ID.
	 * @return bool True if the job has a document, or may have one.
	 */
	public function has_document( string $id ): bool {
		// Jobs are created and pruned by other requests: Files::marker() never answers from PHP's stat cache.
		return 1 === preg_match( self::ID_PATTERN, $id ) && false !== Files::marker( $this->directory . '/' . $id . '/job.json' );
	}

	/**
	 * Why get() does not load a job whose document has_document() counts. `job_unreadable`
	 * where this process reads a regular file in the job's own directory that this version
	 * cannot parse. `job_inaccessible` where it cannot look the document up or read it: below a
	 * job directory that it may not search, as a file that it may not read, below a link in the
	 * place of the job directory, or as anything other than a regular file in the place of the
	 * document. Ownership and permissions settle the second, not another plugin version; a
	 * job directory that a command-line run as another user created (reserve_id() makes it
	 * 0700) is one.
	 *
	 * @param string $id Job ID that has_document() counts.
	 * @return string The code, job_unreadable or job_inaccessible.
	 */
	public function unreadable_code( string $id ): string {
		$file = $this->directory . '/' . $id . '/job.json';
		return true === Files::marker( $file ) && is_readable( $file ) ? 'job_unreadable' : 'job_inaccessible';
	}

	/**
	 * Refuses because of a job that get() does not load although has_document() counts its
	 * document: its state cannot be established, so it may still be running. The refusal
	 * carries the code of unreadable_code() and the job's ID, which its texts name.
	 *
	 * @param string $id Job ID that has_document() counts.
	 * @throws JobConflict Always.
	 */
	public function refuse_unreadable( string $id ): never {
		$code = $this->unreadable_code( $id );
		JobConflict::raise(
			'job_inaccessible' === $code ? 'The PHP user cannot open the directory or the document of a stored job.' : 'A stored job cannot be read by this version of the plugin.',
			[
				'job'  => $id,
				'code' => $code,
			]
		);
	}

	/**
	 * Whether the records of an apply's undo hold its recovery window open, by their presence:
	 * true while `undo/attempted` shows an attempted change and no `undo/reverted` marks it as
	 * undone completely, false where it is established that the apply attempted no change or was
	 * undone completely, and null where a record cannot be established (Files::marker()). Null
	 * keeps what the window keeps, the staging, the sealed plan and the journal, and the undo is
	 * refused while it lasts. The 24 hours of the window are the caller's to check.
	 *
	 * @param Job $apply Apply.
	 * @return bool|null Whether the records hold the window open, or null where they cannot be established.
	 */
	public function undo_open( Job $apply ): ?bool {
		$workspace = $this->directory . '/' . $apply->id . '/undo';
		$reverted  = Files::marker( $workspace . '/reverted' );
		$attempted = Files::marker( $workspace . '/attempted' );
		if ( true === $reverted || false === $attempted ) {
			return false;
		}
		return true === $attempted && false === $reverted ? true : null;
	}

	/**
	 * Redacts a diagnostic before a step retains it in its data or copies it to site state.
	 *
	 * @param string $message Diagnostic text.
	 * @return string Text safe to include in the job report.
	 */
	public function diagnostic( string $message ): string {
		return $this->redactor->redact( $message );
	}

	/**
	 * Writes a job atomically and durably. After the job's creation only the holder of the
	 * runner lock calls this; everyone else would overwrite what a tick saves, or be
	 * overwritten by it.
	 *
	 * @param Job $job Job.
	 */
	public function save( Job $job ): void {
		// Only diagnostics can contain credentials. Replacing text in serialized state also
		// replaces JSON syntax, IDs and step names when a password happens to match them.
		$fields             = $job->to_array();
		$fields['warnings'] = array_map( $this->redactor->redact( ... ), $job->warnings );
		if ( null !== $job->error ) {
			$fields['error']            = $job->error->to_array();
			$fields['error']['message'] = $this->redactor->redact( $job->error->message );
		}
		if ( is_string( $job->progress['current'] ?? null ) ) {
			$fields['progress']            = $job->progress;
			$fields['progress']['current'] = $this->redactor->redact( $job->progress['current'] );
		}
		$json = json_encode( $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
		Files::write_durable( $this->directory( $job ) . '/job.json', $json . "\n", $this->observer );
	}

	/**
	 * The observer of this store's durable writes, for other records in a job's directory
	 * that its document counts, such as the entries an undo kept, so that their writes are
	 * observed in one order with the document's.
	 *
	 * @return (\Closure(string, string): void)|null Observer.
	 */
	public function observer(): ?\Closure {
		return $this->observer;
	}

	/**
	 * Records that an administrator asked to cancel a job, as a marker file in the job's
	 * directory. Unlike a changed job document, the marker cannot overwrite what a running
	 * tick saves, and no save of a tick can lose it; the runner reads it before every step.
	 *
	 * @param Job $job Job.
	 * @throws StorageFailed If the marker cannot be written.
	 */
	public function request_cancel( Job $job ): void {
		// A job whose directory is gone was pruned; there is nothing left to cancel.
		if ( is_dir( $this->directory . '/' . $job->id ) ) {
			Files::write_atomic( $this->directory . '/' . $job->id . '/' . self::CANCEL_MARKER, "1\n" );
		}
	}

	/**
	 * Whether cancelling a job was requested with request_cancel().
	 *
	 * @param Job $job Job.
	 * @return bool True if the marker exists.
	 */
	public function cancel_requested( Job $job ): bool {
		$marker = $this->directory . '/' . $job->id . '/' . self::CANCEL_MARKER;
		// Another request writes the marker while a tick runs: never answer from PHP's stat cache.
		clearstatcache( true, $marker );
		return is_file( $marker );
	}

	/**
	 * The most recent jobs.
	 *
	 * @param int $limit Maximum number.
	 * @return list<Job> Jobs, newest first.
	 */
	public function latest( int $limit ): array {
		$jobs = [];
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null !== $job ) {
				$jobs[] = $job;
				if ( count( $jobs ) >= $limit ) {
					break;
				}
			}
		}
		return $jobs;
	}

	/**
	 * Returns the filesystem location holding file-change journals and their payloads.
	 *
	 * @return string Private jobs directory.
	 */
	public function storage_directory(): string {
		return $this->directory;
	}

	/**
	 * Returns the filesystem that will hold the private file-change journals.
	 *
	 * @return int Device number.
	 * @throws StorageFailed If the jobs directory is unavailable.
	 */
	public function device(): int {
		$stat = is_dir( $this->directory ) ? stat( $this->directory ) : false;
		if ( false === $stat ) {
			throw new StorageFailed( 'The private jobs directory is unavailable.' );
		}
		return $stat['dev'];
	}

	/**
	 * The job's directory, created on demand.
	 *
	 * @param Job $job Job.
	 * @return string Absolute path.
	 * @throws StorageFailed If the directory cannot be created.
	 */
	public function directory( Job $job ): string {
		$path = $this->directory . '/' . $job->id;
		if ( 1 !== preg_match( self::ID_PATTERN, $job->id ) || is_link( $this->directory ) || is_link( $path ) ) {
			throw new StorageFailed( 'The job directory is invalid.' );
		}
		if ( ! is_dir( $path ) ) {
			if ( ! mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
				StorageFailed::raise( 'A job directory could not be created in the private directory.', [ 'job' => $job->id ] );
			}
			$this->created( $path );
		}
		return $path;
	}

	/**
	 * Puts a new job directory's entry in jobs/ on disk, so that a power loss cannot drop the
	 * directory together with the synchronised documents and journals inside it.
	 *
	 * @param string $path New job directory.
	 */
	private function created( string $path ): void {
		if ( null !== $this->observer ) {
			( $this->observer )( 'mkdir', $path );
		}
		Files::sync_directory( $this->directory, $this->observer );
	}

	/**
	 * The job's event log.
	 *
	 * @param Job $job Job.
	 * @return EventLog Log.
	 */
	public function log( Job $job ): EventLog {
		return new EventLog( $this->directory( $job ) . '/events.log', $this->redactor );
	}

	/**
	 * Streams apply and undo jobs without keeping every job document in memory.
	 *
	 * @return \Generator<int, Job> Jobs newest first.
	 */
	public function file_changes(): \Generator {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null !== $job && in_array( $job->type->value, [ 'apply', 'undo', 'file_plan', 'database_apply' ], true ) ) {
				yield $job;
			}
		}
	}

	/**
	 * Restore jobs whose source must survive until an active change or its undo window
	 * ends. The private undo workspace stays independent of ordinary job-history pruning.
	 *
	 * @param int $now Current timestamp.
	 * @return array<string, true> Pinned restore IDs.
	 */
	public function pinned_restores( int $now ): array {
		return array_map( static fn (): true => true, $this->restore_pins( $now ) );
	}

	/**
	 * The restores of pinned_restores() with the reason each one is kept: null while a job
	 * that uses its staging has not ended, otherwise the time at which the undo window of the
	 * latest apply of it ends, 24 hours after that apply ended. An apply whose undo records
	 * cannot be established keeps its staging as an open window does (undo_open()); see
	 * census().
	 *
	 * @param int $now Current timestamp.
	 * @return array<string, int|null> End of the undo window by restore ID, null for a staging in use.
	 */
	public function restore_pins( int $now ): array {
		return $this->census( $now )['restores'];
	}

	/**
	 * The newest stored job that get() does not load although has_document() counts its
	 * document. Its type and state are unknown, so it may be an apply within its recovery
	 * window, whose undo needs the staging of its restore and its sealed review; neither
	 * restore_pins() nor pinned_file_plans() can name them. The window of an apply ends 24 hours
	 * after the apply ended, a time that its ID does not give, so a time-bound hold would end
	 * early for an apply that ran long. Staging::expire() therefore removes no staging and no
	 * sealed review while there is such a job. It blocks every new job too (require_idle()).
	 *
	 * @return string|null Job ID, or null if every stored document can be read.
	 */
	public function unreadable(): ?string {
		foreach ( $this->ids() as $id ) {
			if ( null === $this->get( $id ) && $this->has_document( $id ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Sealed disk plans in use by an active apply, its undo preview or the undo window, also
	 * that of an apply whose undo records cannot be established (undo_open()); see census().
	 *
	 * @param int $now Current time.
	 * @return array<string,true> Pinned plan IDs.
	 */
	public function pinned_file_plans( int $now ): array {
		return $this->census( $now )['plans'];
	}

	/**
	 * One reading of every stored job, for the heartbeat's expiry (Staging::expire()) and the
	 * pins: the newest job that cannot be read, the applies whose journal an undo or the review
	 * of an undo that has not ended holds, the sealed reviews in use (pinned_file_plans()) and
	 * the stagings in use or kept for an undo (restore_pins()). Each document is read once, so
	 * that a job that turns readable or unreadable meanwhile cannot slip between two readings:
	 * either it could not be read, which holds everything that the expiry would remove, or what
	 * it holds is here. Before, the expiry took the active job, the pins and the job that cannot
	 * be read from separate readings, and an undo that could not be read in the first and could
	 * be read in the last was seen by neither, so that the journal of its apply went once the
	 * window had ended. An apply whose undo records cannot be established holds as an open
	 * window does (undo_open()).
	 *
	 * @param int $now Current time.
	 * @return array{unreadable: string|null, undoing: array<string, true>, plans: array<string, true>, restores: array<string, int|null>} The job that cannot be read, or null; apply IDs whose journal an undo or its review holds; pinned plan IDs; and the end of the undo window by restore ID, null for a staging in use.
	 */
	public function census( int $now ): array {
		$unreadable = null;
		$undoing    = [];
		$plans      = [];
		$restores   = [];
		$owners     = [];
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null === $job ) {
				if ( null === $unreadable && $this->has_document( $id ) ) {
					$unreadable = $id;
				}
				continue;
			}
			if ( ! in_array( $job->type->value, [ 'apply', 'undo', 'file_plan', 'database_apply' ], true ) ) {
				continue;
			}
			$ended = $job->state->is_terminal();
			if ( ! $ended ) {
				// An undo, or the review of one, keeps its apply's journal while it runs.
				$subject = JobType::Undo === $job->type ? ( $job->data['apply'] ?? null ) : ( JobType::FilePlan === $job->type && true === ( $job->data['undo'] ?? null ) ? ( $job->data['subject'] ?? null ) : null );
				if ( is_string( $subject ) ) {
					$undoing[ $subject ] = true;
				}
			}
			$open    = $ended && JobType::Apply === $job->type && $job->finished_at + 86400 > $now && false !== $this->undo_open( $job );
			$restore = $job->data['restore'] ?? null;
			if ( is_string( $restore ) ) {
				if ( ! $ended ) {
					$restores[ $restore ] = null;
				} elseif ( $open && ( ! array_key_exists( $restore, $restores ) || ( null !== $restores[ $restore ] && $restores[ $restore ] < $job->finished_at + 86400 ) ) ) {
					$restores[ $restore ] = $job->finished_at + 86400;
				}
			}
			$plan = $job->data['plan_job'] ?? null;
			if ( is_string( $plan ) ) {
				if ( ! $ended || $open ) {
					$plans[ $plan ] = true;
				} else {
					$owners[ $job->id ] = $plan;
				}
			}
		}
		// The sealed review of an apply that an undo is reverting stays while that undo runs.
		foreach ( $owners as $owner => $plan ) {
			if ( isset( $undoing[ $owner ] ) ) {
				$plans[ $plan ] = true;
			}
		}
		return [
			'unreadable' => $unreadable,
			'undoing'    => $undoing,
			'plans'      => $plans,
			'restores'   => $restores,
		];
	}

	/**
	 * Deletes the oldest finished jobs. An unreadable document is kept: this version cannot
	 * know whether its job has finished.
	 *
	 * @param int      $keep Number of jobs to keep.
	 * @param int|null $now Current Unix time.
	 * @return int Number of deleted jobs.
	 */
	public function prune( int $keep, ?int $now = null ): int {
		$deleted = 0;
		$pins    = $this->pinned_restores( $now ?? time() );
		foreach ( array_slice( $this->ids(), max( 1, $keep ) ) as $id ) {
			$job = $this->get( $id );
			if ( null !== $job && $job->state->is_terminal() && ! isset( $pins[ $id ] ) && ! isset( $job->data['plan_retained'] ) && ! isset( $job->data['database_owner'] ) && ! isset( $job->data['database_binding'] ) && ( JobType::Apply !== $job->type || ! is_dir( $this->directory . '/' . $id . '/undo' ) ) ) {
				Files::remove_tree( $this->directory . '/' . $id );
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Enumerates every owning apply, including jobs beyond the visible history list.
	 *
	 * @return \Generator<int, Job> Jobs with active or retained SQL ownership.
	 */
	public function database_owners(): \Generator {
		foreach ( $this->file_changes() as $job ) {
			if ( JobType::DatabaseApply === $job->type && isset( $job->data['database_owner'] ) ) {
				yield $job;
			}
		}
	}

	/**
	 * Finds private database tables still awaiting deletion after a failed cleanup.
	 *
	 * @param bool $uninstall Also finish cleanup for interrupted, deactivated jobs.
	 * @return \Generator<int, Job> Jobs holding their original cleanup identity.
	 * @throws StorageFailed If uninstall cannot verify a stored job's ownership records, with the
	 *                       job's ID and the code of unreadable_code().
	 */
	public function pending_database_cleanups( bool $uninstall ): \Generator {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( $uninstall && null === $job && $this->has_document( $id ) ) {
				StorageFailed::raise(
					'An unreadable job must be recovered before uninstalling.',
					[
						'job'  => $id,
						'code' => $this->unreadable_code( $id ),
					]
				);
			}
			if ( null !== $job && JobType::Restore === $job->type && isset( $job->data['database_binding'] ) && ( $uninstall || $job->state->is_terminal() ) ) {
				yield $job;
			}
		}
	}

	/**
	 * Finds the marker of an interrupted live restore while the runner lock holds.
	 *
	 * @return \Generator<int, Job> Jobs whose private maintenance file still exists.
	 * @throws StorageFailed If the job owning a marker cannot be recovered.
	 */
	public function maintenance_jobs(): \Generator {
		foreach ( $this->ids() as $id ) {
			if ( ! is_file( $this->directory . '/' . $id . '/maintenance.php' ) ) {
				continue;
			}
			$job = $this->get( $id );
			if ( null === $job || ! $job->state->is_terminal() ) {
				throw new StorageFailed( 'Finish the interrupted maintenance job before uninstalling.' );
			}
			yield $job;
		}
	}

	/**
	 * Requires a store with no active job and no unreadable job document, which includes a
	 * document that cannot be looked up (has_document()); refuse_unreadable() names it and
	 * says which of the two it is. Called while the creation lock holds.
	 * An unknown layout cannot prove that a job has finished, even if its raw state field looks
	 * familiar; no part of that layout is interpreted here.
	 *
	 * The private tables of a database restore that belong to another database server are
	 * not on this one, so they cannot reach a backup and need no cleanup here. Only a new
	 * database apply waits until an administrator has discarded that binding.
	 *
	 * @param JobType|null $type Type of the job about to be created, if any.
	 * @throws JobConflict If a job is active or its state cannot be established.
	 */
	private function require_idle( ?JobType $type = null ): void {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null === $job && $this->has_document( $id ) ) {
				$this->refuse_unreadable( $id );
			}
			if ( null !== $job && $job->state->is_terminal() && isset( $job->data['database_owner'], $job->data['database_foreign'] ) ) {
				if ( JobType::DatabaseApply === $type ) {
					JobConflict::raise(
						'A database restore still holds private tables on another database server. Discard its binding before applying another database.',
						[
							'job'  => $id,
							'code' => 'database_exchange_foreign',
						]
					);
				}
				continue;
			}
			if ( null !== $job && $job->state->is_terminal() && ( isset( $job->data['database_binding'] ) || ( isset( $job->data['database_owner'] ) && ! isset( $job->data['retained_until'] ) ) ) ) {
				JobConflict::raise(
					'An interrupted database restore must finish cleaning its owned tables before another job starts.',
					[
						'job'  => $id,
						'code' => 'database_exchange_cleanup',
					]
				);
			}
			if ( null !== $job && ! $job->state->is_terminal() ) {
				JobConflict::raise( 'Another job is still running.', [ 'job' => $id ] );
			}
		}
	}

	/**
	 * Takes the lock of create(), waiting for another request that is creating a job.
	 *
	 * @return resource The open lock file; closing it releases the lock.
	 * @throws StorageFailed If the jobs directory cannot be created or locked.
	 */
	private function lock() {
		if ( ! is_dir( $this->directory ) && ! @mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent creator is harmless and a real write refusal is reported.
			StorageFailed::raise( 'The jobs directory could not be created.' );
		}
		$handle = @fopen( $this->directory . '/' . self::CREATE_LOCK, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked error replaces PHP's path-bearing warning.
		if ( false === $handle ) {
			StorageFailed::raise( 'The jobs directory could not be locked.' );
		}
		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			StorageFailed::raise( 'The jobs directory could not be locked.' );
		}
		return $handle;
	}

	/**
	 * Job IDs, newest first.
	 *
	 * @return list<string> IDs.
	 * @throws StorageFailed If the directory cannot be listed safely.
	 */
	private function ids(): array {
		if ( ! is_dir( $this->directory ) ) {
			return [];
		}
		$entries = is_link( $this->directory ) ? false : scandir( $this->directory, SCANDIR_SORT_DESCENDING );
		if ( false === $entries ) {
			throw new StorageFailed( 'The private jobs directory could not be listed.' );
		}
		return array_values( array_filter( $entries, static fn ( string $entry ): bool => 1 === preg_match( self::ID_PATTERN, $entry ) ) );
	}
}
