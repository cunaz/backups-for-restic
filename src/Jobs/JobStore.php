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
	 * @param string       $directory The private directory's jobs/ folder.
	 * @param Redactor     $redactor  Removes secrets from everything written.
	 * @param RandomSource $random    Randomness for IDs.
	 */
	public function __construct(
		private readonly string $directory,
		private readonly Redactor $redactor,
		private readonly RandomSource $random
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
			$this->require_idle();
			$job       = new Job( $this->reserve_id( $now ), $type, $trigger, $now );
			$job->data = $data;
			$this->save( $job );
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
			if ( ! mkdir( $path, 0700 ) ) {
				throw new StorageFailed( 'The new job directory could not be reserved.' );
			}
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
	 * Whether a document is stored for a job ID, readable by this version or not. Together
	 * with get() it tells a job that is gone from one whose state cannot be established.
	 *
	 * @param string $id Job ID.
	 * @return bool True if the job has a document.
	 */
	public function has_document( string $id ): bool {
		$file = $this->directory . '/' . $id . '/job.json';
		// Jobs are created and pruned by other requests: never answer from PHP's stat cache.
		clearstatcache( true, $file );
		return 1 === preg_match( self::ID_PATTERN, $id ) && is_file( $file );
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
	 * Writes a job atomically. After the job's creation only the holder of the runner lock
	 * calls this; everyone else would overwrite what a tick saves, or be overwritten by it.
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
		Files::write_atomic( $this->directory( $job ) . '/job.json', $json . "\n" );
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
		if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
			StorageFailed::raise( 'A job directory could not be created in the private directory.', [ 'job' => $job->id ] );
		}
		return $path;
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
			if ( null !== $job && in_array( $job->type->value, [ 'apply', 'undo', 'database_apply' ], true ) ) {
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
		$pins = [];
		foreach ( $this->file_changes() as $job ) {
			$id = $job->data['restore'] ?? null;
			if ( is_string( $id ) && ( ! $job->state->is_terminal() || ( JobType::Apply === $job->type && $job->finished_at + 86400 > $now && is_file( $this->directory . '/' . $job->id . '/undo/attempted' ) && ! is_file( $this->directory . '/' . $job->id . '/undo/reverted' ) ) ) ) {
				$pins[ $id ] = true;
			}
		}
		return $pins;
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
			if ( null !== $job && $job->state->is_terminal() && ! isset( $pins[ $id ] ) && ! isset( $job->data['database_binding'] ) && ( JobType::Apply !== $job->type || ! is_dir( $this->directory . '/' . $id . '/undo' ) ) ) {
				Files::remove_tree( $this->directory . '/' . $id );
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Finds private database tables still awaiting deletion after a failed cleanup.
	 *
	 * @param bool $uninstall Also finish cleanup for interrupted, deactivated jobs.
	 * @return \Generator<int, Job> Jobs holding their original cleanup identity.
	 * @throws StorageFailed If uninstall cannot verify a stored job's ownership records.
	 */
	public function pending_database_cleanups( bool $uninstall ): \Generator {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( $uninstall && null === $job && $this->has_document( $id ) ) {
				throw new StorageFailed( 'An unreadable job must be recovered before uninstalling.' );
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
	 * Requires a store with no active job and no unreadable job document. Called while the
	 * creation lock holds. An unknown layout cannot prove that a job has finished, even if
	 * its raw state field looks familiar; no part of that layout is interpreted here.
	 *
	 * @throws JobConflict If a job is active or its state cannot be established.
	 */
	private function require_idle(): void {
		foreach ( $this->ids() as $id ) {
			$job = $this->get( $id );
			if ( null === $job && is_file( $this->directory . '/' . $id . '/job.json' ) ) {
				JobConflict::raise(
					'A stored job cannot be read by this version of the plugin.',
					[
						'job'  => $id,
						'code' => 'job_unreadable',
					]
				);
			}
			if ( null !== $job && ( ! $job->state->is_terminal() || isset( $job->data['database_binding'] ) ) ) {
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
		if ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
			StorageFailed::raise( 'The jobs directory could not be created.' );
		}
		$handle = fopen( $this->directory . '/' . self::CREATE_LOCK, 'c' );
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
