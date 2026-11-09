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
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each job in its own directory under jobs/ (ADR 0014): job.json, written atomically,
 * events.log and the step's working files. IDs sort by creation time.
 */
final class JobStore {

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
	 * Creates a queued job unless another one is active.
	 *
	 * @param JobType $type    Type.
	 * @param string  $trigger manual, schedule, cli or setup.
	 * @param int     $now     Unix time.
	 * @return Job The new job.
	 * @throws JobConflict If a job is already active.
	 */
	public function create( JobType $type, string $trigger, int $now ): Job {
		$active = $this->active();
		if ( null !== $active ) {
			JobConflict::raise( 'Another job is still running.', [ 'job' => $active->id ] );
		}
		$job = new Job( gmdate( 'Ymd\THis\Z', $now ) . '-' . bin2hex( $this->random->bytes( 3 ) ), $type, $trigger, $now );
		$this->save( $job );
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
	 * Loads a job.
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
			return is_array( $fields ) ? Job::from_array( $fields ) : null;
		} catch ( FormatException ) {
			return null;
		}
	}

	/**
	 * Writes a job atomically.
	 *
	 * @param Job $job Job.
	 */
	public function save( Job $job ): void {
		$json = json_encode( $job->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
		Files::write_atomic( $this->directory( $job ) . '/job.json', $this->redactor->redact( $json ) . "\n" );
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
	 * Job IDs, newest first.
	 *
	 * @return list<string> IDs.
	 */
	private function ids(): array {
		$entries = is_dir( $this->directory ) ? scandir( $this->directory, SCANDIR_SORT_DESCENDING ) : false;
		return array_values( array_filter( false === $entries ? [] : $entries, static fn ( string $entry ): bool => 1 === preg_match( self::ID_PATTERN, $entry ) ) );
	}
}
