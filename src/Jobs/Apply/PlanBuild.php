<?php
/**
 * Incrementally builds a disk-backed apply or undo preview.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\ChunkDigest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\FileChange;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\FileWorkPending;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * The trusted staging report is the inventory. Unreported files can never become live
 * candidates. The durable cursor moves only after all rows and indexes are flushed.
 *
 * @phpstan-import-type Row from ApplyManifest
 */
final class PlanBuild {

	/**
	 * Number of committed records per stream.
	 *
	 * @var array<string,int>
	 */
	public array $counts = [];

	/**
	 * Cursor in the published report.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * Number of applicable file operations.
	 *
	 * @var int
	 */
	public int $changes = 0;

	/**
	 * Number of refused entries.
	 *
	 * @var int
	 */
	public int $refused = 0;

	/**
	 * Rows by action: of an undo review restore, remove and keep; of an apply preview create,
	 * replace, mkdir, keep, skip and refuse. An apply preview resumed from a checkpoint of a
	 * version that did not count them counts fewer than its rows.
	 *
	 * @var array<string,int>
	 */
	public array $actions = [];

	/**
	 * Rows of an undo review kept with nothing left to revert.
	 *
	 * @var int
	 */
	public int $settled = 0;

	/**
	 * Conservative data allocation, or null for a plan started by an older version.
	 *
	 * @var int|null
	 */
	public ?int $required_bytes;

	/**
	 * Hash progress within the current report line.
	 *
	 * @var array<string,mixed>
	 */
	private array $pending = [];

	/**
	 * Reconstructs a bounded build checkpoint.
	 *
	 * @param DiskFilePlan        $disk Private output.
	 * @param ApplyPlan           $plan Current site policy.
	 * @param string              $files Canonical staging files.
	 * @param array<string,mixed> $data Worker state.
	 * @param FileSpace           $space Destination volume.
	 * @throws StorageFailed If the stored cursor is invalid.
	 */
	public function __construct( private readonly DiskFilePlan $disk, private readonly ApplyPlan $plan, private readonly string $files, array $data, private readonly FileSpace $space ) {
		$counts  = $data['counts'] ?? [];
		$offset  = $data['offset'] ?? 0;
		$changes = $data['changes'] ?? 0;
		$refused = $data['refused'] ?? 0;
		$pending = $data['pending'] ?? [];
		$actions = $data['actions'] ?? [];
		$settled = $data['settled'] ?? 0;
		if ( ! is_array( $counts ) || ! is_int( $offset ) || $offset < 0 || ! is_int( $changes ) || ! is_int( $refused ) || ! is_array( $pending ) || ! is_array( $actions ) || ! is_int( $settled ) || $settled < 0 ) {
			throw new StorageFailed( 'The background file preview checkpoint is invalid.' );
		}
		$this->counts  = self::tally( $counts );
		$this->actions = self::tally( $actions );
		$required      = $data['required_bytes'] ?? ( 0 === $offset ? 0 : null );
		if ( null !== $required && ( ! is_int( $required ) || $required < 0 ) ) {
			throw new StorageFailed( 'The background file preview allocation total is invalid.' );
		}
		$this->required_bytes = $required;
		$this->offset         = $offset;
		$this->changes        = $changes;
		$this->refused        = $refused;
		$this->settled        = $settled;
		foreach ( $pending as $key => $value ) {
			if ( is_string( $key ) ) {
				$this->pending[ $key ] = $value;
			}
		}
	}

	/**
	 * Reads stored counts by name.
	 *
	 * @param array<mixed> $map Decoded counts.
	 * @return array<string,int> Counts.
	 * @throws StorageFailed If a count is invalid.
	 */
	private static function tally( array $map ): array {
		$counts = [];
		foreach ( $map as $name => $count ) {
			if ( ! is_string( $name ) || ! is_int( $count ) || $count < 0 || $count > DiskFilePlan::MAX_ENTRIES ) {
				throw new StorageFailed( 'The background file preview count is invalid.' );
			}
			$counts[ $name ] = $count;
		}
		return $counts;
	}

