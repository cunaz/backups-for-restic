<?php
/**
 * The steps of a restore job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Cleanup;
use BackupsForRestic\Jobs\ClosureStep;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Restores selected paths of a snapshot into the staging directory of the job (ADR 0037),
 * on top of the engine's resumable restore (ADR 0036).
 *
 * The files of a restore that succeeded stay in restores/<job-id>/ of the private directory.
 * A restore that failed or was cancelled leaves nothing there: half a tree looks like a
 * whole one to whoever finds it later.
 */
final class RestoreSteps implements StepProvider {

	/** How often a run may take up its work again after its repository lock did not hold. */
	public const int MAX_RESUMPTIONS = 3;

	/**
	 * Creates the steps.
	 *
	 * @param RestoreServices $services Dependencies.
	 * @param BackupSteps     $common   Preflight checks, unlock and cleanup shared with backups.
	 */
	public function __construct( private readonly RestoreServices $services, private readonly BackupSteps $common ) {
	}

	/**
	 * Returns the step.
	 *
	 * @param JobType $type Job type.
	 * @param string  $name Step name.
	 * @return Step Step.
	 * @throws InvalidArgument If the step is unknown.
	 */
	public function step( JobType $type, string $name ): Step {
		$method = match ( $name ) {
			'preflight'   => $this->preflight( ... ),
			'unlocking'   => $this->common->unlock( ... ),
			'planning'    => $this->plan( ... ),
			'restoring'   => $this->restore( ... ),
			'recording'   => $this->record( ... ),
			'releasing'   => $this->release( ... ),
			'cleaning_up' => $this->clean_up( ... ),
			default       => InvalidArgument::raise( 'Unknown step.', [ 'step' => $name ] ),
		};
		return new ClosureStep( $method );
	}

	/**
	 * Checks what a restore needs before it touches the repository: a completed setup, a
	 * valid request, a private directory that is not served, and a repository that answers.
	 * The free space is checked by the run itself, once it knows how much the selection holds.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws InvalidArgument If the setup is incomplete or the job holds no valid request.
	 */
	public function preflight( Job $job, EventLog $log, float $deadline ): bool {
		if ( ! $this->services->common->settings->bool( 'setup.completed' ) ) {
			InvalidArgument::raise( 'The setup is not completed.', [ 'code' => 'setup_incomplete' ] );
		}
		RestoreRequest::from_job( $job );
		$this->common->require_configured();
		$this->common->require_reachable();
		$log->write( 'info', 'Preflight passed.' );
		return true;
	}

	/**
	 * Walks the selection once without writing a file: every selected path must exist, and
	 * the files and bytes it counts are the measure of the progress and of the free space.
	 *
	 * The run goes on into the restore when the plan is done before the deadline; the step
	 * ends as soon as the plan is.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True once the selection is counted.
	 */
	public function plan( Job $job, EventLog $log, float $deadline ): bool {
		if ( 'plan' === $this->advance( $job, $log, $deadline ) ) {
			return false;
		}
		$log->write(
			'info',
			'Restore planned.',
			[
				'files' => $job->progress['total_files'] ?? 0,
				'bytes' => $job->progress['total_bytes'] ?? 0,
			]
		);
		return true;
	}

	/**
	 * Writes the selected files into the staging directory of the job.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True once every selected file is staged.
	 */
	public function restore( Job $job, EventLog $log, float $deadline ): bool {
		if ( 'complete' !== $this->advance( $job, $log, $deadline ) ) {
			return false;
		}
		$log->write(
			'info',
			'Files restored to staging.',
			[
				'files' => $job->progress['files'] ?? 0,
				'bytes' => $job->progress['bytes'] ?? 0,
			]
		);
		return true;
	}

	/**
	 * Keeps the report beside the staged files and remembers the result for the job and the
	 * dashboard.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws StorageFailed If the report cannot be kept.
	 */
	public function record( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		$source   = $services->work_dir( $job ) . '/' . RestoreServices::REPORT;
		$report   = $services->report_file( $job );
		// A tick that the host ended after the move finds the report in its place.
		if ( is_file( $source ) && ! is_link( $source ) ) {
			if ( ! rename( $source, $report ) ) {
				StorageFailed::raise( 'The restore report could not be moved to the staging directory.' );
			}
			chmod( $report, 0600 );
		} elseif ( ! is_file( $report ) ) {
			StorageFailed::raise( 'The restore report is missing.' );
		}
		$staging     = $services->staging_name( $job );
		$job->result = [
			'snapshot' => Decoded::string( $job->data['snapshot'] ?? null ),
			'files'    => Decoded::int( $job->progress['files'] ?? null ),
			'bytes'    => Decoded::int( $job->progress['bytes'] ?? null ),
			'skipped'  => Decoded::int( $job->progress['skipped'] ?? null ),
			'staging'  => $staging,
			'report'   => $staging . '/' . RestoreServices::REPORT,
		];
		$services->common->state->set(
			'last_restore',
			[
				'job'      => $job->id,
				'finished' => $services->common->clock->now()[0],
			] + $job->result
		);
		return true;
	}

	/**
	 * Ends the run and releases its repository lock. Runs on every path to the end.
	 *
	 * A run that completed released its lock itself. Looking for a leftover once more is a
	 * precaution then, and its failure does not turn a finished restore into a failed one,
	 * which would cost the staged files.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws PluginException If the lock of an unfinished run cannot be released.
	 */
	public function release( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services->common;
		$work     = $this->services->work_dir( $job );
		// A run records itself before it takes its first lock: no checkpoint, no lock.
		if ( ! is_file( $work . '/' . RestoreServices::CHECKPOINT ) ) {
			return true;
		}
		$repository = $services->access->open_cached();
		if ( null === $repository ) {
			// Without the key the lock cannot be named; it goes stale after 30 minutes.
			return true;
		}
		try {
			RestoreRun::resume( $repository, $work, $services->clock )->abort();
		} catch ( PluginException $failure ) {
			if ( null !== $job->outcome ) {
				throw $failure;
			}
			$log->write( 'warning', 'The repository could not be asked for leftover locks of the finished restore.', [ 'code' => JobError::from_throwable( $failure )->code ] );
			return true;
		}
		$log->write( 'info', 'Repository lock released.' );
		return true;
	}

