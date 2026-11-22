<?php
/**
 * Confirmed live file changes and their undo window.
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
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Step;
use BackupsForRestic\Jobs\StepProvider;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\FileChange;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\FileVerification;
use BackupsForRestic\Restore\FileWorkPending;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The runner repeats the approved preflight before its first write. Each ordinal gets an
 * independent durable journal. Undo includes the last attempted ordinal even after a
 * killed request and works backwards so children leave before their directory.
 *
 * @phpstan-import-type Row from ApplyManifest
 * @phpstan-type Preview array{restore:string,digest:string,applicable:bool,required_bytes:int|null,available_bytes:int|null,enough_space:bool|null,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>}
 */
final readonly class FileChanges implements StepProvider {

	/**
	 * Read-only background plans.
	 *
	 * @var FilePlans
	 */
	private FilePlans $plans;

	/**
	 * Disk estimates for the filesystem that holds all writable payloads.
	 *
	 * @var FileSpace
	 */
	private FileSpace $space;

	/**
	 * Recovery records shared by the plan and mutation workers.
	 *
	 * @var ApplyJournal
	 */
	private ApplyJournal $journal;

	/**
	 * Creates the service.
	 *
	 * @param JobStore                                     $jobs Jobs.
	 * @param Staging                                      $staging Restore retention.
	 * @param Clock                                        $clock Time.
	 * @param int                                          $hours Staging lifetime.
	 * @param \Closure(bool,string,string|null=):ApplyPlan $planner Live site policy, with the uploads root a job was confirmed with or the current one.
	 * @param FileMaintenance                              $maintenance WordPress maintenance marker.
	 * @param \Closure(int|null):bool                      $allowed Host-wide permission to write live files, and the capabilities of the confirming user unless the job came from the command line.
	 * @param FileSpace|null                               $space Host filesystem capacity.
	 * @param (\Closure():string)|null                     $uploads Current uploads root, recorded with a confirmed job: its later ticks boot without the filters of the theme and ordinary plugins.
	 */
	public function __construct(
		private JobStore $jobs,
		private Staging $staging,
		private Clock $clock,
		private int $hours,
		private \Closure $planner,
		private FileMaintenance $maintenance,
		private \Closure $allowed,
		?FileSpace $space = null,
		private ?\Closure $uploads = null
	) {
		$this->space   = $space ?? new FileSpace( $jobs->storage_directory() );
		$this->journal = new ApplyJournal( $jobs, $staging, $clock, $hours );
		$this->plans   = new FilePlans( $jobs, $staging, $clock, $hours, $planner, $this->journal, $this->space );
	}

	/**
	 * Describes the candidate apply and binds it to current source and destination state.
	 *
	 * @param string $restore Restore job ID.
	 * @param bool   $include_config Whether to include wp-config.php.
	 * @return Preview Public preview.
	 */
	public function preview( string $restore, bool $include_config = false ): array {
		$rows = $this->rows( $restore, $include_config, false );
		return $this->summary( $restore, $include_config, $rows );
	}

	/**
	 * Returns a small inline preview, queues a large one, or reads a bounded page of a
	 * completed plan. Repeated initial requests reuse a matching active preview.
	 *
	 * @param string      $id Restore or original apply job.
	 * @param bool        $undo Whether to review undo.
	 * @param bool        $config Include configuration.
	 * @param string|null $plan_job Previously completed background job.
	 * @param int         $offset First presentation ordinal.
	 * @return Preview|array{restore:string,digest:string,applicable:bool,required_bytes:int|null,available_bytes:int|null,enough_space:bool|null,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>,plan_job:string,total:int,offset:int,next_offset:int|null}|Job Preview or queued job.
	 * @throws InvalidArgument If a requested page or option is invalid.
	 */
	public function request_preview( string $id, bool $undo, bool $config, ?string $plan_job = null, int $offset = 0 ): Job|array {
		if ( $offset < 0 || ( null === $plan_job && 0 !== $offset ) || ( $undo && $config ) ) {
			throw new InvalidArgument( 'The file preview request has an invalid scope or page.' );
		}
		$original = $undo ? $this->journal->original( $id ) : null;
		$restore  = null === $original ? $id : ApplyJournal::field( $original, 'restore' );
		if ( null !== $plan_job ) {
			return $this->plans->plan_page( $id, $restore, $undo, $config, $plan_job, $offset );
		}
		if ( null !== $original && ! is_string( $original->data['plan_job'] ?? null ) ) {
			return $this->undo_summary( $original );
		}
		if ( ! $undo && ! $this->plans->large( $restore ) ) {
			try {
				return $this->summary( $restore, $config, $this->rows( $restore, $config, false, FilePlans::INLINE_BYTES ) );
			} catch ( InvalidArgument $exception ) {
				if ( 'file_plan_large' !== ( $exception->context()['code'] ?? null ) ) {
					throw $exception;
				}
			}
		}
		return $this->staging->guard(
			function () use ( $id, $restore, $undo, $config ): Job {
				$active = $this->jobs->active();
				if ( null !== $active && JobType::FilePlan === $active->type && $id === ( $active->data['subject'] ?? null ) && $undo === ( $active->data['undo'] ?? null ) && $config === ( $active->data['include_config'] ?? null ) ) {
					return $active;
				}
				$this->journal->files( $restore, $undo );
				return $this->jobs->create(
					JobType::FilePlan,
					'manual',
					$this->clock->now()[0],
					[
						'subject'        => $id,
						'restore'        => $restore,
						'undo'           => $undo,
						'include_config' => $config,
					]
				);
			}
		);
	}

	/**
	 * Queues an apply only while the reviewed plan and staged files still match.
	 *
	 * @param string      $restore Restore job ID.
	 * @param bool        $include_config Include wp-config.php.
	 * @param string      $digest Approved plan.
	 * @param string      $control_hash SHA-256 of the scoped control token, empty on the command line.
	 * @param string|null $plan_job Completed disk-backed preview ID.
	 * @param int         $user WordPress user who confirmed the job in the browser.
	 * @return Job Queued job.
	 */
	public function start_apply( string $restore, bool $include_config, string $digest, string $control_hash = '', ?string $plan_job = null, int $user = 0 ): Job {
		return $this->staging->guard(
			function () use ( $restore, $include_config, $digest, $control_hash, $plan_job, $user ): Job {
				$this->require_writes( '' === $control_hash ? null : $user );
				self::confirmed( null === $plan_job ? $this->preview( $restore, $include_config ) : $this->plans->plan_page( $restore, $restore, false, $include_config, $plan_job, 0 ), $digest );
				return $this->jobs->create(
					JobType::Apply,
					'manual',
					$this->clock->now()[0],
					[
						'restore'           => $restore,
						'include_config'    => $include_config,
						'digest'            => $digest,
						'maintenance_grant' => $control_hash,
						'plan_job'          => $plan_job,
						'user'              => $user,
						'uploads_dir'       => null === $this->uploads ? null : ( $this->uploads )(),
					]
				);
			}
		);
	}

	/**
	 * Lists undoable apply jobs, including a failed or cancelled partial apply.
	 *
	 * @return list<array{job:string,restore:string,expires:int}> Undo opportunities.
	 */
	public function undoable(): array {
		$list = [];
		foreach ( $this->jobs->file_changes() as $job ) {
			if ( $this->journal->eligible( $job ) ) {
				$list[] = [
					'job'     => $job->id,
					'restore' => ApplyJournal::field( $job, 'restore' ),
					'expires' => $job->finished_at + 86400,
				];
			}
		}
		return $list;
	}

	/**
	 * Previews only changes attempted by this apply, in reverse order.
	 *
	 * @param string $apply_job ID of the apply job.
	 * @return Preview Undo preview.
	 */
	public function preview_undo( string $apply_job ): array {
		return $this->undo_summary( $this->journal->original( $apply_job ) );
	}

	/**
	 * Queues an undo after the administrator confirmed the current preview.
	 *
	 * @param string      $apply_job Apply job.
	 * @param string      $digest Confirmed undo preview.
	 * @param string      $control_hash SHA-256 of the scoped control token, empty on the command line.
	 * @param string|null $plan_job Completed disk-backed preview ID.
	 * @param int         $user WordPress user who confirmed the undo in the browser.
	 * @return Job Queued undo job.
	 */
	public function start_undo( string $apply_job, string $digest, string $control_hash = '', ?string $plan_job = null, int $user = 0 ): Job {
		return $this->staging->guard(
			function () use ( $apply_job, $digest, $control_hash, $plan_job, $user ): Job {
				$this->require_writes( '' === $control_hash ? null : $user );
				$original = $this->journal->original( $apply_job );
				$restore  = ApplyJournal::field( $original, 'restore' );
				$preview  = null === $plan_job ? $this->preview_undo( $apply_job ) : $this->plans->plan_page( $apply_job, $restore, true, false, $plan_job, 0 );
				self::confirmed( $preview, $digest );
				return $this->jobs->create(
					JobType::Undo,
					'manual',
					$this->clock->now()[0],
					[
						'apply'             => $apply_job,
						'restore'           => $preview['restore'],
						'digest'            => $digest,
						'maintenance_grant' => $control_hash,
						'plan_job'          => $plan_job,
						'user'              => $user,
						'uploads_dir'       => null === $this->uploads ? null : ( $this->uploads )(),
					]
				);
			}
		);
	}

	/**
	 * Dispatches one bounded runner step.
	 *
	 * @param JobType $type Job type.
	 * @param string  $name Step.
	 * @return Step Operation.
	 * @throws InvalidArgument If the runner requests an unknown step.
	 */
	public function step( JobType $type, string $name ): Step {
		if ( JobType::FilePlan === $type ) {
			return $this->plans->step( $name );
		}
		return new ClosureStep(
			fn ( Job $job, EventLog $log, float $deadline ): bool => match ( $name ) {
				'preflight' => $this->prepare( $job, $deadline ),
				'applying', 'undoing' => $this->mutate( $job, $deadline ),
				'recording' => $this->record( $job ),
				'cleaning_up' => $this->cleanup( $job, $deadline ),
				default => throw new InvalidArgument( 'Unknown live file change step.' ),
			}
		);
	}

	/**
	 * Revalidates the entire plan before the first mutation, then persists its manifest.
	 *
	 * @param Job   $job Current job.
	 * @param float $deadline Worker deadline.
	 * @return bool False to resume the durably recorded mutation step on the next tick.
	 */
	private function prepare( Job $job, float $deadline ): bool {
		if ( is_string( $job->data['plan_job'] ?? null ) ) {
			return $this->verify_plan( $job, $deadline );
		}
		$digest = ApplyJournal::field( $job, 'digest' );
		$this->require_writes( self::operator( $job ) );
		if ( JobType::Undo === $job->type ) {
			$original = $this->journal->original( ApplyJournal::field( $job, 'apply' ), true );
			self::confirmed( $this->undo_summary( $original, self::uploads( $job ) ), $digest );
			$job->progress['total'] = $this->journal->attempted( $original );
			$this->enter_maintenance( $job );
			$job->step = 'undoing';
			$this->jobs->save( $job );
			return false;
		}
		$restore = ApplyJournal::field( $job, 'restore' );
		$config  = true === ( $job->data['include_config'] ?? null );
		$rows    = $this->rows( $restore, $config, true, ApplyManifest::MAX_BYTES, self::uploads( $job ) );
		$preview = $this->summary( $restore, $config, $rows );
		self::confirmed( $preview, $digest );
		$job->data['remaining_bytes'] = $preview['required_bytes'];
		// A kill can leave this file before the runner persisted its next step. Rebuilding
		// in preflight is safe: a mutation happens only after the step change is durable.
		$dir = $this->journal->workspace( $job );
		ApplyJournal::directory( $dir );
		$mutations = array_values( array_filter( $rows, ApplyManifest::mutates( ... ) ) );
		$json      = self::json( $mutations );
		Files::write_atomic( $dir . '/manifest.json', $json );
		$job->data['manifest_hash'] = hash( 'sha256', $json );
		$job->progress['total']     = count( $mutations );
		$this->enter_maintenance( $job );
		$job->step = 'applying';
		// The runner may continue in this request. Persist the reviewed manifest hash and
		// the fact that preflight is over before any file can be changed.
		$this->jobs->save( $job );
		// Return false: the step name already points at the next tick.
		return false;
	}

	/**
	 * Rechecks an arbitrarily large reviewed plan over multiple ticks before the site
	 * enters maintenance. Each publication also checks its own current fingerprints.
	 *
	 * @param Job   $job Apply or undo.
	 * @param float $deadline Worker deadline.
	 * @return bool False until the next job step has been durably recorded.
	 * @throws JobConflict If the sealed plan has changed since review.
	 */
	private function verify_plan( Job $job, float $deadline ): bool {
		$this->require_writes( self::operator( $job ) );
		$disk     = $this->plans->approved( $job );
		$head     = $disk->header();
		$undo     = JobType::Undo === $job->type;
		$original = $undo ? $this->journal->original( ApplyJournal::field( $job, 'apply' ), true ) : $job;
		$restore  = ApplyJournal::field( $original, 'restore' );
		$config   = true === ( $original->data['include_config'] ?? false );
		if ( $head['restore'] !== $restore || $head['subject'] !== ( $undo ? $original->id : $restore ) || $head['undo'] !== $undo || $head['config'] !== ( ! $undo && $config ) || $head['changes'] < 1 || $head['refused'] > 0 ) {
			throw new JobConflict( 'The sealed file preview does not match the approved operation.' );
		}
		$plan      = ( $this->planner )( $config, $restore, self::uploads( $job ) );
		$files     = $this->journal->files( $restore, true );
		$forward   = $undo ? $this->plans->approved( $original ) : null;
		$attempted = $undo ? $this->journal->attempted( $original ) : 0;
		$cursor    = $job->progress['verified'] ?? 0;
		if ( ! is_int( $cursor ) || $cursor < 0 || $cursor > $head['entries'] ) {
			throw new JobConflict( 'The sealed file preview verification cursor is invalid.' );
		}
		// A pre-upgrade job may have verified rows without accounting for their payloads.
		if ( ! $undo && ! isset( $job->data['verified_bytes'] ) ) {
			$cursor                      = 0;
			$job->progress['verified']   = 0;
			$job->data['verified_bytes'] = 0;
		}
		$required = $job->data['verified_bytes'] ?? 0;
		if ( ! is_int( $required ) || $required < 0 ) {
			throw new StorageFailed( 'The verified file-space total is invalid.' );
		}
		$verify          = $this->journal->verification( $job );
		$spellings       = new DirectorySpellings( $verify, $deadline );
		$proof           = new FileVerification( $verify, $job->id );
		$proof->deadline = $deadline;
		while ( $cursor < $head['entries'] ) {
			$row    = $disk->row( $cursor );
			$source = Bytes::base64url_decode( $row['path'] );
			if ( null !== $forward ) {
				$item   = $forward->mutation( $attempted - $cursor - 1 );
				$target = self::target( $row );
				$change = new FileChange( $plan, $files, $this->journal->workspace( $original ) . '/item-' . $item['index'], $source, $job->id . '-preflight' );
				try {
					$action = $change->undo_action( $target, $item['row']['action'], $deadline, $spellings );
				} catch ( FileWorkPending ) {
					return false;
				}
				if ( $plan->destination( $source ) !== $target || $item['row']['path'] !== $row['path'] || $item['row']['destination'] !== $row['destination'] || $action !== $row['action'] ) {
					throw new JobConflict( 'The approved undo changed before work began.' );
				}
				if ( 'remove' === $action && 'mkdir' === $item['row']['action'] ) {
					$position = [];
					$stored   = $job->data['undo_directory'] ?? [];
					if ( is_array( $stored ) ) {
						foreach ( $stored as $key => $value ) {
							if ( is_string( $key ) ) {
								$position[ $key ] = $value;
							}
						}
					}
					$covered = PlanBuild::children_removable( $disk, $target, $deadline, $position, true );
					if ( null === $covered ) {
						$job->data['undo_directory'] = $position;
						return false;
					}
					if ( ! $covered ) {
						throw new JobConflict( 'A directory contains files outside the approved undo.' );
					}
					unset( $job->data['undo_directory'] );
				}
			} elseif ( ApplyManifest::mutates( $row ) || 'identical_contents' === $row['reason'] ) {
				$target = self::target( $row );
				if ( $plan->destination( $source ) !== $target ) {
					throw new JobConflict( 'The confirmed destination mapping changed before work began.' );
				}
				if ( 'identical_contents' === $row['reason'] ) {
					$row['action'] = 'replace';
				}
				try {
					$this->recheck( $plan, $files, $source, $target, $row, $spellings );
					if ( ( null !== $row['source'] && ! $proof->matches( $files . $source, $row['source'], 1 ) ) || ( null !== $row['original'] && ! $proof->matches( $target, $row['original'], 1 ) ) ) {
						throw new JobConflict( 'A confirmed file changed before work began.' );
					}
				} catch ( FileWorkPending ) {
					return false;
				}
			}
			if ( ! $undo && 'identical_contents' !== $row['reason'] ) {
				$required                    = FileSpace::add( $required, $this->space->row( $row ) );
				$job->data['verified_bytes'] = $required;
			}
			$job->progress['verified'] = ++$cursor;
			if ( microtime( true ) >= $deadline || $this->jobs->cancel_requested( $job ) ) {
				return false;
			}
		}
		if ( ! $undo ) {
			FileSpace::require_space( $this->space->estimate( $required ) );
			$job->data['remaining_bytes'] = $required;
			ApplyJournal::directory( $this->journal->workspace( $job ) );
		}
		$job->progress['total'] = $undo ? $attempted : $head['changes'];
		$this->enter_maintenance( $job );
		$job->step = $undo ? 'undoing' : 'applying';
		$this->jobs->save( $job );
		return false;
	}

	/**
	 * Makes progress on one journal. A restart after publication repeats the same ordinal.
	 *
	 * @param Job   $job Active job.
	 * @param float $deadline Tick deadline.
	 * @return bool Whether all rows have been processed.
	 * @throws StorageFailed If a journal or its cursor is incomplete.
	 * @throws JobConflict If a directory changed after its creation.
	 */
	private function mutate( Job $job, float $deadline ): bool {
		$this->require_writes( self::operator( $job ) );
		$undo     = JobType::Undo === $job->type;
		$original = $undo ? $this->journal->original( ApplyJournal::field( $job, 'apply' ), true ) : $job;
		$disk     = is_string( $original->data['plan_job'] ?? null ) ? $this->plans->approved( $original ) : null;
		$rows     = null === $disk ? $this->load( $original ) : [];
		$total    = $undo ? $this->journal->attempted( $original ) : ( null === $disk ? count( $rows ) : $disk->header()['changes'] );
		$done     = $job->progress['done'] ?? 0;
		if ( ! is_int( $done ) || $done < 0 || $done > $total ) {
			throw new StorageFailed( 'The file change cursor is invalid.' );
		}
		if ( $done === $total ) {
			return true;
		}
		$files = $this->journal->files( ApplyJournal::field( $original, 'restore' ), true );
		$plan  = ( $this->planner )( true === ( $original->data['include_config'] ?? null ), ApplyJournal::field( $original, 'restore' ), self::uploads( $job ) );
		$made  = [];
		foreach ( $rows as $ordinal => $entry ) {
			if ( 'mkdir' === $entry['action'] ) {
				$made[ self::target( $entry ) ] = $ordinal;
			}
		}
		$this->enter_maintenance( $job );
		if ( ! $undo && ! isset( $job->data['remaining_bytes'] ) && ! $this->plans->resume_space( $job, $disk, $rows, $done, $total, $deadline ) ) {
			return false;
		}
		do {
			$i      = $undo ? $total - 1 - $done : $done;
			$record = $disk?->mutation( $i );
			$index  = $record['index'] ?? $i;
			$row    = $record['row'] ?? $rows[ $i ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The file change manifest is incomplete.' );
			}
			$source = Bytes::base64url_decode( $row['path'] );
			$path   = self::target( $row );
			if ( $plan->destination( $source ) !== $path ) {
				throw new JobConflict( 'The destination mapping changed after confirmation.' );
			}
			$dir    = $this->journal->workspace( $original );
			$item   = $dir . '/item-' . $index;
			$change = new FileChange( $plan, $files, $item, $source, $job->id );
			if ( $undo ) {
				$complete = $change->undo( $path, $row['action'], $deadline );
			} else {
				$base = Bytes::base64url_decode( explode( ':', $row['parent'], 2 )[0] );
				for ( $parent = dirname( $path ); $base !== $parent; $parent = dirname( $parent ) ) {
					$owner_index = null === $disk ? ( $made[ $parent ] ?? null ) : $disk->lookup( 'mkdir', $parent );
					if ( null === $owner_index || '/' === $parent ) {
						throw new JobConflict( 'An unreviewed directory appeared above the destination.' );
					}
					$owner_row = null === $disk ? $rows[ $owner_index ] : $disk->row( $owner_index );
					$owner     = new FileChange( $plan, $files, $dir . '/item-' . $owner_index, Bytes::base64url_decode( $owner_row['path'] ), $job->id );
					if ( 'mkdir' !== $owner_row['action'] || self::target( $owner_row ) !== $parent || 'remove' !== $owner->undo_action( $parent, 'mkdir' ) ) {
						throw new JobConflict( 'A parent directory created by this apply changed.' );
					}
				}
				if ( ! $change->started( $path, $row['action'] ) ) {
					$this->recheck( $plan, $files, $source, $path, $row );
				}
				$payload   = $this->space->row( $row );
				$remaining = $job->data['remaining_bytes'] ?? null;
				if ( ! is_int( $remaining ) || $remaining < $payload ) {
					throw new StorageFailed( 'The remaining file-space total is inconsistent.' );
				}
				$later = $remaining - $payload;
				$need  = $change->remaining( $path, $row['action'], $payload, $this->space );
				FileSpace::require_space( $this->space->estimate( FileSpace::add( $later, $need ) ) );
				if ( $this->journal->attempted( $original ) <= $i ) {
					ApplyJournal::directory( $item );
					Files::write_atomic( $dir . '/attempted', (string) ( $i + 1 ) );
				}
				$complete = $change->apply( $path, $row['action'], $row['source'], $row['original'], $deadline, $row['mode'] );
				if ( $complete ) {
					$job->data['remaining_bytes'] = $later;
				}
			}
			if ( ! $complete ) {
				return false;
			}
			$job->progress['done'] = ++$done;
			$this->jobs->save( $job );
		} while ( $done < $total && microtime( true ) < $deadline && ! $this->jobs->cancel_requested( $job ) );
		return $done === $total;
	}

	/**
	 * Checks the live parent's original identity even after this job created descendants.
	 *
	 * @param ApplyPlan               $plan Current policy.
	 * @param string                  $files Staging.
	 * @param string                  $source Snapshot path.
	 * @param string                  $path Raw destination.
	 * @param Row                     $row Confirmed entry.
	 * @param DirectorySpellings|null $review Optional private index for a read-only worker.
	 * @throws JobConflict If an entry changed since confirmation.
	 */
	private function recheck( ApplyPlan $plan, string $files, string $source, string $path, array $row, ?DirectorySpellings $review = null ): void {
		$parent = Bytes::base64url_decode( explode( ':', $row['parent'], 2 )[0] );
		if ( $plan->inspect( $files, $source, $review )['action'] !== $row['action'] || ApplyManifest::parent_identity( $parent . '/child' ) !== $row['parent'] ) {
			throw new JobConflict( 'A destination or staged file changed after confirmation.' );
		}
		$actual = ApplyManifest::identity( $files . $source );
		if ( 'replace' === $row['action'] ) {
			$actual .= '/' . ApplyManifest::identity( $path );
		}
		if ( $actual !== $row['identity'] ) {
			throw new JobConflict( 'A destination or staged file changed after confirmation.' );
		}
	}

	/**
	 * Records completion. Only a finished undo invalidates the original recovery materials.
	 *
	 * @param Job $job Current job.
	 * @return bool True.
	 * @throws StorageFailed If the original apply disappeared.
	 */
	private function record( Job $job ): bool {
		if ( JobType::Undo === $job->type ) {
			$apply = $this->jobs->get( ApplyJournal::field( $job, 'apply' ) );
			if ( null === $apply ) {
				throw new StorageFailed( 'The original apply is missing.' );
			}
			Files::write_atomic( $this->journal->workspace( $apply ) . '/reverted', '1' );
		}
		$job->result = [
			'restore'       => ApplyJournal::field( $job, 'restore' ),
			'files_changed' => $job->progress['done'] ?? 0,
		];
		return true;
	}

	/**
	 * A partial apply keeps all its journals. An interrupted maintenance marker is cleared.
	 *
	 * @param Job   $job Current job.
	 * @param float $deadline Worker cleanup deadline.
	 * @return bool True.
	 */
	private function cleanup( Job $job, float $deadline ): bool {
		$this->maintenance->release( $job->id, $this->jobs->directory( $job ) );
		if ( [] === $job->result && JobType::Apply === $job->type ) {
			$job->result = [
				'entries_completed' => $job->progress['done'] ?? 0,
				'entries_attempted' => $this->journal->attempted( $job ),
			];
		}
		return $this->staging->remove_verification( $job, $deadline );
	}

	/**
	 * Publishes or refreshes this job's maintenance lease.
	 *
	 * @param Job $job Current job.
	 */
	private function enter_maintenance( Job $job ): void {
		$grant = $job->data['maintenance_grant'] ?? '';
		$this->maintenance->enter( $job->id, $this->jobs->directory( $job ), is_string( $grant ) ? $grant : '' );
	}

	/**
	 * Checks the hosting policy and the confirming user's capabilities on every tick, even
	 * if they changed after the job was confirmed.
	 *
	 * @param int|null $user Confirming WordPress user, null for the command line.
	 * @throws JobConflict If WordPress or the user's capabilities disallow file modifications.
	 */
	private function require_writes( ?int $user ): void {
		if ( ! ( $this->allowed )( $user ) ) {
			JobConflict::raise( 'The site or the confirming administrator no longer permits live file modifications.', [ 'code' => 'file_writes_refused' ] );
		}
	}

	/**
	 * Names the WordPress user whose capabilities the job keeps needing. Only a browser
	 * confirmation creates a control grant; the command line has neither grant nor user.
	 *
	 * @param Job $job Apply or undo.
	 * @return int|null Confirming user, zero if a browser job lacks one, or null for the command line.
	 */
	private static function operator( Job $job ): ?int {
		if ( '' === ( $job->data['maintenance_grant'] ?? '' ) ) {
			return null;
		}
		$user = $job->data['user'] ?? null;
		return is_int( $user ) ? $user : 0;
	}

	/**
	 * The uploads root the job was confirmed with. Its later ticks boot without the theme
	 * and ordinary plugins, so an upload_dir filter there no longer applies.
	 *
	 * @param Job $job Apply or undo.
	 * @return string|null Root, or null where the job recorded none.
	 * @throws StorageFailed If the recorded root is unreadable.
	 */
	private static function uploads( Job $job ): ?string {
		$uploads = $job->data['uploads_dir'] ?? null;
		if ( null !== $uploads && ( ! is_string( $uploads ) || '' === $uploads ) ) {
			throw new StorageFailed( 'The confirmed uploads directory of the job is unreadable.' );
		}
		return $uploads;
	}

	/**
	 * Reads the staged report under the current eligibility decision.
	 *
	 * @param string      $restore Restore job.
	 * @param bool        $config Include configuration.
	 * @param bool        $retained Allow pinned staging for an active job.
	 * @param int         $limit Synchronous byte budget.
	 * @param string|null $uploads Uploads root of a confirmed job, null for the current one.
	 * @return list<Row> Confirmable entries.
	 * @throws InvalidArgument If the completed report is unavailable.
	 */
	private function rows( string $restore, bool $config, bool $retained, int $limit = ApplyManifest::MAX_BYTES, ?string $uploads = null ): array {
		$files  = $this->journal->files( $restore, $retained );
		$report = $this->staging->open_download( $restore, $this->hours, 'report', '', $retained );
		if ( null === $report ) {
			throw new InvalidArgument( 'The complete staging report is unavailable.' );
		}
		try {
			return ApplyManifest::build( ( $this->planner )( $config, $restore, $uploads ), $files, $report, $this->jobs->device(), $limit, FilePlans::INLINE_BYTES === $limit );
		} finally {
			fclose( $report );
		}
	}

	/**
	 * Computes the displayed operations and the identifier that authenticates the entire plan.
	 *
	 * @param string    $restore Restore job.
	 * @param bool      $config Include configuration.
	 * @param list<Row> $rows Manifest.
	 * @return Preview Preview.
	 */
	private function summary( string $restore, bool $config, array $rows ): array {
		$entries  = [];
		$blocked  = false;
		$changes  = false;
		$required = 0;
		foreach ( $rows as $row ) {
			$required  = FileSpace::add( $required, $this->space->row( $row ) );
			$entries[] = ApplyManifest::display( $row );
			$blocked   = $blocked || 'refuse' === $row['action'];
			$changes   = $changes || in_array( $row['action'], [ 'create', 'replace', 'mkdir', 'restore', 'remove' ], true );
		}
		$estimate = $this->space->estimate( $required );
		return [
			'entries'    => $entries,
			'restore'    => $restore,
			'digest'     => hash( 'sha256', self::json( [ $restore, $config, $rows ] ) ),
			'applicable' => ! $blocked && $changes && false !== $estimate['enough_space'],
		] + $estimate;
	}

	/**
	 * Rechecks the original journal and any changes to live files before offering undo.
	 *
	 * @param Job         $apply Original apply job.
	 * @param string|null $uploads Uploads root of a confirmed undo, null for the current one.
	 * @return Preview Undo preview.
	 * @throws StorageFailed If the undo cursor exceeds the confirmed manifest.
	 */
	private function undo_summary( Job $apply, ?string $uploads = null ): array {
		if ( is_string( $apply->data['plan_job'] ?? null ) ) {
			throw new JobConflict( 'This apply requires a completed background undo preview.' );
		}
		$restore  = ApplyJournal::field( $apply, 'restore' );
		$files    = $this->journal->files( $restore, true );
		$config   = true === ( $apply->data['include_config'] ?? null );
		$plan     = ( $this->planner )( $config, $restore, $uploads );
		$rows     = $this->load( $apply );
		$undo     = [];
		$removals = [];
		for ( $i = $this->journal->attempted( $apply ) - 1; $i >= 0; --$i ) {
			$row = $rows[ $i ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The undo journal exceeds its manifest.' );
			}
			$source = Bytes::base64url_decode( $row['path'] );
			$path   = self::target( $row );
			$change = new FileChange( $plan, $files, $this->journal->workspace( $apply ) . '/item-' . $i, $source );
			$action = $plan->destination( $source ) === $path ? $change->undo_action( $path, $row['action'] ) : 'refuse';
			if ( 'remove' === $action ) {
				if ( 'mkdir' === $row['action'] && ! self::children_removed( $path, $removals ) ) {
					$action = 'refuse';
				} else {
					$removals[ $path ] = true;
				}
			}
			$row['action'] = $action;
			$row['reason'] = 'refuse' === $row['action'] ? 'changed_since_apply' : 'undo_journal';
			$undo[]        = $row;
		}
		$result               = $this->summary( $restore, $config, $undo );
		$result['applicable'] = [] !== $undo && ! in_array( 'refuse', array_column( $undo, 'action' ), true );
		$result['digest']     = hash( 'sha256', $apply->id . $result['digest'] );
		return $result;
	}

	/**
	 * A directory can be removed only if all current children are already scheduled for
	 * removal by an earlier step in the reversed plan.
	 *
	 * @param string             $path Live directory created by the apply.
	 * @param array<string,true> $removals Confirmed child removals.
	 * @return bool Whether this directory will be empty.
	 */
	private static function children_removed( string $path, array $removals ): bool {
		$handle = opendir( $path );
		if ( false === $handle ) {
			return false;
		}
		try {
			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
				if ( '.' !== $entry && '..' !== $entry && ! isset( $removals[ $path . '/' . $entry ] ) ) {
					return false;
				}
			}
			return true;
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Reads the immutable, bounded manifest generated by prepare(). Its hash is separate
	 * in the job document; any edited or truncated file fails before it names an operation.
	 *
	 * @param Job $apply Original apply.
	 * @return list<Row> Confirmed mutation rows.
	 * @throws StorageFailed If the persisted manifest cannot be verified.
	 */
	private function load( Job $apply ): array {
		$file = $this->journal->workspace( $apply ) . '/manifest.json';
		$text = is_file( $file ) && ! is_link( $file ) ? file_get_contents( $file, false, null, 0, 16777217 ) : false;
		if ( false === $text || strlen( $text ) > 16777216 || ! hash_equals( ApplyJournal::field( $apply, 'manifest_hash' ), hash( 'sha256', $text ) ) ) {
			throw new StorageFailed( 'The confirmed file manifest cannot be verified.' );
		}
		$rows = json_decode( $text, true );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > ApplyManifest::MAX_ENTRIES ) {
			throw new StorageFailed( 'The confirmed file manifest has an invalid shape.' );
		}
		$result = [];
		foreach ( $rows as $row ) {
			$verified = ApplyManifest::checked( $row );
			if ( ! ApplyManifest::mutates( $verified ) || null === $verified['mode'] || null === $verified['destination'] ) {
				throw new StorageFailed( 'A confirmed file mutation has incomplete metadata.' );
			}
			$result[] = $verified;
		}
		return $result;
	}

	/**
	 * Decodes the verified live destination of a mutation row.
	 *
	 * @param Row $row Manifest row.
	 * @return string Absolute destination path.
	 * @throws StorageFailed If the confirmed mutation has no destination.
	 */
	private static function target( array $row ): string {
		if ( null === $row['destination'] ) {
			throw new StorageFailed( 'The confirmed file change has no live destination.' );
		}
		return Bytes::base64url_decode( $row['destination'] );
	}

	/**
	 * Compares the exact approved digest and refuses any unsafe row.
	 *
	 * @param Preview $preview Current plan.
	 * @param string  $digest Approved digest.
	 * @throws JobConflict If the confirmed digest or eligibility changed.
	 */
	private static function confirmed( array $preview, string $digest ): void {
		FileSpace::require_space( $preview );
		if ( ! $preview['applicable'] || ! hash_equals( $preview['digest'], $digest ) ) {
			throw new JobConflict( 'The approved file plan is stale or contains no applicable changes.' );
		}
	}

	/**
	 * Encodes the canonical manifest or its digest inputs. Names are already byte encoded.
	 *
	 * @param array<mixed> $value Data.
	 * @return string JSON.
	 */
	private static function json( array $value ): string {
		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}
}
