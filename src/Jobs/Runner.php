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
			if ( $job->cancel_requested && null === $job->outcome && ! $job->type->is_cleanup( $job->step ) ) {
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
			if ( microtime( true ) >= $deadline ) {
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