	/**
	 * Deletes the working files of the run, and after a failure or a cancellation the partial
	 * staging directory as well. Runs on every path to the end.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function clean_up( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		$partial  = null !== $job->outcome;
		Cleanup::run(
			static fn () => Files::remove_tree( $services->work_dir( $job ) ),
			static function () use ( $services, $job, $partial ): void {
				if ( $partial ) {
					Files::remove_tree( $services->staging_dir( $job ) );
				}
			},
			fn () => $this->common->clean_up( $job, $log, $deadline )
		);
		return true;
	}

	/**
	 * Lets the run of a job work until the deadline and copies its progress into the job.
	 *
	 * A run whose repository lock did not hold, after a long pause or because someone removed
	 * it, gives up the tick (ADR 0036). The next tick takes a new lock, makes sure that the
	 * snapshot is still there and reads the index again. After MAX_RESUMPTIONS such ticks the
	 * job fails rather than go on for ever, for example over a snapshot that was forgotten.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return string The phase of the run after the tick: plan, restore or complete.
	 * @throws RepositoryChanged If the lock was lost too often or the snapshot is gone.
	 */
	private function advance( Job $job, EventLog $log, float $deadline ): string {
		$run = $this->run( $job, $log );
		try {
			$run->advance( $deadline );
		} catch ( RepositoryChanged $changed ) {
			$resumptions = Decoded::int( $job->data['resumptions'] ?? 0 ) + 1;
			if ( $resumptions > self::MAX_RESUMPTIONS ) {
				throw $changed;
			}
			$job->data['resumptions'] = $resumptions;
			$log->write( 'warning', 'The repository lock of the restore did not hold; the restore takes up its work again.', [ 'resumption' => $resumptions ] + $changed->context() );
		}
		$progress      = $run->progress();
		$job->progress = [
			'phase'       => $progress['phase'],
			'files'       => $progress['files'],
			'bytes'       => $progress['bytes'],
			'total_files' => $progress['total_files'],
			'total_bytes' => $progress['total_bytes'],
			'skipped'     => $progress['skipped'],
			'percent'     => self::percent( $progress ),
		];
		return $progress['phase'];
	}

	/**
	 * The run of a job: started once, resumed from its checkpoint afterwards.
	 *
	 * @param Job      $job Job.
	 * @param EventLog $log Log.
	 * @return RestoreRun Run.
	 * @throws InvalidArgument If the master key is missing or the job holds no valid request.
	 * @throws StorageFailed If the staging directory cannot be created.
	 */
	private function run( Job $job, EventLog $log ): RestoreRun {
		$services   = $this->services->common;
		$repository = $this->repository();
		$work       = $this->services->work_dir( $job );
		if ( is_file( $work . '/' . RestoreServices::CHECKPOINT ) ) {
			return RestoreRun::resume( $repository, $work, $services->clock, $services->throttle() );
		}
		$request = RestoreRequest::from_job( $job );
		// No checkpoint means no run: what a start that the host ended left behind holds no
		// lock and no file, and would only keep the new start from finding its directory empty.
		Files::remove_tree( $work );
		$target = $this->services->files_dir( $job );
		if ( ! is_dir( $target ) && ! mkdir( $target, 0700, true ) && ! is_dir( $target ) ) {
			StorageFailed::raise( 'The staging directory could not be created in the private directory.', [ 'job' => $job->id ] );
		}
		chmod( $this->services->staging_dir( $job ), 0700 );
		chmod( $target, 0700 );
		$run = RestoreRun::start( $repository, Id::from_hex( $request->snapshot ), $target, $work, $request->paths, $services->identity, $services->clock, $services->throttle() );
		$log->write(
			'info',
			'Restore started.',
			[
				'snapshot' => $request->snapshot,
				'paths'    => count( $request->paths ),
			]
		);
		return $run;
	}

	/**
	 * How far the run is, for a progress bar. Planning counts as nothing: its totals still
	 * grow. Only a complete run reports the full hundred.
	 *
	 * @param array{phase: string, files: int, bytes: int, total_files: int, total_bytes: int, skipped: int} $progress Progress of the run.
	 * @return int Percent, 0 to 100.
	 */
	private static function percent( array $progress ): int {
		if ( 'complete' === $progress['phase'] ) {
			return 100;
		}
		if ( 'restore' !== $progress['phase'] ) {
			return 0;
		}
		[ $done, $total ] = $progress['total_bytes'] > 0 ? [ $progress['bytes'], $progress['total_bytes'] ] : [ $progress['files'], $progress['total_files'] ];
		return $total > 0 ? max( 0, min( 99, (int) floor( 100 * $done / $total ) ) ) : 0;
	}

	/**
	 * Opens the repository with the cached master key.
	 *
	 * @return Repository Repository.
	 * @throws InvalidArgument If no key is cached.
	 */
	private function repository(): Repository {
		$repository = $this->services->common->access->open_cached();
		if ( null === $repository ) {
			InvalidArgument::raise( 'The repository key is no longer available; the restore must unlock again.', [ 'code' => 'key_missing' ] );
		}
		$repository->limit_memory( $this->services->common->memory_budget() );
		return $repository;
	}
}