	/**
	 * Scans and hashes the next reported paths.
	 *
	 * @param resource           $report Verified staged report, opened by Staging.
	 * @param int                $device Private journal device.
	 * @param float              $deadline Deadline.
	 * @param DirectorySpellings $spellings Private disk index for wide directories.
	 * @return bool Whether the report is exhausted.
	 * @throws StorageFailed If the report or filesystem changes.
	 */
	public function apply( $report, int $device, float $deadline, DirectorySpellings $spellings ): bool {
		$stat = fstat( $report );
		if ( false === $stat || $this->offset > $stat['size'] || 0 !== fseek( $report, $this->offset ) ) {
			throw new StorageFailed( 'The restore report cursor is outside the file.' );
		}
		foreach ( ApplyManifest::report( $report ) as $entry ) {
			$path = $entry['path'];
			if ( strlen( $path ) > 4095 ) {
				throw new StorageFailed( 'A file preview path exceeds the supported filesystem length.' );
			}
			$index = $this->counts['rows'] ?? 0;
			if ( $index >= DiskFilePlan::MAX_ENTRIES ) {
				InvalidArgument::raise( 'The file preview exceeds its limit of one million selected entries.', [ 'code' => 'file_plan_too_large' ] );
			}
			if ( $this->disk->claim( 'source', $path, $index ) !== $index ) {
				throw new StorageFailed( 'The staged report contains a duplicate path.' );
			}
			try {
				$row = $this->entry( $entry, $device, $spellings );
			} catch ( FileWorkPending ) {
				return false;
			}
			if ( ApplyManifest::mutates( $row ) && 'mkdir' !== $row['action'] ) {
				$signature = hash( 'sha256', json_encode( $row, JSON_THROW_ON_ERROR ) );
				if ( $signature !== ( $this->pending['signature'] ?? $signature ) ) {
					throw new StorageFailed( 'A file changed while its background preview was being prepared.' );
				}
				$this->pending['signature'] = $signature;
				$row['source']              = $this->digest( 'source', $this->files . $path, $deadline );
				if ( null === $row['source'] ) {
					return false;
				}
				if ( 'replace' === $row['action'] && null !== $row['destination'] ) {
					$row['original'] = $this->digest( 'original', Bytes::base64url_decode( $row['destination'] ), $deadline );
					if ( null === $row['original'] ) {
						return false;
					}
					if ( $row['source'] === $row['original'] ) {
						$row['action'] = 'keep';
						$row['reason'] = 'identical_contents';
					}
				}
			}
			$this->append( $row );
			$offset = ftell( $report );
			if ( false === $offset || $offset <= $this->offset ) {
				throw new StorageFailed( 'The background preview report cursor did not advance.' );
			}
			$this->offset  = $offset;
			$this->pending = [];
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Reviews the next attempted entries in reverse order. Every entry gets its own decision
	 * and reason: an entry that changed since the apply is kept, and the others are still
	 * reverted. A created directory is removed only if each current child is a removal
	 * earlier in this review. Every completed file decision is bound to its original source,
	 * target, journal and this independent preview job.
	 *
	 * @param DiskFilePlan       $forward Confirmed apply manifest.
	 * @param int                $attempted Number of attempted forward operations.
	 * @param string             $workspace Original apply journal directory.
	 * @param string             $scope Current preview job.
	 * @param float              $deadline Deadline.
	 * @param DirectorySpellings $spellings Private disk index for wide directories.
	 * @return bool Finished.
	 */
	public function undo( DiskFilePlan $forward, int $attempted, string $workspace, string $scope, float $deadline, DirectorySpellings $spellings ): bool {
		$done = $this->counts['rows'] ?? 0;
		if ( array_sum( $this->actions ) !== $done ) {
			// A checkpoint from before the totals counted kept entries as changes, or refused
			// them: review again. Each row reviewed again replaces its claim of a removal.
			$done                 = 0;
			$this->changes        = 0;
			$this->refused        = 0;
			$this->settled        = 0;
			$this->actions        = [];
			$this->pending        = [];
			$this->counts['rows'] = 0;
		}
		while ( $done < $attempted ) {
			$item   = $forward->mutation( $attempted - $done - 1 );
			$row    = $item['row'];
			$source = Bytes::base64url_decode( $row['path'] );
			$target = Bytes::base64url_decode( $row['destination'] ?? '' );
			$change = new FileChange( $this->plan, $this->files, $workspace . '/item-' . $item['index'], $source, $scope );
			try {
				$decision = $this->plan->maps( $source, $target ) ? $change->undo_review( $target, $row['action'], $deadline, $spellings ) : [
					'action' => 'keep',
					'reason' => 'destination_changed',
				];
			} catch ( FileWorkPending ) {
				return false;
			}
			if ( 'remove' === $decision['action'] && 'mkdir' === $row['action'] ) {
				$covered = self::children_removable( $this->disk, $target, $deadline, $this->pending );
				if ( null === $covered ) {
					return false;
				}
				if ( true !== $covered ) {
					$decision = [
						'action' => 'keep',
						'reason' => $covered,
					];
				}
			}
			if ( 'remove' === $decision['action'] ) {
				$this->disk->claim( 'remove', $target, $done );
			} else {
				// The row may be reviewed again after a checkpoint that a stopped tick did not
				// record, or after a reset: a removal that it claimed then no longer stands.
				$this->disk->release( 'remove', $target, $done );
			}
			$row['action'] = $decision['action'];
			$row['reason'] = $decision['reason'];
			$this->disk->put( 'rows', $done, $row );
			// A position in a folder's names belongs to the row that yielded in it, also when
			// that row is decided otherwise once it is reviewed again.
			$this->pending                        = [];
			$this->changes                       += 'keep' === $decision['action'] ? 0 : 1;
			$this->settled                       += 'keep' === $decision['action'] && FileChange::settled( $decision['reason'] ) ? 1 : 0;
			$this->actions[ $decision['action'] ] = ( $this->actions[ $decision['action'] ] ?? 0 ) + 1;
			$this->counts['rows']                 = ++$done;
			if ( microtime( true ) >= $deadline ) {
				return $done === $attempted;
			}
		}
		return true;
	}

	/**
	 * Checks immediate children against their reviewed removals across bounded ticks. A child
	 * counts only while its row's claim of a removal stands: the review withdraws the claim of
	 * a row that it reviews again with another decision, and children are reviewed before
	 * their directory.
	 *
	 * What is found about the directory keeps that directory with a reason, and the review
	 * goes on with the next entry: a name that is not a reviewed removal, or a directory that
	 * changed while it was read, keeps it as `new_children`, and one that is gone or cannot
	 * be read since its own decision keeps it as changed, as the worker's last check does.
	 *
	 * @param DiskFilePlan        $disk Current undo preview.
	 * @param string              $target Created live directory.
	 * @param float               $deadline Worker deadline.
	 * @param array<string,mixed> $cursor Persisted position in the same directory.
	 * @return true|string|null True when every child is covered, the reason to keep the
	 *                          directory otherwise, or null to yield.
	 * @throws StorageFailed If the claims of this review cannot be read.
	 */
	public static function children_removable( DiskFilePlan $disk, string $target, float $deadline, array &$cursor ): true|string|null {
		$identity = self::folder_identity( $target );
		if ( null === $identity ) {
			return 'changed_since_apply';
		}
		$offset = $cursor['offset'] ?? 0;
		if ( ! is_int( $offset ) || $offset < 0 || $identity !== ( $cursor['identity'] ?? $identity ) || realpath( $target ) !== $target || ! is_dir( $target ) ) {
			return 'new_children';
		}
		$ctime = filectime( $target );
		if ( false === $ctime ) {
			return 'changed_since_apply';
		}
		if ( $offset > 0 && true !== ( $cursor['stable'] ?? false ) ) {
			if ( time() <= $ctime ) {
				return null;
			}
			$offset = 0;
		}
		// Earlier checkpoints stored only an offset. Rebuild their short-lived
		// directory proof from the beginning before accepting a boundary.
		if ( $offset > 0 && ! is_string( $cursor['last'] ?? null ) ) {
			$offset = 0;
		}
		$stable = time() > $ctime;
		try {
			$entries = new \DirectoryIterator( $target );
		} catch ( \UnexpectedValueException ) {
			return 'changed_since_apply';
		}
		if ( $offset > 0 ) {
			try {
				$entries->seek( $offset - 1 );
			} catch ( \OutOfBoundsException ) {
				// Names left the directory without a change of its identity, as where a file
				// system keeps coarse times: the boundary of the last tick is gone.
				return 'new_children';
			}
			if ( ! $entries->valid() || hash( 'sha256', $entries->getFilename() ) !== ( $cursor['last'] ?? null ) ) {
				return 'new_children';
			}
			$entries->next();
		}
		while ( $entries->valid() ) {
			if ( ! $entries->isDot() ) {
				if ( null === $disk->lookup( 'remove', $target . '/' . $entries->getFilename() ) ) {
					return 'new_children';
				}
			}
			$last = $entries->getFilename();
			$entries->next();
			++$offset;
			if ( microtime( true ) >= $deadline && $entries->valid() ) {
				$same = self::same_folder( $target, $identity );
				if ( true !== $same ) {
					return $same;
				}
				$cursor = [
					'offset'   => $offset,
					'identity' => $identity,
					'stable'   => $stable,
					'last'     => hash( 'sha256', $last ),
				];
				return null;
			}
		}
		return self::same_folder( $target, $identity );
	}

	/**
	 * The identity of a live directory, or null when it is gone or cannot be looked up.
	 *
	 * @param string $target Live directory.
	 * @return string|null Identity.
	 */
	private static function folder_identity( string $target ): ?string {
		try {
			return ApplyManifest::identity( $target );
		} catch ( StorageFailed ) {
			return null;
		}
	}

	/**
	 * Whether a directory still has the identity with which its reading began.
	 *
	 * @param string $target Live directory.
	 * @param string $identity Identity before the reading.
	 * @return true|string True, or the reason to keep the directory.
	 */
	private static function same_folder( string $target, string $identity ): true|string {
		$now = self::folder_identity( $target );
		if ( $now === $identity ) {
			return true;
		}
		return null === $now ? 'changed_since_apply' : 'new_children';
	}

	/**
	 * Flushes all records before exposing the resumable cursor to the runner.
	 *
	 * @return array<string,mixed> Checkpoint.
	 */
	public function checkpoint(): array {
		$this->disk->flush();
		return [
			'offset'         => $this->offset,
			'counts'         => $this->counts,
			'changes'        => $this->changes,
			'refused'        => $this->refused,
			'pending'        => $this->pending,
			'required_bytes' => $this->required_bytes,
			'actions'        => $this->actions,
			'settled'        => $this->settled,
		];
	}

	/**
	 * Computes one staged record without reading payload bytes.
	 *
	 * @param array{path:string,type:string,mode:int} $entry Restore metadata.
	 * @param int                                     $device Journal device.
	 * @param DirectorySpellings                      $spellings Private lookup for wide folders.
	 * @return Row Candidate.
	 */
	private function entry( array $entry, int $device, DirectorySpellings $spellings ): array {
		$path = $entry['path'];
		$key  = Bytes::base64url_encode( $path );
		if ( ! in_array( $entry['type'], [ 'file', 'dir' ], true ) ) {
			try {
				$target = $this->plan->destination( $path );
			} catch ( InvalidArgument ) {
				$target = null;
			}
			return ApplyManifest::row( $key, $target, 'skip', 'unstaged_special_entry' );
		}
		$action = $this->plan->inspect( $this->files, $path, $spellings );
		$target = $action['destination'];
		$row    = ApplyManifest::row( $key, $target, $action['action'], $action['reason'] );
		if ( null !== $target && 'skip' !== $row['action'] ) {
			$index = $this->counts['rows'] ?? 0;
			if ( $this->disk->claim( 'target', ApplyPlan::fold( $target ), $index ) !== $index ) {
				return ApplyManifest::row( $key, $target, 'refuse', 'overlapping_destinations' );
			}
		}
		if ( ! ApplyManifest::mutates( $row ) || null === $target ) {
			return $row;
		}
		if ( ( 'mkdir' === $row['action'] ? 'dir' : 'file' ) !== $entry['type'] ) {
			return ApplyManifest::row( $key, $target, 'refuse', 'staged_type_changed' );
		}
		$row['mode']   = $entry['mode'] & 0777;
		$row['parent'] = ApplyManifest::parent_identity( $target );
		if ( (string) $device !== explode( ':', $row['parent'] )[1] ) {
			return ApplyManifest::row( $key, $target, 'refuse', 'different_filesystem' );
		}
		$row['identity'] = ApplyManifest::identity( $this->files . $path );
		if ( 'replace' === $row['action'] ) {
			$row['identity'] .= '/' . ApplyManifest::identity( $target );
		}
		return $row;
	}

	/**
	 * Resumes the staged or live file within the current row.
	 *
	 * @param string $side Source or original.
	 * @param string $path Checked path.
	 * @param float  $deadline Deadline.
	 * @return string|null Completed digest or null to yield.
	 */
	private function digest( string $side, string $path, float $deadline ): ?string {
		$cached = $this->pending[ $side . '_hash' ] ?? null;
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$cursor = [];
		$stored = $this->pending[ $side ] ?? null;
		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $value ) {
				if ( is_string( $key ) ) {
					$cursor[ $key ] = $value;
				}
			}
		}
		$hash                             = ChunkDigest::advance( $path, $deadline, $cursor );
		$this->pending[ $side ]           = $cursor;
		$this->pending[ $side . '_hash' ] = $hash;
		return $hash;
	}

