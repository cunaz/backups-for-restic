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
		$memory = self::bytes( ini_get( 'memory_limit' ) );
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
		$services   = $this->services;
		$repository = $services->access->open_cached();
		if ( null === $repository ) {
			$unlock = new KeyUnlock( $services->access->backend(), $services->access->password(), $services->unlock_dir( $job ), $services->random );
			$key    = $unlock->run( $deadline );
			if ( null === $key ) {
				$job->progress['unlock_percent'] = (int) round( 100 * $unlock->progress() );
				return false;
			}
			$repository = $services->access->open( $key );
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
	 * @param Job      $job      Job.
	 * @param EventLog $log      Log.
	 * @param float    $deadline Unix time.
	 * @return bool True once the snapshot is written.
	 * @throws InvalidArgument If the master key is missing.
	 */
	public function back_up( Job $job, EventLog $log, float $deadline ): bool {
		$services   = $this->services;
		$repository = $this->repository();
		$index      = $services->index_cache( $repository )->load( $repository );
		$plan       = ( $services->plan )( $job );
		$work       = $services->work_dir( $job );

		if ( is_file( $work . '/state.json' ) ) {
			$run = BackupRun::resume( $repository, $plan, $work, $services->identity, $services->clock, $services->pack_size(), $index );
		} else {
			$parent = $this->parent( $repository, $plan->paths );
			$run    = BackupRun::start( $repository, $plan, $work, $services->identity, $services->clock, $parent, $services->pack_size(), $index );
			$log->write(
				'info',
				'Backup started.',
				[
					'parent' => $parent?->hex(),
					'roots'  => count( $plan->paths ),
				]
			);
		}

		$done          = $run->advance( $deadline, $services->throttle() );
		$job->progress = $run->progress();
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
		$this->measure( $log );
		return true;
	}

	/**
	 * Remembers how much the repository holds, for the dashboard: the number and total size of
	 * its packs, from one listing. A listing that fails or names no sizes changes nothing; the
	 * job that asked has done its work either way.
	 *
	 * @param EventLog $log Log.
	 */
	public function measure( EventLog $log ): void {
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

	/**
	 * Parses a php.ini size such as 128M.
	 *
	 * @param string $value Value.
	 * @return int Bytes, or -1 for unlimited.
	 */
	private static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$number = (int) $value;
		return match ( strtolower( substr( $value, -1 ) ) ) {
			'g'     => $number * 1073741824,
			'm'     => $number * 1048576,
			'k'     => $number * 1024,
			default => $number,
		};
	}
}
