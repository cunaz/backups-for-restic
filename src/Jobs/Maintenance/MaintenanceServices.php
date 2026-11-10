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
final class MaintenanceServices {

	/**
	 * Applies the retention modes; one per request, so that it remembers its own switch.
	 *
	 * @var RetentionGuard|null
	 */
	private ?RetentionGuard $guard = null;

	/**
	 * Creates the services.
	 *
	 * @param BackupServices         $common           Shared services.
	 * @param \Closure(string): void $switch_retention Saves the retention mode (plugin or external).
	 */
	public function __construct( public readonly BackupServices $common, private readonly \Closure $switch_retention ) {
	}

	/**
	 * The retention guard.
	 *
	 * @return RetentionGuard Guard.
	 */
	public function guard(): RetentionGuard {
		return $this->guard ??= new RetentionGuard( $this->common->state, $this->common->settings->string( 'retention.mode' ), $this->switch_retention, $this->common->clock, RetentionGuard::repository( $this->common->settings ) );
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
