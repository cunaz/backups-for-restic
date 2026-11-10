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
 * A request can die between writing a lock and recording its name, for example during a
 * refresh. The lock it leaves carries this run's host name and number, so it is recognised as
 * a leftover: it never blocks the run itself, and taking, refreshing or releasing a lock
 * deletes it. Runs therefore need a number of their own (restic uses the process ID; the
 * plugin draws a random one per run, because PHP processes are reused across requests).
 *
 * Backups hold non-exclusive locks; maintenance holds exclusive ones. A lock older than 30
 * minutes is stale for every client, so holders refresh it every five minutes. A run that was
 * paused for longer takes its lock again instead of refreshing it; what another client did to
 * the repository in the meantime is not undone by that, so each run then checks that what it
 * relies on is still there, or starts over (ADR 0030). Lock files can be deleted even in
 * append-only repositories.
 */
final class Locking {

	/** Refresh a held lock once it is this old. */
	public const int REFRESH_AFTER_SECONDS = 300;

	/**
	 * A held lock counts as honoured by other clients until it is this old: five minutes less
	 * than the 30 after which they call it stale, because they judge by their own clocks.
	 */
	public const int TRUSTED_SECONDS = Lock::STALE_AFTER_SECONDS - 300;

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
	 * Takes a lock after checking for conflicting ones; deletes leftovers of this run first.
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
	 * Replaces a held lock with a fresh one and deletes leftovers of this run.
	 *
	 * @param Id   $id        Current lock.
	 * @param bool $exclusive Lock type.
	 * @return Id New lock.
	 */
	public function refresh( Id $id, bool $exclusive ): Id {
		$fresh = $this->write( $exclusive );
		$this->delete( $id->hex() );
		$this->remove_leftovers( $fresh );
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
	 * Whether other clients may have ignored a held lock: 30 minutes after it was written a
	 * lock is stale for every client, and a client whose clock runs ahead says so earlier. A
	 * run that finds its lock that old takes it again with acquire(), which notices a client
	 * that holds a conflicting lock by now, and then deals with what may have changed.
	 *
	 * @param int $locked_at Unix time the lock was written.
	 * @return bool True if the lock can no longer be trusted.
	 */
	public function lapsed( int $locked_at ): bool {
		return $this->clock->now()[0] - $locked_at > self::TRUSTED_SECONDS;
	}

	/**
	 * Deletes a held lock and every leftover of this run.
	 *
	 * @param Id|null $id Lock to delete; null if the run recorded none, for example because its
	 *                    request ended between writing the lock and recording it.
	 */
	public function release( ?Id $id ): void {
		if ( null !== $id ) {
			$this->delete( $id->hex() );
		}
		$this->remove_leftovers( null );
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
	 * Deletes the locks this run left behind, except the one it holds.
	 *
	 * @param Id|null $held Lock to keep.
	 */
	private function remove_leftovers( ?Id $held ): void {
		foreach ( $this->locks() as $hex => $lock ) {
			if ( $this->is_leftover( $lock ) && $hex !== $held?->hex() ) {
				$this->delete( $hex );
			}
		}
	}

	/**
	 * Whether a lock was written by this run.
	 *
	 * @param Lock $lock Lock.
	 * @return bool True for the same host name and run number; never without a run number.
	 */
	private function is_leftover( Lock $lock ): bool {
		return 0 !== $this->pid && $lock->pid === $this->pid && $lock->hostname === $this->identity->hostname;
	}

	/**
	 * Deletes a lock file; one that is already gone is fine.
	 *
	 * @param string $hex Name.
	 */
	private function delete( string $hex ): void {
		try {
			$this->repository->backend()->delete( ObjectType::Locks, $hex );
		} catch ( NotFound ) {
			return;
		}
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
	 * Fails if a live lock of another client conflicts with the requested one. Before writing
	 * (no own lock yet), leftovers of this run are deleted on the way.
	 *
	 * @param bool    $exclusive Requested type.
	 * @param Id|null $own       Own lock to ignore, once written.
	 * @throws LockConflict If a conflicting live lock exists.
	 */
	private function assert_no_conflict( bool $exclusive, ?Id $own ): void {
		$now = $this->clock->now()[0];
		foreach ( $this->locks() as $hex => $lock ) {
			if ( null !== $own && $own->hex() === $hex ) {
				continue;
			}
			if ( null === $own && $this->is_leftover( $lock ) ) {
				// A leftover of this run, from a request that died before it recorded the lock.
				$this->delete( $hex );
				continue;
			}
			if ( ( $exclusive || $lock->exclusive ) && ! $lock->is_stale( $now ) && ! $this->is_leftover( $lock ) ) {
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
