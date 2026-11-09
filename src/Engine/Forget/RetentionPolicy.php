<?php
/**
 * Which snapshots to keep.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Forget;

defined( 'ABSPATH' ) || exit;

/**
 * The --keep-* options of restic. Counts of 0 disable a rule; -1 means unlimited.
 */
final readonly class RetentionPolicy {

	/**
	 * Creates a policy.
	 *
	 * @param int                $last           Keep the last n snapshots.
	 * @param int                $hourly         Keep the last n hourly snapshots.
	 * @param int                $daily          Keep the last n daily snapshots.
	 * @param int                $weekly         Keep the last n weekly snapshots.
	 * @param int                $monthly        Keep the last n monthly snapshots.
	 * @param int                $yearly         Keep the last n yearly snapshots.
	 * @param Duration|null      $within         Keep everything within this duration of the latest snapshot.
	 * @param Duration|null      $within_hourly  Keep hourly snapshots within this duration.
	 * @param Duration|null      $within_daily   Keep daily snapshots within this duration.
	 * @param Duration|null      $within_weekly  Keep weekly snapshots within this duration.
	 * @param Duration|null      $within_monthly Keep monthly snapshots within this duration.
	 * @param Duration|null      $within_yearly  Keep yearly snapshots within this duration.
	 * @param list<list<string>> $keep_tags      Keep snapshots that carry all tags of any list.
	 */
	public function __construct(
		public int $last = 0,
		public int $hourly = 0,
		public int $daily = 0,
		public int $weekly = 0,
		public int $monthly = 0,
		public int $yearly = 0,
		public ?Duration $within = null,
		public ?Duration $within_hourly = null,
		public ?Duration $within_daily = null,
		public ?Duration $within_weekly = null,
		public ?Duration $within_monthly = null,
		public ?Duration $within_yearly = null,
		public array $keep_tags = []
	) {
	}

	/**
	 * Whether the policy keeps nothing; such a policy must never be applied.
	 *
	 * @return bool True if no rule is set.
	 */
	public function is_empty(): bool {
		foreach ( [ $this->last, $this->hourly, $this->daily, $this->weekly, $this->monthly, $this->yearly ] as $count ) {
			if ( 0 !== $count ) {
				return false;
			}
		}
		foreach ( [ $this->within, $this->within_hourly, $this->within_daily, $this->within_weekly, $this->within_monthly, $this->within_yearly ] as $duration ) {
			if ( null !== $duration && ! $duration->is_zero() ) {
				return false;
			}
		}
		return [] === $this->keep_tags;
	}
}
