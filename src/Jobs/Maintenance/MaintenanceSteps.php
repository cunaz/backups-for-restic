<?php
/**
 * Steps of forget, prune and check jobs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\ForgetRun;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\ClosureStep;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Forget, prune and check as resumable jobs, with the retention modes of ADR 0010.
 *
 * Forget and prune first probe whether the server is append-only. If it is, retention
 * switches to `external` at once and the job ends without deleting anything. If deletions
 * became possible while retention is external, the job only records a proposal to switch
 * back: nothing is deleted until an administrator confirms. A refused deletion during the run
 * counts as a detection too. The check never deletes and runs in either mode.
 */
final class MaintenanceSteps implements StepProvider {

	/** Findings kept in the job result and the check alert. */
	private const int REPORTED_FINDINGS = 10;

	/**
	 * Creates the steps.
	 *
	 * @param MaintenanceServices $services Dependencies.
	 * @param BackupSteps         $common   Preflight, unlock and cleanup shared with backups.
	 */
	public function __construct( private readonly MaintenanceServices $services, private readonly BackupSteps $common ) {
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
			'preflight'   => $this->common->preflight( ... ),
			'unlocking'   => $this->common->unlock( ... ),
			'detecting'   => $this->detect( ... ),
			'forgetting'  => $this->forget( ... ),
			'pruning'     => $this->prune( ... ),
			'checking'    => $this->check( ... ),
			'recording'   => $this->record( ... ),
			'releasing'   => $this->release( ... ),
			'cleaning_up' => $this->clean_up( ... ),
			default       => InvalidArgument::raise( 'Unknown step.', [ 'step' => $name ] ),
		};
		return new ClosureStep( $method );
	}

	/**
	 * Probes for append-only mode and applies the asymmetric switching of ADR 0010.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function detect( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services->common;
		$status   = AppendOnlyProbe::detect( $services->access->backend(), $services->random );
		$now      = $services->clock->now()[0];
		$services->state->set(
			'append_only',
			[
				'status'     => $status->value,
				'checked_at' => $now,
			]
		);
		if ( AppendOnlyStatus::AppendOnly === $status ) {
			$this->switch_to_external( $job, $log );
			return true;
		}
		if ( 'external' === $services->settings->string( 'retention.mode' ) ) {
			if ( AppendOnlyStatus::DeletionsAllowed === $status ) {
				$services->state->set(
					'retention_proposal',
					[
						'mode'  => 'plugin',
						'since' => $now,
					]
				);
				$log->write( 'info', 'The server allows deletions again; retention stays external until an administrator switches it back.' );
			}
			$job->result['skipped'] = 'retention_external';
		}
		return true;
	}

	/**
	 * Removes the snapshots the retention policy no longer keeps.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True when done.
	 */
	public function forget( Job $job, EventLog $log, float $deadline ): bool {
		if ( isset( $job->result['skipped'] ) ) {
			return true;
		}
		$services   = $this->services->common;
		$repository = $this->repository();
		$work       = $this->services->work_dir( $job );
		$policy     = $this->policy();
		try {
			$run  = is_file( $work . '/state.json' )
				? ForgetRun::resume( $repository, $work, $services->identity, $services->clock, $policy, $this->services->retention_tags() )
				: ForgetRun::start( $repository, $work, $services->identity, $services->clock, $services->random, $policy, $this->services->retention_tags() );
			$done = $run->advance( $deadline );
		} catch ( AppendOnlyRefused ) {
			$this->abandon( $job );
			$this->switch_to_external( $job, $log );
			return true;
		}
		$job->result = array_merge( $job->result, $run->result() );
		if ( $done ) {
			$log->write( 'info', 'Snapshots forgotten.', $run->result() );
		}
		return $done;
	}

	/**
	 * Deletes the packs that no snapshot uses.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True when done.
	 */
	public function prune( Job $job, EventLog $log, float $deadline ): bool {
		if ( isset( $job->result['skipped'] ) ) {
			return true;
		}
		$services   = $this->services->common;
		$repository = $this->repository();
		$work       = $this->services->work_dir( $job );
		$index      = $this->index( $repository );
		try {
			$run  = is_file( $work . '/state.json' )
				? PruneRun::resume( $repository, $work, $services->identity, $services->clock, $index )
				: PruneRun::start( $repository, $work, $services->identity, $services->clock, $services->random, $index );
			$done = $run->advance( $deadline );
		} catch ( AppendOnlyRefused ) {
			$this->abandon( $job );
			$this->switch_to_external( $job, $log );
			return true;
		}
		$job->progress = $run->progress();
		$job->result   = array_merge( $job->result, $run->result() );
		if ( $done ) {
			$log->write( 'info', 'Unused packs deleted.', $run->result() );
		}
		return $done;
	}

	/**
	 * Checks the repository.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True when done.
	 * @throws BackendException If the server cannot be reached; the job tries again.
	 */
	public function check( Job $job, EventLog $log, float $deadline ): bool {
		$services   = $this->services->common;
		$repository = $this->repository();
		$work       = $this->services->work_dir( $job );
		try {
			$index = $this->index( $repository );
		} catch ( BackendException | UnsupportedRepository $exception ) {
			// Not reachable, or not readable with this PHP: neither is damage for the check to find.
			throw $exception;
		} catch ( PluginException ) {
			// A damaged index file must not stop the check that is to find it: the check then
			// builds its index from the files it can read and reports the others.
			$index = null;
		}
		$percent = Decoded::int( $job->data['read_data_subset_percent'] ?? null, $services->settings->int( 'schedule.check.read_data_subset_percent' ) );
		$run     = is_file( $work . '/state.json' )
			? CheckRun::resume( $repository, $work, $services->identity, $services->clock, $index )
			: CheckRun::start( $repository, $work, $services->identity, $services->clock, $services->random, $percent, $index );
		$done    = $run->advance( $deadline );

		$job->progress = $run->progress();
		$result        = $run->result();
		$job->result   = array_merge(
			$job->result,
			[
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
			],
			$result['stats']
		);
		if ( $done ) {
			$log->write(
				$result['errors'] > 0 ? 'error' : 'info',
				$result['errors'] > 0 ? 'The check found damage.' : 'The check found no errors.',
				[
					'errors'   => $result['errors'],
					'warnings' => $result['warnings'],
				]
			);
		}
		return $done;
	}

	/**
	 * Remembers the outcome for the dashboard; a check with errors raises a persistent alert.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function record( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services->common;
		$now      = $services->clock->now()[0];
		$details  = JobType::Check === $job->type ? $this->check_details( $job ) : null;
		$services->state->set(
			'last_' . $job->type->value,
			[
				'job'      => $job->id,
				'finished' => $now,
				'result'   => $job->result,
				'counts'   => $details['counts'] ?? [],
				'findings' => $details['findings'] ?? [],
			]
		);
		if ( null !== $details ) {
			$services->state->set(
				'check_alert',
				0 === $details['errors'] ? [] : [
					'job'      => $job->id,
					'at'       => $now,
					'errors'   => $details['errors'],
					'counts'   => $details['counts'],
					'findings' => $details['findings'],
				]
			);
		}
		return true;
	}

	/**
	 * Counts and the first findings of a finished check, read from its checkpoint.
	 *
	 * @param Job $job Check job.
	 * @return array{errors: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>}|null Details, or null if the check did not run.
	 */
	private function check_details( Job $job ): ?array {
		$services = $this->services->common;
		$work     = $this->services->work_dir( $job );
		if ( ! is_file( $work . '/state.json' ) ) {
			return null;
		}
		$result = CheckRun::resume( $this->repository(), $work, $services->identity, $services->clock )->result();
		return [
			'errors'   => $result['errors'],
			'counts'   => $result['counts'],
			'findings' => array_slice( $result['findings'], 0, self::REPORTED_FINDINGS ),
		];
	}

	/**
	 * Releases the repository lock of an unfinished run. Runs on every path to the end.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function release( Job $job, EventLog $log, float $deadline ): bool {
		if ( $this->abandon( $job ) ) {
			$log->write( 'info', 'Repository lock released.' );
		}
		return true;
	}

	/**
	 * Deletes working files and old jobs. Runs on every path to the end.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function clean_up( Job $job, EventLog $log, float $deadline ): bool {
		Files::remove_tree( $this->services->work_dir( $job ) );
		return $this->common->clean_up( $job, $log, $deadline );
	}

	/**
	 * Aborts the engine run of a job if one is in progress, releasing its lock.
	 *
	 * @param Job $job Job.
	 * @return bool True if a run was aborted.
	 */
	private function abandon( Job $job ): bool {
		$services = $this->services->common;
		$work     = $this->services->work_dir( $job );
		if ( ! is_file( $work . '/state.json' ) ) {
			return false;
		}
		$repository = $services->access->open_cached();
		if ( null === $repository ) {
			// Without the key the lock cannot be named; it goes stale after 30 minutes.
			Files::remove_tree( $work );
			return false;
		}
		try {
			match ( $job->type ) {
				JobType::Forget => ForgetRun::resume( $repository, $work, $services->identity, $services->clock, new RetentionPolicy(), $this->services->retention_tags() )->abort(), // Aborting needs no policy, and the settings may no longer give one.
				JobType::Prune  => PruneRun::resume( $repository, $work, $services->identity, $services->clock )->abort(),
				default         => CheckRun::resume( $repository, $work, $services->identity, $services->clock )->abort(),
			};
		} catch ( PluginException ) {
			Files::remove_tree( $work );
			return false;
		}
		return true;
	}

	/**
	 * Switches retention to external after a detection and ends the job without deleting.
	 *
	 * @param Job      $job Job.
	 * @param EventLog $log Log.
	 */
	private function switch_to_external( Job $job, EventLog $log ): void {
		$services = $this->services->common;
		if ( 'external' !== $services->settings->string( 'retention.mode' ) ) {
			( $this->services->switch_retention )( 'external' );
			$services->state->set(
				'retention_switch',
				[
					'mode' => 'external',
					'at'   => $services->clock->now()[0],
					'job'  => $job->id,
				]
			);
			$log->write( 'warning', 'The repository is append-only. Retention switched to external: the plugin no longer deletes snapshots.' );
		}
		$services->state->set( 'retention_proposal', [] );
		$job->result['skipped'] = 'append_only';
	}

	/**
	 * The retention policy from the settings; keep-within is mandatory (ADR 0010).
	 *
	 * @return RetentionPolicy Policy.
	 * @throws InvalidArgument If keep-within is missing or zero.
	 */
	private function policy(): RetentionPolicy {
		$settings = $this->services->common->settings;
		$within   = Duration::parse( $settings->string( 'retention.keep.within' ) );
		if ( $within->is_zero() ) {
			InvalidArgument::raise( 'The retention policy needs a keep-within duration.', [ 'code' => 'configuration' ] );
		}
		return new RetentionPolicy(
			$settings->int( 'retention.keep.last' ),
			0,
			$settings->int( 'retention.keep.daily' ),
			$settings->int( 'retention.keep.weekly' ),
			$settings->int( 'retention.keep.monthly' ),
			$settings->int( 'retention.keep.yearly' ),
			$within
		);
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
			InvalidArgument::raise( 'The repository key is no longer available; the job must unlock again.', [ 'code' => 'key_missing' ] );
		}
		return $repository;
	}

	/**
	 * The current index from the local cache.
	 *
	 * @param Repository $repository Repository.
	 * @return \BackupsForRestic\Format\Index\IndexSet Index.
	 */
	private function index( Repository $repository ): \BackupsForRestic\Format\Index\IndexSet {
		return ( new IndexCache( $this->services->common->private_dir->subdirectory( 'cache' ) . '/' . $repository->config()->id ) )->load( $repository );
	}
}
