<?php
/**
 * Identity, private paths and retention for file apply recovery records.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/** Locates and validates the staged input and undo records shared by reviews and writers. */
final readonly class ApplyJournal {

	/**
	 * Binds the job and staging stores with the site's retention policy.
	 *
	 * @param JobStore $jobs Job records.
	 * @param Staging  $staging Staged files.
	 * @param Clock    $clock Time source.
	 * @param int      $hours Normal staging lifetime.
	 */
	public function __construct( private JobStore $jobs, private Staging $staging, private Clock $clock, private int $hours ) {
	}

	/**
	 * Checks the undo window. An undo already queued in the window may finish after it. An
	 * apply whose undo records cannot be established is refused with a code of its own, not as
	 * one past its window: its staging and journal are kept while the window lasts (window()).
	 *
	 * @param string $id Apply ID.
	 * @param bool   $active The current runner is undoing this apply.
	 * @return Job Apply job.
	 * @throws JobConflict If the recovery window has closed, or with the code
	 *                     `undo_records_unreadable` if the records of its undo cannot be established.
	 */
	public function original( string $id, bool $active = false ): Job {
		$job    = $this->jobs->get( $id );
		$window = null === $job ? false : $this->window( $job, $active );
		if ( null === $window ) {
			JobConflict::raise( 'The records of this apply\'s undo cannot be looked up; its journal and staged files are kept while its recovery window lasts.', [ 'code' => 'undo_records_unreadable' ] );
		}
		if ( ! $window ) {
			JobConflict::raise( 'This apply has no available undo.', [ 'code' => 'undo_unavailable' ] );
		}
		return $job;
	}


	/**
	 * Whether an apply can be undone: one that has ended, within its window unless an undo of it
	 * is already running, whose records show an attempted change that no undo reverted
	 * completely. Null where those records cannot be established (JobStore::undo_open()): the
	 * apply is neither offered for undo nor taken for one whose window is over.
	 *
	 * @param Job  $job Candidate.
	 * @param bool $active Undo is already running.
	 * @return bool|null Whether it can be undone, or null where its records cannot be established.
	 */
	public function window( Job $job, bool $active = false ): ?bool {
		if ( JobType::Apply !== $job->type || ! $job->state->is_terminal() || ( ! $active && $job->finished_at + 86400 <= $this->clock->now()[0] ) ) {
			return false;
		}
		return $this->jobs->undo_open( $job );
	}


	/**
	 * Reads the count recorded before attempting the latest ordinal: zero only where it is
	 * established that the apply recorded none (Files::marker()).
	 *
	 * @param Job $job Apply.
	 * @return int Count.
	 * @throws StorageFailed If the recovery cursor is invalid or cannot be looked up.
	 */
	public function attempted( Job $job ): int {
		$file  = $this->workspace( $job ) . '/attempted';
		$found = Files::marker( $file );
		if ( false === $found ) {
			return 0;
		}
		if ( null === $found ) {
			throw new StorageFailed( 'The undo cursor cannot be looked up.' );
		}
		$count = file_get_contents( $file, false, null, 0, 16 );
		if ( ! is_string( $count ) || 1 !== preg_match( '/\A[1-9][0-9]{0,6}\z/', $count ) || (int) $count > DiskFilePlan::MAX_ENTRIES ) {
			throw new StorageFailed( 'The undo cursor is damaged.' );
		}
		return (int) $count;
	}


	/**
	 * The private workspace associated with one unique apply.
	 *
	 * @param Job $job Apply.
	 * @return string Path.
	 */
	public function workspace( Job $job ): string {
		return $this->jobs->directory( $job ) . '/undo';
	}


	/**
	 * Resolves staging without trusting an absolute name from a job.
	 *
	 * @param string $restore Restore ID.
	 * @param bool   $retained Include pinned staging.
	 * @return string Verified staging path.
	 * @throws InvalidArgument If this restore is unavailable or expired.
	 */
	public function files( string $restore, bool $retained ): string {
		$files = $this->staging->files_directory( $restore, $this->hours, $retained );
		if ( null === $files ) {
			InvalidArgument::raise( 'This restore has no eligible staged files.', [ 'code' => 'file_staging_gone' ] );
		}
		return $files;
	}


	/**
	 * Reads a required string field from job metadata.
	 *
	 * @param Job    $job Job.
	 * @param string $name Field.
	 * @return string Value.
	 * @throws InvalidArgument If the job lacks required metadata.
	 */
	public static function field( Job $job, string $name ): string {
		$value = $job->data[ $name ] ?? null;
		if ( ! is_string( $value ) ) {
			throw new InvalidArgument( 'The file change request is incomplete.' );
		}
		return $value;
	}


	/**
	 * Opens the ephemeral worker verification area. An independent apply preflight
	 * always checks current directories using a new workspace.
	 *
	 * @param Job $job Current preview, apply or undo.
	 * @return string Canonical verification directory.
	 */
	public function verification( Job $job ): string {
		$path = $this->jobs->directory( $job ) . '/verification';
		self::directory( $path );
		return $path;
	}

	/**
	 * Creates a private component below the job directory. A new directory is synchronised in
	 * its parent, so that the durable records written into it survive a power loss with it.
	 *
	 * @param string $dir Directory.
	 * @throws StorageFailed If the private journal directory is unavailable.
	 */
	public static function directory( string $dir ): void {
		if ( is_link( $dir ) ) {
			throw new StorageFailed( 'The apply journal directory is unavailable.' );
		}
		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0700 ) ) {
				throw new StorageFailed( 'The apply journal directory is unavailable.' );
			}
			Files::sync_directory( dirname( $dir ) );
		}
	}
}
