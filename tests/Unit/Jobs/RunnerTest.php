<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobObserver;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;

final class RunnerTest extends TestCase {

	private string $directory;

	private FrozenClock $clock;

	/** @var array<string, list<bool|\Throwable|\Closure(Job): bool>> Scripted outcomes per step; a closure runs inside the step. */
	private array $script = [];

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		$this->directory = TempDir::create( 'jobs' );
		$this->clock     = new FrozenClock();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function store( ?Redactor $redactor = null ): JobStore {
		return new JobStore( $this->directory . '/jobs', $redactor ?? new Redactor(), new SystemRandom() );
	}

	private function runner( ?JobStore $store = null, ?JobObserver $observer = null ): Runner {
		$provider = new class( $this ) implements StepProvider {
			public function __construct( private readonly RunnerTest $test ) {
			}

			public function step( JobType $type, string $name ): Step {
				return new class( $this->test, $name ) implements Step {
					public function __construct( private readonly RunnerTest $test, private readonly string $name ) {
					}

					public function advance( Job $job, EventLog $log, float $deadline ): bool {
						return $this->test->perform( $this->name, $job );
					}
				};
			}
		};
		return new Runner( $store ?? $this->store(), new RunnerLock( $this->directory . '/runner.lock' ), $provider, $this->clock, new SystemRandom(), $observer );
	}

	/**
	 * Called by the scripted steps.
	 */
	public function perform( string $step, Job $job ): bool {
		$this->calls[]         = $step;
		$queue                 = $this->script[ $step ] ?? [];
		$outcome               = array_shift( $queue ) ?? true;
		$this->script[ $step ] = $queue;
		if ( $outcome instanceof \Throwable ) {
			throw $outcome;
		}
		if ( $outcome instanceof \Closure ) {
			$outcome = $outcome( $job );
		}
		$job->progress['last'] = $step;
		return $outcome;
	}

	/**
	 * The job document as it is on disk, without what JobStore::get() adds.
	 *
	 * @return array<mixed>
	 */
	private function document( Job $job ): array {
		$document = json_decode( (string) file_get_contents( $this->directory . '/jobs/' . $job->id . '/job.json' ), true );
		self::assertIsArray( $document );
		return $document;
	}

	/**
	 * An observer that remembers what it heard and what the store held at that moment.
	 */
	private function listener( JobStore $store ): JobObserver {
		return new class( $store ) implements JobObserver {
			/** @var list<string> */
			public array $heard = [];

			public function __construct( private readonly JobStore $store ) {
			}

			public function state_changed( Job $job, JobState $previous ): void {
				$saved         = $this->store->get( $job->id );
				$this->heard[] = $previous->value . '>' . $job->state->value . '/' . $saved?->state->value;
			}
		};
	}

	public function test_steps_run_in_order_across_ticks_and_cleanup_runs_on_success(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = [ false, false, true ];
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );

		$runner = $this->runner( $store );
		self::assertTrue( $runner->tick( 10 )->has_more_work() );
		self::assertSame( 'backing_up', $store->get( $job->id )?->step );
		$runner->tick( 10 );
		$report = $runner->tick( 10 );

