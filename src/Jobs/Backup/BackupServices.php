<?php
/**
 * What the backup steps work with.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Backup;

use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\CacheDirectory;
use BackupsForRestic\Storage\ExposureCheck;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Throttle;

defined( 'ABSPATH' ) || exit;

/**
 * The dependencies of the backup steps, assembled by the composition root.
 */
final readonly class BackupServices {

	/**
	 * Collects the services.
	 *
	 * @param Settings                      $settings Settings.
	 * @param RepositoryAccess              $access   Repository connection and key cache.
	 * @param PrivateDirectory              $private_dir  Private directory.
	 * @param JobStore                      $jobs     Jobs.
	 * @param SiteState                     $state    Remembered results.
	 * @param Clock                         $clock    Time source.
	 * @param RandomSource                  $random   Randomness.
	 * @param Identity                      $identity Host and user recorded in snapshots.
	 * @param \Closure(Job): BackupPlan     $plan     Builds the plan of a job.
	 * @param ExposureCheck|null            $exposure HTTP probe of the private directory.
	 * @param (\Closure(Config): void)|null $opened Told which repository a job opened (RepositoryRecord).
	 */
	public function __construct(
		public Settings $settings,
		public RepositoryAccess $access,
		public PrivateDirectory $private_dir,
		public JobStore $jobs,
		public SiteState $state,
		public Clock $clock,
		public RandomSource $random,
		public Identity $identity,
		public \Closure $plan,
		public ?ExposureCheck $exposure = null,
		public ?\Closure $opened = null
	) {
	}

	/**
	 * Working directory of the backup run of a job.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function work_dir( Job $job ): string {
		return $this->jobs->directory( $job ) . '/backup';
	}

	/**
	 * Where the database dump of a job keeps its table list and results between ticks.
	 *
	 * @param JobStore $jobs Jobs.
	 * @param Job      $job  Job.
	 * @return string Path of the state file.
	 */
	public static function dump_state( JobStore $jobs, Job $job ): string {
		return $jobs->directory( $job ) . '/database.json';
	}

	/**
	 * Working directory of the unlock of a job.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function unlock_dir( Job $job ): string {
		return $this->private_dir->subdirectory( 'kdf' ) . '/' . $job->id;
	}

	/**
	 * Target pack size.
	 *
	 * @return int Bytes.
	 */
	public function pack_size(): int {
		return $this->settings->int( 'advanced.pack_size_mib' ) * 1048576;
	}

	/**
	 * The cache directory in the private directory.
	 *
	 * @return CacheDirectory Cache.
	 */
	public function cache(): CacheDirectory {
		return new CacheDirectory( $this->private_dir->subdirectory( 'cache' ) );
	}

	/**
	 * The index cache of a repository, within the cache limit.
	 *
	 * @param Repository $repository Open repository.
	 * @return IndexCache Cache.
	 */
	public function index_cache( Repository $repository ): IndexCache {
		return new IndexCache( $this->cache()->path . '/' . $repository->config()->id, $this->settings->int( 'advanced.cache_limit_mib' ) * 1048576 );
	}

	/**
	 * The memory a job may use: PHP's limit as it is now, after the tick raised it where the
	 * host allows (ADR 0031).
	 *
	 * @return MemoryBudget|null Budget, or null if PHP does not limit memory.
	 */
	public function memory_budget(): ?MemoryBudget {
		return MemoryBudget::from_ini( ini_get( 'memory_limit' ) );
	}

	/**
	 * Pauses for the gentle CPU mode, or none.
	 *
	 * @return Throttle|null Throttle for one tick.
	 */
	public function throttle(): ?Throttle {
		return 'gentle' === $this->settings->string( 'advanced.cpu_throttle' ) ? Throttle::gentle() : null;
	}
}
