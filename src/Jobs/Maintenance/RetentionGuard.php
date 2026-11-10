<?php
/**
 * The retention modes of ADR 0010.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the asymmetric switching of ADR 0010 to each append-only detection: a server that
 * refuses deletions switches retention to `external` at once; a server that allows them
 * again while retention is external only leads to a proposal, which an administrator has to
 * confirm. Forget and prune jobs and the heartbeat's daily probe share this logic.
 */
final class RetentionGuard {

	/**
	 * The retention mode as this guard last saw or set it.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Creates the guard.
	 *
	 * @param SiteState              $state            Remembered results.
	 * @param string                 $mode             Current retention mode, plugin or external.
	 * @param \Closure(string): void $switch_retention Saves the retention mode.
	 * @param Clock                  $clock            Time source.
	 */
	public function __construct(
		private readonly SiteState $state,
		string $mode,
		private readonly \Closure $switch_retention,
		private readonly Clock $clock
	) {
		$this->mode = $mode;
	}

	/**
	 * Records a detection and applies the switching rules.
	 *
	 * @param AppendOnlyStatus $status What the probe found.
	 * @param string           $job    Job that probed, or empty for the heartbeat.
	 * @return bool True if retention switched to external just now.
	 */
	public function detected( AppendOnlyStatus $status, string $job = '' ): bool {
		$now      = $this->clock->now()[0];
		$previous = $this->state->get( 'append_only' );
		$this->state->set(
			'append_only',
			[
				// The latest probe, with its time: after an inconclusive one the heartbeat probes
				// again an hour later instead of a day later.
				'status'     => $status->value,
				'checked_at' => $now,
				// The latest probe that had an answer. An inconclusive probe does not erase it.
				'conclusive' => AppendOnlyStatus::Unknown === $status ? self::conclusive( $previous ) : $status->value,
			]
		);
		if ( AppendOnlyStatus::AppendOnly === $status ) {
			return $this->refused( $job );
		}
		if ( 'external' === $this->mode && AppendOnlyStatus::DeletionsAllowed === $status && [] === $this->state->get( 'retention_proposal' ) ) {
			$this->state->set(
				'retention_proposal',
				[
					'mode'  => 'plugin',
					'since' => $now,
				]
			);
		}
		return false;
	}

	/**
	 * Whether the server is append-only, for the dashboard: what the latest conclusive probe
	 * found, else what the wizard found when the server was saved.
	 *
	 * @param string $wizard The wizard's answer: yes, no or unknown.
	 * @return string yes, no or unknown.
	 */
	public function append_only( string $wizard ): string {
		return match ( self::conclusive( $this->state->get( 'append_only' ) ) ) {
			AppendOnlyStatus::AppendOnly->value       => 'yes',
			AppendOnlyStatus::DeletionsAllowed->value => 'no',
			default                                   => $wizard,
		};
	}

	/**
	 * The answer of the latest conclusive probe in a stored entry. An entry written before the
	 * conclusive answer was kept apart has only the status of its latest probe.
	 *
	 * @param array<string, mixed> $probe Stored entry.
	 * @return string append_only, deletions_allowed, or unknown if no probe had an answer yet.
	 */
	private static function conclusive( array $probe ): string {
		$status = $probe['conclusive'] ?? $probe['status'] ?? null;
		return is_string( $status ) && null !== AppendOnlyStatus::tryFrom( $status ) ? $status : AppendOnlyStatus::Unknown->value;
	}

	/**
	 * The server refused a deletion: switches retention to external unless it is already.
	 *
	 * @param string $job Job that saw the refusal, or empty for the heartbeat.
	 * @return bool True if retention switched to external just now.
	 */
	public function refused( string $job = '' ): bool {
		$this->state->set( 'retention_proposal', [] );
		if ( 'external' === $this->mode ) {
			return false;
		}
		( $this->switch_retention )( 'external' );
		$this->mode = 'external';
		$this->state->set(
			'retention_switch',
			[
				'mode' => 'external',
				'at'   => $this->clock->now()[0],
				'job'  => $job,
			]
		);
		return true;
	}

	/**
	 * Whether retention is external.
	 *
	 * @return bool True if old snapshots are cleaned up elsewhere.
	 */
	public function is_external(): bool {
		return 'external' === $this->mode;
	}
}
