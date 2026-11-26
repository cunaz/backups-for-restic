<?php
/**
 * The staging directories of finished restores.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\RestoreServices;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what restore jobs left in restores/ of the private directory (ADR 0037) and removes
 * it: one directory when an administrator asks, and whatever is due when the heartbeat
 * calls (ADR 0040).
 *
 * Staged files are plaintext copies of site data, so they do not stay. A staging directory
 * expires a number of hours after its job finished. A directory whose job did not succeed
 * is a leftover and goes at once: half a tree looks like a whole one. The directory of a
 * job that has not ended is never touched, and neither is one whose job document this
 * version cannot read, because nothing proves that such a job has ended.
 *
 * A removal first renames the directory to a name that the list ignores and deletes it
 * afterwards, until a deadline. What is left is deleted by a later call, so a large tree
 * never holds up a request, and nothing half removed is ever listed.
 */
final class Staging {

	/** The optional report from the database rehearsal. */
	public const string DATABASE_REPORT = 'database-preview.json';

	/** Starts the name of a staging directory whose removal has begun. No job ID starts so. */
	public const string REMOVING = '.removing-';

	/** Names read from a directory at a time while it is emptied, so that a huge one costs no memory. */
	private const int BATCH = 256;

	/**
	 * Uses the staging directories.
	 *
	 * @param string   $directory The private directory's restores/ folder.
	 * @param JobStore $jobs      Tells how the job of a directory ended.
	 * @param Clock    $clock     Time, for the expiry and for deadlines.
	 */
	public function __construct(
		private readonly string $directory,
		private readonly JobStore $jobs,
		private readonly Clock $clock
	) {
	}

	/**
	 * Serializes the start of a live file job with staging removal and expiry.
	 *
	 * @template T
	 * @param \Closure(): T $work Operation.
	 * @param-immediately-invoked-callable $work
	 * @return T Result.
	 */
	public function guard( \Closure $work ): mixed {
		return ( new FileLock( dirname( $this->directory ) . '/staging.lock' ) )->hold( $work, true );
	}

	/**
	 * The staging directories of restores that succeeded and have not expired, newest first.
	 * One that expired is not listed, even before a heartbeat came to remove it.
	 *
	 * @param int $hours Hours a staging directory is kept after its job finished.
	 * @return list<StagedRestore> Staging directories.
	 * @throws StorageFailed If restores/ cannot be listed.
	 */
	public function listing( int $hours ): array {
		return $this->survey( $hours )['kept'];
	}

	/**
	 * Locates the verified files/ directory of a complete, unexpired restore.
	 *
	 * @param string $id    Job ID.
	 * @param int    $hours Staging lifetime.
	 * @param bool   $retained Allow pinned files for an active file change or undo.
	 * @return string|null Canonical directory, or null when unavailable.
	 */
	public function files_directory( string $id, int $hours, bool $retained = false ): ?string {
		if ( null === $this->available( $id, $hours, $retained ) ) {
			return null;
		}
		$files = $this->directory . '/' . $id . '/files';
		$real  = realpath( $files );
		return false !== $real && ! is_link( $files ) && is_dir( $files ) ? $real : null;
	}

	/**
	 * Checks the same eligibility for listing, preview and download.
	 *
	 * @param string $id    Job ID.
	 * @param int    $hours Staging lifetime.
	 * @param bool   $retained Allow pinned files for an active file change or undo.
	 * @return StagedRestore|null A usable restore.
	 */
	private function available( string $id, int $hours, bool $retained = false ): ?StagedRestore {
		clearstatcache( true );
		if ( 1 !== preg_match( JobStore::ID_PATTERN, $id ) || is_link( $this->directory ) ) {
			return null;
		}
		$job = $this->jobs->get( $id );
		if ( null === $job && $this->jobs->has_document( $id ) ) {
			return null;
		}
		$staged = $this->describe( $id, $job, $hours );
		$now    = $this->clock->now()[0];
		return null !== $staged && ( $staged->expires > $now || ( $retained && isset( $this->jobs->pinned_restores( $now )[ $id ] ) ) ) ? $staged : null;
	}

