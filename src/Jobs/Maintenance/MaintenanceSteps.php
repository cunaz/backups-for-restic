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
use BackupsForRestic\Engine\CheckpointLock;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\ForgetRun;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\Cleanup;
use BackupsForRestic\Jobs\ClosureStep;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Settings\Schema;
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
		$guard    = $this->services->guard();
		$status   = AppendOnlyProbe::detect( $services->access->backend(), $services->random );
		if ( $guard->detected( $status, $job->id ) ) {
			$this->switched( $job, $log );
		}
		if ( AppendOnlyStatus::AppendOnly === $status ) {
			$job->result['skipped'] = 'append_only';
		} elseif ( $guard->is_external() ) {
			if ( AppendOnlyStatus::DeletionsAllowed === $status ) {
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
			$done = $run->advance( $deadline, $services->throttle() );
		} catch ( AppendOnlyRefused ) {
			Cleanup::run(
				fn () => $this->switch_to_external( $job, $log ),
				fn () => $this->abandon( $job )
			);
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
		$cache      = $services->index_cache( $repository );
		try {
			$run      = is_file( $work . '/state.json' )
				? PruneRun::resume( $repository, $work, $services->identity, $services->clock, cache: $cache )
				: PruneRun::start( $repository, $work, $services->identity, $services->clock, $services->random, cache: $cache );
			$restarts = $run->result()['restarts'];
			$done     = $run->advance( $deadline, $services->throttle() );
		} catch ( AppendOnlyRefused ) {
			Cleanup::run(
				fn () => $this->switch_to_external( $job, $log ),
				fn () => $this->abandon( $job )
			);
			return true;
		}
		if ( $run->result()['restarts'] > $restarts ) {
			$log->write( 'warning', 'Another program changed the repository; prune starts over.', [ 'restart' => $run->result()['restarts'] ] );
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
		$cache      = $services->index_cache( $repository );
		$percent    = Decoded::int( $job->data['read_data_subset_percent'] ?? null, $services->settings->int( 'schedule.check.read_data_subset_percent' ) );
		$run        = is_file( $work . '/state.json' )
			? CheckRun::resume( $repository, $work, $services->identity, $services->clock, $cache )
			: CheckRun::start( $repository, $work, $services->identity, $services->clock, $services->random, $percent, $cache );
		$restarts   = $run->result()['restarts'];
		$done       = $run->advance( $deadline, $services->throttle() );
		if ( $run->result()['restarts'] > $restarts ) {
			$log->write( 'warning', 'Another program removed snapshots or data from the repository; the check starts over.', [ 'restart' => $run->result()['restarts'] ] );
		}

		$job->progress = $run->progress();
		$result        = $run->result();
		$job->result   = array_merge(
			$job->result,
			[
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
				'restarts' => $result['restarts'],
			],
			$result['stats']
		);
		if ( $done ) {
			// Kept with the job: recording then needs neither the checkpoint nor the server, and
			// so also works in the last tick before the plugin is switched off.
			$job->data['check_details'] = self::details( $result );
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
		$details  = JobType::Check === $job->type ? ( self::kept_details( $job ) ?? $this->check_details( $job ) ) : null;
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
		if ( JobType::Prune === $job->type && ! isset( $job->result['skipped'] ) ) {
			// Prune is the job that makes the repository smaller.
			$this->common->measure( $job, $log );
		}
		return true;
	}

	/**
	 * Counts and the first findings of a finished check, as the checking step kept them with
	 * the job.
	 *
	 * @param Job $job Check job.
	 * @return array{errors: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>}|null Details, or null if the job carries none.
	 */
	private static function kept_details( Job $job ): ?array {
		$kept = $job->data['check_details'] ?? null;
		// Anything but what the checking step wrote: the checkpoint is asked. A count of errors
		// that was taken for zero would clear the alert of a damaged repository.
		if ( ! is_array( $kept ) || ! is_int( $kept['errors'] ?? null ) || $kept['errors'] < 0 || ! is_array( $kept['counts'] ?? null ) || ! is_array( $kept['findings'] ?? null ) ) {
			return null;
		}
		$counts = [];
		foreach ( $kept['counts'] as $code => $count ) {
			$counts[ (string) $code ] = Decoded::int( $count );
		}
		$findings = [];
		foreach ( Decoded::arrays( $kept['findings'] ) as $finding ) {
			$findings[] = [
				'severity' => Decoded::string( $finding['severity'] ?? null ),
				'code'     => Decoded::string( $finding['code'] ?? null ),
				'object'   => Decoded::string( $finding['object'] ?? null ),
				'message'  => Decoded::string( $finding['message'] ?? null ),
			];
		}
		return [
			'errors'   => $kept['errors'],
			'counts'   => $counts,
			'findings' => $findings,
		];
	}

	/**
	 * Counts and the first findings of a finished check, read from its checkpoint: for a job
	 * whose checking step ended before the details were kept with the job.
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
		return self::details( CheckRun::resume( $this->repository(), $work, $services->identity, $services->clock )->result() );
	}

	/**
	 * What the dashboard and the alert show of a check's result. Errors come before warnings,
	 * each in the order found: the alert announces errors, and warnings from an earlier phase,
	 * such as packs that no index lists, must not take their places.
	 *
	 * @param array<string, mixed> $result Result of the check.
	 * @phpstan-param array{errors: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, ...} $result
	 * @return array{errors: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>} Details.
	 */
	private static function details( array $result ): array {
		$findings = $result['findings'];
		// usort() is stable, so each severity keeps the order in which the check found it.
		usort( $findings, static fn ( array $a, array $b ): int => ( 'error' === $b['severity'] ) <=> ( 'error' === $a['severity'] ) );
		return [
			'errors'   => $result['errors'],
			'counts'   => $result['counts'],
			'findings' => array_slice( $findings, 0, self::REPORTED_FINDINGS ),
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
		Cleanup::run(
			fn () => Files::remove_tree( $this->services->work_dir( $job ) ),
			fn () => $this->common->clean_up( $job, $log, $deadline )
		);
		return true;
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
			CheckpointLock::release( $repository, $work . '/state.json', $services->identity, $services->clock );
		} catch ( PluginException ) {
			Files::remove_tree( $work );
			return false;
		}
		Files::remove_tree( $work );
		return true;
	}

	/**
	 * Switches retention to external after a refused deletion and ends the job without deleting.
	 *
	 * @param Job      $job Job.
	 * @param EventLog $log Log.
	 */
	private function switch_to_external( Job $job, EventLog $log ): void {
		if ( $this->services->guard()->refused( $job->id ) ) {
			$this->switched( $job, $log );
		}
		$job->result['skipped'] = 'append_only';
	}

	/**
	 * Notes a switch to external retention in the log and in the result, for the notification.
	 *
	 * @param Job      $job Job.
	 * @param EventLog $log Log.
	 */
	private function switched( Job $job, EventLog $log ): void {
		$job->result['retention_switched'] = true;
		$log->write( 'warning', 'The repository is append-only. Retention switched to external: the plugin no longer deletes snapshots.' );
	}

	/**
	 * The retention policy from the settings; keep-within is mandatory (ADR 0010), and the
	 * policy must not fall below the retention floor, whatever the stored settings say.
	 *
	 * @return RetentionPolicy Policy.
	 * @throws InvalidArgument If keep-within is missing or zero, or the policy is below the floor.
	 */
	private function policy(): RetentionPolicy {
		$settings = $this->services->common->settings;
		$within   = Duration::parse( $settings->string( 'retention.keep.within' ) );
		if ( $within->is_zero() ) {
			InvalidArgument::raise( 'The retention policy needs a keep-within duration.', [ 'code' => 'configuration' ] );
		}
		if ( ! Schema::retention_floor( $settings->string( 'retention.keep.within' ), $settings->int( 'retention.keep.last' ) ) ) {
			InvalidArgument::raise( 'The retention policy must keep every snapshot of at least one day, or at least the newest one.', [ 'code' => 'configuration' ] );
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
		$repository->limit_memory( $this->services->common->memory_budget() );
		return $repository;
	}
}
