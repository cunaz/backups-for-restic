<?php
/**
 * Decides which e-mail notifications to send.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Notifications;

use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\Options;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * E-mail on the first failure, once when backups become stale, and once on recovery
 * (docs/02-architecture.md §14, FR-09). A failure streak is tracked per job type, so a
 * failing check does not hide a failing backup; a stale notice is not repeated until a backup
 * succeeds again. A repository check with findings and an automatic switch to retention mode
 * `external` are reported each time they happen.
 *
 * Notices wait in an outbox in their own option until the mailer accepts them, so that a mail
 * server that is down for a while delays a notice instead of losing it. Each notice is tried
 * at most five times, with growing pauses between the attempts, and for at most two days.
 */
final class Notifier {

	public const string OPTION = 'backups_for_restic_notifications';

	/**
	 * Seconds to wait after the first, second, third and fourth failed attempt: 15 minutes, one
	 * hour, six hours and a day. With the first attempt that makes five, spread over a good day,
	 * where the heartbeat alone would use them up within twenty minutes.
	 */
	private const array RETRY_AFTER = [ 900, 3600, 21600, 86400 ];

	/** Seconds after which an unsent notice is dropped: two days. */
	private const int MAX_AGE = 172800;

	/** Job types whose outcome is reported; unlocking runs while an administrator watches. */
	private const array REPORTED = [ JobType::Backup, JobType::Forget, JobType::Prune, JobType::Check ];

	/**
	 * Creates the notifier.
	 *
	 * @param Options   $options  Options table, for the notifier's own state and outbox.
	 * @param SiteState $state    Remembered results, for the last successful backup.
	 * @param Settings  $settings Settings.
	 * @param Mailer    $mailer   Delivery.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly SiteState $state,
		private readonly Settings $settings,
		private readonly Mailer $mailer
	) {
	}

	/**
	 * Reports the outcome of a job that just ended.
	 *
	 * @param Job $job Finished job.
	 * @param int $now Unix time.
	 */
	public function job_finished( Job $job, int $now ): void {
		if ( ! in_array( $job->type, self::REPORTED, true ) || ! in_array( $job->state, [ JobState::Failed, JobState::Succeeded ], true ) ) {
			return;
		}
		$data       = $this->load();
		$type       = $job->type->value;
		$on_failure = $this->settings->bool( 'notifications.email.on_failure' );
		if ( JobState::Failed === $job->state ) {
			if ( $on_failure && ! isset( $data['failing'][ $type ] ) ) {
				$data['failing'][ $type ] = $now;
				$data['outbox'][]         = $this->entry( new Notice( NoticeKind::Failure, $now, $type, $job->id, $job->error->code ?? 'internal' ) );
			}
		} elseif ( JobState::Succeeded === $job->state ) {
			// The problem started with the first failure notice, or the stale notice for backups.
			$starts = array_filter( [ $data['failing'][ $type ] ?? 0, JobType::Backup === $job->type ? $data['stale'] : 0 ], static fn ( int $time ): bool => $time > 0 );
			$since  = [] === $starts ? 0 : min( $starts );
			unset( $data['failing'][ $type ] );
			if ( JobType::Backup === $job->type ) {
				$data['stale'] = 0;
			}
			$findings = JobType::Check === $job->type ? Decoded::int( $job->result['errors'] ?? null ) : 0;
			if ( $findings > 0 && $on_failure ) {
				$data['outbox'][] = $this->entry( new Notice( NoticeKind::CheckFindings, $now, $type, $job->id, '', 0, $findings ) );
			} elseif ( $since > 0 ) {
				$data['outbox'][] = $this->entry( new Notice( NoticeKind::Recovery, $now, $type, $job->id, '', $since ) );
			}
			if ( true === ( $job->result['retention_switched'] ?? null ) && $on_failure ) {
				$data['outbox'][] = $this->entry( new Notice( NoticeKind::RetentionExternal, $now, $type, $job->id ) );
			}
		}
		$this->save( $data );
		$this->flush( $now );
	}

	/**
	 * Reports a switch to retention mode external that happened outside a job, for example
	 * after the heartbeat's daily probe.
	 *
	 * @param int $now Unix time.
	 */
	public function retention_switched( int $now ): void {
		if ( ! $this->settings->bool( 'notifications.email.on_failure' ) ) {
			return;
		}
		$data             = $this->load();
		$data['outbox'][] = $this->entry( new Notice( NoticeKind::RetentionExternal, $now ) );
		$this->save( $data );
		$this->flush( $now );
	}

	/**
	 * Runs from the heartbeat: notices stale backups once, and retries unsent notices.
	 *
	 * @param int $now Unix time.
	 */
	public function heartbeat( int $now ): void {
		$data  = $this->load();
		$stale = $this->stale_notice( $data, $now );
		if ( null !== $stale ) {
			$data['stale']    = $now;
			$data['outbox'][] = $this->entry( $stale );
			$this->save( $data );
		}
		$this->flush( $now );
	}

