<?php
/**
 * A lock for short changes that several requests make to the same stored value.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * A flock() on a file in the private directory. A request that reads a value, changes it and
 * writes it back holds the lock for that long, so that no other request's change in between
 * is written over.
 *
 * Required changes fail closed when exclusive access is unavailable. A caller that only
 * records a diagnostic may opt into best-effort writes. As with the runner lock, a shared
 * filesystem must implement flock() across every worker.
 */
final class FileLock {

	/**
	 * How often this request holds the lock; the same request may take it again.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Names the lock file.
	 *
	 * @param string|(\Closure(): string) $file Path, or a resolver that prepares the private directory on first use.
	 */
	public function __construct( private readonly string|\Closure $file ) {
	}

	/**
	 * Runs the work while holding the lock, waiting for another request that holds it.
	 *
	 * @template T
	 * @param \Closure(): T $work What reads, changes and writes.
	 * @param bool          $required Fail if this operation cannot run exclusively.
	 * @param-immediately-invoked-callable $work
	 * @return T What the work returned.
	 */
	public function hold( \Closure $work, bool $required = false ): mixed {
		if ( $this->depth > 0 ) {
			return $work();
		}
		$handle = $this->acquire( true );
		if ( ! is_resource( $handle ) ) {
			if ( $required ) {
				StorageFailed::raise( 'The exclusive file lock could not be acquired.' );
			}
			return $work();
		}
		return $this->run( $handle, $work );
	}

	/**
	 * Runs the work while holding the lock, unless another request holds it.
	 *
	 * @param \Closure(): void $work What only one request at a time should do.
	 * @param-immediately-invoked-callable $work
	 * @return bool False if another request holds the lock; the work did not run.
	 */
	public function hold_if_free( \Closure $work ): bool {
		if ( $this->depth > 0 ) {
			$work();
			return true;
		}
		$handle = $this->acquire( false );
		if ( false === $handle ) {
			return false;
		}
		if ( null === $handle ) {
			$work();
		} else {
			$this->run( $handle, $work );
		}
		return true;
	}

	/**
	 * Prepares and locks the file. A busy nonblocking lock is distinct from an unavailable file.
	 *
	 * @param bool $wait Whether to wait for a lock held by another request.
	 * @return resource|false|null Locked handle, false if busy, null if locking failed.
	 */
	private function acquire( bool $wait ) {
		try {
			$file = $this->file instanceof \Closure ? ( $this->file )() : $this->file;
		} catch ( StorageFailed ) {
			return null;
		}
		if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) {
			return null;
		}
		$directory = dirname( $file );
		$usable    = is_file( $file ) ? is_writable( $file ) : is_dir( $directory ) && is_writable( $directory );
		$handle    = $usable ? fopen( $file, 'c' ) : false;
		if ( false === $handle ) {
			return null;
		}
		$busy = 0;
		if ( flock( $handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB, $busy ) ) {
			return $handle;
		}
		fclose( $handle );
		return 1 === $busy ? false : null;
	}

	/**
	 * Runs the work and releases the lock.
	 *
	 * @template T
	 * @param resource      $handle Locked file.
	 * @param \Closure(): T $work   Work.
	 * @param-immediately-invoked-callable $work
	 * @return T What the work returned.
	 */
	private function run( $handle, \Closure $work ): mixed {
		++$this->depth;
		try {
			return $work();
		} finally {
			--$this->depth;
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}
}