	/**
	 * Opens one regular file in a successful, unexpired restore. The caller streams the
	 * handle already inspected here, even if the next heartbeat removes the directory.
	 *
	 * @param string $id    Restore job ID.
	 * @param int    $hours Configured staging lifetime.
	 * @param string $kind  file, report or archive.
	 * @param string $path  Absolute snapshot path, empty for a report or archive.
	 * @param bool   $retained Allow an active job to use pinned staging.
	 * @return resource|null Open handle, or null for anything unavailable.
	 */
	public function open_download( string $id, int $hours, string $kind, string $path, bool $retained = false ) {
		$staged = $this->available( $id, $hours, $retained );
		if ( null === $staged ) {
			return null;
		}
		$root = $this->directory . '/' . $id;
		$file = match ( $kind ) {
			'file'    => self::payload( $root, $path ),
			'report'  => '' === $path && null !== $staged->report ? $root . '/' . RestoreServices::REPORT : null,
			'archive' => '' === $path && $staged->archive ? $root . '/' . RestoreServices::ARCHIVE : null,
			'database_report' => '' === $path && $staged->database_report ? $root . '/' . self::DATABASE_REPORT : null,
			default   => null,
		};
		if ( null === $file || ! self::regular( $file ) ) {
			return null;
		}
		$before = lstat( $file );
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		$opened = fstat( $handle );
		if ( false === $before || false === $opened || 1 !== $opened['nlink'] || 0100000 !== ( $opened['mode'] & 0170000 ) || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino'] ) {
			fclose( $handle );
			return null;
		}
		return $handle;
	}

	/**
	 * Resolves one path through existing staging directories without following a link.
	 *
	 * @param string $root Job's staging directory.
	 * @param string $path Absolute snapshot path.
	 * @return string|null Regular staged file, if present.
	 */
	private static function payload( string $root, string $path ): ?string {
		if ( str_ends_with( $path, '/' ) ) {
			return null;
		}
		$file = $root . '/files';
		try {
			foreach ( Path::parts( $path ) as $part ) {
				Names::validate( $part );
				clearstatcache( true, $file );
				if ( is_link( $file ) || ! is_dir( $file ) ) {
					return null;
				}
				$file .= '/' . $part;
			}
		} catch ( FormatException | InvalidArgument ) {
			return null;
		}
		return self::regular( $file ) ? $file : null;
	}

	/**
	 * Checks the entry itself, including whether a second hard link exists.
	 *
	 * @param string $file File in staging.
	 * @return bool Whether this is an ordinary file with one name.
	 */
	private static function regular( string $file ): bool {
		clearstatcache( true, $file );
		$stat = is_file( $file ) && ! is_link( $file ) ? lstat( $file ) : false;
		return false !== $stat && 0100000 === ( $stat['mode'] & 0170000 ) && 1 === $stat['nlink'];
	}

	/**
	 * How many directories are on their way out: due and not yet set aside, or set aside and
	 * not yet deleted completely.
	 *
	 * @param int $hours Hours a staging directory is kept after its job finished.
	 * @return int Number of directories.
	 * @throws StorageFailed If restores/ cannot be listed.
	 */
	public function removing( int $hours ): int {
		$survey = $this->survey( $hours );
		return count( $survey['due'] ) + count( $survey['removing'] );
	}