	/**
	 * Hands the waiting notices that are due to the mailer. A notice it refused stays for
	 * another attempt after the pause of RETRY_AFTER; the heartbeat and every finished job call
	 * this, which must not use up the attempts of a notice while the mail server is down. After
	 * the last attempt, or two days, the notice is dropped.
	 *
	 * @param int $now Unix time.
	 */
	public function flush( int $now ): void {
		$data = $this->load();
		if ( [] === $data['outbox'] ) {
			return;
		}
		$kept = [];
		foreach ( $data['outbox'] as $entry ) {
			$notice   = Notice::from_array( $entry );
			$attempts = Decoded::count( $entry['attempts'] ?? null );
			$tried_at = Decoded::int( $entry['tried_at'] ?? null );
			if ( null === $notice || $attempts > count( self::RETRY_AFTER ) || $now - $notice->at > self::MAX_AGE ) {
				continue;
			}
			if ( $attempts > 0 && $now - $tried_at < self::RETRY_AFTER[ $attempts - 1 ] ) {
				$kept[] = self::waiting( $notice, $attempts, $tried_at );
				continue;
			}
			if ( ! $this->mailer->send( $notice ) && $attempts < count( self::RETRY_AFTER ) ) {
				$kept[] = self::waiting( $notice, $attempts + 1, $now );
			}
		}
		$data['outbox'] = $kept;
		$this->save( $data );
	}

	/**
	 * Notices waiting for delivery.
	 *
	 * @return int Count.
	 */
	public function pending(): int {
		return count( $this->load()['outbox'] );
	}

	/**
	 * The stale notice that is due now, if any.
	 *
	 * @param array{failing: array<string, int>, stale: int, outbox: list<array<mixed>>} $data State.
	 * @param int                                                                        $now  Unix time.
	 * @return Notice|null Notice.
	 */
	private function stale_notice( array $data, int $now ): ?Notice {
		$limit = SchedulePolicy::stale_after( $this->settings );
		if ( null === $limit || $data['stale'] > 0 || ! $this->settings->bool( 'setup.completed' ) || ! $this->settings->bool( 'notifications.email.on_stale' ) ) {
			return null;
		}
		$last = $this->state->get( 'last_backup' );
		$job  = Decoded::string( $last['job'] ?? null );
		// Without a backup in this repository yet, the time counts from the setup, or from the
		// day the jobs turned to this repository if that was later.
		$since = '' === $job ? max( $this->setup_time(), Decoded::int( $this->state->get( 'repository' )['since'] ?? null ) ) : Decoded::int( $last['finished'] ?? null );
		if ( 0 === $since || $now - $since < $limit ) {
			return null;
		}
		return new Notice( NoticeKind::Stale, $now, '', $job, '', $since );
	}

	/**
	 * When the setup wizard finished.
	 *
	 * @return int Unix time, or 0 if unknown.
	 */
	private function setup_time(): int {
		$time = strtotime( $this->settings->string( 'setup.recovery_kit_acknowledged_at' ) );
		return false === $time ? 0 : $time;
	}

	/**
	 * An outbox entry for a new notice.
	 *
	 * @param Notice $notice Notice.
	 * @return array<mixed> Entry.
	 */
	private function entry( Notice $notice ): array {
		return self::waiting( $notice, 0, 0 );
	}

	/**
	 * An outbox entry.
	 *
	 * @param Notice $notice   Notice.
	 * @param int    $attempts Failed attempts so far.
	 * @param int    $tried_at Unix time of the last attempt; 0 before the first.
	 * @return array<mixed> Entry.
	 */
	private static function waiting( Notice $notice, int $attempts, int $tried_at ): array {
		return [
			'attempts' => $attempts,
			'tried_at' => $tried_at,
		] + $notice->to_array();
	}

	/**
	 * The stored state.
	 *
	 * @return array{failing: array<string, int>, stale: int, outbox: list<array<mixed>>} State.
	 */
	private function load(): array {
		$stored  = $this->options->get( self::OPTION );
		$stored  = is_array( $stored ) ? $stored : [];
		$failing = [];
		foreach ( is_array( $stored['failing'] ?? null ) ? $stored['failing'] : [] as $type => $since ) {
			if ( is_string( $type ) && is_int( $since ) ) {
				$failing[ $type ] = $since;
			}
		}
		return [
			'failing' => $failing,
			'stale'   => Decoded::int( $stored['stale'] ?? null ),
			'outbox'  => Decoded::arrays( $stored['outbox'] ?? null ),
		];
	}

	/**
	 * Stores the state; not autoloaded.
	 *
	 * @param array{failing: array<string, int>, stale: int, outbox: list<array<mixed>>} $data State.
	 */
	private function save( array $data ): void {
		$this->options->set( self::OPTION, $data, false );
	}
}
