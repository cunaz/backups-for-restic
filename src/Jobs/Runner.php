<?php
/**
 * Advances the active job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One tick: take the runner lock and the job's lease, run steps until the budget is spent,
 * persist the job (docs/02-architecture.md §4.2). Every driver (WP-Cron, loopback chaining,
 * admin polling, WP-CLI) calls tick(); none of them needs to know what a job does.
 *
 * Errors end in the cleanup steps, which run on every path to the end, so repository locks
 * are released after failures and cancellations too. Retryable errors (an unreachable server,
 * a lock held by another client) are retried with exponential backoff before they fail the
 * job.
 *
 * Only the holder of the runner lock writes a job document. A request to cancel reaches a
 * running tick as a marker file (JobStore::request_cancel()), which the tick reads before
 * every step.
 */
final class Runner {

	public const int MAX_CONSECUTIVE_FAILURES = 5;

	/** Extra lease time beyond the budget, for slow requests near the end of a tick. */
	private const int LEASE_SLACK_SECONDS = 60;

	/**
	 * Creates the runner.
	 *
	 * @param JobStore         $store    Jobs.
	 * @param RunnerLock       $lock     Process-level lock.
	 * @param StepProvider     $steps    Step implementations.
	 * @param Clock            $clock    Time source.
	 * @param RandomSource     $random   Randomness for lease owners.
	 * @param JobObserver|null $observer Told when a job starts and ends.
	 */
	public function __construct(
		private readonly JobStore $store,
		private readonly RunnerLock $lock,
		private readonly StepProvider $steps,
		private readonly Clock $clock,
		private readonly RandomSource $random,
		private readonly ?JobObserver $observer = null
	) {
	}

