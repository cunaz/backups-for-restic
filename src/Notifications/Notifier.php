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
use BackupsForRestic\Storage\FileLock;
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
 *
 * A server clock that was ahead and is set back leaves recorded times in the future
 * (SchedulePolicy::ahead()): the age of the last backup would be negative, and no stale
 * notice would come until the clock caught up. Such a time is not used; the age counts from
 * the moment it was found instead, until a newer backup replaces it, and the administrator
 * hears of that moment once.
 *
 * @phpstan-type Data array{repository: string|null, failing: array<string, int>, stale: int, clock: int, clock_since: int, outbox: list<array<mixed>>}
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

	/** Job types whose outcome is reported; unlocking and restoring run while an administrator watches. */
	private const array REPORTED = [ JobType::Backup, JobType::Forget, JobType::Prune, JobType::Check ];

	/**
	 * Creates the notifier.
	 *
	 * @param Options       $options  Options table, for the notifier's own state and outbox.
	 * @param SiteState     $state    Remembered results, for the last successful backup.
	 * @param Settings      $settings Settings.
	 * @param Mailer        $mailer   Delivery.
	 * @param FileLock|null $lock     Held while the state is read, changed and written back.
	 * @param FileLock|null $sending  Held by the one request that hands notices to the mailer.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly SiteState $state,
		private readonly Settings $settings,
		private readonly Mailer $mailer,
		private readonly ?FileLock $lock = null,
		private readonly ?FileLock $sending = null
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
		$this->change( fn ( array $data ): array => $this->counted( $data, $job, $now ) );
		$this->flush( $now );
	}

	/**
	 * Counts the outcome of a job and queues the notices it calls for.
	 *
	 * @param Data $data State.
	 * @param Job  $job  Finished job.
	 * @param int  $now  Unix time.
	 * @return Data State with the outcome.
	 */
	private function counted( array $data, Job $job, int $now ): array {
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
		return $data;
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
		$this->change(
			function ( array $data ) use ( $now ): array {
				$data['outbox'][] = $this->entry( new Notice( NoticeKind::RetentionExternal, $now ) );
				return $data;
			}
		);
		$this->flush( $now );
	}

	/**
	 * Runs from the heartbeat: notices stale backups once, and retries unsent notices.
	 *
	 * @param int $now Unix time.
	 */
	public function heartbeat( int $now ): void {
		$this->change(
			function ( array $data ) use ( $now ): ?array {
				[ $job, $since ] = $this->stale_since();
				$before          = $data;
				$data            = $this->reference( $data, $since, $now );
				if ( 0 !== $data['clock'] ) {
					// Recorded by a clock that was ahead: the age counts from when that was found.
					$since = $data['clock'];
				}
				$stale = $this->stale_notice( $data, $job, $since, $now );
				if ( null !== $stale ) {
					$data['stale']    = $now;
					$data['outbox'][] = $this->entry( $stale );
				}
				return $before === $data ? null : $data;
			}
		);
		$this->flush( $now );
	}

	/**
	 * Keeps the time from which the age of the backups counts while the record they would
	 * count from is one that lay ahead of the clock when it was found. It ends only when a
	 * newer backup, or another repository, gives the age a record of its own: once the clock
	 * comes within the hour of the wrong record, that record would pass for a right one, and
	 * the age would count from it again, as much later as the clock had been ahead.
	 *
	 * @param Data $data  State.
	 * @param int  $since The time the record of the last backup, or of the setup, gives.
	 * @param int  $now   Unix time.
	 * @return Data State.
	 */
	private function reference( array $data, int $since, int $now ): array {
		if ( 0 !== $data['clock'] && $data['clock_since'] !== $since ) {
			$data['clock']       = 0;
			$data['clock_since'] = 0;
		}
		if ( SchedulePolicy::ahead( $since, $now ) && ( 0 === $data['clock'] || SchedulePolicy::ahead( $data['clock'], $now ) ) ) {
			$data['clock']       = $now;
			$data['clock_since'] = $since;
			$data                = $this->clock_notice( $data, $now, $since );
		}
		return $data;
	}

	/**
	 * Reports that the heartbeat found a handled schedule slot that lies ahead of the clock
	 * and put it back to the current slot: the server clock was set back after it had been
	 * ahead. One notice for one setting back, however many recorded times it left ahead.
	 *
	 * @param int $now   Unix time.
	 * @param int $ahead The latest of the times that lay ahead.
	 */
	public function clock_set_back( int $now, int $ahead ): void {
		$this->change(
			function ( array $data ) use ( $now, $ahead ): ?array {
				[ , $since ] = $this->stale_since();
				if ( 0 !== $data['clock'] && $data['clock_since'] === $since && ! SchedulePolicy::ahead( $data['clock'], $now ) ) {
					// Found with the record of the last backup, which lies ahead too, and reported then.
					return null;
				}
				if ( SchedulePolicy::ahead( $since, $now ) ) {
					// The same setting back: the age of the backups counts from now as well.
					$data['clock']       = $now;
					$data['clock_since'] = $since;
				}
				return $this->clock_notice( $data, $now, max( $ahead, $since ) );
			}
		);
		$this->flush( $now );
	}

	/**
	 * Queues the notice that a recorded time lay ahead of the clock.
	 *
	 * @param Data $data  State.
	 * @param int  $now   Unix time.
	 * @param int  $ahead The latest time that lay ahead.
	 * @return Data State with the notice.
	 */
	private function clock_notice( array $data, int $now, int $ahead ): array {
		if ( $this->settings->bool( 'notifications.email.on_failure' ) ) {
			$data['outbox'][] = $this->entry( new Notice( NoticeKind::Clock, $now, '', '', '', $ahead ) );
		}
		return $data;
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
		$send = function () use ( $now ): void {
			$this->send_due( $now );
		};
		if ( null === $this->sending ) {
			$send();
		} else {
			// Another request is at it: its notices are these, and nobody gets a mail twice.
			$this->sending->hold_if_free( $send );
		}
	}

	/**
	 * What flush() does as the only request that sends. The mailer can take long, so the
	 * state is not locked meanwhile: what other requests queue in that time is kept when the
	 * outcome is stored.
	 *
	 * @param int $now Unix time.
	 */
	private function send_due( int $now ): void {
		$handled = $this->load( true )['outbox'];
		if ( [] === $handled ) {
			return;
		}
		$kept = [];
		foreach ( $handled as $entry ) {
			$notice   = Notice::from_array( $entry );
			$attempts = Decoded::count( $entry['attempts'] ?? null );
			$tried_at = Decoded::int( $entry['tried_at'] ?? null );
			if ( null === $notice || $attempts > count( self::RETRY_AFTER ) || $now - $notice->at > self::MAX_AGE ) {
				continue;
			}
			// An attempt recorded by a clock that was ahead would wait until the clock caught up.
			if ( $attempts > 0 && $now - $tried_at < self::RETRY_AFTER[ $attempts - 1 ] && ! SchedulePolicy::ahead( $tried_at, $now ) ) {
				$kept[] = self::waiting( $notice, $attempts, $tried_at );
				continue;
			}
			if ( ! $this->mailer->send( $notice ) && $attempts < count( self::RETRY_AFTER ) ) {
				$kept[] = self::waiting( $notice, $attempts + 1, $now );
			}
		}
		$this->change(
			static function ( array $data ) use ( $handled, $kept ): array {
				$queued         = array_values( array_filter( $data['outbox'], static fn ( array $entry ): bool => ! in_array( $entry, $handled, true ) ) );
				$data['outbox'] = [ ...$kept, ...$queued ];
				return $data;
			}
		);
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
	 * @param Data   $data  State.
	 * @param string $job   The last successful backup; empty if there was none.
	 * @param int    $since Unix time the age of the backups counts from; 0 if unknown.
	 * @param int    $now   Unix time.
	 * @return Notice|null Notice.
	 */
	private function stale_notice( array $data, string $job, int $since, int $now ): ?Notice {
		$limit = SchedulePolicy::stale_after( $this->settings );
		if ( null === $limit || $data['stale'] > 0 || ! $this->settings->bool( 'setup.completed' ) || ! $this->settings->bool( 'notifications.email.on_stale' ) ) {
			return null;
		}
		if ( 0 === $since || $now - $since < $limit ) {
			return null;
		}
		return new Notice( NoticeKind::Stale, $now, '', $job, '', $since );
	}

	/**
	 * The last successful backup and when it finished. Without a backup in this repository
	 * yet, the time counts from the setup, or from the day the jobs turned to this repository
	 * if that was later.
	 *
	 * @return array{0: string, 1: int} Job ID, empty if there was none, and Unix time; 0 if unknown.
	 */
	private function stale_since(): array {
		$last = $this->state->get( 'last_backup' );
		$job  = Decoded::string( $last['job'] ?? null );
		return [ $job, '' === $job ? max( $this->setup_time(), Decoded::int( $this->state->get( 'repository' )['since'] ?? null ) ) : Decoded::int( $last['finished'] ?? null ) ];
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
	 * @param bool $fresh Whether to read past what this request read before, as a change has to.
	 * @return Data State.
	 */
	private function load( bool $fresh = false ): array {
		$stored  = $fresh ? $this->options->fresh( self::OPTION ) : $this->options->get( self::OPTION );
		$stored  = is_array( $stored ) ? $stored : [];
		$failing = [];
		foreach ( is_array( $stored['failing'] ?? null ) ? $stored['failing'] : [] as $type => $since ) {
			if ( is_string( $type ) && is_int( $since ) ) {
				$failing[ $type ] = $since;
			}
		}
		$stale = Decoded::int( $stored['stale'] ?? null );
		// What is being counted belongs to one repository. Once the jobs turn to another one,
		// its first stale notice is not held back by the old one's, and its first success ends
		// no failure streak of the old one. Notices that wait to be sent stay true and go out.
		$bound = $this->state->get( 'repository' )['id'] ?? null;
		$known = $stored['repository'] ?? null;
		if ( is_string( $bound ) && is_string( $known ) && $bound !== $known ) {
			$failing = [];
			$stale   = 0;
		}
		return [
			'repository'  => is_string( $bound ) ? $bound : ( is_string( $known ) ? $known : null ),
			'failing'     => $failing,
			'stale'       => $stale,
			'clock'       => Decoded::int( $stored['clock'] ?? null ),
			'clock_since' => Decoded::int( $stored['clock_since'] ?? null ),
			'outbox'      => Decoded::arrays( $stored['outbox'] ?? null ),
		];
	}

	/**
	 * Changes the state, starting from what the database holds now and under the lock, so that
	 * a notice another request queued at the same moment is not written over.
	 *
	 * @param \Closure(Data): (Data|null) $change Returns what to store; null to store nothing.
	 */
	private function change( \Closure $change ): void {
		$apply = function () use ( $change ): void {
			$next = $change( $this->load( true ) );
			if ( null !== $next ) {
				// Not autoloaded.
				$this->options->set( self::OPTION, $next, false );
			}
		};
		if ( null === $this->lock ) {
			$apply();
		} else {
			$this->lock->hold( $apply );
		}
	}
}
