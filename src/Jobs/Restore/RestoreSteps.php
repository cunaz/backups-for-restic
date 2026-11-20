<?php
/**
 * The steps of a restore job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\DatabaseFailed;
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
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Storage\Zip\ZipWriter;
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
		$this->common->require_reachable( true );
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
	 * @throws DatabaseFailed If the snapshot has no database for the requested preview.
	 */
	public function restore( Job $job, EventLog $log, float $deadline ): bool {
		if ( 'database_import' === ( $job->progress['phase'] ?? null ) ) {
			if ( $this->preview()->advance( $this->services, $job, $deadline ) ) {
				$job->progress['phase'] = 'database_cleanup';
			}
			return false;
		}
		if ( 'database_cleanup' === ( $job->progress['phase'] ?? null ) ) {
			if ( ! $this->preview()->clean( $job, $deadline ) ) {
				return false;
			}
			$job->progress['phase'] = 'complete';
			return $this->after_staging( $job );
		}
		if ( 'archiving' === ( $job->progress['phase'] ?? null ) ) {
			return $this->archive( $job, $deadline );
		}
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
		if ( RestoreRequest::from_job( $job )->database_preview ) {
			$common     = $this->services->common;
			$repository = $this->repository();
			$reader     = new Reader( $repository, $common->identity, $common->clock, $common->index_cache( $repository ) );
			$manifest   = $reader->manifest( Id::from_hex( RestoreRequest::from_job( $job )->snapshot ) );
			if ( null === $manifest || true !== ( $manifest['data']['database'] ?? null ) || ! is_string( $manifest['data']['home_url'] ?? null ) ) {
				throw new DatabaseFailed( 'This snapshot has no complete site manifest with a database.' );
			}
			$this->preview()->bind( $this->services, $job, $manifest['path'], $manifest['data']['home_url'], $manifest['data']['versions'] ?? null );
			$job->progress['phase'] = 'database_import';
			// Returning before DDL ensures the runner persists the cleanup identity first.
			return false;
		}
		return $this->after_staging( $job );
	}

	/**
	 * Opens the non-reconnecting preview connection only for jobs that need it.
	 *
	 * @return DatabasePreview Database operations of this request.
	 * @throws DatabaseFailed If the adapter is unavailable.
	 */
	private function preview(): DatabasePreview {
		if ( null === $this->services->preview ) {
			throw new DatabaseFailed( 'The database preview connection is unavailable.' );
		}
		return ( $this->services->preview )();
	}

	/**
	 * Continues with archive creation or recording after staging and any database rehearsal.
	 *
	 * @param Job $job Restore job.
	 * @return bool Whether recording can begin.
	 */
	private function after_staging( Job $job ): bool {
		if ( RestoreRequest::from_job( $job )->archive ) {
			$job->progress['phase'] = 'archiving';
			return false;
		}
		return true;
	}

	/**
	 * Builds the optional archive in the remaining restore ticks. Staged files stay usable
	 * when a name cannot be archived or the second copy does not fit on disk.
	 *
	 * @param Job   $job      Job with completed staging.
	 * @param float $deadline End of this tick.
	 * @return bool Whether recording may begin.
	 */
	private function archive( Job $job, float $deadline ): bool {
		$writer = new ZipWriter( $this->services->files_dir( $job ), $this->services->staging_dir( $job ) . '/' . RestoreServices::ARCHIVE );
		try {
			if ( ! $writer->advance( $deadline ) ) {
				$job->progress['archive_bytes'] = $writer->progress()['bytes'];
				return false;
			}
		} catch ( InvalidArgument | StorageFailed $error ) {
			$name = $error->context()['name'] ?? '';
			$job->warn( 'ZIP unavailable. ' . $error->getMessage() . ( is_string( $name ) && '' !== $name ? ' ' . $name : '' ) );
			try {
				$writer->discard();
			} catch ( StorageFailed ) {
				$job->warn( 'Archive working files will be removed when staging expires.' );
			}
		}
		$job->progress['phase'] = 'complete';
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
		] + $job->result;
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
	 * which would cost the staged files: whatever the failure is, also an Error of PHP such
	 * as a function that a host disabled.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws \Throwable If the lock of an unfinished run cannot be released.
	 */
	public function release( Job $job, EventLog $log, float $deadline ): bool {
		if ( isset( $job->data['database_binding'] ) && ! $this->preview()->clean( $job, $deadline ) ) {
			return false;
		}
		$services = $this->services->common;
		$work     = $this->services->work_dir( $job );
		// A run records itself before it takes its first lock: no checkpoint, no lock.
		if ( ! is_file( $work . '/' . RestoreServices::CHECKPOINT ) ) {
			return true;
		}
		try {
			$repository = $services->access->open_cached();
			if ( null === $repository ) {
				// Without the key the lock cannot be named; it goes stale after 30 minutes.
				return true;
			}
			RestoreRun::resume( $repository, $work, $services->clock )->abort();
		} catch ( \Throwable $failure ) {
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
	 * A restore that is complete stays succeeded when something here cannot be removed,
	 * whatever the failure is. A failing cleanup step would end the job as failed while its
	 * whole tree and the entry of the last restore stay, and a failed job must not own a
	 * staging directory (ADR 0037): the heartbeat would remove it as a leftover. What is left
	 * lies in the directory of the job and goes when old jobs are pruned.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws \Throwable If the cleanup after a failure or a cancellation fails.
	 */
	public function clean_up( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		$partial  = null !== $job->outcome;
		try {
			Cleanup::run(
				static fn () => Files::remove_tree( $services->work_dir( $job ) ),
				static function () use ( $services, $job, $partial ): void {
					if ( $partial ) {
						Files::remove_tree( $services->staging_dir( $job ) );
					}
				},
				fn () => $this->common->clean_up( $job, $log, $deadline )
			);
		} catch ( \Throwable $failure ) {
			if ( $partial ) {
				throw $failure;
			}
			$log->write( 'warning', 'Working files of the finished restore could not be removed.', [ 'code' => JobError::from_throwable( $failure )->code ] );
		}
		return true;
	}

	/**
	 * Lets the run of a job work until the deadline and copies its progress into the job.
	 *
	 * A run whose repository lock did not hold, after a long pause or because someone removed
	 * it, gives up the tick (ADR 0036). The next tick takes a new lock, makes sure that the
	 * snapshot is still there and reads the index again. After MAX_RESUMPTIONS such ticks the
	 * job fails rather than go on for ever. A snapshot that the repository does not hold, never
	 * held or was made to forget, does not come back: the job fails in the tick that finds out.
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
			if ( $resumptions > self::MAX_RESUMPTIONS || RestoreRun::SNAPSHOT_MISSING === ( $changed->context()['cause'] ?? null ) ) {
				throw $changed;
			}
			$job->data['resumptions'] = $resumptions;
			$log->write( 'warning', 'The repository lock of the restore did not hold; the restore takes up its work again.', [ 'resumption' => $resumptions ] + $changed->context() );
		}
		if ( $run->space_unknown() && true !== ( $job->data['space_unknown'] ?? null ) ) {
			$job->data['space_unknown'] = true;
			$job->warn( 'The free disk space is unknown because the host does not report it. The restore went on without checking it and stops if the disk is full.' );
			$log->write( 'warning', 'The free disk space for the restore is unknown; the restore relies on its checked writes.' );
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
		$request    = RestoreRequest::from_job( $job );
		$resume     = is_file( $work . '/' . RestoreServices::CHECKPOINT );
		$paths      = $request->paths;
		if ( $request->fresh_install && ( ! $resume || ! isset( $job->data['site_layout'] ) ) ) {
			// A worker can stop after creating the engine checkpoint and before saving the
			// job. Re-read the same immutable snapshot in that case to recover the binding.
			$reader   = new Reader( $repository, $services->identity, $services->clock, $services->index_cache( $repository ) );
			$manifest = $reader->manifest( Id::from_hex( $request->snapshot ) );
			if ( null === $manifest ) {
				throw new InvalidArgument( 'Fresh installation requires a verified site manifest.' );
			}
			$binding                  = SiteLayout::bind( $request->snapshot, $manifest['data'], $manifest['path'] );
			$paths                    = SiteLayout::selection( $binding );
			$job->data['site_layout'] = $binding;
		}
		if ( $resume ) {
			return RestoreRun::resume( $repository, $work, $services->clock, $services->throttle(), $this->services->space );
		}
		// No checkpoint means no run: what a start that the host ended left behind holds no
		// lock and no file, and would only keep the new start from finding its directory empty.
		Files::remove_tree( $work );
		$target = $this->services->files_dir( $job );
		if ( ! is_dir( $target ) && ! mkdir( $target, 0700, true ) && ! is_dir( $target ) ) {
			StorageFailed::raise( 'The staging directory could not be created in the private directory.', [ 'job' => $job->id ] );
		}
		chmod( $this->services->staging_dir( $job ), 0700 );
		chmod( $target, 0700 );
		$run = RestoreRun::start( $repository, Id::from_hex( $request->snapshot ), $target, $work, $paths, $services->identity, $services->clock, $services->throttle(), $this->services->space );
		$log->write(
			'info',
			'Restore started.',
			[
				'snapshot' => $request->snapshot,
				'paths'    => count( $paths ),
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
