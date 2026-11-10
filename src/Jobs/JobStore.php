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

	private const string ID_PATTERN = '/\A\d{8}T\d{6}Z-[0-9a-f]{6}\z/';

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
	 * @throws JobConflict If a job is already active.
	 * @throws StorageFailed If the jobs directory cannot be locked or written.
	 */
	public function create( JobType $type, string $trigger, int $now, array $data = [] ): Job {
		$lock = $this->lock();
		try {
			$active = $this->active();
			if ( null !== $active ) {
				JobConflict::raise( 'Another job is still running.', [ 'job' => $active->id ] );
			}
			$job       = new Job( gmdate( 'Ymd\THis\Z', $now ) . '-' . bin2hex( $this->random->bytes( 3 ) ), $type, $trigger, $now );
			$job->data = $data;
			$this->save( $job );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
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
	 * @return Job|null The job, or null if the ID is unknown or invalid.
	 */
	public function get( string $id ): ?Job {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}
		$json = is_file( $this->directory . '/' . $id . '/job.json' ) ? file_get_contents( $this->directory . '/' . $id . '/job.json' ) : false;
		if ( false === $json ) {
			return null;
		}
		$fields = json_decode( $json, true );
		try {
			$job = is_array( $fields ) ? Job::from_array( $fields ) : null;
		} catch ( FormatException ) {
			return null;
		}
		if ( null !== $job && ! $job->cancel_requested ) {
			$job->cancel_requested = $this->cancel_requested( $job );
		}
		return $job;
	}

	/**
	 * Writes a job atomically. After the job's creation only the holder of the runner lock
	 * calls this; everyone else would overwrite what a tick saves, or be overwritten by it.
	 *
	 * @param Job $job Job.
	 */
	public function save( Job $job ): void {
		$json = json_encode( $job->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
		Files::write_atomic( $this->directory( $job ) . '/job.json', $this->redactor->redact( $json ) . "\n" );
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
	 * The job's directory, created on demand.
	 *
	 * @param Job $job Job.
	 * @return string Absolute path.
	 */
	public function directory( Job $job ): string {
		$path = $this->directory . '/' . $job->id;
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0700, true );
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
	 * Deletes the oldest finished jobs.
	 *
	 * @param int $keep Number of jobs to keep.
	 * @return int Number of deleted jobs.
	 */
	public function prune( int $keep ): int {
		$deleted = 0;
		foreach ( array_slice( $this->ids(), max( 1, $keep ) ) as $id ) {
			$job = $this->get( $id );
			if ( null === $job || $job->state->is_terminal() ) {
				Files::remove_tree( $this->directory . '/' . $id );
				++$deleted;
			}
		}
		return $deleted;
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
	 */
	private function ids(): array {
		$entries = is_dir( $this->directory ) ? scandir( $this->directory, SCANDIR_SORT_DESCENDING ) : false;
		return array_values( array_filter( false === $entries ? [] : $entries, static fn ( string $entry ): bool => 1 === preg_match( self::ID_PATTERN, $entry ) ) );
	}
}
