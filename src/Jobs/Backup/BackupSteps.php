<?php
/**
 * The steps of a backup job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Backup;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\KeyUnlock;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
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
 * Implements the backup job (docs/02-architecture.md §6) on top of the engine. Each public
 * method is one step; step() hands them to the runner.
 */
final class BackupSteps implements StepProvider {

	/** Smallest free space accepted before a backup, besides one pack. */
	private const int MIN_FREE_BYTES = 67108864;

	/** Smallest memory_limit accepted for a backup. */
	private const int MIN_MEMORY_BYTES = 100663296;

	/** How often a backup may start over because the repository changed while its lock did not hold. */
	public const int MAX_RESTARTS = 3;

	/**
	 * Creates the steps.
	 *
	 * @param BackupServices $services Dependencies.
	 */
	public function __construct( private readonly BackupServices $services ) {
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
			'unlocking'   => $this->unlock( ... ),
			'backing_up'  => $this->back_up( ... ),
			'recording'   => $this->record( ... ),
			'releasing'   => $this->release( ... ),
			'cleaning_up' => $this->clean_up( ... ),
			default       => InvalidArgument::raise( 'Unknown step.', [ 'step' => $name ] ),
		};
		return new ClosureStep( $method );
	}

	/**
	 * Checks everything that can be checked before touching the repository.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 * @throws InvalidArgument If the setup is incomplete.
	 * @throws StorageFailed If the environment cannot run a backup.
	 * @throws NotFound If the repository does not exist.
	 */
	public function preflight( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		if ( '' === $services->settings->string( 'repository.url' ) ) {
			InvalidArgument::raise( 'No repository is configured.', [ 'code' => 'no_repository' ] );
		}
		$services->access->password();
		$services->private_dir->ensure();

		$free = $services->private_dir->free_space();
		if ( null !== $free && $free < self::MIN_FREE_BYTES + 2 * $services->pack_size() ) {
			StorageFailed::raise( 'Not enough free disk space for the backup.', [ 'free' => $free ] );
		}
		$memory = $services->memory_budget()->limit ?? -1;
		if ( $memory > 0 && $memory < self::MIN_MEMORY_BYTES ) {
			StorageFailed::raise( 'The PHP memory limit is too low for a backup.', [ 'memory_limit' => $memory ] );
		}
		if ( true === $services->exposure?->exposed() ) {
			StorageFailed::raise( 'The private directory can be read from the web.' );
		}
		if ( null === $services->access->backend()->size( ObjectType::Config, 'config' ) ) {
			NotFound::raise( 'The repository has no config file.' );
		}
		$log->write( 'info', 'Preflight passed.' );
		return true;
	}

	/**
	 * Gets the master key: from the cache, or with the resumable key derivation. Every job
	 * comes through here, so this is also where the plugin records which repository it works
	 * with, and where a repository that it cannot read is refused (ADR 0019).
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True once the key is cached.
	 * @throws UnsupportedRepository If the repository has format version 2 and PHP lacks the zstd extension.
	 */
	public function unlock( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		// A password that somebody entered is what the unlock job is there to try; a cached key
		// would say nothing about it. Every other job works with what is confirmed.
		$candidate  = JobType::Unlock === $job->type ? $services->access->candidate_password() : null;
		$repository = null === $candidate ? $services->access->open_cached() : null;
		if ( null === $repository ) {
			$unlock = new KeyUnlock( $services->access->backend(), $candidate ?? $services->access->password(), $services->unlock_dir( $job ), $services->random );
			$key    = $unlock->run( $deadline );
			if ( null === $key ) {
				$job->progress['unlock_percent'] = (int) round( 100 * $unlock->progress() );
				return false;
			}
			$repository = $services->access->open( $key );
			if ( null !== $candidate ) {
				// The password before the key: a key without the password it came from could not
				// be derived again once the cache is lost.
				$services->access->confirm_password( $candidate );
			}
			$services->access->cache_master_key( $repository->config(), $key );
			$log->write( 'info', 'Repository unlocked.', [ 'format' => $repository->config()->version ] );
		}
		$config = $repository->config();
		// Recorded before the refusal: the Doctor then names the missing extension.
		if ( null !== $services->opened ) {
			( $services->opened )( $config );
		}
		if ( $config->version >= 2 && ! Compression::available() ) {
			UnsupportedRepository::raise( 'The repository has format version 2, which needs the zstd PHP extension.', [ 'version' => $config->version ] );
		}
		return true;
	}

	/**
	 * Runs the engine's resumable backup.
	 *
	 * A run whose lock did not hold, after a long pause or because someone removed it, and
	 * that then finds that another client removed what it relied on, is given up by the
	 * engine (ADR 0030). The step then starts a new run with the next tick; what was uploaded
	 * and is still there is found again and not sent twice. After MAX_RESTARTS such runs the
	 * job fails rather than go on for ever.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True once the snapshot is written.
	 * @throws InvalidArgument If the master key is missing.
	 * @throws RepositoryChanged If the backup had to start over too often.
	 */
	public function back_up( Job $job, EventLog $log, float $deadline ): bool {
		$services   = $this->services;
		$repository = $this->repository();
		$cache      = $services->index_cache( $repository );
		$plan       = ( $services->plan )( $job );
		$work       = $services->work_dir( $job );

		if ( is_file( $work . '/state.json' ) ) {
			$run = BackupRun::resume( $repository, $plan, $work, $services->identity, $services->clock, $services->pack_size(), $cache );
		} else {
			$parent = $this->parent( $repository, $plan->paths );
			$run    = BackupRun::start( $repository, $plan, $work, $services->identity, $services->clock, $parent, $services->pack_size(), $cache );
			$log->write(
				'info',
				'Backup started.',
				[
					'parent' => $parent?->hex(),
					'roots'  => count( $plan->paths ),
				]
			);
		}

		try {
			$done = $run->advance( $deadline, $services->throttle() );
		} catch ( RepositoryChanged $changed ) {
			$restarts = Decoded::int( $job->data['restarts'] ?? 0 ) + 1;
			if ( $restarts > self::MAX_RESTARTS ) {
				throw $changed;
			}
			$job->data['restarts'] = $restarts;
			$job->progress         = [];
			$job->warnings         = [];
			// The new run lists the tables again; the old list may be as outdated as the rest.
			$dump = BackupServices::dump_state( $services->jobs, $job );
			if ( is_file( $dump ) && ! unlink( $dump ) ) {
				StorageFailed::raise( 'The previous database dump state could not be removed.', [ 'file' => basename( $dump ) ] );
			}
			$log->write( 'warning', 'Another program removed data that the backup relied on; the backup starts over.', [ 'restart' => $restarts ] + $changed->context() );
			return false;
		}
		$job->progress = $run->progress();
		if ( $run->pack_size() < $services->pack_size() && true !== ( $job->data['small_packs'] ?? null ) ) {
			$job->data['small_packs'] = true;
			$log->write(
				'info',
				'Packs are made smaller than configured, because the index leaves little memory.',
				[
					'pack_size'  => $run->pack_size(),
					'configured' => $services->pack_size(),
				]
			);
		}
		// How much the last backup of this repository went through: the measure for a
		// progress bar and an estimate, since this backup knows its own total only at the end.
		$last     = $services->state->get( 'last_backup' )['progress'] ?? null;
		$expected = Decoded::int( is_array( $last ) ? ( $last['total_bytes_processed'] ?? null ) : null );
		if ( $expected > 0 ) {
			$job->progress['expected_bytes'] = $expected;
		}
		foreach ( array_slice( $run->warnings(), count( $job->warnings ) ) as $warning ) {
			$job->warn( $warning );
		}
		if ( $done ) {
			$job->result['snapshot'] = $run->snapshot_id()?->hex();
			$log->write( 'info', 'Snapshot saved.', [ 'snapshot' => $job->result['snapshot'] ] );
		}
		return $done;
	}

	/**
	 * Remembers the result for the dashboard.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function record( Job $job, EventLog $log, float $deadline ): bool {
		$this->services->state->set(
			'last_backup',
			[
				'job'      => $job->id,
				'snapshot' => $job->result['snapshot'] ?? null,
				'finished' => $this->services->clock->now()[0],
				'progress' => $job->progress,
				'warnings' => count( $job->warnings ),
			]
		);
		$this->measure( $job, $log );
		return true;
	}

	/**
	 * Remembers how much the repository holds, for the dashboard: the number and total size of
	 * its packs, from one listing. A listing that fails or names no sizes changes nothing; the
	 * job that asked has done its work either way.
	 *
	 * A job asks once. Should the listing take so long that the host ends the request, the
	 * next tick repeats the step that asked, and without this it would ask again, and again
	 * with every tick: a backup that is done could not finish over a number on the dashboard.
	 *
	 * @param Job      $job Job that asks.
	 * @param EventLog $log Log.
	 */
	public function measure( Job $job, EventLog $log ): void {
		if ( ( $this->services->state->get( 'usage_asked' )['job'] ?? null ) === $job->id ) {
			$log->write( 'warning', 'The size of the repository was not read: an earlier attempt of this job did not come back.' );
			return;
		}
		$this->services->state->set( 'usage_asked', [ 'job' => $job->id ] );
		try {
			$repository = $this->services->access->open_cached();
			if ( null === $repository ) {
				return;
			}
			$sizes = $repository->backend()->list( ObjectType::Data );
		} catch ( PluginException $e ) {
			$log->write( 'warning', 'The size of the repository could not be read.', [ 'code' => JobError::from_throwable( $e )->code ] );
			return;
		}
		if ( [] !== $sizes && min( $sizes ) < 0 ) {
			// A server that lists names only.
			return;
		}
		$this->services->state->set(
			'usage',
			[
				'bytes' => array_sum( $sizes ),
				'packs' => count( $sizes ),
				'at'    => $this->services->clock->now()[0],
			]
		);
	}

	/**
	 * Releases the repository lock of an unfinished backup. Runs on every path to the end.
	 *
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool Always true.
	 */
	public function release( Job $job, EventLog $log, float $deadline ): bool {
		$services = $this->services;
		$work     = $services->work_dir( $job );
		if ( ! is_file( $work . '/state.json' ) ) {
			return true;
		}
		$repository = $services->access->open_cached();
		if ( null !== $repository ) {
			BackupRun::resume( $repository, ( $services->plan )( $job ), $work, $services->identity, $services->clock, $services->pack_size() )->abort();
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
		$services = $this->services;
		Files::remove_tree( $services->unlock_dir( $job ) );
		Files::remove_tree( $services->work_dir( $job ) );
		// A password that did not open the repository, or whose unlock was cancelled; after any
		// other job, one that an unlock which never ended left behind.
		$services->access->forget_candidate_password();
		$services->jobs->prune( $services->settings->int( 'advanced.keep_jobs' ) );
		// The repository in use is the one whose key is cached. Without a cached key nothing is
		// removed: better an old cache too many than the current one deleted after every job.
		$services->cache()->remove_others( $services->access->cached_repository_id() );
		return true;
	}

	/**
	 * Opens the repository with the cached master key.
	 *
	 * @return Repository Repository.
	 * @throws InvalidArgument If no key is cached.
	 */
	private function repository(): Repository {
		$repository = $this->services->access->open_cached();
		if ( null === $repository ) {
			InvalidArgument::raise( 'The repository key is no longer available; the backup must unlock again.', [ 'code' => 'key_missing' ] );
		}
		$repository->limit_memory( $this->services->memory_budget() );
		return $repository;
	}

	/**
	 * Finds the parent: the newest snapshot of this host with the same paths, as restic does.
	 *
	 * @param Repository   $repository Repository.
	 * @param list<string> $paths      Paths of the new snapshot.
	 * @return Id|null Parent snapshot.
	 */
	private function parent( Repository $repository, array $paths ): ?Id {
		$hostname = $this->services->identity->hostname;
		foreach ( $repository->snapshots() as $hex => $snapshot ) {
			if ( $snapshot->hostname === $hostname && $this->same_paths( $snapshot, $paths ) ) {
				return Id::from_hex( $hex );
			}
		}
		return null;
	}

	/**
	 * Whether a snapshot covers the same paths.
	 *
	 * @param Snapshot     $snapshot Snapshot.
	 * @param list<string> $paths    Paths.
	 * @return bool True if equal as sets.
	 */
	private function same_paths( Snapshot $snapshot, array $paths ): bool {
		$theirs = $snapshot->paths;
		sort( $theirs );
		sort( $paths );
		return $theirs === $paths;
	}
}
