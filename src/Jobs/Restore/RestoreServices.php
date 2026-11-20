<?php
/**
 * Dependencies of the restore steps.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The backup services plus where a restore job keeps its files (ADR 0037).
 *
 * The staging directory of a job is restores/<job-id>/ in the private directory. The staged
 * tree lies in its files/ folder and the report of the entries beside it, so that no name
 * from a snapshot can take the place of the report.
 */
final readonly class RestoreServices {

	/** The optional archive beside the staged tree; every restore has a unique job ID. */
	public const string ARCHIVE = 'selection.zip';

	/** The checkpoint that RestoreRun writes into its working directory. */
	public const string CHECKPOINT = 'restore.json';

	/** The report of entries that RestoreRun writes into its working directory. */
	public const string REPORT = 'entries.jsonl';

	/**
	 * Creates the services.
	 *
	 * @param BackupServices                     $common Shared services.
	 * @param (\Closure(): DatabasePreview)|null $preview Opens a database preview on demand.
	 */
	public function __construct( public BackupServices $common, public ?\Closure $preview = null ) {
	}

	/**
	 * Working directory of the restore run of a job: checkpoint, local lock and the report
	 * while it grows.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function work_dir( Job $job ): string {
		return $this->common->jobs->directory( $job ) . '/restore';
	}

	/**
	 * Name of the staging directory of a job, relative to the private directory.
	 *
	 * @param Job $job Job.
	 * @return string Relative name such as restores/20270115T030000Z-abcdef.
	 */
	public function staging_name( Job $job ): string {
		return 'restores/' . $job->id;
	}

	/**
	 * The staging directory of a job.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function staging_dir( Job $job ): string {
		return $this->common->private_dir->subdirectory( 'restores' ) . '/' . $job->id;
	}

	/**
	 * Where the restored tree of a job goes.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function files_dir( Job $job ): string {
		return $this->staging_dir( $job ) . '/files';
	}

	/**
	 * Where the report of a finished restore stays.
	 *
	 * @param Job $job Job.
	 * @return string Path.
	 */
	public function report_file( Job $job ): string {
		return $this->staging_dir( $job ) . '/' . self::REPORT;
	}
}
