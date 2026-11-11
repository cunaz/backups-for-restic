<?php
/**
 * A lock for short changes that several requests make to the same stored value.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A flock() on a file in the private directory. A request that reads a value, changes it and
 * writes it back holds the lock for that long, so that no other request's change in between
 * is written over.
 *
 * Where the file cannot be opened or locked, as before the private directory exists, the
 * work runs without the lock: a change that may be lost in a rare collision is better than
 * one that is not made at all. Like the runner's lock, this one does not hold across web
 * servers that share a network file system without a working flock().
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
	 * @param string $file Path of the lock file; its directory need not exist.
	 */
	public function __construct( private readonly string $file ) {
	}

	/**
	 * Runs the work while holding the lock, waiting for another request that holds it.
	 *
	 * @template T
	 * @param \Closure(): T $work What reads, changes and writes.
	 * @param-immediately-invoked-callable $work
	 * @return T What the work returned.
	 */
	public function hold( \Closure $work ): mixed {
		if ( $this->depth > 0 ) {
			return $work();
		}
		$handle = $this->open();
		if ( null !== $handle ) {
			// Should this fail, the work runs all the same.
			flock( $handle, LOCK_EX );
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
		$handle = $this->open();
		$busy   = 0;
		if ( null !== $handle && ! flock( $handle, LOCK_EX | LOCK_NB, $busy ) && 1 === $busy ) {
			fclose( $handle );
			return false;
		}
		$this->run( $handle, $work );
		return true;
	}

	/**
	 * Opens the lock file.
	 *
	 * @return resource|null Handle; null where there is no file to lock.
	 */
	private function open() {
		// Asked first: before setup there is no private directory, and that is no error.
		$directory = dirname( $this->file );
		$usable    = is_file( $this->file ) ? is_writable( $this->file ) : is_dir( $directory ) && is_writable( $directory );
		$handle    = $usable ? fopen( $this->file, 'c' ) : false;
		return false === $handle ? null : $handle;
	}

	/**
	 * Runs the work and releases the lock.
	 *
	 * @template T
	 * @param resource|null $handle Lock file, if there is one.
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
			if ( null !== $handle ) {
				flock( $handle, LOCK_UN );
				fclose( $handle );
			}
		}
	}
}
