<?php
/**
 * Applies or reverses one reviewed change under the file job's exclusive lock.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Source and preimage copies stay in a private directory on the destination filesystem.
 * A durable intent precedes publication. On retry, the recorded inode identifies a rename
 * that completed before its caller could checkpoint. An undo reverts an entry only while it
 * still is what the apply left there and keeps it, with its journal, otherwise.
 * The caller serializes writers and pins staging and the item directory until undo expires.
 *
 * The undo decides entry by entry (ADR 0041). Whatever can be known about one entry keeps
 * that entry with a reason, and the undo goes on with the next one: an entry that changed,
 * is gone or cannot be read, a parent that is not the recorded folder or that the worker
 * may not change, a destination that the site no longer maps to it, a policy refusal, a
 * name that arrives after the last check. Only three things stop an undo: a journal record
 * that is damaged or cannot be looked up, a write of the plugin's own records or private
 * copies that fails, and a refusal of the file system that no check could foresee, such
 * as a rename or unlink refused after every check passed. The reviews, the worker's last
 * check and the cleanup of a stopped undo read an entry by the same rule.
 *
 * Every journal record is synchronised to disk before the live change it authorises, and a
 * private copy before the record that names it. A live publication or removal is
 * synchronised in its directory before the record that marks it complete, so a power loss
 * leaves a state that the retry or the undo recognises, as a killed process does.
 *
 * @phpstan-type Identity array{dev: int, ino: int}
 * @phpstan-type Record array{path: string, action: string, source: string|null, original: string|null, parent: Identity, previous: Identity|null, candidate: Identity|null, installed: string|null, size: int|null, backup: Identity|null, mode: int, gid: int|null, uid: int|null, mtime: int|null, phase: string, undo: string|null}
 * @phpstan-type Decision array{action: string, reason: string, live: string|null}
 * @phpstan-type Outcome array{outcome: string, reason: string}
 */