	/**
	 * Advances the active job for at most the budget.
	 *
	 * @param float $budget Seconds this tick may spend.
	 * @return TickReport What happened.
	 */
	public function tick( float $budget ): TickReport {
		if ( ! $this->lock->acquire() ) {
			return new TickReport( 'busy' );
		}
		try {
			return $this->advance( $budget );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Asks a job to stop: at once while it is queued, otherwise after the step that is running,
	 * through the cleanup steps.
	 *
	 * The request is recorded as a marker next to the job document first. A tick that is running
	 * finds the marker before its next step, and nothing it saves can lose the request; nothing
	 * written here can overwrite what the tick saves. The job document itself is changed only
	 * for a queued job, and only under the runner lock, that is, while no tick runs.
	 *
	 * @param string                     $id      Job ID.
	 * @param array<string, scalar|null> $context Who asked, for the job's log.
	 * @return Job|null The job as it is now, or null if the ID is unknown.
	 */
	public function cancel( string $id, array $context = [] ): ?Job {
		$job = $this->store->get( $id );
		if ( null === $job || $job->state->is_terminal() ) {
			return $job;
		}
		$this->store->request_cancel( $job );
		$job->cancel_requested = true;
		$log                   = $this->store->log( $job );
		$log->write( 'info', 'Cancellation requested.', $context );
		if ( ! $this->lock->acquire() ) {
			// The lock is taken, as a rule by a tick, which honours the marker before its next
			// step; if the heartbeat holds it for a moment instead, the next tick does.
			return $job;
		}
		try {
			// What the tick that just ended saved, not what was read before the lock was taken.
			$job = $this->store->get( $id ) ?? $job;
			if ( JobState::Queued === $job->state ) {
				$this->cancel_queued( $job, $log );
			}
		} finally {
			$this->lock->release();
		}
		return $job;
	}

	/**
	 * Ends the active job as far as one call can, for the moment the plugin is switched off:
	 * asks it to stop and runs its cleanup steps, which release the repository lock, for at
	 * most the budget. A job that another tick is working on right now stops there, after the
	 * step that is running.
	 *
	 * @param float                      $budget  Seconds the cleanup may spend.
	 * @param array<string, scalar|null> $context Why, for the job's log.
	 * @return Job|null The job as it is now, or null if none was active.
	 */
	public function stop( float $budget, array $context = [] ): ?Job {
		$job = $this->store->active();
		if ( null === $job ) {
			return null;
		}
		$this->cancel( $job->id, $context );
		// A tick that is about to end may have looked for the request a moment before it was
		// recorded. For that case the lock is asked for again for a second: either that tick
		// is gone by then and this call cleans up, or it is in a step and finds the request
		// before its next one.
		$patience = microtime( true ) + min( $budget, 1.0 );
		do {
			$report = $this->tick( $budget );
			if ( 'busy' !== $report->status || null !== $report->job ) {
				break;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $patience );
		return $this->store->get( $job->id ) ?? $job;
	}

	/**
	 * Advances the job while holding the runner lock.
	 *
	 * @param float $budget Seconds.
	 * @return TickReport What happened.
	 */
	private function advance( float $budget ): TickReport {
		$job = $this->store->active();
		if ( null === $job ) {
			return new TickReport( 'idle' );
		}
		$now = $this->now();
		if ( '' !== $job->lease_owner && $job->lease_expires_at > $now ) {
			return new TickReport( 'busy', $job );
		}
		if ( JobState::Queued === $job->state && $job->cancel_requested ) {
			// The request came while the runner lock was taken, so cancel() left it to this tick.
			$this->cancel_queued( $job, $this->store->log( $job ) );
			return new TickReport( 'advanced', $job );
		}
		if ( $job->not_before > $now && ! $job->cancel_requested ) {
			return new TickReport( 'waiting', $job );
		}

		$start                 = microtime( true );
		$deadline              = $start + $budget;
		$job->lease_owner      = bin2hex( $this->random->bytes( 8 ) );
		$job->lease_expires_at = $now + (int) ceil( $budget ) + self::LEASE_SLACK_SECONDS;
		$log                   = $this->store->log( $job );
		$started               = JobState::Queued === $job->state;
		if ( $started ) {
			$job->state      = JobState::Running;
			$job->started_at = $now;
			$job->step       = $job->type->steps()[0];
			$log->write( 'info', 'Job started.' );
		}
		$this->store->save( $job );
		if ( $started ) {
			$this->notify( $job, JobState::Queued, $log );
		}

		try {
			$this->run_steps( $job, $log, $deadline );
		} finally {
			++$job->ticks;
			$job->seconds         += microtime( true ) - $start;
			$job->updated_at       = $this->now();
			$job->lease_owner      = '';
			$job->lease_expires_at = 0;
			$this->store->save( $job );
		}
		if ( $job->state->is_terminal() ) {
			$this->notify( $job, JobState::Running, $log );
		}
		return new TickReport( 'advanced', $job );
	}

	/**
	 * Ends a job that was asked to stop before its first step: nothing ran, so there is nothing
	 * to clean up. The caller holds the runner lock.
	 *
	 * @param Job      $job Queued job.
	 * @param EventLog $log Log.
	 */
	private function cancel_queued( Job $job, EventLog $log ): void {
		$job->state            = JobState::Cancelled;
		$job->cancel_requested = true;
		$job->finished_at      = $this->now();
		$job->updated_at       = $job->finished_at;
		$this->store->save( $job );
		$log->write( 'info', 'Job finished.', [ 'state' => $job->state->value ] );
		$this->notify( $job, JobState::Queued, $log );
	}

	/**
	 * Tells the observer about a state change. Its failure is logged and does not change the
	 * job: a notification that cannot be sent must not turn a backup into a failure.
	 *
	 * @param Job      $job      Job.
	 * @param JobState $previous State before.
	 * @param EventLog $log      Log.
	 */
	private function notify( Job $job, JobState $previous, EventLog $log ): void {
		if ( null === $this->observer ) {
			return;
		}
		try {
			$this->observer->state_changed( $job, $previous );
		} catch ( \Throwable $exception ) {
			$log->write( 'warning', 'Reporting the state change failed.', [ 'code' => JobError::from_throwable( $exception )->code ] );
		}
	}

	/**
	 * Runs steps until the job ends, a step needs another tick, or the deadline passes.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 */
	private function run_steps( Job $job, EventLog $log, float $deadline ): void {
		while ( true ) {
			// A request to cancel may have arrived while the last step ran.
			$job->cancel_requested = $job->cancel_requested || $this->store->cancel_requested( $job );
			if ( $job->cancel_requested && null === $job->outcome && $job->type->cancels_before( $job->step ) ) {
				$job->outcome = JobState::Cancelled;
				$job->step    = $job->type->cleanup_steps()[0];
				$log->write( 'info', 'Cancelled; cleaning up.' );
			}

			try {
				$done                      = $this->steps->step( $job->type, $job->step )->advance( $job, $log, $deadline );
				$job->consecutive_failures = 0;
				$job->not_before           = 0;
			} catch ( \Throwable $exception ) {
				$error = JobError::from_throwable( $exception );
				$log->write(
					'error',
					$error->message,
					[
						'code' => $error->code,
						'step' => $job->step,
					]
				);
				if ( $job->type->is_cleanup( $job->step ) ) {
					// A failing cleanup step must not keep the job alive; the next one still runs.
					$job->outcome ??= JobState::Failed;
					$job->error   ??= $error;
					$done           = true;
				} elseif ( $error->retryable && $job->consecutive_failures < self::MAX_CONSECUTIVE_FAILURES && null === $job->outcome && $job->type->cancels_before( $job->step ) && $this->store->cancel_requested( $job ) ) {
					// Asked to cancel while the step failed with what would be tried again: no
					// retry is waited for, the cleanup starts now. After a deactivation no later
					// tick would come to start it. An error that ends the job stays the outcome, also the
					// last of too many attempts.
					$job->cancel_requested = true;
					continue;
				} elseif ( $error->retryable && $job->consecutive_failures < self::MAX_CONSECUTIVE_FAILURES ) {
					++$job->consecutive_failures;
					$job->not_before = $this->now() + 30 * ( 2 ** ( $job->consecutive_failures - 1 ) );
					$log->write(
						'warning',
						'Will try again later.',
						[
							'attempt' => $job->consecutive_failures,
							'after'   => $job->not_before,
						]
					);
					return;
				} else {
					$job->error   = $error;
					$job->outcome = JobState::Failed;
					$job->step    = $job->type->cleanup_steps()[0];
					continue;
				}
			}

			if ( ! $done ) {
				// A step that wants another tick, and a request to cancel that came while it ran:
				// the cleanup starts in this tick. After a deactivation no other tick comes.
				if ( null === $job->outcome && $job->type->cancels_before( $job->step ) && $this->store->cancel_requested( $job ) ) {
					continue;
				}
				return;
			}
			$next = $job->type->next_step( $job->step );
			if ( null === $next ) {
				$job->state       = $job->outcome ?? JobState::Succeeded;
				$job->finished_at = $this->now();
				$log->write( 'info', 'Job finished.', [ 'state' => $job->state->value ] );
				return;
			}
			$job->step = $next;
			// The deadline holds back regular steps only. The cleanup steps are short, they
			// release the lock in the repository, and the tick that could run them may be the
			// last one for a long time, for example when the plugin is being deactivated.
			// Nor a request to cancel that came while the last step ran: the loop turns it into
			// the cleanup now.
			if ( microtime( true ) >= $deadline && ! $job->type->is_cleanup( $next ) && ! ( $job->type->cancels_before( $next ) && $this->store->cancel_requested( $job ) ) ) {
				return;
			}
		}
	}

	/**
	 * Current Unix time.
	 *
	 * @return int Seconds.
	 */
	private function now(): int {
		return $this->clock->now()[0];
	}
}
