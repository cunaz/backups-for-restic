<?php
/**
 * The retention modes of ADR 0010.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the asymmetric switching of ADR 0010 to each append-only detection: a server that
 * refuses deletions switches retention to `external` at once; a server that allows them
 * again while retention is external only leads to a proposal, which an administrator has to
 * confirm. Forget and prune jobs and the heartbeat's daily probe share this logic.
 *
 * What a probe found is remembered together with the repository it tested. It says nothing
 * about another server or account, so after the repository changed it is neither shown nor
 * a reason to wait with the next probe.
 */
final class RetentionGuard {

	/** Seconds between the heartbeat's probes, and until the next one after a probe without an answer. */
	private const int PROBE_EVERY = 86400;
	private const int PROBE_AGAIN = 3600;

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
	 * @param string                 $repository       Names the repository the guard speaks for; see repository().
	 */
	public function __construct(
		private readonly SiteState $state,
		string $mode,
		private readonly \Closure $switch_retention,
		private readonly Clock $clock,
		private readonly string $repository
	) {
		$this->mode = $mode;
	}

	/**
	 * Names the repository of the settings for remembered probe results: a fingerprint of its
	 * address and REST user name, which the state option has no need to repeat. Whether
	 * deletions are refused is a matter of the server and the account.
	 *
	 * @param Settings $settings Settings.
	 * @return string Fingerprint.
	 */
	public static function repository( Settings $settings ): string {
		return hash( 'sha256', $settings->string( 'repository.url' ) . "\n" . $settings->string( 'repository.rest_username' ) );
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
		$previous = $this->probe();
		$this->state->set(
			'append_only',
			[
				// The latest probe, with its time: after an inconclusive one the heartbeat probes
				// again an hour later instead of a day later.
				'status'     => $status->value,
				'checked_at' => $now,
				// The latest probe that had an answer. An inconclusive probe does not erase it.
				'conclusive' => AppendOnlyStatus::Unknown === $status ? self::conclusive( $previous ) : $status->value,
				// The repository that was probed.
				'repository' => $this->repository,
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
	 * Whether the server is append-only, for the dashboard: what the latest conclusive probe of
	 * this repository found, else what the wizard found when the server was saved.
	 *
	 * @param string $wizard The wizard's answer: yes, no or unknown.
	 * @return string yes, no or unknown.
	 */
	public function append_only( string $wizard ): string {
		return match ( self::conclusive( $this->probe() ) ) {
			AppendOnlyStatus::AppendOnly->value       => 'yes',
			AppendOnlyStatus::DeletionsAllowed->value => 'no',
			default                                   => $wizard,
		};
	}

	/**
	 * Whether the heartbeat should probe now: a day after the latest probe of this repository,
	 * an hour after one without an answer, and at once if this repository was never probed, for
	 * example right after another server was saved.
	 *
	 * @return bool True if a probe is due.
	 */
	public function probe_due(): bool {
		$probe = $this->probe();
		if ( [] === $probe ) {
			return true;
		}
		$wait = AppendOnlyStatus::Unknown->value === ( $probe['status'] ?? null ) ? self::PROBE_AGAIN : self::PROBE_EVERY;
		return $this->clock->now()[0] - Decoded::int( $probe['checked_at'] ?? null ) >= $wait;
	}

	/**
	 * The remembered probe result, if it is about this guard's repository.
	 *
	 * @return array<string, mixed> Stored entry; empty if there is none or it tested another repository.
	 */
	private function probe(): array {
		$probe = $this->state->get( 'append_only' );
		return ( $probe['repository'] ?? null ) === $this->repository ? $probe : [];
	}

	/**
	 * The answer of the latest conclusive probe in a stored entry.
	 *
	 * @param array<string, mixed> $probe Stored entry.
	 * @return string append_only, deletions_allowed, or unknown if no probe had an answer yet.
	 */
	private static function conclusive( array $probe ): string {
		$status = $probe['conclusive'] ?? null;
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
