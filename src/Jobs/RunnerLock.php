<?php
/**
 * One runner at a time.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A non-blocking flock() on runner.lock in the private directory, so that a WP-Cron tick, an
 * admin polling tick and a WP-CLI tick never advance a job at the same time
 * (docs/02-architecture.md §4.3). The lease in the job document covers hosts where several
 * web servers share a network filesystem without working flock().
 */
final class RunnerLock {

	/**
	 * Open lock file while held.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Uses a lock file.
	 *
	 * @param string $file Lock file.
	 */
	public function __construct( private readonly string $file ) {
	}

	/**
	 * Tries to take the lock without waiting.
	 *
	 * @return bool True if this process holds it now.
	 */
	public function acquire(): bool {
		if ( null !== $this->handle ) {
			return true;
		}
		$handle = fopen( $this->file, 'c' );
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}
		$this->handle = $handle;
		return true;
	}

	/**
	 * Releases the lock.
	 */
	public function release(): void {
		if ( null !== $this->handle ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
			$this->handle = null;
		}
	}
}
