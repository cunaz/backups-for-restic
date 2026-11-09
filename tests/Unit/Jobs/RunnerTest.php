<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
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

	/** @var array<string, list<bool|\Throwable>> Scripted outcomes per step. */
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

	private function runner( ?JobStore $store = null ): Runner {
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
		return new Runner( $store ?? $this->store(), new RunnerLock( $this->directory . '/runner.lock' ), $provider, $this->clock, new SystemRandom() );
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
		$job->progress['last'] = $step;
		return $outcome;
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
