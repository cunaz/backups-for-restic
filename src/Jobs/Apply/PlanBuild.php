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
use BackupsForRestic\Restore\FileChange;
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
	 * @throws StorageFailed If the stored cursor is invalid.
	 */
	public function __construct( private readonly DiskFilePlan $disk, private readonly ApplyPlan $plan, private readonly string $files, array $data ) {
		$counts  = $data['counts'] ?? [];
		$offset  = $data['offset'] ?? 0;
		$changes = $data['changes'] ?? 0;
		$refused = $data['refused'] ?? 0;
		$pending = $data['pending'] ?? [];
		if ( ! is_array( $counts ) || ! is_int( $offset ) || $offset < 0 || ! is_int( $changes ) || ! is_int( $refused ) || ! is_array( $pending ) ) {
			throw new StorageFailed( 'The background file preview checkpoint is invalid.' );
		}
		foreach ( $counts as $name => $count ) {
			if ( ! is_string( $name ) || ! is_int( $count ) || $count < 0 || $count > DiskFilePlan::MAX_ENTRIES ) {
				throw new StorageFailed( 'The background file preview count is invalid.' );
			}
			$this->counts[ $name ] = $count;
		}
		$this->offset  = $offset;
		$this->changes = $changes;
		$this->refused = $refused;
		foreach ( $pending as $key => $value ) {
			if ( is_string( $key ) ) {
				$this->pending[ $key ] = $value;
			}
		}
	}

	/**
	 * Scans and hashes the next reported paths.
	 *
	 * @param resource $report Verified staged report, opened by Staging.
	 * @param int      $device Private journal device.
	 * @param float    $deadline Deadline.
	 * @return bool Whether the report is exhausted.
	 * @throws StorageFailed If the report or filesystem changes.
	 */
	public function apply( $report, int $device, float $deadline ): bool {
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
			if ( $this->disk->claim( 'source', $path, $index ) !== $index ) {
				throw new StorageFailed( 'The staged report contains a duplicate path.' );
			}
			$row = $this->entry( $entry, $device );
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
	 * Verifies the next attempted entries in reverse order. Every completed file action
	 * is bound to its original source, target, journal and this independent preview job.
	 *
	 * @param DiskFilePlan $forward Confirmed apply manifest.
	 * @param int          $attempted Number of attempted forward operations.
	 * @param string       $workspace Original apply journal directory.
	 * @param string       $scope Current preview job.
	 * @param float        $deadline Deadline.
	 * @return bool Finished.
	 */
	public function undo( DiskFilePlan $forward, int $attempted, string $workspace, string $scope, float $deadline ): bool {
		$done = $this->counts['rows'] ?? 0;
		while ( $done < $attempted ) {
			$item   = $forward->mutation( $attempted - $done - 1 );
			$row    = $item['row'];
			$source = Bytes::base64url_decode( $row['path'] );
			$target = Bytes::base64url_decode( $row['destination'] ?? '' );
			$change = new FileChange( $this->plan, $this->files, $workspace . '/item-' . $item['index'], $source, $scope );
			try {
				$action = $this->plan->destination( $source ) === $target ? $change->undo_action( $target, $row['action'], $deadline ) : 'refuse';
			} catch ( FileWorkPending ) {
				return false;
			}
			if ( 'remove' === $action ) {
				if ( 'mkdir' === $row['action'] ) {
					$covered = self::children_removable( $this->disk, $target, $deadline, $this->pending, false );
					if ( null === $covered ) {
						return false;
					}
					$this->pending = [];
					if ( ! $covered ) {
						$action = 'refuse';
					}
				}
				if ( 'remove' === $action ) {
					$this->disk->claim( 'remove', $target, $done );
				}
			}
			$row['action'] = $action;
			$row['reason'] = 'refuse' === $action ? 'changed_since_apply' : 'undo_journal';
			$this->disk->put( 'rows', $done, $row );
			$this->refused       += 'refuse' === $action ? 1 : 0;
			$this->changes       += in_array( $action, [ 'restore', 'remove', 'keep' ], true ) ? 1 : 0;
			$this->counts['rows'] = ++$done;
			if ( microtime( true ) >= $deadline ) {
				return $done === $attempted;
			}
		}
		return true;
	}

	/**
	 * Checks immediate children against their reviewed removals across bounded ticks.
	 * At apply time each name must refer back to its authenticated undo manifest row.
	 *
	 * @param DiskFilePlan        $disk Current undo preview.
	 * @param string              $target Created live directory.
	 * @param float               $deadline Worker deadline.
	 * @param array<string,mixed> $cursor Persisted position in the same directory.
	 * @param bool                $sealed Whether authenticated undo rows are available.
	 * @return bool|null Whether all children are covered, or null to yield.
	 * @throws StorageFailed If the directory cannot be enumerated consistently.
	 */
	public static function children_removable( DiskFilePlan $disk, string $target, float $deadline, array &$cursor, bool $sealed ): ?bool {
		$identity = ApplyManifest::identity( $target );
		$offset   = $cursor['offset'] ?? 0;
		if ( ! is_int( $offset ) || $offset < 0 || $identity !== ( $cursor['identity'] ?? $identity ) || realpath( $target ) !== $target || ! is_dir( $target ) ) {
			return false;
		}
		$ctime = filectime( $target );
		if ( false === $ctime ) {
			return false;
		}
		if ( $offset > 0 && true !== ( $cursor['stable'] ?? false ) ) {
			if ( time() <= $ctime ) {
				return null;
			}
			$offset = 0;
		}
		$stable  = time() > $ctime;
		$entries = new \DirectoryIterator( $target );
		if ( $offset > 0 ) {
			$entries->seek( $offset );
		}
		while ( $entries->valid() ) {
			if ( ! $entries->isDot() ) {
				$path  = $target . '/' . $entries->getFilename();
				$index = $disk->lookup( 'remove', $path );
				if ( null === $index ) {
					return false;
				}
				if ( $sealed ) {
					$row = $disk->row( $index );
					if ( 'remove' !== $row['action'] || Bytes::base64url_encode( $path ) !== $row['destination'] ) {
						return false;
					}
				}
			}
			$entries->next();
			++$offset;
			if ( microtime( true ) >= $deadline && $entries->valid() ) {
				if ( ApplyManifest::identity( $target ) !== $identity ) {
					return false;
				}
				$cursor = [
					'offset'   => $offset,
					'identity' => $identity,
					'stable'   => $stable,
				];
				return null;
			}
		}
		return ApplyManifest::identity( $target ) === $identity;
	}

	/**
	 * Flushes all records before exposing the resumable cursor to the runner.
	 *
	 * @return array<string,mixed> Checkpoint.
	 */
	public function checkpoint(): array {
		$this->disk->flush();
		return [
			'offset'  => $this->offset,
			'counts'  => $this->counts,
			'changes' => $this->changes,
			'refused' => $this->refused,
			'pending' => $this->pending,
		];
	}

	/**
	 * Computes one staged record without reading payload bytes.
	 *
	 * @param array{path:string,type:string,mode:int} $entry Restore metadata.
	 * @param int                                     $device Journal device.
	 * @return Row Candidate.
	 */
	private function entry( array $entry, int $device ): array {
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
		$action = $this->plan->inspect( $this->files, $path, true );
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
		$this->counts['rows'] = $index + 1;
		$this->refused       += 'refuse' === $row['action'] ? 1 : 0;
		if ( ! ApplyManifest::mutates( $row ) || null === $row['destination'] ) {
			return;
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