	/**
	 * Adds the presentation row and its ordered mutation reference, if any.
	 *
	 * @param Row $row Completed row.
	 */
	private function append( array $row ): void {
		$index = $this->counts['rows'] ?? 0;
		$this->disk->put( 'rows', $index, $row );
		$this->counts['rows']            = $index + 1;
		$this->refused                  += 'refuse' === $row['action'] ? 1 : 0;
		$this->actions[ $row['action'] ] = ( $this->actions[ $row['action'] ] ?? 0 ) + 1;
		if ( ! ApplyManifest::mutates( $row ) || null === $row['destination'] ) {
			return;
		}
		if ( null !== $this->required_bytes ) {
			$this->required_bytes = FileSpace::add( $this->required_bytes, $this->space->row( $row ) );
		}
		$target  = Bytes::base64url_decode( $row['destination'] );
		$stream  = sprintf( 'd%03d', substr_count( $target, '/' ) );
		$ordinal = $this->counts[ $stream ] ?? 0;
		$this->disk->put( $stream, $ordinal, [ 'row' => $index ] );
		$this->counts[ $stream ] = $ordinal + 1;
		if ( 'mkdir' === $row['action'] ) {
			$this->disk->claim( 'mkdir', $target, $index );
		}
		++$this->changes;
	}
}
