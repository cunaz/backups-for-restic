<?php
/**
 * Repository locks.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Takes, refreshes and releases locks the way restic expects.
 *
 * Backups hold non-exclusive locks; maintenance holds exclusive ones. A lock older than 30
 * minutes is stale for every client, so holders refresh it every five minutes. Lock files can be
 * deleted even in append-only repositories.
 */
final class Locking {

	/** Refresh a held lock once it is this old. */
	public const int REFRESH_AFTER_SECONDS = 300;

	/**
	 * Creates the helper.
	 *
	 * @param Repository $repository Repository.
	 * @param Identity   $identity   Recorded in lock files.
	 * @param Clock      $clock      Time source.
	 * @param int        $pid        Process or job number recorded in lock files.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly Identity $identity,
		private readonly Clock $clock,
		private readonly int $pid
	) {
	}

	/**
	 * Takes a lock after checking for conflicting ones.
	 *
	 * @param bool $exclusive Exclusive lock (maintenance) or shared lock (backup).
	 * @return Id Name of the lock file.
	 * @throws LockConflict If another live lock conflicts.
	 */
	public function acquire( bool $exclusive ): Id {
		$this->assert_no_conflict( $exclusive, null );
		$id = $this->write( $exclusive );
		try {
			// restic re-checks after writing: two clients may have locked at the same time.
			$this->assert_no_conflict( $exclusive, $id );
		} catch ( LockConflict $conflict ) {
			$this->release( $id );
			throw $conflict;
		}
		return $id;
	}

	/**
	 * Replaces a held lock with a fresh one.
	 *
	 * @param Id   $id        Current lock.
	 * @param bool $exclusive Lock type.
	 * @return Id New lock.
	 */
	public function refresh( Id $id, bool $exclusive ): Id {
		$fresh = $this->write( $exclusive );
		$this->release( $id );
		return $fresh;
	}

	/**
	 * Whether a held lock should be refreshed.
	 *
	 * @param int $locked_at Unix time the lock was written.
	 * @return bool True if older than five minutes.
	 */
	public function due( int $locked_at ): bool {
		return $this->clock->now()[0] - $locked_at >= self::REFRESH_AFTER_SECONDS;
	}

	/**
	 * Deletes a lock.
	 *
	 * @param Id $id Lock to delete.
	 */
	public function release( Id $id ): void {
		$this->repository->backend()->delete( ObjectType::Locks, $id->hex() );
	}

	/**
	 * Deletes stale locks written by this site (same host name).
	 *
	 * @return int Number of removed locks.
	 */
	public function remove_own_stale(): int {
		$removed = 0;
		foreach ( $this->locks() as $hex => $lock ) {
			if ( $lock->hostname === $this->identity->hostname && $lock->is_stale( $this->clock->now()[0] ) ) {
				$this->repository->backend()->delete( ObjectType::Locks, $hex );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Writes a lock file.
	 *
	 * @param bool $exclusive Lock type.
	 * @return Id Name of the lock file.
	 */
	private function write( bool $exclusive ): Id {
		[ $seconds, $nanoseconds ] = $this->clock->now();
		$lock                      = new Lock(
			Rfc3339::format( $seconds, $nanoseconds ),
			$exclusive,
			$this->identity->hostname,
			$this->identity->username,
			$this->pid,
			$this->identity->uid,
			$this->identity->gid
		);
		return $this->repository->save_unpacked( ObjectType::Locks, $lock->to_json() );
	}

	/**
	 * Fails if a live lock conflicts with the requested one.
	 *
	 * @param bool    $exclusive Requested type.
	 * @param Id|null $own       Own lock to ignore.
	 * @throws LockConflict If a conflicting live lock exists.
	 */
	private function assert_no_conflict( bool $exclusive, ?Id $own ): void {
		$now = $this->clock->now()[0];
		foreach ( $this->locks() as $hex => $lock ) {
			if ( null !== $own && $own->hex() === $hex ) {
				continue;
			}
			if ( ( $exclusive || $lock->exclusive ) && ! $lock->is_stale( $now ) ) {
				LockConflict::raise(
					'The repository is locked by another process.',
					[
						'hostname'  => $lock->hostname,
						'exclusive' => $lock->exclusive,
						'time'      => $lock->time,
					]
				);
			}
		}
	}

	/**
	 * Loads all lock files, skipping ones that vanish while listing.
	 *
	 * @return array<string, Lock> Locks by hex name.
	 */
	private function locks(): array {
		$locks = [];
		foreach ( array_keys( $this->repository->backend()->list( ObjectType::Locks ) ) as $name ) {
			try {
				$locks[ $name ] = Lock::from_json( $this->repository->load_unpacked( ObjectType::Locks, Id::from_hex( $name ) ) );
			} catch ( NotFound ) {
				continue;
			}
		}
		return $locks;
	}
}
