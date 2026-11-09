<?php
/**
 * Kinds of jobs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Job types and their step lists. Restore joins in Milestone 3 (docs/05-roadmap.md).
 */
enum JobType: string {

	case Backup = 'backup';

	/** Derives and caches the master key, for the setup wizard. */
	case Unlock = 'unlock';

	/** Removes this site's snapshots that the retention policy no longer keeps (ADR 0010). */
	case Forget = 'forget';

	/** Deletes packs that no snapshot uses (ADR 0022). */
	case Prune = 'prune';

	/** Verifies the repository with a random share of packs read (ADR 0022). */
	case Check = 'check';

	/**
	 * Steps of the regular path, in order.
	 *
	 * @return list<string> Step names.
	 */
	public function steps(): array {
		return match ( $this ) {
			self::Backup => [ 'preflight', 'unlocking', 'backing_up', 'recording' ],
			self::Unlock => [ 'unlocking' ],
			self::Forget => [ 'preflight', 'unlocking', 'detecting', 'forgetting', 'recording' ],
			self::Prune  => [ 'preflight', 'unlocking', 'detecting', 'pruning', 'recording' ],
			self::Check  => [ 'preflight', 'unlocking', 'checking', 'recording' ],
		};
	}

	/**
	 * Steps that run on every path to the end: success, failure and cancellation.
	 *
	 * @return list<string> Step names.
	 */
	public function cleanup_steps(): array {
		return match ( $this ) {
			self::Unlock => [ 'cleaning_up' ],
			default      => [ 'releasing', 'cleaning_up' ],
		};
	}

	/**
	 * Whether the job maintains the repository rather than writing a snapshot.
	 *
	 * @return bool True for forget, prune and check.
	 */
	public function is_maintenance(): bool {
		return in_array( $this, [ self::Forget, self::Prune, self::Check ], true );
	}

	/**
	 * The step after another one.
	 *
	 * @param string $step Current step.
	 * @return string|null Next step, or null at the end.
	 */
	public function next_step( string $step ): ?string {
		$all   = array_merge( $this->steps(), $this->cleanup_steps() );
		$index = array_search( $step, $all, true );
		return false === $index ? null : ( $all[ $index + 1 ] ?? null );
	}

	/**
	 * Whether a step belongs to the cleanup part.
	 *
	 * @param string $step Step name.
	 * @return bool True if it is a cleanup step.
	 */
	public function is_cleanup( string $step ): bool {
		return in_array( $step, $this->cleanup_steps(), true );
	}
}