	/**
	 * Removes the staging directory of one job, whatever became of the job, unless it is a
	 * restore that is still active. The directory leaves the list at once; deleting it may
	 * take longer than the deadline allows and is then finished by remove_due().
	 *
	 * @param string $id       Job ID.
	 * @param float  $deadline Unix time at which the deletion stops.
	 * @return bool|null True if the directory is gone, false if a part is left for a later call,
	 *                   null if nothing is staged for the job.
	 * @throws InvalidArgument With the code invalid_job_id if the ID is not one of a job.
	 * @throws JobConflict If the job is a restore that has not ended, or with the code
	 *                     job_unreadable or job_inaccessible if its document cannot be read
	 *                     (JobStore::refuse_unreadable()).
	 * @throws StorageFailed If the directory cannot be set aside or deleted.
	 */
	public function remove( string $id, float $deadline ): ?bool {
		if ( ! is_dir( dirname( $this->directory ) ) ) {
			if ( 1 !== preg_match( JobStore::ID_PATTERN, $id ) ) {
				InvalidArgument::raise( 'This is not the ID of a job.', [ 'code' => 'invalid_job_id' ] );
			}
			return null;
		}
		return $this->guard( fn (): ?bool => $this->remove_one( $id, $deadline ) );
	}

	/**
	 * Removes staging under the lifecycle lock.
	 *
	 * @param string $id Job ID.
	 * @param float  $deadline Deletion deadline.
	 * @return bool|null Removal result.
	 * @throws JobConflict If this staging belongs to an active change (`restore_in_use`) or to
	 *                     the undo window of an apply (`restore_kept_for_undo`, with the time at
	 *                     which the window ends as `until`).
	 */
	private function remove_one( string $id, float $deadline ): ?bool {
		$this->require_root();
		$pins = $this->jobs->restore_pins( $this->clock->now()[0] );
		if ( array_key_exists( $id, $pins ) ) {
			JobConflict::raise(
				'A restore operation still needs this staging for apply or undo.',
				null === $pins[ $id ] ? [ 'code' => 'restore_in_use' ] : [
					'code'  => 'restore_kept_for_undo',
					'until' => $pins[ $id ],
				]
			);
		}
		// The ID becomes part of a path: nothing but the form of a job ID gets that far.
		if ( 1 !== preg_match( JobStore::ID_PATTERN, $id ) ) {
			InvalidArgument::raise( 'This is not the ID of a job.', [ 'code' => 'invalid_job_id' ] );
		}
		$job = $this->jobs->get( $id );
		if ( null === $job && $this->jobs->has_document( $id ) ) {
			$this->jobs->refuse_unreadable( $id );
		}
		// Only a restore stages. An active job of another type has nothing here to wait for,
		// and the answer for it is the one for a job without staging.
		if ( null !== $job && JobType::Restore === $job->type && ! $job->state->is_terminal() ) {
			JobConflict::raise( 'The restore job has not ended.', [ 'job' => $id ] );
		}
		$aside = $this->directory . '/' . self::REMOVING . $id;
		if ( self::exists( $this->directory . '/' . $id ) ) {
			// What an earlier removal under this name left goes first: its place is needed.
			if ( ! $this->delete( $aside, $deadline ) ) {
				return false;
			}
			$this->set_aside( $id );
		} elseif ( ! self::exists( $aside ) ) {
			return null;
		}
		return $this->delete( $aside, $deadline );
	}

	/**
	 * Removes what is due: staging that expired, and leftovers, which are directories of
	 * jobs that did not succeed or that no job accounts for. For the heartbeat.
	 *
	 * Everything due is set aside first. That is one rename each, so it is done even when the
	 * time is short, and nothing that expired stays on the list. The deletion then runs
	 * until the deadline; what remains is continued by the next call. A directory that
	 * cannot be removed does not keep the others from going, and neither do the preview data
	 * or the undo workspace of a file job that cannot be removed.
	 *
	 * @param int   $hours    Hours a staging directory is kept after its job finished.
	 * @param float $deadline Unix time at which the deletion stops.
	 * @return array{removed: int, pending: int, failed: int} Staging directories deleted and left for a later call, and removals refused by the file system: of staging directories, preview data and undo workspaces.
	 * @throws StorageFailed If restores/ cannot be listed.
	 */
	public function remove_due( int $hours, float $deadline ): array {
		if ( ! is_dir( dirname( $this->directory ) ) ) {
			return [
				'removed' => 0,
				'pending' => 0,
				'failed'  => 0,
			];
		}
		return $this->guard( fn (): array => $this->expire( $hours, $deadline ) );
	}

