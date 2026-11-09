<?php
/**
 * Dependencies of the maintenance steps.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The backup services plus what only maintenance needs.
 */
final readonly class MaintenanceServices {

	/**
	 * Creates the services.
	 *
	 * @param BackupServices         $common           Shared services.
	 * @param \Closure(string): void $switch_retention Saves the retention mode (plugin or external).
	 */
	public function __construct( public BackupServices $common, public \Closure $switch_retention ) {
	}

	/**
	 * Working directory of a maintenance job.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function work_dir( Job $job ): string {
		return $this->common->jobs->directory( $job ) . '/maintenance';
	}
}
