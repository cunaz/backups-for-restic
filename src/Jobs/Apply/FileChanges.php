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
use BackupsForRestic\Exception\PluginException;
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
use BackupsForRestic\Restore\DiskFilePlan;
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
 * killed request and works backwards so children leave before their directory. It decides
 * entry by entry: what still is as the apply left it is reverted, what changed since is
 * kept with its journal for a later undo within the same window.
 *
 * @phpstan-import-type Row from ApplyManifest
 * @phpstan-import-type Outcome from FileChange
 * @phpstan-type Totals array{restore:int,remove:int,keep:int}
 * @phpstan-type Preview array{restore:string,digest:string,applicable:bool,required_bytes:int|null,available_bytes:int|null,enough_space:bool|null,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>,totals?:Totals|null,undone?:true}
 * @phpstan-type Counts array{restored:int,removed:int,unchanged:int,kept:int,changed_after_review:int}
 * @phpstan-type KeptPage array{entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>,total:int,offset:int,next_offset:int|null,plan_job:string,digest:string}
 */
final readonly class FileChanges implements StepProvider {

	/**
	 * Seconds in which a review checks every entry of an apply against its live path again
	 * before it records the apply as undone. An apply that takes longer stays eligible.
	 */
	private const float SETTLE_SECONDS = 2.0;

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
	 * @return Preview|array{restore:string,digest:string,applicable:bool,required_bytes:int|null,available_bytes:int|null,enough_space:bool|null,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>,plan_job:string,total:int,offset:int,next_offset:int|null,totals?:Totals|null,undone?:true}|Job Preview or queued job; an undo review that found nothing left to revert has undone, and its apply is recorded as undone.
	 * @throws InvalidArgument If a requested page or option is invalid.
	 */
	public function request_preview( string $id, bool $undo, bool $config, ?string $plan_job = null, int $offset = 0 ): Job|array {
		if ( $offset < 0 || ( null === $plan_job && 0 !== $offset ) || ( $undo && $config ) ) {
			throw new InvalidArgument( 'The file preview request has an invalid scope or page.' );
		}
		$original = $undo ? $this->journal->original( $id ) : null;
		$restore  = null === $original ? $id : ApplyJournal::field( $original, 'restore' );
		if ( null !== $plan_job ) {
			$page = $this->plans->plan_page( $id, $restore, $undo, $config, $plan_job, $offset );
			if ( null !== $original && 0 === $offset && $this->settle_review( $original, $page['totals'] ?? null, $this->plans->settled( $plan_job ) ) ) {
				$page['undone'] = true;
			}
			return $page;
		}
		if ( null !== $original && ! is_string( $original->data['plan_job'] ?? null ) ) {
			return $this->review_undo( $original );
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
	 * Lists undoable apply jobs, including a failed or cancelled partial apply and an apply
	 * that an undo reverted in part: its kept entries stay undoable until the window ends.
	 *
	 * @return list<array{job:string,restore:string,expires:int,state:string,kept:int|null}> Undo opportunities, with applied or partly_undone and the number of entries the last undo kept, null when it stopped before it finished.
	 */
	public function undoable(): array {
		$list = [];
		foreach ( $this->jobs->file_changes() as $job ) {
			if ( $this->journal->eligible( $job ) ) {
				$kept   = $job->result['undo_kept'] ?? null;
				$list[] = [
					'job'     => $job->id,
					'restore' => ApplyJournal::field( $job, 'restore' ),
					'expires' => $job->finished_at + 86400,
					'state'   => 'partly_undone' === ( $job->result['undo_state'] ?? null ) ? 'partly_undone' : 'applied',
					'kept'    => is_int( $kept ) ? $kept : null,
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
		return $this->review_undo( $this->journal->original( $apply_job ) );
	}

	/**
	 * The inline undo review of an apply, which records the apply as undone when nothing of
	 * it is left to revert.
	 *
	 * @param Job $apply Apply.
	 * @return Preview Undo preview.
	 */
	private function review_undo( Job $apply ): array {
		$review  = $this->undo_summary( $apply );
		$settled = array_filter( $review['entries'], static fn ( array $entry ): bool => 'keep' === $entry['action'] && FileChange::settled( $entry['reason'] ) );
		if ( $this->settle_review( $apply, $review['totals'] ?? null, count( $settled ) ) ) {
			$review['undone'] = true;
		}
		return $review;
	}

	/**
	 * Records an apply as undone when its review finds every entry with nothing left to
	 * revert: the reverted marker ends its undo window, so that its staging and journal can
	 * expire, and the review says so instead of offering an undo that would be refused. While
	 * a job is active the apply stays as it is, and a later review records it.
	 *
	 * @param Job         $apply Apply.
	 * @param Totals|null $totals Totals of its review by action.
	 * @param int|null    $settled Entries kept with nothing left to revert, null if not counted.
	 * @return bool Whether the apply is now recorded as undone.
	 */
	private function settle_review( Job $apply, ?array $totals, ?int $settled ): bool {
		if ( null === $totals || 0 !== $totals['restore'] + $totals['remove'] || $settled !== $totals['keep'] ) {
			return false;
		}
		try {
			return true === $this->staging->guard(
				fn (): bool => $this->jobs->while_idle(
					function () use ( $apply ): bool {
						$current = $this->journal->original( $apply->id );
						// The review can be older than this request: each entry is decided again now.
						if ( ! $this->nothing_to_revert( $current ) ) {
							return false;
						}
						Files::write_durable( $this->journal->workspace( $current ) . '/reverted', '1' );
						$current->result['undo_state'] = 'undone';
						$current->result['undo_kept']  = 0;
						$this->jobs->save( $current );
						return true;
					}
				)
			);
		} catch ( JobConflict ) {
			return false;
		}
	}

	/**
	 * Whether every attempted entry of an apply has nothing left to revert, decided against its
	 * live path now. An entry that came back since a review, a check that does not finish in
	 * SETTLE_SECONDS, such as one that verifies a large file, and anything that cannot be read
	 * leave the apply as it is.
	 *
	 * @param Job $apply Apply.
	 * @return bool Whether nothing of it can be reverted any more.
	 */
	private function nothing_to_revert( Job $apply ): bool {
		$deadline = microtime( true ) + self::SETTLE_SECONDS;
		try {
			$restore = ApplyJournal::field( $apply, 'restore' );
			$files   = $this->journal->files( $restore, true );
			$plan    = ( $this->planner )( true === ( $apply->data['include_config'] ?? null ), $restore, null );
			$forward = is_string( $apply->data['plan_job'] ?? null ) ? $this->plans->approved( $apply ) : null;
			$rows    = null === $forward ? $this->load( $apply ) : [];
			$total   = $this->journal->attempted( $apply );
			for ( $i = 0; $i < $total; ++$i ) {
				$record = $forward?->mutation( $i );
				$row    = $record['row'] ?? $rows[ $i ] ?? null;
				if ( null === $row || microtime( true ) >= $deadline ) {
					return false;
				}
				$source = Bytes::base64url_decode( $row['path'] );
				$path   = self::target( $row );
				$change = new FileChange( $plan, $files, $this->journal->workspace( $apply ) . '/item-' . ( $record['index'] ?? $i ), $source );
				$now    = $plan->destination( $source ) === $path ? $change->undo_review( $path, $row['action'], $deadline ) : [
					'action' => 'keep',
					'reason' => 'destination_changed',
				];
				if ( 'keep' !== $now['action'] || ! FileChange::settled( $now['reason'] ) ) {
					return false;
				}
			}
		} catch ( PluginException | FileWorkPending ) {
			return false;
		}
		return true;
	}

	/**
	 * Queues an undo after the administrator confirmed the current preview. The job keeps
	 * the reviewed decision of every entry: it reverts only entries that the review showed
	 * for a revert. An undo with nothing to revert is refused with its own code.
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
				$preview  = null === $plan_job ? $this->undo_summary( $original ) : $this->plans->plan_page( $apply_job, $restore, true, false, $plan_job, 0 );
				$totals   = $preview['totals'] ?? null;
				if ( null !== $totals && 0 === $totals['restore'] + $totals['remove'] && hash_equals( $preview['digest'], $digest ) ) {
					JobConflict::raise( 'Nothing of this apply can be undone now.', [ 'code' => 'undo_nothing_to_revert' ] );
				}
				self::confirmed( $preview, $digest );
				$data = [
					'apply'             => $apply_job,
					'restore'           => $preview['restore'],
					'digest'            => $digest,
					'maintenance_grant' => $control_hash,
					'plan_job'          => $plan_job,
					'user'              => $user,
					'uploads_dir'       => null === $this->uploads ? null : ( $this->uploads )(),
				];
				if ( null === $plan_job ) {
					// The digest was just compared: these are the decisions the administrator saw.
					$data['reviewed'] = array_map( static fn ( array $entry ): array => [ $entry['action'], $entry['reason'] ], $preview['entries'] );
				}
				return $this->jobs->create( JobType::Undo, 'manual', $this->clock->now()[0], $data );
			}
		);
	}

	/**
	 * Reads one page of the entries that a finished or stopped undo kept, with their reasons.
	 *
	 * @param string $undo_job Undo job.
	 * @param int    $offset First kept entry.
	 * @return KeptPage Page.
	 * @throws JobConflict If the job is not an undo that has ended.
	 * @throws InvalidArgument If the page is outside the list.
	 * @throws StorageFailed If the sealed list does not belong to this undo.
	 */
	public function kept_page( string $undo_job, int $offset ): array {
		$job = $this->jobs->get( $undo_job );
		if ( null === $job || JobType::Undo !== $job->type || ! $job->state->is_terminal() ) {
			throw new JobConflict( 'Kept entries are listed only for an undo that has ended.' );
		}
		$digest = $job->data['kept_digest'] ?? null;
		$total  = 0;
		$disk   = null;
		if ( is_string( $digest ) ) {
			$disk   = $this->kept( $job );
			$header = $disk->open( $digest );
			$total  = $header['entries'];
			$expect = [ ApplyJournal::field( $job, 'apply' ), ApplyJournal::field( $job, 'restore' ), true, false, 0, 0, [ 'keep' => $total ] ];
			if ( [ $header['subject'], $header['restore'], $header['undo'], $header['config'], $header['changes'], $header['refused'], $header['actions'] ] !== $expect || ( $job->result['kept'] ?? null ) !== $total ) {
				throw new StorageFailed( 'The list of kept entries does not belong to this undo.' );
			}
		}
		if ( $offset < 0 || $offset > $total ) {
			throw new InvalidArgument( 'This page is outside the kept entries.' );
		}
		if ( null === $disk ) {
			return [
				'entries'     => [],
				'total'       => 0,
				'offset'      => 0,
				'next_offset' => null,
				'plan_job'    => $job->id,
				'digest'      => '',
			];
		}
		$entries = array_map( ApplyManifest::display( ... ), $disk->page( $offset ) );
		$next    = $offset + count( $entries );
		return [
			'entries'     => $entries,
			'total'       => $total,
			'offset'      => $offset,
			'next_offset' => $next < $total ? $next : null,
			'plan_job'    => $job->id,
			'digest'      => $digest,
		];
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
				'preflight' => JobType::Undo === $job->type ? $this->prepare_undo( $job ) : $this->prepare( $job, $deadline ),
				'applying' => $this->mutate( $job, $deadline ),
				'undoing' => $this->revert( $job, $deadline ),
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
		// Undo reads this manifest after a crash as well, so it is on disk before maintenance.
		Files::write_durable( $dir . '/manifest.json', $json );
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
	 * Checks before maintenance that the confirmed undo still belongs to its apply: the sealed
	 * review or the reviewed decisions of an inline one, and the apply's own manifest. It does
	 * not recheck the live entries: the worker checks each one again immediately before it
	 * touches it, and keeps an entry that changed since the review instead of failing. An
	 * inline undo that an earlier version queued without the decisions is refused.
	 *
	 * @param Job $job Undo.
	 * @return bool False: the next step has been durably recorded.
	 * @throws JobConflict If the sealed review does not belong to the approved undo, or the undo has no reviewed decisions.
	 */
	private function prepare_undo( Job $job ): bool {
		$this->require_writes( self::operator( $job ) );
		$original = $this->journal->original( ApplyJournal::field( $job, 'apply' ), true );
		$total    = $this->journal->attempted( $original );
		if ( is_string( $job->data['plan_job'] ?? null ) ) {
			$head = $this->plans->approved( $job )->header();
			$this->plans->approved( $original );
			if ( $head['restore'] !== ApplyJournal::field( $original, 'restore' ) || $head['subject'] !== $original->id || true !== $head['undo'] || $head['config'] || $head['entries'] !== $total || $head['changes'] < 1 || $head['refused'] > 0 ) {
				throw new JobConflict( 'The sealed file preview does not match the approved operation.' );
			}
		} else {
			self::reviewed( $job, $total );
			$this->load( $original );
		}
		$job->progress['total'] = $total;
		$this->enter_maintenance( $job );
		$job->step = 'undoing';
		$this->jobs->save( $job );
		return false;
	}

	/**
	 * Rechecks an arbitrarily large reviewed plan over multiple ticks before the site
	 * enters maintenance. Each publication also checks its own current fingerprints.
	 *
	 * @param Job   $job Apply.
	 * @param float $deadline Worker deadline.
	 * @return bool False until the next job step has been durably recorded.
	 * @throws JobConflict If the sealed plan has changed since review.
	 */
	private function verify_plan( Job $job, float $deadline ): bool {
		$this->require_writes( self::operator( $job ) );
		$disk    = $this->plans->approved( $job );
		$head    = $disk->header();
		$restore = ApplyJournal::field( $job, 'restore' );
		$config  = true === ( $job->data['include_config'] ?? false );
		if ( $head['restore'] !== $restore || $head['subject'] !== $restore || $head['undo'] || $head['config'] !== $config || $head['changes'] < 1 || $head['refused'] > 0 ) {
			throw new JobConflict( 'The sealed file preview does not match the approved operation.' );
		}
		$plan   = ( $this->planner )( $config, $restore, self::uploads( $job ) );
		$files  = $this->journal->files( $restore, true );
		$cursor = $job->progress['verified'] ?? 0;
		if ( ! is_int( $cursor ) || $cursor < 0 || $cursor > $head['entries'] ) {
			throw new JobConflict( 'The sealed file preview verification cursor is invalid.' );
		}
		// A pre-upgrade job may have verified rows without accounting for their payloads.
		if ( ! isset( $job->data['verified_bytes'] ) ) {
			$cursor                      = 0;
			$job->progress['verified']   = 0;
			$job->data['verified_bytes'] = 0;
		}
		$required = $job->data['verified_bytes'];
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
			if ( ApplyManifest::mutates( $row ) || 'identical_contents' === $row['reason'] ) {
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
			if ( 'identical_contents' !== $row['reason'] ) {
				$required                    = FileSpace::add( $required, $this->space->row( $row ) );
				$job->data['verified_bytes'] = $required;
			}
			$job->progress['verified'] = ++$cursor;
			if ( microtime( true ) >= $deadline || $this->jobs->cancel_requested( $job ) ) {
				return false;
			}
		}
		FileSpace::require_space( $this->space->estimate( $required ) );
		$job->data['remaining_bytes'] = $required;
		ApplyJournal::directory( $this->journal->workspace( $job ) );
		$job->progress['total'] = $head['changes'];
		$this->enter_maintenance( $job );
		$job->step = 'applying';
		$this->jobs->save( $job );
		return false;
	}

	/**
	 * Makes progress on one journal. A restart after publication repeats the same ordinal.
	 *
	 * @param Job   $job Active apply.
	 * @param float $deadline Tick deadline.
	 * @return bool Whether all rows have been processed.
	 * @throws StorageFailed If a journal or its cursor is incomplete.
	 * @throws JobConflict If a directory changed after its creation.
	 */
	private function mutate( Job $job, float $deadline ): bool {
		$this->require_writes( self::operator( $job ) );
		$disk  = is_string( $job->data['plan_job'] ?? null ) ? $this->plans->approved( $job ) : null;
		$rows  = null === $disk ? $this->load( $job ) : [];
		$total = null === $disk ? count( $rows ) : $disk->header()['changes'];
		$done  = $job->progress['done'] ?? 0;
		if ( ! is_int( $done ) || $done < 0 || $done > $total ) {
			throw new StorageFailed( 'The file change cursor is invalid.' );
		}
		if ( $done === $total ) {
			return true;
		}
		$files = $this->journal->files( ApplyJournal::field( $job, 'restore' ), true );
		$plan  = ( $this->planner )( true === ( $job->data['include_config'] ?? null ), ApplyJournal::field( $job, 'restore' ), self::uploads( $job ) );
		$made  = [];
		foreach ( $rows as $ordinal => $entry ) {
			if ( 'mkdir' === $entry['action'] ) {
				$made[ self::target( $entry ) ] = $ordinal;
			}
		}
		$this->enter_maintenance( $job );
		if ( ! isset( $job->data['remaining_bytes'] ) && ! $this->plans->resume_space( $job, $disk, $rows, $done, $total, $deadline ) ) {
			return false;
		}
		do {
			$record = $disk?->mutation( $done );
			$index  = $record['index'] ?? $done;
			$row    = $record['row'] ?? $rows[ $done ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The file change manifest is incomplete.' );
			}
			$source = Bytes::base64url_decode( $row['path'] );
			$path   = self::target( $row );
			if ( $plan->destination( $source ) !== $path ) {
				throw new JobConflict( 'The destination mapping changed after confirmation.' );
			}
			$dir    = $this->journal->workspace( $job );
			$item   = $dir . '/item-' . $index;
			$change = new FileChange( $plan, $files, $item, $source, $job->id );
			$base   = Bytes::base64url_decode( explode( ':', $row['parent'], 2 )[0] );
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
			if ( $this->journal->attempted( $job ) <= $done ) {
				ApplyJournal::directory( $item );
				// Undo covers this ordinal after a power loss too: the count is on disk
				// before the entry's first record, and so before its first live change.
				Files::write_durable( $dir . '/attempted', (string) ( $done + 1 ) );
			}
			if ( ! $change->apply( $path, $row['action'], $row['source'], $row['original'], $deadline, $row['mode'] ) ) {
				return false;
			}
			$job->data['remaining_bytes'] = $later;
			$job->progress['done']        = ++$done;
			$this->jobs->save( $job );
		} while ( $done < $total && microtime( true ) < $deadline && ! $this->jobs->cancel_requested( $job ) );
		return $done === $total;
	}

	/**
	 * Reverts the attempted entries backwards, so that children leave before their directory.
	 * Each entry follows its reviewed decision and is checked again immediately before it is
	 * touched: one that changed since the review is kept and counted, and the job goes on.
	 * Its outcome, and a kept entry in the paged list of kept entries, is on disk before the
	 * job document that counts it, so that a restarted tick repeats the entry and nothing else.
	 *
	 * @param Job   $job Active undo.
	 * @param float $deadline Tick deadline.
	 * @return bool Whether all entries have been processed.
	 * @throws StorageFailed If a journal or its cursor is incomplete.
	 */
	private function revert( Job $job, float $deadline ): bool {
		$this->require_writes( self::operator( $job ) );
		$original = $this->journal->original( ApplyJournal::field( $job, 'apply' ), true );
		$forward  = is_string( $original->data['plan_job'] ?? null ) ? $this->plans->approved( $original ) : null;
		$rows     = null === $forward ? $this->load( $original ) : [];
		$review   = is_string( $job->data['plan_job'] ?? null ) ? $this->plans->approved( $job ) : null;
		$total    = $this->journal->attempted( $original );
		$reviewed = null === $review ? self::reviewed( $job, $total ) : [];
		$done     = $job->progress['done'] ?? 0;
		if ( ! is_int( $done ) || $done < 0 || $done > $total || ( null !== $review && $review->header()['entries'] !== $total ) ) {
			throw new StorageFailed( 'The undo cursor is invalid.' );
		}
		if ( $done === $total ) {
			return true;
		}
		$counts = self::counts( $job );
		$files  = $this->journal->files( ApplyJournal::field( $original, 'restore' ), true );
		$plan   = ( $this->planner )( true === ( $original->data['include_config'] ?? null ), ApplyJournal::field( $original, 'restore' ), self::uploads( $job ) );
		$kept   = null;
		$this->enter_maintenance( $job );
		do {
			$i      = $total - 1 - $done;
			$record = $forward?->mutation( $i );
			$index  = $record['index'] ?? $i;
			$row    = $record['row'] ?? $rows[ $i ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The file change manifest is incomplete.' );
			}
			[ $action, $reason ] = null === $review ? $reviewed[ $done ] : self::reviewed_row( $review->row( $done ), $row );
			$source              = Bytes::base64url_decode( $row['path'] );
			$path                = self::target( $row );
			$change              = new FileChange( $plan, $files, $this->journal->workspace( $original ) . '/item-' . $index, $source, $job->id );
			if ( 'keep' === $action ) {
				$outcome = FileChange::settled( $reason ) ? self::still_settled( $change, $plan, $source, $path, $row['action'], $reason, $deadline ) : [
					'outcome' => 'kept',
					'reason'  => $reason,
				];
			} elseif ( $plan->destination( $source ) !== $path ) {
				$outcome = [
					'outcome' => 'kept',
					'reason'  => 'destination_changed',
				];
			} else {
				$outcome = $change->undo( $path, $row['action'], $deadline, $action );
			}
			if ( null === $outcome ) {
				return false;
			}
			++$counts[ $outcome['outcome'] ];
			if ( 'kept' === $outcome['outcome'] ) {
				// A revert, or an entry that had nothing left to revert, changed since the review.
				$counts['changed_after_review'] += 'keep' !== $action || FileChange::settled( $reason ) ? 1 : 0;
				$kept                          ??= $this->kept( $job, true );
				$kept->put( 'rows', $counts['kept'] - 1, ApplyManifest::row( $row['path'], $path, 'keep', $outcome['reason'] ) );
				$kept->flush();
			}
			$job->data['undo_counts'] = $counts;
			$job->progress['done']    = ++$done;
			$this->jobs->save( $job );
		} while ( $done < $total && microtime( true ) < $deadline && ! $this->jobs->cancel_requested( $job ) );
		return $done === $total;
	}

	/**
	 * The outcome of an entry that the review showed with nothing left to revert: unchanged
	 * while that still holds. An entry that came back since, or that the site now treats
	 * otherwise, is kept, not reverted against the confirmation, so that the apply stays
	 * partly undone and a later review can revert it.
	 *
	 * @param FileChange $change Entry.
	 * @param ApplyPlan  $plan Current policy.
	 * @param string     $source Snapshot path.
	 * @param string     $path Live destination.
	 * @param string     $action Forward action.
	 * @param string     $reason Reviewed reason.
	 * @param float      $deadline Tick deadline.
	 * @return Outcome|null Outcome, or null when the check needs another tick.
	 */
	private static function still_settled( FileChange $change, ApplyPlan $plan, string $source, string $path, string $action, string $reason, float $deadline ): ?array {
		try {
			$now = $plan->destination( $source ) === $path ? $change->undo_review( $path, $action, $deadline ) : [
				'action' => 'keep',
				'reason' => 'destination_changed',
			];
		} catch ( FileWorkPending ) {
			return null;
		}
		if ( 'keep' === $now['action'] && FileChange::settled( $now['reason'] ) ) {
			return [
				'outcome' => 'unchanged',
				'reason'  => $reason,
			];
		}
		return [
			'outcome' => 'kept',
			'reason'  => 'keep' === $now['action'] ? $now['reason'] : 'changed_since_apply',
		];
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
	 * Records completion. Only a finished undo that kept no entry invalidates the original
	 * recovery materials.
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
			$this->conclude( $job, $apply, true );
			return true;
		}
		$job->result = [
			'restore'       => ApplyJournal::field( $job, 'restore' ),
			'files_changed' => $job->progress['done'] ?? 0,
		];
		return true;
	}

	/**
	 * Records what an undo did, in its result and in the document of its apply: fully undone
	 * when a finished undo kept no entry, partly undone with the number of kept entries when
	 * it kept some, and partly undone without a number when it stopped after a revert. Only
	 * a fully undone apply is marked reverted, which ends its undo window early; kept entries
	 * keep their journal and saved copies for a later undo within the window.
	 *
	 * @param Job  $job Undo.
	 * @param Job  $apply Its apply.
	 * @param bool $finished Whether every entry was processed.
	 */
	private function conclude( Job $job, Job $apply, bool $finished ): void {
		$counts  = self::counts( $job );
		$changed = $counts['restored'] + $counts['removed'];
		if ( $counts['kept'] > 0 ) {
			$job->data['kept_digest'] = $this->kept( $job )->seal( $apply->id, ApplyJournal::field( $apply, 'restore' ), true, false, [ 'rows' => $counts['kept'] ], 0, 0, null, [ 'keep' => $counts['kept'] ] );
		}
		$state = null;
		if ( $finished ) {
			$state = 0 === $counts['kept'] ? 'undone' : 'partly_undone';
		} elseif ( $changed > 0 ) {
			$state = 'partly_undone';
		}
		if ( 'undone' === $state ) {
			Files::write_durable( $this->journal->workspace( $apply ) . '/reverted', '1' );
		}
		if ( null !== $state ) {
			$apply->result['undo_state'] = $state;
			$apply->result['undo_kept']  = $finished ? $counts['kept'] : null;
			$apply->result['undo_job']   = $job->id;
			$this->jobs->save( $apply );
		}
		$job->result = [
			'restore'              => ApplyJournal::field( $job, 'restore' ),
			'files_changed'        => $changed,
			'restored'             => $counts['restored'],
			'removed'              => $counts['removed'],
			'unchanged'            => $counts['unchanged'],
			'kept'                 => $counts['kept'],
			'changed_after_review' => $counts['changed_after_review'],
			'apply_state'          => is_string( $apply->result['undo_state'] ?? null ) ? $apply->result['undo_state'] : 'applied',
		];
	}

	/**
	 * A partial apply keeps all its journals. An interrupted maintenance marker is cleared. A
	 * stopped undo records what it did before it stopped.
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
		if ( ! $this->staging->remove_verification( $job, $deadline ) ) {
			return false;
		}
		if ( [] === $job->result && JobType::Undo === $job->type && isset( $job->data['undo_counts'] ) ) {
			$apply = $this->jobs->get( ApplyJournal::field( $job, 'apply' ) );
			if ( null !== $apply ) {
				$this->conclude( $job, $apply, false );
			}
		}
		return true;
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
	 * Reviews every attempted entry on its own: what still is as the apply left it is
	 * reverted, what changed since is kept with its reason. The undo is applicable while at
	 * least one entry can be reverted.
	 *
	 * @param Job         $apply Original apply job.
	 * @param string|null $uploads Uploads root of a confirmed undo, null for the current one.
	 * @return Preview Undo preview, with the totals by action.
	 * @throws StorageFailed If the undo cursor exceeds the confirmed manifest.
	 * @throws JobConflict If the apply needs a background review.
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
			$source   = Bytes::base64url_decode( $row['path'] );
			$path     = self::target( $row );
			$change   = new FileChange( $plan, $files, $this->journal->workspace( $apply ) . '/item-' . $i, $source );
			$decision = $plan->destination( $source ) === $path ? $change->undo_review( $path, $row['action'] ) : [
				'action' => 'keep',
				'reason' => 'destination_changed',
			];
			if ( 'remove' === $decision['action'] && 'mkdir' === $row['action'] && ! self::children_removed( $path, $removals ) ) {
				// What someone added stays, and so does the directory that holds it.
				$decision = [
					'action' => 'keep',
					'reason' => 'new_children',
				];
			}
			if ( 'remove' === $decision['action'] ) {
				$removals[ $path ] = true;
			}
			$row['action'] = $decision['action'];
			$row['reason'] = $decision['reason'];
			$undo[]        = $row;
		}
		$actions              = array_count_values( array_column( $undo, 'action' ) );
		$totals               = [
			'restore' => $actions['restore'] ?? 0,
			'remove'  => $actions['remove'] ?? 0,
			'keep'    => $actions['keep'] ?? 0,
		];
		$result               = $this->summary( $restore, $config, $undo );
		$result['applicable'] = $totals['restore'] + $totals['remove'] > 0 && false !== $result['enough_space'];
		$result['totals']     = $totals;
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
	 * The outcomes an undo has counted so far.
	 *
	 * @param Job $job Undo.
	 * @return Counts Counts.
	 * @throws StorageFailed If the stored counts are invalid.
	 */
	private static function counts( Job $job ): array {
		$stored = $job->data['undo_counts'] ?? [];
		if ( ! is_array( $stored ) ) {
			throw new StorageFailed( 'The counted undo outcomes are invalid.' );
		}
		$count = static function ( string $name ) use ( $stored ): int {
			$value = $stored[ $name ] ?? 0;
			if ( ! is_int( $value ) || $value < 0 ) {
				throw new StorageFailed( 'The counted undo outcomes are invalid.' );
			}
			return $value;
		};
		return [
			'restored'             => $count( 'restored' ),
			'removed'              => $count( 'removed' ),
			'unchanged'            => $count( 'unchanged' ),
			'kept'                 => $count( 'kept' ),
			'changed_after_review' => $count( 'changed_after_review' ),
		];
	}

	/**
	 * The decisions of a confirmed inline undo review, in the order of the undo. An undo that
	 * an earlier version queued has none, and its confirmation does not say which entries the
	 * administrator saw as kept: it is refused and reviewed again.
	 *
	 * @param Job $job Undo.
	 * @param int $total Attempted entries of its apply.
	 * @return list<array{0:string,1:string}> Action and reason of each entry.
	 * @throws JobConflict If the undo was confirmed before the decisions were kept.
	 * @throws StorageFailed If the stored review does not cover the attempted entries.
	 */
	private static function reviewed( Job $job, int $total ): array {
		if ( ! array_key_exists( 'reviewed', $job->data ) ) {
			JobConflict::raise( 'This undo was confirmed before the decision of each entry was kept.', [ 'code' => 'undo_review_required' ] );
		}
		$stored = $job->data['reviewed'];
		if ( ! is_array( $stored ) || ! array_is_list( $stored ) || count( $stored ) !== $total ) {
			throw new StorageFailed( 'The confirmed undo review does not cover the attempted entries.' );
		}
		$decisions = [];
		foreach ( $stored as $pair ) {
			if ( ! is_array( $pair ) || ! in_array( $pair[0] ?? null, [ 'restore', 'remove', 'keep' ], true ) || ! is_string( $pair[1] ?? null ) ) {
				throw new StorageFailed( 'The confirmed undo review is invalid.' );
			}
			$decisions[] = [ $pair[0], $pair[1] ];
		}
		return $decisions;
	}

	/**
	 * The decision of a sealed undo review for one entry, which must name the same entry as
	 * the apply's own manifest.
	 *
	 * @param Row $review Reviewed undo row.
	 * @param Row $row Forward row.
	 * @return array{0:string,1:string} Action and reason.
	 * @throws JobConflict If the review does not belong to this entry.
	 */
	private static function reviewed_row( array $review, array $row ): array {
		if ( $review['path'] !== $row['path'] || $review['destination'] !== $row['destination'] || ! in_array( $review['action'], [ 'restore', 'remove', 'keep' ], true ) ) {
			throw new JobConflict( 'The approved undo does not match the apply it reverses.' );
		}
		return [ $review['action'], $review['reason'] ];
	}

	/**
	 * The paged list of the entries that an undo kept, written durably.
	 *
	 * @param Job  $job Undo.
	 * @param bool $create Create its directory.
	 * @return DiskFilePlan Store.
	 */
	private function kept( Job $job, bool $create = false ): DiskFilePlan {
		$directory = $this->jobs->directory( $job ) . '/kept';
		if ( $create ) {
			ApplyJournal::directory( $directory );
		}
		return new DiskFilePlan( $directory, true );
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