	/**
	 * Removes a background preview's private data after cancellation.
	 *
	 * @param string $restore Source restore.
	 * @param string $plan Preview job.
	 * @param float  $deadline Deletion deadline.
	 * @return bool Finished.
	 */
	public function remove_plan( string $restore, string $plan, float $deadline ): bool {
		return $this->guard( fn (): bool => $this->delete_plan( $restore, $plan, $deadline ) );
	}

	/**
	 * Discards a finished worker's temporary name and content verification indexes.
	 *
	 * @param Job   $job Preview, apply or undo owner.
	 * @param float $deadline Current cleanup deadline.
	 * @return bool Whether the private workspace is gone.
	 */
	public function remove_verification( Job $job, float $deadline ): bool {
		return $this->delete( $this->jobs->directory( $job ) . '/verification', $deadline );
	}

	/**
	 * Removes only a plan directory whose complete parent chain remains canonical.
	 *
	 * @param string $restore Restore ID.
	 * @param string $plan Preview ID.
	 * @param float  $deadline Worker deadline.
	 * @return bool Finished.
	 * @throws StorageFailed If a component is ambiguous.
	 */
	private function delete_plan( string $restore, string $plan, float $deadline ): bool {
		if ( 1 !== preg_match( JobStore::ID_PATTERN, $restore ) || 1 !== preg_match( JobStore::ID_PATTERN, $plan ) ) {
			throw new StorageFailed( 'The preview has an invalid storage identity.' );
		}
		$parent = $this->directory . '/' . $restore . '/plans';
		if ( ! self::exists( $parent ) ) {
			return true;
		}
		if ( realpath( $parent ) !== $parent || ! is_dir( $parent ) ) {
			throw new StorageFailed( 'The preview storage parent is not canonical.' );
		}
		return $this->delete( $parent . '/' . $plan, $deadline );
	}

	/**
	 * Deletes due staging and expired undo workspaces under the lifecycle lock.
	 *
	 * The preview data and undo workspaces of file jobs come first, one job at a time. One that
	 * the file system refuses to remove, or whose job cannot be saved afterwards, is counted as
	 * failed and tried again by the next call; it never keeps the staging of other restores
	 * from expiring.
	 *
	 * @param int   $hours Staging lifetime.
	 * @param float $deadline Deletion deadline.
	 * @return array{removed:int,pending:int,failed:int} Staging deletion summary.
	 */
	private function expire( int $hours, float $deadline ): array {
		$active       = $this->jobs->active();
		$now          = $this->clock->now()[0];
		$pinned_plans = $this->jobs->pinned_file_plans( $now );
		$failed       = 0;
		foreach ( $this->jobs->file_changes() as $job ) {
			if ( $this->late( $deadline ) ) {
				break;
			}
			try {
				if ( JobType::FilePlan === $job->type && $job->state->is_terminal() && isset( $job->data['plan_retained'] ) && $job->finished_at + 3600 <= $now && ! isset( $pinned_plans[ $job->id ] ) && is_string( $job->data['restore'] ?? null ) ) {
					if ( $this->delete_plan( $job->data['restore'], $job->id, $deadline ) ) {
						unset( $job->data['plan_retained'] );
						$this->jobs->save( $job );
					}
				}
			} catch ( PluginException ) {
				++$failed;
			}
			if ( JobType::Apply !== $job->type || ! $job->state->is_terminal() || ( JobType::Undo === $active?->type && $job->id === ( $active->data['apply'] ?? null ) ) || ( JobType::FilePlan === $active?->type && true === ( $active->data['undo'] ?? false ) && $job->id === ( $active->data['subject'] ?? null ) ) ) {
				continue;
			}
			try {
				$undo = $this->jobs->directory( $job ) . '/undo';
				// Only an ended window, or records that establish that none is open, let the journal go.
				if ( $job->finished_at + 86400 <= $this->clock->now()[0] || false === $this->jobs->undo_open( $job ) ) {
					$this->delete( $undo, $deadline );
				}
			} catch ( PluginException ) {
				++$failed;
			}
		}
		$survey  = $this->survey( $hours );
		$removed = 0;
		$pending = 0;
		$aside   = $survey['removing'];
		foreach ( $survey['due'] as $id ) {
			if ( in_array( self::REMOVING . $id, $aside, true ) ) {
				// An earlier removal under this name is deleted below; then this one can follow.
				++$pending;
				continue;
			}
			try {
				$this->set_aside( $id );
				$aside[] = self::REMOVING . $id;
			} catch ( StorageFailed ) {
				++$failed;
			}
		}
		foreach ( $aside as $name ) {
			try {
				if ( $this->delete( $this->directory . '/' . $name, $deadline ) ) {
					++$removed;
				} else {
					++$pending;
				}
			} catch ( StorageFailed ) {
				++$failed;
			}
		}
		return [
			'removed' => $removed,
			'pending' => $pending,
			'failed'  => $failed,
		];
	}

