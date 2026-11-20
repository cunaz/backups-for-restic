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
use BackupsForRestic\Restore\FileChange;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

/**
 * The runner repeats the approved preflight before its first write. Each ordinal gets an
 * independent durable journal. Undo includes the last attempted ordinal even after a
 * killed request and works backwards so children leave before their directory.
 *
 * @phpstan-import-type Row from ApplyManifest
 * @phpstan-type Preview array{restore:string,digest:string,applicable:bool,entries:list<array{action:string,path:string,source:string,destination:string|null,reason:string}>}
 */
final readonly class FileChanges implements StepProvider {

	/**
	 * Creates the service.
	 *
	 * @param JobStore                        $jobs Jobs.
	 * @param Staging                         $staging Restore retention.
	 * @param Clock                           $clock Time.
	 * @param int                             $hours Staging lifetime.
	 * @param \Closure(bool,string):ApplyPlan $planner Current live site policy.
	 * @param FileMaintenance                 $maintenance WordPress maintenance marker.
	 * @param \Closure():bool                 $allowed Host-wide permission to write live files.
	 */
	public function __construct(
		private JobStore $jobs,
		private Staging $staging,
		private Clock $clock,
		private int $hours,
		private \Closure $planner,
		private FileMaintenance $maintenance,
		private \Closure $allowed
	) {
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
		return self::summary( $restore, $include_config, $rows );
	}

	/**
	 * Queues an apply only while the reviewed plan and staged files still match.
	 *
	 * @param string $restore Restore job ID.
	 * @param bool   $include_config Include wp-config.php.
	 * @param string $digest Approved plan.
	 * @param string $control_hash SHA-256 of the scoped control token.
	 * @return Job Queued job.
	 */
	public function start_apply( string $restore, bool $include_config, string $digest, string $control_hash = '' ): Job {
		return $this->staging->guard(
			function () use ( $restore, $include_config, $digest, $control_hash ): Job {
				$this->require_writes();
				self::confirmed( $this->preview( $restore, $include_config ), $digest );
				return $this->jobs->create(
					JobType::Apply,
					'manual',
					$this->clock->now()[0],
					[
						'restore'           => $restore,
						'include_config'    => $include_config,
						'digest'            => $digest,
						'maintenance_grant' => $control_hash,
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
			if ( $this->eligible( $job ) ) {
				$list[] = [
					'job'     => $job->id,
					'restore' => self::field( $job, 'restore' ),
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
		return $this->undo_summary( $this->original( $apply_job ) );
	}

	/**
	 * Queues an undo after the administrator confirmed the current preview.
	 *
	 * @param string $apply_job Apply job.
	 * @param string $digest Confirmed undo preview.
	 * @param string $control_hash SHA-256 of the scoped control token.
	 * @return Job Queued undo job.
	 */
	public function start_undo( string $apply_job, string $digest, string $control_hash = '' ): Job {
		return $this->staging->guard(
			function () use ( $apply_job, $digest, $control_hash ): Job {
				$this->require_writes();
				$preview = $this->preview_undo( $apply_job );
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
		return new ClosureStep(
			fn ( Job $job, EventLog $log, float $deadline ): bool => match ( $name ) {
				'preflight' => $this->prepare( $job ),
				'applying', 'undoing' => $this->mutate( $job, $deadline ),
				'recording' => $this->record( $job ),
				'cleaning_up' => $this->cleanup( $job ),
				default => throw new InvalidArgument( 'Unknown live file change step.' ),
			}
		);
	}

	/**
	 * Revalidates the entire plan before the first mutation, then persists its manifest.
	 *
	 * @param Job $job Current job.
	 * @return bool False to resume the durably recorded mutation step on the next tick.
	 */
	private function prepare( Job $job ): bool {
		$digest = self::field( $job, 'digest' );
		$this->require_writes();
		if ( JobType::Undo === $job->type ) {
			$original = $this->original( self::field( $job, 'apply' ), true );
			self::confirmed( $this->undo_summary( $original ), $digest );
			$job->progress['total'] = $this->attempted( $original );
			$this->enter_maintenance( $job );
			$job->step = 'undoing';
			$this->jobs->save( $job );
			return false;
		}
		$restore = self::field( $job, 'restore' );
		$config  = true === ( $job->data['include_config'] ?? null );
		$rows    = $this->rows( $restore, $config, true );
		self::confirmed( self::summary( $restore, $config, $rows ), $digest );
		// A kill can leave this file before the runner persisted its next step. Rebuilding
		// in preflight is safe: a mutation happens only after the step change is durable.
		$dir = $this->workspace( $job );
		self::directory( $dir );
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
	 * Makes progress on one journal. A restart after publication repeats the same ordinal.
	 *
	 * @param Job   $job Active job.
	 * @param float $deadline Tick deadline.
	 * @return bool Whether all rows have been processed.
	 * @throws StorageFailed If a journal or its cursor is incomplete.
	 * @throws JobConflict If a directory changed after its creation.
	 */
	private function mutate( Job $job, float $deadline ): bool {
		$this->require_writes();
		$undo     = JobType::Undo === $job->type;
		$original = $undo ? $this->original( self::field( $job, 'apply' ), true ) : $job;
		$rows     = $this->load( $original );
		$total    = $undo ? $this->attempted( $original ) : count( $rows );
		$done     = $job->progress['done'] ?? 0;
		if ( ! is_int( $done ) || $done < 0 || $done > $total ) {
			throw new StorageFailed( 'The file change cursor is invalid.' );
		}
		if ( $done === $total ) {
			return true;
		}
		$files = $this->files( self::field( $original, 'restore' ), true );
		$plan  = ( $this->planner )( true === ( $original->data['include_config'] ?? null ), self::field( $original, 'restore' ) );
		$made  = [];
		foreach ( $rows as $ordinal => $entry ) {
			if ( 'mkdir' === $entry['action'] ) {
				$made[ self::target( $entry ) ] = $ordinal;
			}
		}
		$this->enter_maintenance( $job );
		do {
			$i   = $undo ? $total - 1 - $done : $done;
			$row = $rows[ $i ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The file change manifest is incomplete.' );
			}
			$source = Bytes::base64url_decode( $row['path'] );
			$path   = self::target( $row );
			if ( $plan->destination( $source ) !== $path ) {
				throw new JobConflict( 'The destination mapping changed after confirmation.' );
			}
			$dir    = $this->workspace( $original );
			$item   = $dir . '/item-' . $i;
			$change = new FileChange( $plan, $files, $item, $source );
			if ( $undo ) {
				$complete = $change->undo( $path, $row['action'], $deadline );
			} else {
				for ( $parent = dirname( $path ); isset( $made[ $parent ] ); $parent = dirname( $parent ) ) {
					$owner = new FileChange( $plan, $files, $dir . '/item-' . $made[ $parent ], Bytes::base64url_decode( $rows[ $made[ $parent ] ]['path'] ) );
					if ( 'remove' !== $owner->undo_action( $parent, 'mkdir' ) ) {
						throw new JobConflict( 'A parent directory created by this apply changed.' );
					}
				}
				if ( ! $change->started( $path, $row['action'] ) ) {
					$this->recheck( $plan, $files, $source, $path, $row );
				}
				if ( $this->attempted( $original ) <= $i ) {
					self::directory( $item );
					Files::write_atomic( $dir . '/attempted', (string) ( $i + 1 ) );
				}
				$complete = $change->apply( $path, $row['action'], $row['source'], $row['original'], $deadline, $row['mode'] );
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
	 * @param ApplyPlan $plan Current policy.
	 * @param string    $files Staging.
	 * @param string    $source Snapshot path.
	 * @param string    $path Raw destination.
	 * @param Row       $row Confirmed entry.
	 * @throws JobConflict If an entry changed since confirmation.
	 */
	private function recheck( ApplyPlan $plan, string $files, string $source, string $path, array $row ): void {
		$parent = Bytes::base64url_decode( explode( ':', $row['parent'], 2 )[0] );
		if ( $plan->inspect( $files, $source )['action'] !== $row['action'] || ApplyManifest::parent_identity( $parent . '/child' ) !== $row['parent'] ) {
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
			$apply = $this->jobs->get( self::field( $job, 'apply' ) );
			if ( null === $apply ) {
				throw new StorageFailed( 'The original apply is missing.' );
			}
			Files::write_atomic( $this->workspace( $apply ) . '/reverted', '1' );
		}
		$job->result = [
			'restore'       => self::field( $job, 'restore' ),
			'files_changed' => $job->progress['done'] ?? 0,
		];
		return true;
	}

	/**
	 * A partial apply keeps all its journals. An interrupted maintenance marker is cleared.
	 *
	 * @param Job $job Current job.
	 * @return bool True.
	 */
	private function cleanup( Job $job ): bool {
		$this->maintenance->release( $job->id, $this->jobs->directory( $job ) );
		if ( [] === $job->result && JobType::Apply === $job->type ) {
			$job->result = [
				'entries_completed' => $job->progress['done'] ?? 0,
				'entries_attempted' => $this->attempted( $job ),
			];
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
	 * Checks the hosting policy even if it changed after the job was confirmed.
	 *
	 * @throws JobConflict If WordPress disallows file modifications.
	 */
	private function require_writes(): void {
		if ( ! ( $this->allowed )() ) {
			throw new JobConflict( 'The site no longer permits live file modifications.' );
		}
	}

	/**
	 * Reads the staged report under the current eligibility decision.
	 *
	 * @param string $restore Restore job.
	 * @param bool   $config Include configuration.
	 * @param bool   $retained Allow pinned staging for an active job.
	 * @return list<Row> Confirmable entries.
	 * @throws InvalidArgument If the completed report is unavailable.
	 */
	private function rows( string $restore, bool $config, bool $retained ): array {
		$files  = $this->files( $restore, $retained );
		$report = $this->staging->open_download( $restore, $this->hours, 'report', '', $retained );
		if ( null === $report ) {
			throw new InvalidArgument( 'The complete staging report is unavailable.' );
		}
		try {
			return ApplyManifest::build( ( $this->planner )( $config, $restore ), $files, $report, $this->jobs->device() );
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
	private static function summary( string $restore, bool $config, array $rows ): array {
		$entries = [];
		$blocked = false;
		$changes = false;
		foreach ( $rows as $row ) {
			$source    = GoQuote::quote( Bytes::base64url_decode( $row['path'] ) );
			$target    = null === $row['destination'] ? null : GoQuote::quote( self::target( $row ) );
			$entries[] = [
				'action'      => $row['action'],
				'path'        => $target ?? $source,
				'source'      => $source,
				'destination' => $target,
				'reason'      => $row['reason'],
			];
			$blocked   = $blocked || 'refuse' === $row['action'];
			$changes   = $changes || in_array( $row['action'], [ 'create', 'replace', 'mkdir', 'restore', 'remove' ], true );
		}
		return [
			'entries'    => $entries,
			'restore'    => $restore,
			'digest'     => hash( 'sha256', self::json( [ $restore, $config, $rows ] ) ),
			'applicable' => ! $blocked && $changes,
		];
	}

	/**
	 * Rechecks the original journal and any changes to live files before offering undo.
	 *
	 * @param Job $apply Original apply job.
	 * @return Preview Undo preview.
	 * @throws StorageFailed If the undo cursor exceeds the confirmed manifest.
	 */
	private function undo_summary( Job $apply ): array {
		$restore  = self::field( $apply, 'restore' );
		$files    = $this->files( $restore, true );
		$config   = true === ( $apply->data['include_config'] ?? null );
		$plan     = ( $this->planner )( $config, $restore );
		$rows     = $this->load( $apply );
		$undo     = [];
		$removals = [];
		for ( $i = $this->attempted( $apply ) - 1; $i >= 0; --$i ) {
			$row = $rows[ $i ] ?? null;
			if ( null === $row ) {
				throw new StorageFailed( 'The undo journal exceeds its manifest.' );
			}
			$source = Bytes::base64url_decode( $row['path'] );
			$path   = self::target( $row );
			$change = new FileChange( $plan, $files, $this->workspace( $apply ) . '/item-' . $i, $source );
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
		$result               = self::summary( $restore, $config, $undo );
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
		$file = $this->workspace( $apply ) . '/manifest.json';
		$text = is_file( $file ) && ! is_link( $file ) ? file_get_contents( $file, false, null, 0, 16777217 ) : false;
		if ( false === $text || strlen( $text ) > 16777216 || ! hash_equals( self::field( $apply, 'manifest_hash' ), hash( 'sha256', $text ) ) ) {
			throw new StorageFailed( 'The confirmed file manifest cannot be verified.' );
		}
		$rows = json_decode( $text, true );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > ApplyManifest::MAX_ENTRIES ) {
			throw new StorageFailed( 'The confirmed file manifest has an invalid shape.' );
		}
		$result = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['path'] ?? null ) || ( array_key_exists( 'destination', $row ) && ! is_string( $row['destination'] ) ) || ! in_array( $row['action'] ?? null, [ 'mkdir', 'create', 'replace' ], true ) || ! is_string( $row['reason'] ?? null ) || ! is_int( $row['mode'] ?? null ) || ! is_string( $row['parent'] ?? null ) || ! is_string( $row['identity'] ?? null ) ) {
				throw new StorageFailed( 'The confirmed file manifest has an invalid entry.' );
			}
			$source   = $row['source'] ?? null;
			$original = $row['original'] ?? null;
			if ( ( null !== $source && ! is_string( $source ) ) || ( null !== $original && ! is_string( $original ) ) ) {
				throw new StorageFailed( 'The confirmed file digests are invalid.' );
			}
			$result[] = [
				'path'        => $row['path'],
				'destination' => $row['destination'] ?? $row['path'],
				'action'      => $row['action'],
				'reason'      => $row['reason'],
				'mode'        => $row['mode'],
				'parent'      => $row['parent'],
				'identity'    => $row['identity'],
				'source'      => $source,
				'original'    => $original,
			];
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
	 * Resolves staging without trusting an absolute name from a job.
	 *
	 * @param string $restore Restore ID.
	 * @param bool   $retained Include pinned staging.
	 * @return string Verified staging path.
	 * @throws InvalidArgument If this restore is unavailable or expired.
	 */
	private function files( string $restore, bool $retained ): string {
		return $this->staging->files_directory( $restore, $this->hours, $retained ) ?? throw new InvalidArgument( 'This restore has no eligible staged files.' );
	}

	/**
	 * Checks the undo window. An undo already queued in the window may finish after it.
	 *
	 * @param string $id Apply ID.
	 * @param bool   $active The current runner is undoing this apply.
	 * @return Job Apply job.
	 * @throws JobConflict If the recovery window has closed.
	 */
	private function original( string $id, bool $active = false ): Job {
		$job = $this->jobs->get( $id );
		if ( null === $job || ! $this->eligible( $job, $active ) ) {
			throw new JobConflict( 'This apply has no available undo.' );
		}
		return $job;
	}

	/**
	 * Checks for an unreverted attempted change.
	 *
	 * @param Job  $job Candidate.
	 * @param bool $active Undo is already running.
	 * @return bool Eligible.
	 */
	private function eligible( Job $job, bool $active = false ): bool {
		return JobType::Apply === $job->type && $job->state->is_terminal() && ( $active || $job->finished_at + 86400 > $this->clock->now()[0] ) && is_file( $this->workspace( $job ) . '/attempted' ) && ! is_file( $this->workspace( $job ) . '/reverted' );
	}

	/**
	 * Reads the count recorded before attempting the latest ordinal.
	 *
	 * @param Job $job Apply.
	 * @return int Count.
	 * @throws StorageFailed If the recovery cursor is invalid.
	 */
	private function attempted( Job $job ): int {
		$file = $this->workspace( $job ) . '/attempted';
		if ( ! is_file( $file ) ) {
			return 0;
		}
		$count = file_get_contents( $file, false, null, 0, 16 );
		if ( ! is_string( $count ) || 1 !== preg_match( '/\A[1-9][0-9]{0,3}\z/', $count ) || (int) $count > ApplyManifest::MAX_ENTRIES ) {
			throw new StorageFailed( 'The undo cursor is damaged.' );
		}
		return (int) $count;
	}

	/**
	 * The private workspace associated with one unique apply.
	 *
	 * @param Job $job Apply.
	 * @return string Path.
	 */
	private function workspace( Job $job ): string {
		return $this->jobs->directory( $job ) . '/undo';
	}

	/**
	 * Creates a private component below the job directory.
	 *
	 * @param string $dir Directory.
	 * @throws StorageFailed If the private journal directory is unavailable.
	 */
	private static function directory( string $dir ): void {
		if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) ) {
			throw new StorageFailed( 'The apply journal directory is unavailable.' );
		}
	}

	/**
	 * Compares the exact approved digest and refuses any unsafe row.
	 *
	 * @param Preview $preview Current plan.
	 * @param string  $digest Approved digest.
	 * @throws JobConflict If the confirmed digest or eligibility changed.
	 */
	private static function confirmed( array $preview, string $digest ): void {
		if ( ! $preview['applicable'] || ! hash_equals( $preview['digest'], $digest ) ) {
			throw new JobConflict( 'The approved file plan is stale or contains no applicable changes.' );
		}
	}

	/**
	 * Reads a required string field from job metadata.
	 *
	 * @param Job    $job Job.
	 * @param string $name Field.
	 * @return string Value.
	 * @throws InvalidArgument If the job lacks required metadata.
	 */
	private static function field( Job $job, string $name ): string {
		$value = $job->data[ $name ] ?? null;
		if ( ! is_string( $value ) ) {
			throw new InvalidArgument( 'The file change request is incomplete.' );
		}
		return $value;
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