		self::assertFalse( $report->has_more_work() );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'backing_up', 'backing_up', 'recording', 'releasing', 'cleaning_up' ], $this->calls );
		$finished = $store->get( $job->id );
		self::assertSame( JobState::Succeeded, $finished?->state );
		self::assertSame( 3, $finished->ticks );
		self::assertSame( '', $finished->lease_owner );
		self::assertSame( 'idle', $runner->tick( 10 )->status );
	}

	public function test_the_observer_hears_the_start_and_the_end_after_the_job_is_saved(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = [ false, true ];
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$observer                   = new class( $store ) implements JobObserver {
			/** @var list<string> */
			public array $heard = [];

			public function __construct( private readonly JobStore $store ) {
			}

			public function state_changed( Job $job, JobState $previous ): void {
				$saved         = $this->store->get( $job->id );
				$this->heard[] = $previous->value . '>' . $job->state->value . '/' . $saved?->state->value;
			}
		};
		$runner                     = $this->runner( $store, $observer );
		$runner->tick( 10 );
		self::assertSame( [ 'queued>running/running' ], $observer->heard );
		$runner->tick( 10 );
		self::assertSame( [ 'queued>running/running', 'running>succeeded/succeeded' ], $observer->heard );
	}

	public function test_a_failing_observer_is_logged_and_does_not_change_the_job(): void {
		$store    = $this->store();
		$job      = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$observer = new class() implements JobObserver {
			public function state_changed( Job $job, JobState $previous ): void {
				throw new \RuntimeException( 'The mail server is gone.' );
			}
		};
		$report   = $this->runner( $store, $observer )->tick( 10 );
		self::assertSame( 'advanced', $report->status );
		$finished = $store->get( $job->id );
		self::assertSame( JobState::Succeeded, $finished?->state );
		$warnings = array_filter( $store->log( $finished )->read(), static fn ( array $entry ): bool => 'warning' === $entry['level'] );
		self::assertCount( 2, $warnings, 'One warning for the start, one for the end.' );
	}

	public function test_a_failure_still_runs_the_cleanup_steps(): void {
		$store                     = $this->store();
		$this->script['unlocking'] = [ new WrongPassword( 'No key in the repository accepts the password.' ) ];
		$job                       = $store->create( JobType::Backup, 'manual', $this->clock->seconds );

		$this->runner( $store )->tick( 10 );

		self::assertSame( [ 'preflight', 'unlocking', 'releasing', 'cleaning_up' ], $this->calls );
		$failed = $store->get( $job->id );
		self::assertSame( JobState::Failed, $failed?->state );
		self::assertSame( 'wrong_password', $failed->error?->code );
		self::assertFalse( $failed->error->retryable );
	}

	public function test_retryable_errors_back_off_and_then_fail(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = array_fill( 0, 6, new BackendException( 'Connection reset.' ) );
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$runner                     = $this->runner( $store );

		$runner->tick( 10 );
		$waiting = $store->get( $job->id );
		self::assertSame( 1, $waiting?->consecutive_failures );
		self::assertSame( $this->clock->seconds + 30, $waiting->not_before );
		self::assertSame( 'waiting', $runner->tick( 10 )->status );

		for ( $attempt = 2; $attempt <= 6; $attempt++ ) {
			$this->clock->advance( 3600 );
			$runner->tick( 10 );
		}
		$failed = $store->get( $job->id );
		self::assertSame( JobState::Failed, $failed?->state );
		self::assertSame( 'repository_unreachable', $failed->error?->code );
		self::assertSame( [ 'releasing', 'cleaning_up' ], array_slice( $this->calls, -2 ) );
	}

	public function test_cancellation_runs_the_cleanup_steps(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = [ false, false ];
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$runner                     = $this->runner( $store );
		$runner->tick( 10 );

		$running                   = $store->get( $job->id );
		$running->cancel_requested = true;
		$store->save( $running );
		$runner->tick( 10 );

		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
		self::assertSame( [ 'releasing', 'cleaning_up' ], array_slice( $this->calls, -2 ) );
	}

	public function test_stopping_cancels_the_active_job_and_cleans_up_in_the_same_call(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = [ false, false ];
		$job                        = $store->create( JobType::Backup, 'schedule', $this->clock->seconds );
		$runner                     = $this->runner( $store );
		$runner->tick( 10 );

		$stopped = $runner->stop( 10, [ 'reason' => 'deactivation' ] );

		self::assertSame( $job->id, $stopped?->id );
		self::assertSame( JobState::Cancelled, $stopped->state );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'releasing', 'cleaning_up' ], $this->calls, 'The lock in the repository is released, and no other step runs.' );
		self::assertNull( $store->active() );
		self::assertStringContainsString( '"reason":"deactivation"', (string) file_get_contents( $this->directory . '/jobs/' . $job->id . '/events.log' ) );
	}

	public function test_the_deadline_holds_back_regular_steps_but_not_the_cleanup(): void {
		$store  = $this->store();
		$job    = $store->create( JobType::Backup, 'schedule', $this->clock->seconds );
		$runner = $this->runner( $store );

		// No time at all: every tick runs the one step it is at.
		$runner->tick( 0 );
		$runner->tick( 0 );
		self::assertSame( [ 'preflight', 'unlocking' ], $this->calls );
		self::assertSame( 'backing_up', $store->get( $job->id )?->step );

		// A cancelled job is cleaned up in one go, with the deadline long past.
		$stopped = $runner->stop( 0 );
		self::assertSame( JobState::Cancelled, $stopped?->state, 'A job left between its cleanup steps would stay active with nobody to finish it.' );
		self::assertSame( [ 'preflight', 'unlocking', 'releasing', 'cleaning_up' ], $this->calls );
	}

	public function test_a_job_that_ends_at_the_deadline_is_cleaned_up_by_the_same_tick(): void {
		$store  = $this->store();
		$job    = $store->create( JobType::Backup, 'schedule', $this->clock->seconds );
		$runner = $this->runner( $store );
		foreach ( [ 'preflight', 'unlocking', 'backing_up' ] as $step ) {
			$runner->tick( 0 );
			self::assertSame( JobState::Running, $store->get( $job->id )?->state, $step );
		}

		// The last regular step, and the cleanup after it.
		$runner->tick( 0 );

		self::assertSame( JobState::Succeeded, $store->get( $job->id )?->state );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'recording', 'releasing', 'cleaning_up' ], $this->calls );
	}

	public function test_stopping_ends_a_queued_job_at_once_and_does_nothing_without_a_job(): void {
		$store  = $this->store();
		$runner = $this->runner( $store );
		self::assertNull( $runner->stop( 10 ) );

		$job = $store->create( JobType::Backup, 'schedule', $this->clock->seconds );
		self::assertSame( JobState::Cancelled, $runner->stop( 10 )?->state );
		self::assertSame( [], $this->calls, 'Nothing ran, so there is nothing to clean up.' );
		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
	}

	public function test_stopping_a_job_that_another_tick_works_on_leaves_the_cleanup_to_that_tick(): void {
		$store                      = $this->store();
		$job                        = $store->create( JobType::Backup, 'schedule', $this->clock->seconds );
		$stopped                    = null;
		$this->script['backing_up'] = [
			function () use ( $store, &$stopped ): bool {
				// The plugin is deactivated while this step runs.
				$stopped = $this->runner( $store )->stop( 10 );
				return false;
			},
		];

		$this->runner( $store )->tick( 10 );

		self::assertSame( JobState::Running, $stopped?->state, 'The other tick holds the lock.' );
		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'releasing', 'cleaning_up' ], $this->calls );
	}

	public function test_a_cancel_request_during_a_step_is_honoured_before_the_next_step(): void {
		$store                     = $this->store();
		$job                       = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$this->script['unlocking'] = [
			static function ( Job $running ) use ( $store ): bool {
				$store->request_cancel( $running );
				return true;
			},
		];

		$this->runner( $store )->tick( 10 );

		self::assertSame( [ 'preflight', 'unlocking', 'releasing', 'cleaning_up' ], $this->calls, 'The backup itself never started.' );
		$ended = $store->get( $job->id );
		self::assertSame( JobState::Cancelled, $ended?->state );
		self::assertTrue( $this->document( $ended )['cancel_requested'], 'The runner wrote the request into its own document.' );
	}

	public function test_a_cancel_request_during_a_tick_is_honoured_by_that_tick_and_does_not_touch_its_document(): void {
		$store                      = $this->store();
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$during                     = [];
		$this->script['backing_up'] = [
			function ( Job $running ) use ( $store, &$during ): bool {
				// Another request, as the REST API makes it, while this tick holds the runner lock.
				$answer = $this->runner( $store )->cancel( $running->id, [ 'user' => 7 ] );
				$during = [ $answer?->cancel_requested, $this->document( $running ) ];
				return false;
			},
			false,
		];
		$runner                     = $this->runner( $store );

		self::assertFalse( $runner->tick( 10 )->has_more_work(), 'The step wanted another tick, but the cleanup does not wait for one.' );

		self::assertTrue( $during[0], 'The answer shows the request.' );
		self::assertFalse( $during[1]['cancel_requested'], 'The request did not touch the document of the running tick.' );
		self::assertNotSame( '', $during[1]['lease_owner'] );
		$saved = $this->document( $job );
		self::assertSame( 'cancelled', $saved['state'] );
		self::assertTrue( $saved['cancel_requested'], 'The tick wrote the request into its own document.' );
		self::assertSame( 1, $saved['ticks'] );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'releasing', 'cleaning_up' ], $this->calls, 'The backup step did not run again.' );
		$requests = array_filter( $store->log( $job )->read(), static fn ( array $entry ): bool => 'Cancellation requested.' === $entry['message'] );
		self::assertSame( [ [ 'user' => 7 ] ], array_column( $requests, 'context' ) );
	}

	public function test_a_cancel_request_that_arrives_as_a_tick_ends_is_honoured_by_the_next_tick(): void {
		$store                      = $this->store();
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$this->script['backing_up'] = [ false, false ];
		$runner                     = $this->runner( $store );
		self::assertTrue( $runner->tick( 10 )->has_more_work() );

		// Between two ticks nobody holds the lock, but the job is not queued: only the marker is written.
		$runner->cancel( $job->id );
		self::assertSame( 'running', $this->document( $job )['state'] );
		self::assertTrue( $store->get( $job->id )?->cancel_requested, 'The request is part of what is loaded.' );

		self::assertFalse( $runner->tick( 10 )->has_more_work() );
		self::assertSame( [ 'preflight', 'unlocking', 'backing_up', 'releasing', 'cleaning_up' ], $this->calls );
		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
	}

	public function test_a_queued_job_is_cancelled_at_once(): void {
		$store    = $this->store();
		$job      = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$observer = $this->listener( $store );
		$this->clock->advance( 5 );

		$cancelled = $this->runner( $store, $observer )->cancel( $job->id );

		self::assertSame( JobState::Cancelled, $cancelled?->state );
		self::assertSame( $this->clock->seconds, $cancelled->finished_at );
		self::assertSame( 'cancelled', $this->document( $job )['state'] );
		self::assertSame( [ 'queued>cancelled/cancelled' ], $observer->heard );
		self::assertSame( [], $this->calls, 'No step ran, so nothing needs cleaning up.' );
		self::assertSame( 'idle', $this->runner( $store )->tick( 10 )->status );
		$store->create( JobType::Backup, 'manual', $this->clock->seconds );
	}

	public function test_a_queued_job_is_cancelled_by_the_next_tick_when_the_request_found_the_lock_taken(): void {
		$store    = $this->store();
		$job      = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$observer = $this->listener( $store );
		$held     = new RunnerLock( $this->directory . '/runner.lock' );
		self::assertTrue( $held->acquire() );

		$answer = $this->runner( $store, $observer )->cancel( $job->id );

		self::assertSame( JobState::Queued, $answer?->state );
		self::assertTrue( $answer->cancel_requested );
		self::assertSame( 'queued', $this->document( $job )['state'], 'Whoever holds the lock may be about to save the job.' );
		self::assertSame( [], $observer->heard );
		$held->release();

		$report = $this->runner( $store, $observer )->tick( 10 );

		self::assertSame( 'advanced', $report->status );
		self::assertFalse( $report->has_more_work() );
		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
		self::assertSame( [ 'queued>cancelled/cancelled' ], $observer->heard );
		self::assertSame( [], $this->calls );
	}

	public function test_a_cancel_request_between_ticks_ends_a_job_that_waits_for_a_retry(): void {
		$store                      = $this->store();
		$this->script['backing_up'] = [ new BackendException( 'Connection reset.' ) ];
		$job                        = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$runner                     = $this->runner( $store );
		$runner->tick( 10 );
		self::assertSame( 'waiting', $runner->tick( 10 )->status );

		$answer = $runner->cancel( $job->id );

		self::assertSame( JobState::Running, $answer?->state );
		self::assertTrue( $answer->cancel_requested );
		self::assertFalse( $this->document( $job )['cancel_requested'], 'Only a tick writes the document of a started job.' );
		self::assertSame( 'advanced', $runner->tick( 10 )->status, 'A cancelled job does not wait for its retry.' );
		self::assertSame( JobState::Cancelled, $store->get( $job->id )?->state );
		self::assertSame( [ 'releasing', 'cleaning_up' ], array_slice( $this->calls, -2 ) );
	}

	public function test_a_cancel_request_for_a_finished_or_unknown_job_changes_nothing(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$this->runner( $store )->tick( 10 );
		$before = $this->document( $job );

		$answer = $this->runner( $store )->cancel( $job->id );

		self::assertSame( JobState::Succeeded, $answer?->state );
		self::assertFalse( $answer->cancel_requested );
		self::assertSame( $before, $this->document( $job ) );
		self::assertFileDoesNotExist( $this->directory . '/jobs/' . $job->id . '/' . JobStore::CANCEL_MARKER );
		self::assertNull( $this->runner( $store )->cancel( '20260101T000000Z-abcdef' ) );
	}

	public function test_a_failing_cleanup_step_cannot_keep_the_job_alive(): void {
		$store                     = $this->store();
		$this->script['releasing'] = [ new BackendException( 'Connection reset.' ) ];
		$job                       = $store->create( JobType::Backup, 'manual', $this->clock->seconds );

		$this->runner( $store )->tick( 10 );

		$ended = $store->get( $job->id );
		self::assertSame( JobState::Failed, $ended?->state );
		self::assertContains( 'cleaning_up', $this->calls );
	}

	public function test_lease_and_lock_keep_runners_apart(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', $this->clock->seconds );

		$held = new RunnerLock( $this->directory . '/runner.lock' );
		self::assertTrue( $held->acquire() );
		self::assertSame( 'busy', $this->runner( $store )->tick( 10 )->status );
		$held->release();

		$leased                   = $store->get( $job->id );
		$leased->lease_owner      = 'other-server';
		$leased->lease_expires_at = $this->clock->seconds + 60;
		$store->save( $leased );
		self::assertSame( 'busy', $this->runner( $store )->tick( 10 )->status );

		$this->clock->advance( 61 );
		self::assertSame( 'advanced', $this->runner( $store )->tick( 10 )->status, 'An expired lease from a killed tick is taken over.' );
	}

	public function test_store_rejects_a_second_active_job_and_prunes_only_finished_ones(): void {
		$store = $this->store();
		$first = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		try {
			$store->create( JobType::Backup, 'manual', $this->clock->seconds );
			self::fail( 'A second active job must be refused.' );
		} catch ( JobConflict ) {
			self::assertSame( $first->id, $store->active()?->id );
		}

		$this->runner( $store )->tick( 10 );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->clock->advance( 10 );
			$store->create( JobType::Backup, 'schedule', $this->clock->seconds );
			$this->runner( $store )->tick( 10 );
		}
		self::assertCount( 4, $store->latest( 10 ) );
		self::assertSame( 2, $store->prune( 2 ) );
		self::assertCount( 2, $store->latest( 10 ) );
		self::assertNull( $store->get( $first->id ) );
		self::assertNull( $store->get( '../../etc/passwd' ) );
	}

	public function test_a_job_is_checked_for_and_created_under_an_exclusive_lock(): void {
		$file  = $this->directory . '/jobs/' . JobStore::CREATE_LOCK;
		$probe = new class( $file ) implements RandomSource {
			/** @var list<bool> Whether the lock was taken each time an ID was drawn. */
			public array $locked = [];

			public function __construct( private readonly string $file ) {
			}

			public function bytes( int $length ): string {
				$this->locked[] = ! self::free( $this->file );
				return random_bytes( $length );
			}

			/**
			 * Whether another open file could take the lock now.
			 */
			public static function free( string $file ): bool {
				$handle = fopen( $file, 'c' );
				if ( false === $handle ) {
					return false;
				}
				$free = flock( $handle, LOCK_EX | LOCK_NB );
				fclose( $handle );
				return $free;
			}
		};
		$store = new JobStore( $this->directory . '/jobs', new Redactor(), $probe );

		$job = $store->create( JobType::Check, 'cli', $this->clock->seconds, [ 'read_data_subset_percent' => 7 ] );

		self::assertSame( [ true ], $probe->locked, 'Between the check for an active job and the new job document nobody else can create one.' );
		self::assertTrue( $probe::free( $file ), 'The lock is released afterwards.' );
		self::assertSame( [ 'read_data_subset_percent' => 7 ], $store->get( $job->id )?->data, 'The data is written with the job, not by a second save.' );
		try {
			$store->create( JobType::Backup, 'manual', $this->clock->seconds );
			self::fail( 'A second active job must be refused.' );
		} catch ( JobConflict ) {
			self::assertTrue( $probe::free( $file ), 'A refusal releases the lock too.' );
		}
	}

	public function test_job_files_are_redacted_and_private(): void {
		$redactor = new Redactor();
		$redactor->add( new Secret( 'hunter2-secret' ) );
		$store = $this->store( $redactor );
		$job   = $store->create( JobType::Backup, 'manual', $this->clock->seconds );
		$job->warn( 'Could not read /srv/site/hunter2-secret.txt' );
		$store->save( $job );
		$store->log( $job )->write( 'info', 'Password hunter2-secret leaked', [ 'url' => 'https://u:' . rawurlencode( 'hunter2-secret' ) . '@x' ] );

		$directory = $this->directory . '/jobs/' . $job->id;
		$content   = file_get_contents( $directory . '/job.json' ) . file_get_contents( $directory . '/events.log' );
		self::assertStringNotContainsString( 'hunter2-secret', $content );
		self::assertSame( 0600, fileperms( $directory . '/job.json' ) & 0777 );
		self::assertCount( 2, $store->log( $job )->read() );
	}
}