	/**
	 * Looks once at every entry of restores/ and sorts it: kept and listed, due for removal,
	 * or set aside by a removal that has begun. Entries of active and unreadable jobs are in
	 * none of the three, and neither are names that are not those of jobs: they are not this
	 * class's to judge.
	 *
	 * @param int $hours Hours a staging directory is kept after its job finished.
	 * @return array{kept: list<StagedRestore>, due: list<string>, removing: list<string>} Listed directories, job IDs of due ones, and names of those set aside.
	 * @throws StorageFailed If restores/ cannot be listed.
	 */
	private function survey( int $hours ): array {
		$kept     = [];
		$due      = [];
		$removing = [];
		$now      = $this->clock->now()[0];
		$pins     = $this->jobs->pinned_restores( $now );
		foreach ( $this->entries() as $name ) {
			if ( str_starts_with( $name, self::REMOVING ) && 1 === preg_match( JobStore::ID_PATTERN, substr( $name, strlen( self::REMOVING ) ) ) ) {
				$removing[] = $name;
				continue;
			}
			if ( 1 !== preg_match( JobStore::ID_PATTERN, $name ) ) {
				continue;
			}
			$job = $this->jobs->get( $name );
			if ( null === $job ? $this->jobs->has_document( $name ) || self::undecided( $this->directory . '/' . $name ) : ! $job->state->is_terminal() ) {
				continue;
			}
			$staged = $this->describe( $name, $job, $hours );
			if ( null === $staged || $now >= $staged->expires ) {
				if ( ! isset( $pins[ $name ] ) ) {
					$due[] = $name;
				}
			} else {
				$kept[] = $staged;
			}
		}
		return [
			'kept'     => $kept,
			'due'      => $due,
			'removing' => $removing,
		];
	}

