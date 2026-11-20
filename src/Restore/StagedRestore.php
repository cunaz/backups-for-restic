<?php
/**
 * One finished restore that still has its files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * A staging directory as the list shows it. The counts are what the job recorded when it
 * ended, not a walk of the tree: a request must not depend on how much was restored. They
 * are unknown once the job document was pruned with the old jobs.
 */
final readonly class StagedRestore {

	/**
	 * Describes a staging directory.
	 *
	 * @param string      $job      ID of the restore job, which is the name of the directory.
	 * @param string|null $snapshot Full ID of the snapshot; null if unknown.
	 * @param int|null    $finished Unix time the job ended; null if unknown.
	 * @param int         $expires  Unix time from which the heartbeat removes the directory.
	 * @param int|null    $files    Number of staged files; null if unknown.
	 * @param int|null    $bytes    Bytes of the staged files; null if unknown.
	 * @param string      $staging  Name of the directory, relative to the private directory.
	 * @param string|null $report   Name of the report of the entries, relative to the private directory; null if it is missing.
	 * @param bool        $archive  Whether the ZIP is ready.
	 * @param string|null $file     Sole selected path when it names a staged regular file.
	 * @param bool        $database_report Whether the database rehearsal published its report.
	 * @param bool        $fresh_install Whether the staged selection belongs to a fresh-site import.
	 */
	public function __construct(
		public string $job,
		public ?string $snapshot,
		public ?int $finished,
		public int $expires,
		public ?int $files,
		public ?int $bytes,
		public string $staging,
		public ?string $report,
		public bool $archive = false,
		public ?string $file = null,
		public bool $database_report = false,
		public bool $fresh_install = false
	) {
	}

	/**
	 * For the REST API.
	 *
	 * @return array{job: string, snapshot: string|null, finished: int|null, expires: int, files: int|null, bytes: int|null, staging: string, report: string|null, archive: bool, file: string|null, database_report: bool, fresh_install: bool} Fields.
	 */
	public function to_array(): array {
		return [
			'job'             => $this->job,
			'snapshot'        => $this->snapshot,
			'finished'        => $this->finished,
			'expires'         => $this->expires,
			'files'           => $this->files,
			'bytes'           => $this->bytes,
			'staging'         => $this->staging,
			'report'          => $this->report,
			'archive'         => $this->archive,
			'file'            => $this->file,
			'database_report' => $this->database_report,
			'fresh_install'   => $this->fresh_install,
		];
	}
}