final readonly class FileChange {

	/**
	 * Reasons of an undo decision that leave nothing to revert: the apply published nothing
	 * here, an undo already reverted the entry, or what the apply created is gone. An undo
	 * review made before entries could be kept gave such entries the reason undo_journal.
	 */
	private const array SETTLED = [ 'not_applied', 'already_undone', 'removed_since_apply' ];

	/**
	 * Checks reviewed file contents within the active worker budget.
	 *
	 * @var FileVerification
	 */
	private FileVerification $proofs;

	/**
	 * Binds a change to its staged tree and its private undo journal.
	 *
	 * @param ApplyPlan                             $plan Current site policy.
	 * @param string                                $files Canonical staging files directory.
	 * @param string                                $item_directory Unique private directory for this manifest row.
	 * @param string|null                           $source_path Original snapshot path when the live target is mapped.
	 * @param string                                $scope Current job, for independent verification checkpoints, and the
	 *                                                     undo that its restore and completing records name.
	 * @param (\Closure(string, string): void)|null $observer Optional observer, called after each journal record
	 *                                                         (with its phase), synchronisation and live operation
	 *                                                         (with its path), and after an undo's last check
	 *                                                         (check, with the path), in order. Tests use it to
	 *                                                         stop a worker at each crash point and to change an
	 *                                                         entry after its last check.
	 */
	public function __construct( private ApplyPlan $plan, private string $files, private string $item_directory, private ?string $source_path = null, private string $scope = 'legacy', private ?\Closure $observer = null ) {
		$this->proofs = new FileVerification( $item_directory, $scope );
	}

	/**
	 * Whether a validated intent already governs this entry on a retried tick.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Original action.
	 * @return bool Whether the entry has a journal.
	 */
	public function started( string $path, string $action ): bool {
		return null !== $this->read( $path, $action );
	}

	/**
	 * Charges only the portion that will still be written after a stopped copy tick.
	 *
	 * @param string    $path Reviewed live path.
	 * @param string    $action Original action.
	 * @param int       $payload Reviewed allocation of the entire entry.
	 * @param FileSpace $space Allocation estimates on this volume.
	 * @return int Remaining payload allocation.
	 */
	public function remaining( string $path, string $action, int $payload, FileSpace $space ): int {
		if ( 0 === $payload ) {
			return 0;
		}
		$record = $this->read( $path, $action );
		if ( null === $record ) {
			return $payload;
		}
		if ( 'copy' !== $record['phase'] ) {
			return 0;
		}
		foreach ( 'replace' === $action ? [ 'original', 'candidate' ] : [ 'candidate' ] as $name ) {
			$file = $this->item_directory . '/' . $name;
			if ( self::exists( $file ) ) {
				$payload = max( 0, $payload - $space->allocation( self::regular( $file )['size'] ) );
			}
		}
		return $payload;
	}

	/**
	 * Advances one reviewed change. The file digests bind the bytes to the confirmed plan.
	 *
	 * @param string      $path Absolute snapshot and destination path.
	 * @param string      $action mkdir, create or replace.
	 * @param string|null $source_hash SHA-256 of a staged regular file.
	 * @param string|null $original_hash SHA-256 of the existing file for a replacement.
	 * @param float       $deadline Unix time to stop copying.
	 * @param int|null    $mode Original snapshot permissions for new entries.
	 * @return bool Whether the entry is published.
	 * @throws StorageFailed If the reviewed action cannot be performed safely.
	 */
	public function apply( string $path, string $action, ?string $source_hash, ?string $original_hash, float $deadline, ?int $mode = null ): bool {
		$this->proofs->deadline = $deadline;
		try {
			return $this->apply_entry( $path, $action, $source_hash, $original_hash, $deadline, $mode );
		} catch ( FileWorkPending ) {
			return false;
		}
	}

	/**
	 * Advances one reviewed change. The file digests bind the bytes to the confirmed plan.
	 *
	 * @param string      $path Absolute snapshot and destination path.
	 * @param string      $action mkdir, create or replace.
	 * @param string|null $source_hash SHA-256 of a staged regular file.
	 * @param string|null $original_hash SHA-256 of the existing file for a replacement.
	 * @param float       $deadline Unix time to stop copying.
	 * @param int|null    $mode Original snapshot permissions for new entries.
	 * @return bool Whether the entry is published.
	 * @throws StorageFailed If the reviewed action cannot be performed safely.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function apply_entry( string $path, string $action, ?string $source_hash, ?string $original_hash, float $deadline, ?int $mode = null ): bool {
		if ( ! in_array( $action, [ 'mkdir', 'create', 'replace' ], true )
			|| ( 'mkdir' === $action ? null !== $source_hash : ! self::digest( $source_hash ) )
			|| ( 'replace' === $action ? ! self::digest( $original_hash ) : null !== $original_hash ) ) {
			throw new StorageFailed( 'Invalid file apply manifest entry.' );
		}
		$record = $this->read( $path, $action );
		if ( null === $record ) {
			$this->require_action( $path, $action );
			$this->prepare_directory();
			$parent = self::directory_identity( dirname( $path ) );
			if ( self::directory_identity( $this->item_directory )['dev'] !== $parent['dev'] ) {
				throw new StorageFailed( 'Atomic file apply requires private storage on the destination filesystem.' );
			}
			$previous = 'replace' === $action ? self::regular( $path ) : null;
			if ( null !== $previous && 0 !== ( $previous['mode'] & 07000 ) ) {
				StorageFailed::raise( 'A file with special permission bits cannot be replaced.', [ 'code' => 'file_changed_after_review' ] );
			}
			$record = [
				'path'      => hash( 'sha256', $path . "\0" . ( $this->source_path ?? $path ) ),
				'action'    => $action,
				'source'    => $source_hash,
				'original'  => $original_hash,
				'parent'    => $parent,
				'previous'  => null === $previous ? null : self::identity( $previous ),
				'candidate' => null,
				'installed' => null,
				'size'      => null,
				'backup'    => null,
				'mode'      => null === $previous ? ( ( ( $mode ?? 0600 ) & 0755 ) | ( 'mkdir' === $action ? 0700 : 0400 ) ) : ( $previous['mode'] & 0777 ),
				'uid'       => $previous['uid'] ?? null,
				'gid'       => $previous['gid'] ?? null,
				'mtime'     => $previous['mtime'] ?? null,
				'phase'     => 'copy',
				'undo'      => null,
			];
			$this->save( $record );
		}
		if ( $record['source'] !== $source_hash || $record['original'] !== $original_hash ) {
			throw new StorageFailed( 'The file manifest changed since apply started.' );
		}
		$this->require_parent( $path, $record );
		if ( 'done' === $record['phase'] ) {
			return true;
		}
		if ( ! in_array( $record['phase'], [ 'copy', 'ready' ], true ) ) {
			throw new StorageFailed( 'An undone entry cannot be applied a second time.' );
		}
		if ( 'mkdir' === $action ) {
			return $this->make_directory( $path, $record );
		}

		$next = $this->item_directory . '/candidate';
		$old  = $this->item_directory . '/original';
		if ( 'copy' === $record['phase'] ) {
			$this->require_action( $path, $action );
			if ( null !== $original_hash && null === $record['backup'] ) {
				$this->require_original( $path, $record );
				if ( ! $this->copy_file( $path, $old, $original_hash, $deadline ) ) {
					return false;
				}
				// The retained bytes are on disk before the record that names them.
				$this->sync_file( $old );
				$record['backup'] = self::identity( self::regular( $old ) );
				$this->save( $record );
			}
			if ( null === $source_hash || ! $this->copy_file( $this->files . ( $this->source_path ?? $path ), $next, $source_hash, $deadline ) ) {
				return false;
			}
			self::set_attributes( $next, $record, false );
			// The candidate's bytes and attributes are on disk before publication is authorised.
			$this->sync_file( $next );
			$prepared            = self::regular( $next );
			$record['candidate'] = self::identity( $prepared );
			$record['installed'] = self::metadata( $next );
			// With the installed attributes, what an undo compares a live file with another inode against.
			$record['size']  = $prepared['size'];
			$record['phase'] = 'ready';
			$this->save( $record );
		}

		// A create uses a no-clobber hard link. If it was interrupted between link and
		// unlink, drop our private name before the planner rechecks the live link count.
		if ( 'create' === $action && self::at( $next, $record['candidate'] ) && self::at( $path, $record['candidate'] ) ) {
			$this->remove_file( $next );
		}
		if ( ! self::at( $path, $record['candidate'] ) ) {
			$this->require_action( $path, $action );
			$this->require_parent( $path, $record );
			if ( ! self::at( $next, $record['candidate'] ) || self::metadata( $next ) !== $record['installed'] || ! $this->matches( $next, $source_hash ) ) {
				throw new StorageFailed( 'The prepared replacement changed before publication.' );
			}
			if ( 'replace' === $action ) {
				$live = ApplyManifest::identity( $path );
				$this->require_original( $path, $record );
				if ( ! $this->retained_original( $old, $record ) ) {
					throw new StorageFailed( 'The retained undo copy changed before the live file could be replaced.' );
				}
				if ( ApplyManifest::identity( $path ) !== $live ) {
					StorageFailed::raise( 'The live original changed during verification of its undo copy.', [ 'code' => 'file_changed_after_review' ] );
				}
				// The digests above can take long: recheck the folders and the name last.
				$this->require_action( $path, $action );
				$this->publish( $next, $path );
			} else {
				$this->require_action( $path, $action );
				$this->link( $next, $path );
				$this->remove_file( $next );
			}
		}
		// Also after a retry: the publication is on disk before the record that completes it.
		$this->sync_directory( dirname( $path ) );
		$this->require_action( $path, 'replace' );
		if ( self::metadata( $path ) !== $record['installed'] || ! $this->matches( $path, $source_hash ) ) {
			StorageFailed::raise( 'The published file has changed.', [ 'code' => 'file_changed_after_review' ] );
		}
		$record['phase'] = 'done';
		self::invalidate( $path );
		$this->save( $record );
		return true;
	}

	/**
	 * Whether a kept entry's reason leaves nothing to revert, so that the entry already is as
	 * before the apply. Every other reason keeps a change of the apply, or of someone after it,
	 * in place, and the entry stays undoable.
	 *
	 * @param string $reason Reason of a keep decision.
	 * @return bool Whether nothing is left to revert.
	 */
	public static function settled( string $reason ): bool {
		return in_array( $reason, self::SETTLED, true ) || 'undo_journal' === $reason;
	}

	/**
	 * Decides what an undo can do with this entry now, and why. An entry is reverted only while
	 * it still is what the apply left there: the inode that the apply published with the
	 * attributes it recorded, or a regular file with another inode whose content, size, mode,
	 * owner, group and modification time are those that the apply installed, as after the undo
	 * of a later apply put its copy back. Every other entry is kept, with the reason, and its
	 * journal stays for a later undo. A missing intent means that no live write was attempted.
	 *
	 * Absence is decided before the parent: a created file or directory that is gone, also
	 * below a directory that was removed since, leaves nothing to revert, and a replaced file
	 * that is gone is kept. An entry is gone only where its name is missing from a folder
	 * that this process can search; one that cannot be looked up is kept as changed. A parent that is no longer the recorded canonical directory keeps
	 * the entry. Whether a created directory holds entries that the undo keeps is for the
	 * caller to decide, which knows the other entries.
	 *
	 * @param string                  $path Reviewed path.
	 * @param string                  $action Reviewed forward action.
	 * @param float|null              $deadline Deadline of a background preview.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return array{action: string, reason: string} restore, remove or keep, and why.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	public function undo_review( string $path, string $action, ?float $deadline = null, ?DirectorySpellings $review = null ): array {
		if ( null !== $deadline ) {
			$this->proofs->deadline = $deadline;
		}
		$decision = $this->decide( $path, $action, $this->read( $path, $action ), $review );
		return [
			'action' => $decision['action'],
			'reason' => $decision['reason'],
		];
	}

	/**
	 * The action of undo_review().
	 *
	 * @param string                  $path Reviewed path.
	 * @param string                  $action Reviewed forward action.
	 * @param float|null              $deadline Deadline of a background preview.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return string restore, remove or keep.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	public function undo_action( string $path, string $action, ?float $deadline = null, ?DirectorySpellings $review = null ): string {
		return $this->undo_review( $path, $action, $deadline, $review )['action'];
	}

	/**
	 * Reverts one entry if it still is what the apply left there, and otherwise keeps it with
	 * its journal for a later undo. A confirmed undo names the reviewed action: an entry
	 * reviewed for a revert that changed since is kept, never reverted in another way.
	 *
	 * A retry counts a revert as its own only from a record of this undo, named by its scope,
	 * that proves it: the record that completes the entry, or for a restore the record that
	 * precedes the rename while the retained inode is back in place, which only that rename
	 * puts there. An entry that someone else removed or another undo reverted counts as
	 * unchanged, and so does a removal whose completing record a stop prevented: nothing tells
	 * it apart from someone else's.
	 *
	 * @param string      $path Reviewed path.
	 * @param string      $action Original action.
	 * @param float       $deadline Unix deadline of the tick.
	 * @param string|null $reviewed restore or remove as confirmed, or null for what is revertible now.
	 * @return Outcome|null restored, removed, unchanged (nothing to revert) or kept with the
	 *                      reason, or null when the entry needs another tick.
	 * @throws StorageFailed If the journal is damaged or the filesystem refuses a checked change.
	 */
	public function undo( string $path, string $action, float $deadline, ?string $reviewed = null ): ?array {
		$this->proofs->deadline = $deadline;
		try {
			return $this->undo_entry( $path, $action, $reviewed );
		} catch ( FileWorkPending ) {
			return null;
		}
	}

	/**
	 * What this undo did to the entry at its cursor when it stopped before the job counted it,
	 * read from the entry's record by the rule of a retry, without a change to the live site:
	 * restored or removed when the record that completes the entry names this undo, and
	 * restored when the record that precedes a restore's rename names it while the retained
	 * inode is at the live path, which only that rename puts there. Such a record is then
	 * completed as a retry completes it, after the folder. Anything else proves no revert of
	 * this undo, also a removal whose completing record the stop prevented.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Original action.
	 * @return 'restored'|'removed'|null The outcome, or null when no record of this undo proves a revert.
	 * @throws StorageFailed If the journal is damaged or its record cannot be completed.
	 */
	public function reverted( string $path, string $action ): ?string {
		$record = $this->read( $path, $action );
		if ( null === $record || $this->scope !== $record['undo'] ) {
			return null;
		}
		if ( 'restore' === $record['phase'] && self::at( $path, $record['backup'] ) ) {
			$this->settle( $path, $action, $record );
		} elseif ( 'undone' !== $record['phase'] ) {
			return null;
		}
		return 'replace' === $action ? 'restored' : 'removed';
	}

	/**
	 * Reverts one entry, checking again as late as possible before the rename or unlink that
	 * the live file is still the verified inode with its size and change times. The rename or
	 * unlink itself acts on the name: a replacement in between is not seen (ADR 0041).
	 *
	 * As an apply does, every tick advances at least one entry, also one that begins after
	 * the tick's deadline; the verification of a large file still yields after a block.
	 *
	 * @param string      $path Reviewed path.
	 * @param string      $action Original action.
	 * @param string|null $reviewed Confirmed undo action.
	 * @return Outcome Outcome.
	 * @throws StorageFailed If the journal is damaged or the filesystem refuses a checked change.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function undo_entry( string $path, string $action, ?string $reviewed ): array {
		$record = $this->read( $path, $action );
		$next   = $this->item_directory . '/candidate';
		if ( null !== $record && self::private_link( $action, $record, $next ) && self::at( $path, $record['candidate'] ) ) {
			// An interrupted create still holds its private name; release it before deciding.
			$this->remove_file( $next );
		}
		$decision = $this->decide( $path, $action, $record, null );
		if ( 'keep' === $decision['action'] ) {
			if ( null === $record || ! self::settled( $decision['reason'] ) ) {
				// The entry stays as it is, and so does its journal.
				return self::outcome( null === $record ? 'unchanged' : 'kept', $decision['reason'] );
			}
			// A retry of this undo, before the job counted the entry, finds it reverted under its
			// own record, and completes that record. Anything else that it finds with nothing left
			// to revert is unchanged, and its journal stays as it is: an entry that someone else
			// removed can come back as the apply left it, and a later undo must see it then.
			$own = $this->scope === $record['undo'] && ( 'undone' === $record['phase'] || ( 'restore' === $record['phase'] && 'already_undone' === $decision['reason'] ) );
			if ( $own && 'undone' !== $record['phase'] ) {
				$this->settle( $path, $action, $record );
			}
			return self::outcome( $own ? ( 'replace' === $action ? 'restored' : 'removed' ) : 'unchanged', $decision['reason'] );
		}
		if ( null === $record || ( null !== $reviewed && $reviewed !== $decision['action'] ) ) {
			return self::outcome( 'kept', 'changed_since_apply' );
		}
		$old = $this->item_directory . '/original';
		if ( 'restore' === $decision['action'] ) {
			self::set_attributes( $old, $record, true );
			// The restored permissions and time are on disk before the rename is authorised,
			// and so is the record that names this undo.
			$this->sync_file( $old );
			$record['phase'] = 'restore';
			$record['undo']  = $this->scope;
			$this->save( $record );
		}
		$changed = $this->recheck_live( $path, $action, $record, $decision );
		if ( null !== $changed ) {
			return self::outcome( 'kept', $changed );
		}
		$this->observe( 'check', $path );
		if ( 'restore' === $decision['action'] ) {
			$this->publish( $old, $path );
		} elseif ( 'mkdir' === $action ) {
			$changed = $this->remove_directory( $path );
			if ( null !== $changed ) {
				// Something was put into the folder after its last check: it stays.
				return self::outcome( 'kept', $changed );
			}
		} else {
			$this->remove_file( $path );
		}
		$this->settle( $path, $action, $record );
		return self::outcome( 'restore' === $decision['action'] ? 'restored' : 'removed', $decision['reason'] );
	}

	/**
	 * Records an entry that this undo reverted as undone, under this undo's name. Also after a
	 * retry, the reversal is on disk before the record that completes it.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Forward action.
	 * @param Record $record Intent.
	 */
	private function settle( string $path, string $action, array $record ): void {
		$this->sync_directory( dirname( $path ) );
		if ( 'mkdir' !== $action ) {
			self::invalidate( $path );
		}
		$record['phase'] = 'undone';
		$record['undo']  = $this->scope;
		$this->save( $record );
	}

	/**
	 * Checks the entry again immediately before its rename or unlink: the folder, the site
	 * policy, the content, and last the verified identity of the live file, which covers its
	 * inode, size and change times.
	 *
	 * @param string   $path Reviewed path.
	 * @param string   $action Forward action.
	 * @param Record   $record Intent.
	 * @param Decision $decision Decision whose live identity was verified.
	 * @return string|null Reason to keep the entry, or null to revert it.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function recheck_live( string $path, string $action, array $record, array $decision ): ?string {
		if ( self::current_directory( dirname( $path ) ) !== $record['parent'] ) {
			return 'parent_changed';
		}
		// The entry's own state before the site policy, as in decide().
		if ( 'mkdir' === $action ? ! $this->installed( $path, $record ) : ( ! self::as_left( self::file( $path ), $record, $this->installed( $path, $record ), false ) || ! $this->holds( $path, $record['source'], 1 ) ) ) {
			return 'changed_since_apply';
		}
		$policy = $this->live_policy( $path );
		if ( ( 'mkdir' === $action ? 'keep' : 'replace' ) !== $policy['action'] ) {
			return $policy['reason'];
		}
		if ( ! self::changeable( dirname( $path ) ) ) {
			return 'unwritable_destination';
		}
		if ( 'mkdir' === $action ) {
			$child = self::has_child( $path );
			return false === $child ? null : ( true === $child ? 'new_children' : 'changed_since_apply' );
		}
		return self::live_identity( $path ) === $decision['live'] ? null : 'changed_since_apply';
	}

	/**
	 * Decides what an undo can do now. See undo_review().
	 *
	 * @param string                  $path Reviewed path.
	 * @param string                  $action Forward action.
	 * @param Record|null             $record Intent.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return Decision Decision, with the identity of the verified live file of a revert.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function decide( string $path, string $action, ?array $record, ?DirectorySpellings $review ): array {
		if ( null === $record || 'copy' === $record['phase'] ) {
			return self::decision( 'keep', 'not_applied' );
		}
		if ( 'undone' === $record['phase'] ) {
			return self::decision( 'keep', 'already_undone' );
		}
		// A live name that cannot be looked up is neither missing nor someone else's entry: such
		// an entry falls through to the checks below, which keep it as changed.
		$private = 'mkdir' === $action
			? null === $record['candidate'] || self::at( $this->item_directory . '/directory', $record['candidate'] )
			: 'ready' === $record['phase'] && self::at( $this->item_directory . '/candidate', $record['candidate'] ) && ( self::absent( $path, $record['parent'] ) || ( self::exists( $path ) && ! self::at( $path, $record['candidate'] ) ) );
		if ( $private ) {
			// Nothing was published: the prepared entry is still private.
			return self::decision( 'keep', 'not_applied' );
		}
		if ( self::absent( $path, $record['parent'] ) ) {
			return self::decision( 'keep', 'replace' === $action ? 'deleted_since_apply' : 'removed_since_apply' );
		}
		if ( self::current_directory( dirname( $path ) ) !== $record['parent'] ) {
			return self::decision( 'keep', 'parent_changed' );
		}
		if ( 'mkdir' === $action ) {
			if ( ! $this->installed( $path, $record ) ) {
				return self::decision( 'keep', 'changed_since_apply' );
			}
			$policy = $this->live_policy( $path, $review );
			if ( 'keep' !== $policy['action'] ) {
				return self::decision( 'keep', $policy['reason'] );
			}
			// rmdir() needs a parent that the worker may change; a refusal known now keeps it.
			return self::changeable( dirname( $path ) ) ? self::decision( 'remove', 'undo_journal' ) : self::decision( 'keep', 'unwritable_destination' );
		}
		if ( 'restore' === $record['phase'] && self::at( $path, $record['backup'] ) && $this->holds( $path, $record['original'], 1 ) ) {
			// An interrupted undo of this entry has put the original back already.
			return self::decision( 'keep', 'already_undone' );
		}
		$same   = $this->installed( $path, $record );
		$linked = $same && self::private_link( $action, $record, $this->item_directory . '/candidate' );
		// The file's own state is decided before the site policy, for the installed inode as for
		// another one: the policy refuses every file with a second link, and a file system that
		// reuses inode numbers can give a later file the recorded one.
		if ( ! self::as_left( self::file( $path ), $record, $same, $linked ) ) {
			return self::decision( 'keep', 'changed_since_apply' );
		}
		if ( ! $linked ) {
			$policy = $this->live_policy( $path, $review );
			if ( 'replace' !== $policy['action'] ) {
				return self::decision( 'keep', $policy['reason'] );
			}
		} elseif ( ! self::changeable( dirname( $path ) ) ) {
			// The site policy refuses an unwritable parent; a file whose private name remains
			// is decided without it, and its unlink would be refused as well.
			return self::decision( 'keep', 'unwritable_destination' );
		}
		$live = self::live_identity( $path );
		if ( null === $live || ! $this->holds( $path, $record['source'], $linked ? 2 : 1 ) || self::live_identity( $path ) !== $live ) {
			return self::decision( 'keep', 'changed_since_apply' );
		}
		$reason = $same ? 'undo_journal' : 'installed_content';
		if ( 'create' === $action ) {
			return self::decision( 'remove', $reason, $live );
		}
		return $this->retained_original( $this->item_directory . '/original', $record ) ? self::decision( 'restore', $reason, $live ) : self::decision( 'keep', 'original_unavailable' );
	}

	/**
	 * Whether the entry is the inode that the apply published, with the attributes it recorded.
	 *
	 * @param string $path Live entry.
	 * @param Record $record Intent.
	 * @return bool Whether the published inode is in place unchanged.
	 */
	private function installed( string $path, array $record ): bool {
		if ( ! self::at( $path, $record['candidate'] ) ) {
			return false;
		}
		try {
			return self::metadata( $path ) === $record['installed'];
		} catch ( StorageFailed ) {
			return false;
		}
	}

	/**
	 * Whether a live regular file has the state that the apply left there: one link, or two
	 * while the private name of an interrupted create remains, and the installed size, which
	 * needs no hashing to tell. Another inode than the recorded one also needs the attributes
	 * that the apply recorded for its candidate: type, permissions, owner, group and
	 * modification time. The undo of a later apply puts its copy back with all of them, and
	 * a file that someone wrote after the apply has a later modification time, also with the
	 * same bytes. An entry recorded before the installed size was kept counts only its own
	 * inode.
	 *
	 * @param array<int|string,int>|null $stat Live regular file.
	 * @param Record                     $record Intent.
	 * @param bool                       $same Whether it is the recorded inode with the recorded attributes.
	 * @param bool                       $linked Whether the private name of an interrupted create is its second link.
	 * @return bool Whether the file is as the apply left it, its content aside.
	 */
	private static function as_left( ?array $stat, array $record, bool $same, bool $linked ): bool {
		return null !== $stat && ( $linked ? 2 : 1 ) === $stat['nlink'] && ( null === $record['size'] || $record['size'] === $stat['size'] )
			&& ( $same || ( null !== $record['size'] && self::attributes( $stat, false ) === $record['installed'] ) );
	}

	/**
	 * Verifies content without failing on a live file that changes or is no longer regular:
	 * what cannot be established counts as a change.
	 *
	 * @param string      $path Canonical path.
	 * @param string|null $hash Expected digest.
	 * @param int         $links Expected link count.
	 * @return bool Whether the content matches.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function holds( string $path, ?string $hash, int $links ): bool {
		if ( null === $hash || null === self::file( $path ) ) {
			return false;
		}
		try {
			return $this->proofs->matches( $path, $hash, $links );
		} catch ( StorageFailed ) {
			return false;
		}
	}

	/**
	 * Builds a decision.
	 *
	 * @param string      $action restore, remove or keep.
	 * @param string      $reason Reason code.
	 * @param string|null $live Identity of the verified live file of a revert.
	 * @return Decision Decision.
	 */
	private static function decision( string $action, string $reason, ?string $live = null ): array {
		return [
			'action' => $action,
			'reason' => $reason,
			'live'   => $live,
		];
	}

	/**
	 * Builds the outcome of a worker's undo of one entry.
	 *
	 * @param string $outcome restored, removed, unchanged or kept.
	 * @param string $reason Reason code.
	 * @return Outcome Outcome.
	 */
	private static function outcome( string $outcome, string $reason ): array {
		return [
			'outcome' => $outcome,
			'reason'  => $reason,
		];
	}

	/**
	 * Whether a create still holds its private name for the candidate: the worker stopped
	 * between the no-clobber link and the unlink, or a power loss dropped the unlink although
	 * the record that followed it survived.
	 *
	 * @param string $action Forward action.
	 * @param Record $record Intent.
	 * @param string $next Private candidate name.
	 * @return bool Whether the private name refers to the candidate.
	 */
	private static function private_link( string $action, array $record, string $next ): bool {
		return 'create' === $action && in_array( $record['phase'], [ 'ready', 'done' ], true ) && self::at( $next, $record['candidate'] );
	}

	/**
	 * Prepares the directory in the item directory, records its inode and then renames it
	 * into place, as a replacement file is. A retry after a kill at any step finds the
	 * recorded inode either still private or published and completes the creation; undo
	 * removes exactly the directory that this entry created.
	 *
	 * @param string $path Reviewed path.
	 * @param Record $record Intent.
	 * @return bool Done.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function make_directory( string $path, array $record ): bool {
		$prepared = $this->item_directory . '/directory';
		if ( null === $record['candidate'] ) {
			// Nothing of this entry is live yet. An intent that an earlier version recorded
			// before its mkdir() also requires the reviewed destination to be missing.
			$this->require_action( $path, 'mkdir' );
			$this->prepare_candidate_directory( $prepared, $record['mode'], dirname( $path ) );
			$record['candidate'] = self::directory_identity( $prepared );
			$record['installed'] = self::metadata( $prepared );
			$record['phase']     = 'ready';
			$this->save( $record );
		}
		if ( ! self::at( $path, $record['candidate'] ) ) {
			if ( ! self::at( $prepared, $record['candidate'] ) || self::metadata( $prepared ) !== $record['installed'] || false !== self::has_child( $prepared ) ) {
				throw new StorageFailed( 'The prepared directory changed before publication.' );
			}
			$this->require_parent( $path, $record );
			$this->require_action( $path, 'mkdir' );
			if ( ! rename( $prepared, $path ) ) {
				throw new StorageFailed( 'The prepared directory could not be renamed into place.' );
			}
			$this->plan->changed( $path, true );
			$this->observe( 'rename', $path );
		}
		// Also after a retry: the publication is on disk before the record that completes it.
		$this->sync_directory( dirname( $path ) );
		$this->require_action( $path, 'keep' );
		if ( self::metadata( $path ) !== $record['installed'] ) {
			StorageFailed::raise( 'The published directory has changed.', [ 'code' => 'file_changed_after_review' ] );
		}
		$record['phase'] = 'done';
		$this->save( $record );
		return true;
	}

	/**
	 * Creates the private directory that becomes the reviewed one, or reuses the empty one
	 * that a killed worker created before it could record it. It gets the permissions that
	 * mkdir() in place gave: the reviewed mode narrowed by the process umask and, below a
	 * set-group-ID parent, that parent's group and the bit, where the worker may set the group.
	 *
	 * @param string $prepared Private path in the item directory.
	 * @param int    $mode Reviewed permissions.
	 * @param string $live_parent Live parent directory.
	 * @throws StorageFailed If the private directory cannot be prepared.
	 */
	private function prepare_candidate_directory( string $prepared, int $mode, string $live_parent ): void {
		clearstatcache( true );
		if ( is_link( $prepared ) || ( file_exists( $prepared ) && ( realpath( $prepared ) !== $prepared || ! is_dir( $prepared ) || false !== self::has_child( $prepared ) ) ) ) {
			throw new StorageFailed( 'The private copy of a reviewed directory is not an empty directory.' );
		}
		if ( ! is_dir( $prepared ) ) {
			if ( ! mkdir( $prepared, 0700 ) ) {
				throw new StorageFailed( 'The reviewed directory could not be prepared.' );
			}
			$this->observe( 'mkdir', $prepared );
		}
		$mode &= ~umask();
		$live  = lstat( $live_parent );
		if ( false !== $live && 0 !== ( $live['mode'] & 02000 ) && @chgrp( $prepared, $live['gid'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- A group the worker may not set leaves the worker's group, as for a created file.
			$mode |= 02000;
		}
		if ( ! chmod( $prepared, $mode ) ) {
			throw new StorageFailed( 'The permissions of a reviewed directory could not be set.' );
		}
		// Its permissions are on disk before the record that names them.
		$this->sync_directory( $prepared );
	}

	/**
	 * Creates work files only underneath an existing canonical private directory.
	 *
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function prepare_directory(): void {
		$parent = dirname( $this->item_directory );
		self::directory_identity( $parent );
		if ( ! is_dir( $this->item_directory ) ) {
			if ( ! mkdir( $this->item_directory, 0700 ) ) {
				throw new StorageFailed( 'The file apply journal could not be created.' );
			}
			// The journal's directory survives a power loss before its first record does.
			Files::sync_directory( $parent );
		}
		self::directory_identity( $this->item_directory );
	}

	/**
	 * Rechecks site policy immediately before a filesystem change.
	 *
	 * @param string $path Reviewed path.
	 * @param string $expected Required disposition under current site policy.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function require_action( string $path, string $expected ): void {
		if ( $this->live_action( $path ) !== $expected ) {
			StorageFailed::raise( 'The live file plan changed since it was reviewed.', [ 'code' => 'file_changed_after_review' ] );
		}
	}

	/**
	 * Rechecks the original source against the current policy and the exact live target
	 * in this journal. Reconfiguration cannot redirect a partially applied change.
	 *
	 * @param string                  $path Live path.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return string Current disposition.
	 */
	private function live_action( string $path, ?DirectorySpellings $review = null ): string {
		return $this->live_policy( $path, $review )['action'];
	}

	/**
	 * The current disposition of the live target with its reason. A source that the site now
	 * maps to another destination gives destination_changed.
	 *
	 * @param string                  $path Live path.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return array{action: string, reason: string} Current disposition.
	 */
	private function live_policy( string $path, ?DirectorySpellings $review = null ): array {
		$source = $this->source_path ?? $path;
		$entry  = $this->plan->inspect( $this->files, $source, $review );
		if ( ( $entry['destination'] ?? ( null === $this->source_path ? $path : null ) ) !== $path ) {
			return [
				'action' => 'refuse',
				'reason' => 'destination_changed',
			];
		}
		return [
			'action' => $entry['action'],
			'reason' => $entry['reason'],
		];
	}

	/**
	 * Detects a replaced parent even if the canonical path matches.
	 *
	 * @param string $path Live entry.
	 * @param Record $record Persisted intent.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function require_parent( string $path, array $record ): void {
		if ( self::directory_identity( dirname( $path ) ) !== $record['parent'] ) {
			StorageFailed::raise( 'The parent of the reviewed file changed.', [ 'code' => 'file_changed_after_review' ] );
		}
	}

	/**
	 * Checks the existing file against the reviewed preimage.
	 *
	 * @param string $path Existing live file.
	 * @param Record $record Persisted intent.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function require_original( string $path, array $record ): void {
		$stat = self::regular( $path );
		if ( self::identity( $stat ) !== $record['previous'] || $stat['uid'] !== $record['uid'] || $stat['gid'] !== $record['gid']
			|| ( $stat['mode'] & 07777 ) !== $record['mode'] || $stat['mtime'] !== $record['mtime'] || ! $this->matches( $path, $record['original'] ) ) {
			StorageFailed::raise( 'The live file no longer matches the reviewed original.', [ 'code' => 'file_changed_after_review' ] );
		}
	}

	/**
	 * Verifies the same recoverable private inode and bytes at publication and undo.
	 * Large originals share the verifier's identity-bound, resumable block checkpoints.
	 *
	 * @param string $path Private undo copy.
	 * @param Record $record Original intent and recorded inode.
	 * @return bool Whether the approved preimage is still intact.
	 */
	private function retained_original( string $path, array $record ): bool {
		$before = self::at( $path, $record['backup'] ) ? self::live_identity( $path ) : null;
		return null !== $before && $this->holds( $path, $record['original'], 1 ) && self::at( $path, $record['backup'] ) && self::live_identity( $path ) === $before;
	}

	/**
	 * Opens a bounded-memory copy at the durable length of its private scratch file.
	 *
	 * @param string $source Canonical regular source.
	 * @param string $target Private regular scratch file.
	 * @param string $digest Reviewed SHA-256.
	 * @param float  $deadline Unix deadline.
	 * @return bool Whether all bytes were written and verified.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function copy_file( string $source, string $target, string $digest, float $deadline ): bool {
		$before = self::regular( $source );
		$input  = fopen( $source, 'rb' );
		if ( false === $input ) {
			throw new StorageFailed( 'The source of a file change could not be opened.' );
		}
		try {
			$opened = fstat( $input );
			if ( false === $opened || self::identity( $opened ) !== self::identity( $before ) ) {
				StorageFailed::raise( 'The source changed while opening a file.', [ 'code' => 'file_changed_after_review' ] );
			}
			$offset = self::exists( $target ) ? self::regular( $target )['size'] : 0;
			if ( $offset === $before['size'] && self::exists( $target ) && $this->matches( $target, $digest ) ) {
				return true;
			}
			$output = self::open_copy( $target );
			try {
				if ( $offset > $before['size'] || 0 !== fseek( $input, $offset ) || 0 !== fseek( $output, $offset ) ) {
					throw new StorageFailed( 'A private copy could not be resumed.' );
				}
				while ( $offset < $before['size'] ) {
					$bytes = fread( $input, max( 1, min( 1048576, $before['size'] - $offset ) ) );
					if ( false === $bytes || '' === $bytes || strlen( $bytes ) !== fwrite( $output, $bytes ) ) {
						throw new StorageFailed( 'A private file copy could not be completed.' );
					}
					$offset += strlen( $bytes );
					if ( $offset < $before['size'] && microtime( true ) >= $deadline ) {
						return false;
					}
				}
				if ( ! fflush( $output ) ) {
					throw new StorageFailed( 'A private file copy could not be flushed.' );
				}
			} finally {
				fclose( $output );
			}
		} finally {
			fclose( $input );
		}
		if ( ! $this->matches( $target, $digest ) ) {
			StorageFailed::raise( 'A private file copy differs from its reviewed digest.', [ 'code' => 'file_changed_after_review' ] );
		}
		return true;
	}

	/**
	 * Creates an owner-only scratch file or opens the partial copy.
	 *
	 * @param string $path Private scratch path.
	 * @return resource Writable stream.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private static function open_copy( string $path ) {
		$umask = umask( 0077 );
		try {
			$file = fopen( $path, self::exists( $path ) ? 'r+b' : 'x+b' );
		} finally {
			umask( $umask );
		}
		if ( false === $file ) {
			throw new StorageFailed( 'A private copy could not be opened for writing.' );
		}
		return $file;
	}

	/**
	 * Retains original ownership and access on replacement and undo.
	 *
	 * @param string $path Private file to publish.
	 * @param Record $record Original metadata.
	 * @param bool   $undo Whether to restore the previous modification time.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private static function set_attributes( string $path, array $record, bool $undo ): void {
		$stat = self::regular( $path );
		if ( null !== $record['uid'] && $record['uid'] !== $stat['uid'] ) {
			throw new StorageFailed( 'File apply cannot preserve this file owner.' );
		}
		if ( ( null !== $record['gid'] && $record['gid'] !== $stat['gid'] && ! chgrp( $path, $record['gid'] ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- The private same-filesystem candidate must retain the reviewed live group before its atomic rename.
			|| ( ( $stat['mode'] & 07777 ) !== $record['mode'] && ! chmod( $path, $record['mode'] ) )
			|| ( $undo && null !== $record['mtime'] && $stat['mtime'] !== $record['mtime'] && ! touch( $path, $record['mtime'] ) ) ) {
			throw new StorageFailed( 'File apply could not preserve file metadata.' );
		}
	}

	/**
	 * Reads the identity of a directory without accepting an alias.
	 *
	 * @param string $path Existing canonical directory.
	 * @return Identity Identity.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private static function directory_identity( string $path ): array {
		return self::current_directory( $path ) ?? throw new StorageFailed( 'A file change directory is no longer a canonical directory.' );
	}

	/**
	 * Reads the identity of a directory that may have been removed or replaced since.
	 *
	 * @param string $path Directory to inspect.
	 * @return Identity|null Identity, or null if the path is no longer a canonical directory.
	 */
	private static function current_directory( string $path ): ?array {
		clearstatcache( true );
		if ( ! is_dir( $path ) || realpath( $path ) !== $path ) {
			return null;
		}
		$stat = lstat( $path );
		return false === $stat ? null : self::identity( $stat );
	}

	/**
	 * Requires a regular file at its canonical path.
	 *
	 * @param string $path Existing canonical regular file.
	 * @param int    $links Expected number of links.
	 * @return array<string|int,int> File metadata.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private static function regular( string $path, int $links = 1 ): array {
		clearstatcache( true );
		$stat = realpath( $path ) === $path ? lstat( $path ) : false;
		if ( false === $stat || ( $stat['mode'] & 0170000 ) !== 0100000 || $links !== $stat['nlink'] ) {
			throw new StorageFailed( 'A file change requires a canonical regular file.' );
		}
		return $stat;
	}

	/**
	 * Reads a canonical regular file without failing when it is gone or of another type.
	 *
	 * @param string $path Path to inspect.
	 * @return array<int|string,int>|null File metadata, or null.
	 */
	private static function file( string $path ): ?array {
		clearstatcache( true );
		$stat = self::exists( $path ) && realpath( $path ) === $path ? lstat( $path ) : false;
		return false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ? null : $stat;
	}

	/**
	 * The full identity of a live file, which includes its inode, size and change times, or
	 * null when it is gone.
	 *
	 * @param string $path Live file.
	 * @return string|null Identity.
	 */
	private static function live_identity( string $path ): ?string {
		if ( null === self::file( $path ) ) {
			return null;
		}
		try {
			return ApplyManifest::identity( $path );
		} catch ( StorageFailed ) {
			return null;
		}
	}

	/**
	 * Checks whether a name still refers to the recorded object. Concurrent rename or
	 * unlink can change the result between two checks with the same arguments.
	 *
	 * @phpstan-impure
	 * @param string        $path Path to inspect.
	 * @param Identity|null $id Expected identity.
	 * @return bool Identity matches at the canonical path.
	 */
	private static function at( string $path, ?array $id ): bool {
		if ( null === $id || ! self::exists( $path ) || realpath( $path ) !== $path ) {
			return false;
		}
		$stat = lstat( $path );
		return false !== $stat && self::identity( $stat ) === $id;
	}

	/**
	 * Records permission and ownership changes independently of content. Directory
	 * timestamps change when subsequent manifest entries create or remove children.
	 *
	 * @param string $path Canonical entry whose identity the caller verified.
	 * @return string Digest of the metadata protected from subsequent undo.
	 * @throws StorageFailed If the entry metadata is unavailable.
	 */
	private static function metadata( string $path ): string {
		clearstatcache( true, $path );
		$stat = lstat( $path );
		if ( false === $stat ) {
			throw new StorageFailed( 'File metadata could not be read.' );
		}
		return self::attributes( $stat, is_dir( $path ) );
	}

	/**
	 * The digest of metadata(): type and permissions, owner, group and, for a file, the
	 * modification time.
	 *
	 * @param array<int|string,int> $stat lstat result.
	 * @param bool                  $directory Whether the entry is a directory.
	 * @return string Digest.
	 */
	private static function attributes( array $stat, bool $directory ): string {
		return hash( 'sha256', implode( '|', [ $stat['mode'], $stat['uid'], $stat['gid'], $directory ? 0 : $stat['mtime'] ] ) );
	}

	/**
	 * Extracts the filesystem and inode pair.
	 *
	 * @param array<int|string,int> $stat stat or fstat result.
	 * @return Identity Identity.
	 */
	private static function identity( array $stat ): array {
		return [
			'dev' => $stat['dev'],
			'ino' => $stat['ino'],
		];
	}

	/**
	 * Checks the representation of a SHA-256 digest.
	 *
	 * @param string|null $hash SHA-256.
	 * @return bool Whether correctly encoded.
	 */
	private static function digest( ?string $hash ): bool {
		return null !== $hash && ( 1 === preg_match( '/\A[0-9a-f]{64}\z/', $hash ) || ChunkDigest::valid( $hash ) );
	}

	/**
	 * Verifies the bytes and file type before a publication decision.
	 *
	 * @param string      $path Canonical regular file.
	 * @param string|null $hash Expected SHA-256.
	 * @param int         $links Expected link count.
	 * @return bool Whether the content matches.
	 */
	private function matches( string $path, ?string $hash, int $links = 1 ): bool {
		self::regular( $path, $links );
		return null !== $hash && $this->proofs->matches( $path, $hash, $links );
	}

	/**
	 * Installs a fully prepared file with one rename.
	 *
	 * @param string $from Private regular file.
	 * @param string $to Destination on the same filesystem.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function publish( string $from, string $to ): void {
		if ( ! rename( $from, $to ) ) {
			throw new StorageFailed( 'An atomic file replacement was refused.' );
		}
		$this->plan->changed( $to, true );
		$this->observe( 'rename', $to );
	}

	/**
	 * Publishes a new file under a name that must not exist, with a no-clobber hard link.
	 *
	 * @param string $from Private candidate.
	 * @param string $to Live destination on the same filesystem.
	 * @throws StorageFailed If the name exists or the filesystem refuses the link.
	 */
	private function link( string $from, string $to ): void {
		if ( ! function_exists( 'link' ) || ! link( $from, $to ) ) {
			throw new StorageFailed( 'The new file could not be published without replacing an existing entry.' );
		}
		$this->plan->changed( $to, true );
		$this->observe( 'link', $to );
	}

	/**
	 * Removes one verified file.
	 *
	 * @param string $path File to unlink.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function remove_file( string $path ): void {
		if ( ! unlink( $path ) ) {
			throw new StorageFailed( 'A file change could not remove its file.' );
		}
		$this->plan->changed( $path, false );
		$this->observe( 'unlink', $path );
	}

	/**
	 * Removes a directory that this entry created, only while it is empty: rmdir() itself is
	 * the last check. A name that appeared in it since its last check keeps the directory.
	 *
	 * @param string $path Verified directory.
	 * @return string|null new_children for a directory that holds a name now, null once it is removed.
	 * @throws StorageFailed If the directory cannot be removed and no name in it could be read.
	 */
	private function remove_directory( string $path ): ?string {
		if ( ! @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A refusal is answered below, from what the directory holds.
			if ( true === self::has_child( $path ) ) {
				return 'new_children';
			}
			throw new StorageFailed( 'A directory created by file apply cannot be removed.' );
		}
		$this->plan->changed( $path, false );
		$this->observe( 'rmdir', $path );
		return null;
	}

	/**
	 * Whether a directory holds an entry, read only up to its first name: a folder that gained
	 * very many names is not listed in full to tell that it is not empty.
	 *
	 * @param string $path Directory.
	 * @return bool|null True when a name in it was read, false when it holds none, null when it cannot be read.
	 */
	private static function has_child( string $path ): ?bool {
		$handle = @opendir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A directory that cannot be read is answered with null.
		if ( false === $handle ) {
			return null;
		}
		try {
			for ( $name = readdir( $handle ); false !== $name; $name = readdir( $handle ) ) {
				if ( '.' !== $name && '..' !== $name ) {
					return true;
				}
			}
			return false;
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Puts a private copy on disk before a record names it.
	 *
	 * @param string $path Private regular file.
	 * @throws StorageFailed If the copy cannot be synchronised.
	 */
	private function sync_file( string $path ): void {
		Files::sync_file( $path );
		$this->observe( 'sync', $path );
	}

	/**
	 * Puts a directory's entries on disk where the host can synchronise a directory.
	 *
	 * @param string $path Directory.
	 */
	private function sync_directory( string $path ): void {
		Files::sync_directory( $path );
		$this->observe( 'sync', $path );
	}

	/**
	 * Reports a completed step to the optional observer.
	 *
	 * @param string $operation journal, sync, mkdir, rename, link, unlink or rmdir.
	 * @param string $subject Phase of a journal record, otherwise the path.
	 */
	private function observe( string $operation, string $subject ): void {
		if ( null !== $this->observer ) {
			( $this->observer )( $operation, $subject );
		}
	}

	/**
	 * Checks for a directory entry without using stale metadata.
	 *
	 * @param string $path Path to test.
	 * @return bool Entry present, including dangling links.
	 */
	private static function exists( string $path ): bool {
		clearstatcache( true );
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Whether this process may add and remove names in a folder: write and search permission.
	 * A rename, unlink or rmdir in a folder without them would be refused.
	 *
	 * @param string $folder Folder.
	 * @return bool Whether names in it can be changed.
	 */
	private static function changeable( string $folder ): bool {
		clearstatcache( true, $folder );
		return is_writable( $folder ) && is_executable( $folder );
	}

	/**
	 * Whether an entry is established to be missing: its name is not in a canonical folder
	 * that this process can search and that, where the caller knows it, is the recorded
	 * parent, or that folder's name is missing in the same sense. A name that cannot be looked
	 * up, below a folder without search permission, where file_exists() answers false for a
	 * file that is there, is not missing; nor is one below an alias, such as a link in place of
	 * the folder, or below another folder at the recorded name: the folder that holds the entry
	 * may be elsewhere.
	 *
	 * @param string        $path Path to test.
	 * @param Identity|null $recorded Recorded identity of its parent, if any.
	 * @return bool Whether the entry is missing.
	 */
	private static function absent( string $path, ?array $recorded = null ): bool {
		if ( self::exists( $path ) ) {
			return false;
		}
		$folder = dirname( $path );
		if ( $folder === $path ) {
			return false;
		}
		$identity = self::current_directory( $folder );
		if ( null !== $identity ) {
			return ( null === $recorded || $identity === $recorded ) && is_executable( $folder );
		}
		return self::absent( $folder );
	}

	/**
	 * Drops stale bytecode for code that was installed or removed.
	 *
	 * @param string $path Changed live path.
	 */
	private static function invalidate( string $path ): void {
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
	}

	/**
	 * Publishes a complete progress record, on disk before the caller's next live change.
	 *
	 * @param Record $record Private intent.
	 */
	private function save( array $record ): void {
		Files::write_durable( $this->item_directory . '/entry.json', json_encode( $record, JSON_THROW_ON_ERROR ) );
		$this->observe( 'journal', $record['phase'] );
	}

	/**
	 * Reads and validates the intent for this manifest entry.
	 *
	 * @param string $path Path from the confirmed manifest.
	 * @param string $action Desired forward action.
	 * @return Record|null Validated private intent.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function read( string $path, string $action ): ?array {
		$file = $this->item_directory . '/entry.json';
		if ( ! self::exists( $file ) ) {
			if ( self::absent( $file ) ) {
				return null;
			}
			throw new StorageFailed( 'The file change journal cannot be read.' );
		}
		$stat = self::regular( $file );
		$text = $stat['size'] <= 4096 ? file_get_contents( $file ) : false;
		$data = false === $text ? null : json_decode( $text, true );
		if ( ! is_array( $data ) || hash( 'sha256', $path . "\0" . ( $this->source_path ?? $path ) ) !== ( $data['path'] ?? null ) || $action !== ( $data['action'] ?? null )
			|| ! in_array( $data['phase'] ?? '', [ 'copy', 'ready', 'done', 'restore', 'undone' ], true )
			|| ! is_int( $data['mode'] ?? null ) || $data['mode'] < 0 || $data['mode'] > 0777 ) {
			throw new StorageFailed( 'The file change journal is damaged or belongs to another entry.' );
		}
		foreach ( [ 'parent', 'previous', 'candidate', 'backup' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ( ! is_array( $data[ $key ] ) || ! is_int( $data[ $key ]['dev'] ?? null ) || ! is_int( $data[ $key ]['ino'] ?? null ) ) ) ) {
				throw new StorageFailed( 'The file change journal has an invalid identity.' );
			}
		}
		foreach ( [ 'uid', 'gid', 'mtime' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ! is_int( $data[ $key ] ) ) ) {
				throw new StorageFailed( 'The file change journal has invalid file metadata.' );
			}
		}
		foreach ( [ 'source', 'original', 'installed' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ( ! is_string( $data[ $key ] ) || ! self::digest( $data[ $key ] ) ) ) ) {
				throw new StorageFailed( 'The file change journal has an invalid digest.' );
			}
		}
		if ( null === $data['parent'] ) {
			throw new StorageFailed( 'The file change journal has no parent identity.' );
		}
		// Entries recorded before the installed size was kept have none.
		$data['size'] ??= null;
		if ( null !== $data['size'] && ( ! is_int( $data['size'] ) || $data['size'] < 0 ) ) {
			throw new StorageFailed( 'The file change journal has an invalid installed size.' );
		}
		// Records written before an undo named itself name none.
		$data['undo'] ??= null;
		if ( null !== $data['undo'] && ( ! is_string( $data['undo'] ) || 1 !== preg_match( '/\A[\x21-\x7e]{1,128}\z/', $data['undo'] ) ) ) {
			throw new StorageFailed( 'The file change journal names an invalid undo.' );
		}
		/**
		 * The record shape and all scalar fields have been checked above.
		 *
		 * @var Record $data
		 */
		return $data;
	}
}
