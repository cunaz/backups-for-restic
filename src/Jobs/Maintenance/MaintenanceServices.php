<?php
/**
 * Dependencies of the maintenance steps.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Backup\PlanFactory;
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
	 * @param string                 $site_tag         Tag that every snapshot of this site carries, such as site:1a2b3c4d5e6f.
	 */
	public function __construct( public readonly BackupServices $common, private readonly \Closure $switch_retention, public readonly string $site_tag ) {
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
	 * Tags a snapshot must carry to fall under this site's retention: the plugin's tag and
	 * the site's. Sites that share a repository and a host name keep their snapshots apart.
	 *
	 * @return list<string> Tags.
	 */
	public function retention_tags(): array {
		return [ PlanFactory::TAG, $this->site_tag ];
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
