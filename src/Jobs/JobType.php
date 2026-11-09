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
 * Job types and their step lists. Forget, prune, check and restore join in later milestones
 * with their own lists (docs/05-roadmap.md).
 */
enum JobType: string {

	case Backup = 'backup';

	/** Derives and caches the master key, for the setup wizard. */
	case Unlock = 'unlock';

	/**
	 * Steps of the regular path, in order.
	 *
	 * @return list<string> Step names.
	 */
	public function steps(): array {
		return match ( $this ) {
			self::Backup => [ 'preflight', 'unlocking', 'backing_up', 'recording' ],
			self::Unlock => [ 'unlocking' ],
		};
	}

	/**
	 * Steps that run on every path to the end: success, failure and cancellation.
	 *
	 * @return list<string> Step names.
	 */
	public function cleanup_steps(): array {
		return match ( $this ) {
			self::Backup => [ 'releasing', 'cleaning_up' ],
			self::Unlock => [ 'cleaning_up' ],
		};
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