	/**
	 * Describes the staging directory of a job that has ended, or finds it to be a leftover.
	 *
	 * Without a job document, pruned with the old jobs, the report tells the two apart: a job
	 * moves it beside the files only when every file is staged. The time the job finished is
	 * unknown then, and the expiry counts from the time in the job ID, when the job was
	 * created. That is earlier, so such a directory never stays longer than a known one.
	 *
	 * @param string   $id    Job ID.
	 * @param Job|null $job   The job, or null if its document is gone.
	 * @param int      $hours Hours a staging directory is kept after its job finished.
	 * @return StagedRestore|null The staging, or null for a leftover.
	 */
	private function describe( string $id, ?Job $job, int $hours ): ?StagedRestore {
		$path = $this->directory . '/' . $id;
		// A link or a file under the name of a job is no staging, whatever the job says.
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return null;
		}
		$staging         = basename( $this->directory ) . '/' . $id;
		$file            = $path . '/' . RestoreServices::REPORT;
		$database_report = self::regular( $path . '/' . self::DATABASE_REPORT );
		$archive         = self::regular( $path . '/' . RestoreServices::ARCHIVE );
		$report          = self::regular( $file ) ? $staging . '/' . RestoreServices::REPORT : null;
		$lifetime        = 3600 * max( 1, $hours );
		if ( null === $job ) {
			return null === $report ? null : new StagedRestore( $id, null, null, self::created( $id ) + $lifetime, null, null, $staging, $report, $archive, null, $database_report );
		}
		if ( JobType::Restore !== $job->type || JobState::Succeeded !== $job->state ) {
			return null;
		}
		$finished = $job->finished_at > 0 ? $job->finished_at : null;
		$snapshot = $job->result['snapshot'] ?? null;
		$paths    = $job->data['paths'] ?? null;
		$selected = is_array( $paths ) && 1 === count( $paths ) && is_string( $paths[0] ?? null ) ? $paths[0] : null;
		return new StagedRestore(
			$id,
			is_string( $snapshot ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $snapshot ) ? $snapshot : null,
			$finished,
			( $finished ?? self::created( $id ) ) + $lifetime,
			self::count( $job->result['files'] ?? null ),
			self::count( $job->result['bytes'] ?? null ),
			$staging,
			$report,
			$archive,
			null !== $selected && null !== self::payload( $path, $selected ) ? $selected : null,
			$database_report,
			true === ( $job->data['fresh_install'] ?? false )
		);
	}

	/**
	 * Whether the staging directory of a job whose document is gone cannot be told from a
	 * leftover: a directory in which its report can neither be found as a file nor be missing
	 * (Files::marker()), because this process cannot search it, or because something other than
	 * a file has the report's name. Such a directory is not touched, as one whose job document
	 * cannot be read; before, it was removed at once as a leftover without a report.
	 *
	 * @param string $path Staging directory.
	 * @return bool Whether its report can neither be found nor be missing.
	 */
	private static function undecided( string $path ): bool {
		clearstatcache( true, $path );
		return ! is_link( $path ) && is_dir( $path ) && null === Files::marker( $path . '/' . RestoreServices::REPORT );
	}

	/**
	 * A count from a job result.
	 *
	 * @param int|string|bool|null $value Value of the result.
	 * @return int|null The count, or null if the result holds none.
	 */
	private static function count( int|string|bool|null $value ): ?int {
		return is_int( $value ) && $value >= 0 ? $value : null;
	}

	/**
	 * The time a job was created, which its ID starts with.
	 *
	 * @param string $id Job ID.
	 * @return int Unix time; 0 if the ID names no time.
	 */
	private static function created( string $id ): int {
		$time = gmmktime( (int) substr( $id, 9, 2 ), (int) substr( $id, 11, 2 ), (int) substr( $id, 13, 2 ), (int) substr( $id, 4, 2 ), (int) substr( $id, 6, 2 ), (int) substr( $id, 0, 4 ) );
		return false === $time ? 0 : $time;
	}

	/**
	 * Renames the staging directory of a job to the name under which it is deleted. From
	 * then on no list shows it.
	 *
	 * @param string $id Job ID.
	 * @throws StorageFailed If the directory is still there under its name afterwards.
	 */
	private function set_aside( string $id ): void {
		$path = $this->directory . '/' . $id;
		// Another request may have set it aside in the meantime: that is as good.
		if ( ! rename( $path, $this->directory . '/' . self::REMOVING . $id ) && self::exists( $path ) ) {
			StorageFailed::raise( 'A staging directory could not be set aside for its removal.', [ 'job' => $id ] );
		}
	}

	/**
	 * Deletes a directory with everything in it, until the deadline. A symbolic link is
	 * removed as a link and never followed. An entry that another request removed meanwhile
	 * counts as removed.
	 *
	 * No name of an entry goes into an error: the names come from a snapshot.
	 *
	 * @param string $path     Directory, file or link.
	 * @param float  $deadline Unix time at which the deletion stops.
	 * @return bool True if nothing is left, false if the deadline came first.
	 * @throws StorageFailed If an entry cannot be listed or removed.
	 */
	private function delete( string $path, float $deadline ): bool {
		if ( ! self::exists( $path ) ) {
			return true;
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( ! unlink( $path ) && self::exists( $path ) ) {
				StorageFailed::raise( 'An entry of a staging directory could not be removed.' );
			}
			return true;
		}
		do {
			$names = self::names( $path );
			foreach ( $names as $name ) {
				if ( $this->late( $deadline ) || ! $this->delete( $path . '/' . $name, $deadline ) ) {
					return false;
				}
			}
		} while ( [] !== $names );
		if ( ! rmdir( $path ) && self::exists( $path ) ) {
			StorageFailed::raise( 'A directory of a staging directory could not be removed.' );
		}
		return true;
	}

	/**
	 * Some names of a directory's entries: as many as are deleted before the directory is
	 * read again.
	 *
	 * @param string $directory Directory.
	 * @return list<string> Names, without the two dot entries; empty if the directory is empty or gone.
	 * @throws StorageFailed If the directory cannot be read.
	 */
	private static function names( string $directory ): array {
		$handle = opendir( $directory );
		if ( false === $handle ) {
			if ( self::exists( $directory ) ) {
				StorageFailed::raise( 'A directory of a staging directory could not be listed.' );
			}
			return [];
		}
		$names = [];
		$room  = self::BATCH;
		$name  = readdir( $handle );
		while ( false !== $name && $room > 0 ) {
			if ( '.' !== $name && '..' !== $name ) {
				$names[] = $name;
				--$room;
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		return $names;
	}

	/**
	 * Checks the staging root before listing or removing names beneath it.
	 *
	 * @throws StorageFailed If the root has become a link.
	 */
	private function require_root(): void {
		clearstatcache( true, $this->directory );
		if ( is_link( $this->directory ) ) {
			StorageFailed::raise( 'The staging root is a symbolic link.' );
		}
	}

	/**
	 * The names in restores/, newest job first.
	 *
	 * @return list<string> Names without the two dot entries.
	 * @throws StorageFailed If the directory cannot be listed.
	 */
	private function entries(): array {
		$this->require_root();
		if ( ! is_dir( $this->directory ) ) {
			return [];
		}
		$entries = scandir( $this->directory, SCANDIR_SORT_DESCENDING );
		if ( false === $entries ) {
			StorageFailed::raise( 'The staging directories could not be listed.' );
		}
		return array_values( array_filter( $entries, static fn ( string $entry ): bool => '.' !== $entry && '..' !== $entry ) );
	}

	/**
	 * Whether a path names anything, a dangling link included. Other requests remove
	 * entries too, so PHP's stat cache is not asked.
	 *
	 * @param string $path Path.
	 * @return bool True if there is an entry of that name.
	 * @phpstan-impure Reads the file system, which this class and other requests change.
	 */
	private static function exists( string $path ): bool {
		clearstatcache( true, $path );
		return is_link( $path ) || file_exists( $path );
	}

	/**
	 * Whether the deadline has come.
	 *
	 * @param float $deadline Unix time.
	 * @return bool True if no time is left.
	 * @phpstan-impure Reads the advancing clock.
	 */
	private function late( float $deadline ): bool {
		[ $seconds, $nanoseconds ] = $this->clock->now();
		return $seconds + $nanoseconds / 1000000000 >= $deadline;
	}
}
