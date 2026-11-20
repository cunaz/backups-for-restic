<?php
/**
 * Read-only background jobs that publish paged, authenticated file previews.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\ClosureStep;
use BackupsForRestic\Jobs\EventLog;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Builds read-only reviews and checks their identity and expiry when a caller opens one.
 *
 * @phpstan-import-type Row from ApplyManifest
 */
final readonly class FilePlans {

	/** Maximum content comparison bytes in the initial synchronous preview. */
	public const int INLINE_BYTES = 8388608;

	/**
	 * Opens the background preview service.
	 *
	 * @param JobStore                        $jobs Job documents.
	 * @param Staging                         $staging Private staging.
	 * @param Clock                           $clock Time source.
	 * @param int                             $hours Normal staging lifetime.
	 * @param \Closure(bool,string):ApplyPlan $planner Site policy factory.
	 * @param ApplyJournal                    $journal Original source and undo records.
	 * @param FileSpace                       $space Destination volume.
	 */
	public function __construct( private JobStore $jobs, private Staging $staging, private Clock $clock, private int $hours, private \Closure $planner, private ApplyJournal $journal, private FileSpace $space ) {
	}

	/**
	 * Advances the read-only worker or retires its unfinished data.
	 *
	 * @param string $name Step name.
	 * @return Step Worker.
	 * @throws InvalidArgument If the step is unknown.
	 */
	public function step( string $name ): Step {
		return new ClosureStep(
			fn ( Job $job, EventLog $log, float $deadline ): bool => match ( $name ) {
				'preflight' => $this->init_plan( $job ),
				'planning' => $this->build_plan( $job, $deadline ),
				'recording' => $this->seal_plan( $job ),
				'cleaning_up' => $this->clean_plan( $job, $deadline ),
				default => throw new InvalidArgument( 'Unknown background file preview step.' ),
			}
		);
	}

	/**
	 * Limits synchronous planning to 100 metadata entries and 8 MiB of content.
	 *
	 * @param string $restore Restore ID.
	 * @return bool Whether background work is needed.
	 * @throws InvalidArgument If the report is unavailable.
	 */
	public function large( string $restore ): bool {
		$job = $this->jobs->get( $restore );
		if ( null !== $job && ( Decoded::int( $job->result['bytes'] ?? 0 ) > self::INLINE_BYTES || Decoded::int( $job->result['files'] ?? 0 ) > DiskFilePlan::PAGE ) ) {
			return true;
		}
		$handle = $this->staging->open_download( $restore, $this->hours, 'report', '' );
		if ( null === $handle ) {
			throw new InvalidArgument( 'The staged report is unavailable.' );
		}
		try {
			$count = 0;
			foreach ( ApplyManifest::report( $handle ) as $_entry ) {
				if ( ++$count > DiskFilePlan::PAGE ) {
					return true;
				}
			}
			return false;
		} finally {
			fclose( $handle );
		}
	}


	/**
	 * Reads one page only while the confirmed subject, direction and configuration match.
	 *
	 * @param string $id Subject.
	 * @param string $restore Staging.
	 * @param bool   $undo Direction.
	 * @param bool   $config Configuration.
	 * @param string $plan_job Preview job.
	 * @param int    $offset First row.
	 * @return array{restore:string,digest:string,applicable:bool,required_bytes:int|null,available_bytes:int|null,enough_space:bool|null,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>,plan_job:string,total:int,offset:int,next_offset:int|null} Public page.
	 * @throws JobConflict If this preview expired or does not match the request.
	 */
	public function plan_page( string $id, string $restore, bool $undo, bool $config, string $plan_job, int $offset ): array {
		$job = $this->jobs->get( $plan_job );
		if ( null === $job || JobType::FilePlan !== $job->type || JobState::Succeeded !== $job->state || $job->finished_at + 3600 <= $this->clock->now()[0] || $id !== ( $job->data['subject'] ?? null ) || $undo !== ( $job->data['undo'] ?? null ) || $config !== ( $job->data['include_config'] ?? null ) || $restore !== ( $job->data['restore'] ?? null ) || ! is_string( $job->result['digest'] ?? null ) ) {
			throw new JobConflict( 'This file preview is unavailable, expired or has a different scope.' );
		}
		$disk   = $this->disk( $restore, $plan_job, $undo );
		$digest = $job->result['digest'];
		$header = $disk->open( $digest );
		$total  = $header['entries'];
		if ( $id !== $header['subject'] || $restore !== $header['restore'] || $undo !== $header['undo'] || $config !== $header['config'] || $offset < 0 || $offset > $total ) {
			throw new JobConflict( 'This file preview does not match the requested page.' );
		}
		$entries  = array_map( ApplyManifest::display( ... ), $disk->page( $offset ) );
		$next     = $offset + count( $entries );
		$estimate = $this->space->estimate( $undo ? 0 : $header['required_bytes'] );
		return [
			'restore'     => $restore,
			'digest'      => $digest,
			'applicable'  => 0 === $header['refused'] && $header['changes'] > 0 && false !== $estimate['enough_space'],
			'entries'     => $entries,
			'plan_job'    => $plan_job,
			'total'       => $total,
			'offset'      => $offset,
			'next_offset' => $next < $total ? $next : null,
		] + $estimate;
	}


	/**
	 * Opens plan material inside the original staging. Staging pinning also pins all
	 * authenticated indexes needed during an apply's undo window.
	 *
	 * @param string $restore Restore ID.
	 * @param string $plan_job Plan job ID.
	 * @param bool   $retained Allow pinned staging.
	 * @param bool   $create Create per-plan directory.
	 * @return DiskFilePlan Store.
	 * @throws InvalidArgument If the plan ID is invalid.
	 */
	private function disk( string $restore, string $plan_job, bool $retained, bool $create = false ): DiskFilePlan {
		if ( 1 !== preg_match( JobStore::ID_PATTERN, $plan_job ) ) {
			throw new InvalidArgument( 'This is not a file preview job ID.' );
		}
		$plans = dirname( $this->journal->files( $restore, $retained ) ) . '/plans';
		if ( $create ) {
			ApplyJournal::directory( $plans );
			ApplyJournal::directory( $plans . '/' . $plan_job );
		}
		return new DiskFilePlan( $plans . '/' . $plan_job );
	}


	/**
	 * Initializes an isolated background plan before its first row.
	 *
	 * @param Job $job Preview job.
	 * @return bool Ready.
	 */
	private function init_plan( Job $job ): bool {
		$this->disk( ApplyJournal::field( $job, 'restore' ), $job->id, true, true );
		$job->data['plan_retained'] = true;
		return true;
	}


	/**
	 * Reconstructs persisted plan metadata from the worker document.
	 *
	 * @param Job          $job Preview job.
	 * @param DiskFilePlan $disk Plan store.
	 * @return PlanBuild Builder.
	 */
	private function builder( Job $job, DiskFilePlan $disk ): PlanBuild {
		$restore = ApplyJournal::field( $job, 'restore' );
		$state   = $job->data['plan_cursor'] ?? [];
		$cursor  = [];
		if ( is_array( $state ) ) {
			foreach ( $state as $key => $value ) {
				if ( is_string( $key ) ) {
					$cursor[ $key ] = $value;
				}
			}
		}
		$config = true === ( $job->data['include_config'] ?? false );
		if ( true === ( $job->data['undo'] ?? false ) ) {
			$config = true === ( $this->journal->original( ApplyJournal::field( $job, 'subject' ), true )->data['include_config'] ?? false );
		}
		return new PlanBuild( $disk, ( $this->planner )( $config, $restore ), $this->journal->files( $restore, true ), $cursor, $this->space );
	}


	/**
	 * Advances expensive work only in a bounded background worker.
	 *
	 * @param Job   $job Preview job.
	 * @param float $deadline Deadline.
	 * @return bool Finished planning.
	 * @throws InvalidArgument If the original report disappeared.
	 */
	private function build_plan( Job $job, float $deadline ): bool {
		$restore   = ApplyJournal::field( $job, 'restore' );
		$disk      = $this->disk( $restore, $job->id, true );
		$builder   = $this->builder( $job, $disk );
		$spellings = new DirectorySpellings( $this->journal->verification( $job ), $deadline );
		if ( true === ( $job->data['undo'] ?? false ) ) {
			$original = $this->journal->original( ApplyJournal::field( $job, 'subject' ), true );
			$forward  = $this->approved( $original );
			$done     = $builder->undo( $forward, $this->journal->attempted( $original ), $this->journal->workspace( $original ), $job->id, $deadline, $spellings );
		} else {
			$report = $this->staging->open_download( $restore, $this->hours, 'report', '', true );
			if ( null === $report ) {
				throw new InvalidArgument( 'The original staging report is no longer available.' );
			}
			try {
				$identity = self::report_identity( $report );
				if ( $identity !== ( $job->data['report_identity'] ?? $identity ) ) {
					throw new JobConflict( 'The staged report changed while its preview was being built.' );
				}
				$done = $builder->apply( $report, $this->jobs->device(), $deadline, $spellings );
				if ( self::report_identity( $report ) !== $identity ) {
					throw new JobConflict( 'The staged report changed during this preview tick.' );
				}
				$job->data['report_identity'] = $identity;
			} finally {
				fclose( $report );
			}
		}
		$job->data['plan_cursor'] = $builder->checkpoint();
		$job->progress['done']    = $builder->counts['rows'] ?? 0;
		return $done;
	}


	/**
	 * Identifies the same opened, regular report over all its background ticks.
	 *
	 * @param resource $report Verified report stream.
	 * @return string Stat identity, excluding access time.
	 * @throws StorageFailed If the stream is no longer a regular file.
	 */
	private static function report_identity( $report ): string {
		$stat = fstat( $report );
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink'] ) {
			throw new StorageFailed( 'The private staging report is no longer a regular file.' );
		}
		return implode( ':', [ $stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'], $stat['ctime'] ] );
	}


	/**
	 * Records a completed, immutable background plan.
	 *
	 * @param Job $job Preview job.
	 * @return bool Published.
	 */
	private function seal_plan( Job $job ): bool {
		$restore     = ApplyJournal::field( $job, 'restore' );
		$disk        = $this->disk( $restore, $job->id, true );
		$builder     = $this->builder( $job, $disk );
		$digest      = $disk->seal( ApplyJournal::field( $job, 'subject' ), $restore, true === ( $job->data['undo'] ?? false ), true === ( $job->data['include_config'] ?? false ), $builder->counts, $builder->changes, $builder->refused, $builder->required_bytes );
		$job->result = [
			'digest'  => $digest,
			'entries' => $builder->counts['rows'] ?? 0,
			'changes' => $builder->changes,
			'refused' => $builder->refused,
		];
		return true;
	}


	/**
	 * A failed or cancelled preview retires its unpublished plan for private cleanup.
	 *
	 * @param Job   $job Preview job.
	 * @param float $deadline Deadline.
	 * @return bool Clean.
	 */
	private function clean_plan( Job $job, float $deadline ): bool {
		if ( ! $this->staging->remove_verification( $job, $deadline ) ) {
			return false;
		}
		if ( isset( $job->result['digest'] ) ) {
			return true;
		}
		$removed = $this->staging->remove_plan( ApplyJournal::field( $job, 'restore' ), $job->id, $deadline );
		if ( $removed ) {
			unset( $job->data['plan_retained'] );
		}
		return $removed;
	}


	/**
	 * Accounts for outstanding, confirmed items on an apply already in progress when
	 * the space check was introduced. Private partial copies are credited by FileChange.
	 *
	 * @param Job               $job Current apply.
	 * @param DiskFilePlan|null $disk Its authenticated large plan, if any.
	 * @param list<Row>         $rows Inline mutation manifest.
	 * @param int               $done Published rows.
	 * @param int               $total Number of mutations.
	 * @param float             $deadline Worker tick deadline.
	 * @return bool Whether all remaining rows have been charged.
	 * @throws StorageFailed If a migration checkpoint or confirmed row is missing.
	 */
	public function resume_space( Job $job, ?DiskFilePlan $disk, array $rows, int $done, int $total, float $deadline ): bool {
		$cursor = $job->data['space_cursor'] ?? $done;
		$bytes  = $job->data['space_total'] ?? 0;
		if ( ! is_int( $cursor ) || $cursor < $done || $cursor > $total || ! is_int( $bytes ) || $bytes < 0 ) {
			throw new StorageFailed( 'The remaining file estimate cursor is invalid.' );
		}
		while ( $cursor < $total ) {
			$row = null === $disk ? ( $rows[ $cursor ] ?? null ) : $disk->mutation( $cursor )['row'];
			if ( null === $row ) {
				throw new StorageFailed( 'The remaining file estimate has an incomplete manifest.' );
			}
			$bytes = FileSpace::add( $bytes, $this->space->row( $row ) );
			++$cursor;
			if ( $cursor < $total && microtime( true ) >= $deadline ) {
				$job->data['space_cursor'] = $cursor;
				$job->data['space_total']  = $bytes;
				return false;
			}
		}
		$job->data['remaining_bytes'] = $bytes;
		unset( $job->data['space_cursor'], $job->data['space_total'] );
		return true;
	}


	/**
	 * Opens the immutable plan attached to an active mutation or its original apply.
	 *
	 * @param Job $job Apply or undo.
	 * @return DiskFilePlan Verified store.
	 */
	public function approved( Job $job ): DiskFilePlan {
		$disk = $this->disk( ApplyJournal::field( $job, 'restore' ), ApplyJournal::field( $job, 'plan_job' ), true );
		$disk->open( ApplyJournal::field( $job, 'digest' ) );
		return $disk;
	}
}
