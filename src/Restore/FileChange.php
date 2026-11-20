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
 * that completed before its caller could checkpoint. An undo refuses an edited live file.
 * The caller serializes writers and pins staging and the item directory until undo expires.
 *
 * @phpstan-type Identity array{dev: int, ino: int}
 * @phpstan-type Record array{path: string, action: string, source: string|null, original: string|null, parent: Identity, previous: Identity|null, candidate: Identity|null, installed: string|null, backup: Identity|null, mode: int, gid: int|null, uid: int|null, mtime: int|null, phase: string}
 */
final readonly class FileChange {

	/**
	 * Checks reviewed file contents within the active worker budget.
	 *
	 * @var FileVerification
	 */
	private FileVerification $proofs;

	/**
	 * Binds a change to its staged tree and its private undo journal.
	 *
	 * @param ApplyPlan   $plan Current site policy.
	 * @param string      $files Canonical staging files directory.
	 * @param string      $item_directory Unique private directory for this manifest row.
	 * @param string|null $source_path Original snapshot path when the live target is mapped.
	 * @param string      $scope Current job for independent verification checkpoints.
	 */
	public function __construct( private ApplyPlan $plan, private string $files, private string $item_directory, private ?string $source_path = null, string $scope = 'legacy' ) {
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
				throw new StorageFailed( 'A file with special permission bits cannot be replaced.' );
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
				'backup'    => null,
				'mode'      => null === $previous ? ( ( ( $mode ?? 0600 ) & 0755 ) | ( 'mkdir' === $action ? 0700 : 0400 ) ) : ( $previous['mode'] & 0777 ),
				'uid'       => $previous['uid'] ?? null,
				'gid'       => $previous['gid'] ?? null,
				'mtime'     => $previous['mtime'] ?? null,
				'phase'     => 'copy',
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
				$record['backup'] = self::identity( self::regular( $old ) );
				$this->save( $record );
			}
			if ( null === $source_hash || ! $this->copy_file( $this->files . ( $this->source_path ?? $path ), $next, $source_hash, $deadline ) ) {
				return false;
			}
			self::set_attributes( $next, $record, false );
			$record['candidate'] = self::identity( self::regular( $next ) );
			$record['installed'] = self::metadata( $next );
			$record['phase']     = 'ready';
			$this->save( $record );
		}

		// A create uses a no-clobber hard link. If it was interrupted between link and
		// unlink, drop our private name before the planner rechecks the live link count.
		if ( 'create' === $action && self::at( $next, $record['candidate'] ) && self::at( $path, $record['candidate'] ) ) {
			self::remove_file( $next );
		}
		if ( ! self::at( $path, $record['candidate'] ) ) {
			$this->require_action( $path, $action );
			$this->require_parent( $path, $record );
			if ( ! self::at( $next, $record['candidate'] ) || self::metadata( $next ) !== $record['installed'] || ! $this->matches( $next, $source_hash ) ) {
				throw new StorageFailed( 'The prepared replacement changed before publication.' );
			}
			if ( 'replace' === $action ) {
				$this->require_original( $path, $record );
				self::publish( $next, $path );
			} else {
				if ( ! function_exists( 'link' ) || ! link( $next, $path ) ) {
					throw new StorageFailed( 'The new file could not be published without replacing an existing entry.' );
				}
				self::remove_file( $next );
			}
		}
		$this->require_action( $path, 'replace' );
		if ( self::metadata( $path ) !== $record['installed'] || ! $this->matches( $path, $source_hash ) ) {
			throw new StorageFailed( 'The published file has changed.' );
		}
		$record['phase'] = 'done';
		self::invalidate( $path );
		$this->save( $record );
		return true;
	}

	/**
	 * Determines what can currently be reversed. Only a file installed by this entry is a
	 * removal candidate. A missing intent means that no live write was attempted.
	 *
	 * @param string                  $path Reviewed path.
	 * @param string                  $action Reviewed forward action.
	 * @param float|null              $deadline Deadline of a background preview.
	 * @param DirectorySpellings|null $review Optional name index for a read-only undo preview.
	 * @return string restore, remove, keep or refuse.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	public function undo_action( string $path, string $action, ?float $deadline = null, ?DirectorySpellings $review = null ): string {
		if ( null !== $deadline ) {
			$this->proofs->deadline = $deadline;
		}
		$record = $this->read( $path, $action );
		if ( null === $record || 'undone' === $record['phase'] || 'copy' === $record['phase'] ) {
			return 'keep';
		}
		if ( self::directory_identity( dirname( $path ) ) !== $record['parent'] ) {
			return 'refuse';
		}
		if ( 'mkdir' === $action ) {
			if ( null === $record['candidate'] || ! self::exists( $path ) ) {
				return 'keep';
			}
			return self::at( $path, $record['candidate'] ) && self::metadata( $path ) === $record['installed'] && 'keep' === $this->live_action( $path, $review ) ? 'remove' : 'refuse';
		}
		if ( ! self::exists( $path ) ) {
			return 'create' === $action ? 'keep' : 'refuse';
		}
		if ( 'restore' === $record['phase'] && self::at( $path, $record['backup'] ) && $this->matches( $path, $record['original'] ) ) {
			return 'keep';
		}
		if ( 'ready' === $record['phase'] && self::at( $this->item_directory . '/candidate', $record['candidate'] ) && ! self::at( $path, $record['candidate'] ) ) {
			// The replacement is still private; there is nothing of ours at the destination.
			return 'keep';
		}
		if ( ! self::at( $path, $record['candidate'] ) || self::metadata( $path ) !== $record['installed'] ) {
			return 'refuse';
		}
		$unfinished_link = 'create' === $action && 'ready' === $record['phase'] && self::at( $this->item_directory . '/candidate', $record['candidate'] );
		if ( ! $unfinished_link && 'replace' !== $this->live_action( $path, $review ) ) {
			return 'refuse';
		}
		if ( ! $this->matches( $path, $record['source'], $unfinished_link ? 2 : 1 ) ) {
			return 'refuse';
		}
		if ( 'create' === $action ) {
			return 'remove';
		}
		$old = $this->item_directory . '/original';
		return self::at( $old, $record['backup'] ) && $this->matches( $old, $record['original'] ) ? 'restore' : 'refuse';
	}

	/**
	 * Reverses one reviewed change, retaining the original when the live file was edited.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Original action.
	 * @param float  $deadline Unix deadline of the tick.
	 * @return bool Whether this item finished.
	 * @throws StorageFailed If undo would affect unrelated data.
	 */
	public function undo( string $path, string $action, float $deadline ): bool {
		$this->proofs->deadline = $deadline;
		try {
			return $this->undo_entry( $path, $action, $deadline );
		} catch ( FileWorkPending ) {
			return false;
		}
	}

	/**
	 * Reverses one reviewed change, retaining the original when the live file was edited.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Original action.
	 * @param float  $deadline Unix deadline of the tick.
	 * @return bool Whether this item finished.
	 * @throws StorageFailed If undo would affect unrelated data.
	 * @throws FileWorkPending If the current file verification requires another tick.
	 */
	private function undo_entry( string $path, string $action, float $deadline ): bool {
		if ( microtime( true ) >= $deadline ) {
			return false;
		}
		$record = $this->read( $path, $action );
		if ( null === $record || 'undone' === $record['phase'] ) {
			return true;
		}
		$decision = $this->undo_decision( $path, $action, $record );
		if ( 'refuse' === $decision ) {
			throw new StorageFailed( 'Undo found a later change to this live entry and left it alone.' );
		}
		if ( 'restore' === $decision ) {
			$old = $this->item_directory . '/original';
			self::set_attributes( $old, $record, true );
			$record['phase'] = 'restore';
			$this->save( $record );
			$this->require_action( $path, 'replace' );
			$this->require_parent( $path, $record );
			if ( ! self::at( $path, $record['candidate'] ) || self::metadata( $path ) !== $record['installed'] || ! $this->matches( $path, $record['source'] ) ) {
				throw new StorageFailed( 'The live file changed during undo.' );
			}
			self::publish( $old, $path );
		} elseif ( 'remove' === $decision ) {
			$this->require_parent( $path, $record );
			if ( 'mkdir' === $action ) {
				if ( [ '.', '..' ] !== scandir( $path ) || ! rmdir( $path ) ) {
					throw new StorageFailed( 'A directory created by file apply is no longer empty or cannot be removed.' );
				}
			} else {
				self::remove_file( $path );
			}
		}
		if ( 'mkdir' !== $action && 'copy' !== $record['phase'] ) {
			self::invalidate( $path );
		}
		$record['phase'] = 'undone';
		$this->save( $record );
		return true;
	}

	/**
	 * Releases an interrupted create's remaining private link before rechecking the policy.
	 *
	 * @param string $path Reviewed path.
	 * @param string $action Forward action.
	 * @param Record $record Intent.
	 * @return string Current undo decision.
	 */
	private function undo_decision( string $path, string $action, array $record ): string {
		$next = $this->item_directory . '/candidate';
		if ( 'create' === $action && 'ready' === $record['phase'] && self::at( $next, $record['candidate'] ) && self::at( $path, $record['candidate'] ) ) {
			self::remove_file( $next );
		}
		return $this->undo_action( $path, $action );
	}

	/**
	 * Creates a directory exclusively. If killed after mkdir but before recording its
	 * identity, a retry can use the directory but never claims ownership for deletion.
	 *
	 * @param string $path Reviewed path.
	 * @param Record $record Intent.
	 * @return bool Done.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function make_directory( string $path, array $record ): bool {
		if ( 'copy' === $record['phase'] ) {
			$this->require_action( $path, 'mkdir' );
			$record['phase'] = 'ready';
			$this->save( $record );
		}
		if ( self::exists( $path ) ) {
			$this->require_action( $path, 'keep' );
		} else {
			$this->require_action( $path, 'mkdir' );
			if ( ! mkdir( $path, $record['mode'] | 0700 ) ) {
				throw new StorageFailed( 'The reviewed directory could not be created.' );
			}
			$record['candidate'] = self::directory_identity( $path );
			$record['installed'] = self::metadata( $path );
		}
		$record['phase'] = 'done';
		$this->save( $record );
		return true;
	}

	/**
	 * Creates work files only underneath an existing canonical private directory.
	 *
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private function prepare_directory(): void {
		$parent = dirname( $this->item_directory );
		self::directory_identity( $parent );
		if ( ! is_dir( $this->item_directory ) && ! mkdir( $this->item_directory, 0700 ) ) {
			throw new StorageFailed( 'The file apply journal could not be created.' );
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
			throw new StorageFailed( 'The live file plan changed since it was reviewed.' );
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
		$source = $this->source_path ?? $path;
		$entry  = $this->plan->inspect( $this->files, $source, $review );
		return ( $entry['destination'] ?? ( null === $this->source_path ? $path : null ) ) === $path ? $entry['action'] : 'refuse';
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
			throw new StorageFailed( 'The parent of the reviewed file changed.' );
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
			throw new StorageFailed( 'The live file no longer matches the reviewed original.' );
		}
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
				throw new StorageFailed( 'The source changed while opening a file.' );
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
			throw new StorageFailed( 'A private file copy differs from its reviewed digest.' );
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
		clearstatcache( true );
		if ( ! is_dir( $path ) || realpath( $path ) !== $path ) {
			throw new StorageFailed( 'A file change directory is no longer a canonical directory.' );
		}
		$stat = lstat( $path );
		if ( false === $stat ) {
			throw new StorageFailed( 'The directory identity is unavailable.' );
		}
		return self::identity( $stat );
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
	 * Checks whether a name still refers to the recorded object.
	 *
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
		return hash( 'sha256', implode( '|', [ $stat['mode'], $stat['uid'], $stat['gid'], is_dir( $path ) ? 0 : $stat['mtime'] ] ) );
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
	private static function publish( string $from, string $to ): void {
		if ( ! rename( $from, $to ) ) {
			throw new StorageFailed( 'An atomic file replacement was refused.' );
		}
	}

	/**
	 * Removes one verified file.
	 *
	 * @param string $path File to unlink.
	 * @throws StorageFailed If the checked filesystem operation cannot complete.
	 */
	private static function remove_file( string $path ): void {
		if ( ! unlink( $path ) ) {
			throw new StorageFailed( 'A file change could not remove its file.' );
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
	 * Publishes a complete progress record.
	 *
	 * @param Record $record Private intent.
	 */
	private function save( array $record ): void {
		Files::write_atomic( $this->item_directory . '/entry.json', json_encode( $record, JSON_THROW_ON_ERROR ) );
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
			return null;
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
		/**
		 * The record shape and all scalar fields have been checked above.
		 *
		 * @var Record $data
		 */
		return $data;
	}
}
