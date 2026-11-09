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
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\ExposureCheck;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The dependencies of the backup steps, assembled by the composition root.
 */
final readonly class BackupServices {

	/**
	 * Collects the services.
	 *
	 * @param Settings                  $settings Settings.
	 * @param RepositoryAccess          $access   Repository connection and key cache.
	 * @param PrivateDirectory          $private_dir  Private directory.
	 * @param JobStore                  $jobs     Jobs.
	 * @param SiteState                 $state    Remembered results.
	 * @param Clock                     $clock    Time source.
	 * @param RandomSource              $random   Randomness.
	 * @param Identity                  $identity Host and user recorded in snapshots.
	 * @param \Closure(Job): BackupPlan $plan     Builds the plan of a job.
	 * @param ExposureCheck|null        $exposure HTTP probe of the private directory.
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
		public ?ExposureCheck $exposure = null
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
}
