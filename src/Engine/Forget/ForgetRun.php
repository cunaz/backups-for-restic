<?php
/**
 * Forget, resumable.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Forget;

use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the snapshots of one site that a retention policy no longer keeps, like `restic
 * forget --host <host> --tag <tags> --group-by host` (ADR 0010, ADR 0022).
 *
 * The run loads the snapshots one per step, decides with Forget::apply() once all are loaded,
 * and deletes the removed snapshot files one per step, under an exclusive lock as restic does.
 * Snapshots of other hosts or without every one of the tags are never touched. The snapshots
 * that are left form one group: the tags a snapshot carries besides the required ones, such
 * as the tag of the job that wrote it, do not split them, or every snapshot would be the
 * newest of a group of its own and none would ever be removed. A snapshot written by
 * another client during a pause can only make the policy remove more, never keep one that
 * this run removes, so a lost lock is simply taken again.
 */
final class ForgetRun {

	public const int STATE_VERSION = 1;

	/**
	 * Lock handling.
	 *
	 * @var Locking
	 */
	private Locking $locking;

	/**
	 * Checkpoint: pid, lock, locked_at, phase, names, position, snapshots (JSON by name),
	 * remove, removed.
	 *
	 * @var array{pid: int, lock: string, locked_at: int, phase: string, names: list<string>, position: int, snapshots: array<string, string>, remove: list<string>, removed: int}
	 */
	private array $state;

	/**
	 * Creates the run from a state.
	 *
	 * @param Repository           $repository Repository.
	 * @param string               $work_dir   Directory for the checkpoint.
	 * @param Identity             $identity   Host name of this site, written into lock files.
	 * @param Clock                $clock      Time source.
	 * @param RetentionPolicy      $policy     Policy; never empty.
	 * @param list<string>         $tags       Only snapshots with all these tags are considered.
	 * @param array<string, mixed> $fields     Decoded checkpoint.
	 */
	private function __construct(
		private readonly Repository $repository,
		private readonly string $work_dir,
		private readonly Identity $identity,
		private readonly Clock $clock,
		private readonly RetentionPolicy $policy,
		private readonly array $tags,
		array $fields
	) {
		$snapshots = [];
		foreach ( is_array( $fields['snapshots'] ?? null ) ? $fields['snapshots'] : [] as $name => $json ) {
			$snapshots[ (string) $name ] = Decoded::string( $json );
		}
		$this->state   = [
			'pid'       => Decoded::int( $fields['pid'] ?? null, random_int( 100000, 2147483647 ) ),
			'lock'      => Decoded::string( $fields['lock'] ?? null ),
			'locked_at' => Decoded::int( $fields['locked_at'] ?? null ),
			'phase'     => Decoded::string( $fields['phase'] ?? null, 'load' ),
			'names'     => Decoded::strings( $fields['names'] ?? [] ),
			'position'  => Decoded::count( $fields['position'] ?? null ),
			'snapshots' => $snapshots,
			'remove'    => Decoded::strings( $fields['remove'] ?? [] ),
			'removed'   => Decoded::count( $fields['removed'] ?? null ),
		];
		$this->locking = new Locking( $repository, $identity, $clock, $this->state['pid'] );
	}

	/**
	 * Starts a forget: refuses append-only repositories, takes the exclusive lock and lists the
	 * snapshots.
	 *
	 * @param Repository      $repository Repository.
	 * @param string          $work_dir   Empty directory for the checkpoint.
	 * @param Identity        $identity   Host name of this site, written into lock files.
	 * @param Clock           $clock      Time source.
	 * @param RandomSource    $random     For the append-only probe.
	 * @param RetentionPolicy $policy     Policy.
	 * @param list<string>    $tags       Only snapshots with all these tags are considered.
	 * @return self Run.
	 * @throws InvalidArgument If the policy is empty or the directory already holds a run.
	 * @throws AppendOnlyRefused If the server refuses deletions.
	 */
	public static function start( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RandomSource $random, RetentionPolicy $policy, array $tags ): self {
		if ( $policy->is_empty() ) {
			InvalidArgument::raise( 'An empty retention policy would remove every snapshot.' );
		}
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) && ! is_dir( $work_dir ) ) {
			InvalidArgument::raise( 'The working directory cannot be created.' );
		}
		if ( is_file( $work_dir . '/state.json' ) ) {
			InvalidArgument::raise( 'The working directory already holds a forget.' );
		}
		if ( AppendOnlyStatus::AppendOnly === AppendOnlyProbe::detect( $repository->backend(), $random ) ) {
			AppendOnlyRefused::raise( 'The repository is append-only; forget runs on another computer.' );
		}
		$run = new self( $repository, $work_dir, $identity, $clock, $policy, $tags, [] );
		$run->locking->remove_own_stale();
		$run->state['lock']      = $run->locking->acquire( true )->hex();
		$run->state['locked_at'] = $clock->now()[0];
		try {
			$run->state['names'] = array_keys( $repository->backend()->list( ObjectType::Snapshots ) );
			sort( $run->state['names'] );
			$run->persist();
		} catch ( \Throwable $failure ) {
			// No checkpoint names the lock yet, so nothing else would ever release it.
			try {
				$run->locking->release( Id::from_hex( $run->state['lock'] ) );
			} catch ( PluginException $unreleased ) {
				unset( $unreleased ); // Stale after 30 minutes; the first failure is the one to report.
			}
			throw $failure;
		}
		return $run;
	}

	/**
	 * Continues a forget from its checkpoint.
	 *
	 * @param Repository      $repository Repository.
	 * @param string          $work_dir   Directory with the checkpoint.
	 * @param Identity        $identity   Host name of this site.
	 * @param Clock           $clock      Time source.
	 * @param RetentionPolicy $policy     Policy, used when all snapshots are loaded.
	 * @param list<string>    $tags       Tags.
	 * @return self Run.
	 * @throws FormatException If the checkpoint is unusable.
	 */
	public static function resume( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RetentionPolicy $policy, array $tags ): self {
		$json  = is_file( $work_dir . '/state.json' ) ? file_get_contents( $work_dir . '/state.json' ) : false;
		$state = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $state ) || self::STATE_VERSION !== ( $state['version'] ?? null ) ) {
			FormatException::raise( 'The forget checkpoint is missing or from another version.' );
		}
		$fields = [];
		foreach ( $state as $key => $value ) {
			$fields[ (string) $key ] = $value;
		}
		return new self( $repository, $work_dir, $identity, $clock, $policy, $tags, $fields );
	}

	/**
	 * Works until the forget is finished or the deadline has passed; at least one step per call.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when finished and the lock is released.
	 */
	public function advance( float $deadline ): bool {
		if ( 'done' === $this->state['phase'] ) {
			return true;
		}
		$this->keep_lock();
		do {
			match ( $this->state['phase'] ) {
				'load'   => $this->load_one(),
				'delete' => $this->delete_one(),
				default  => $this->finish(),
			};
		} while ( 'done' !== $this->state['phase'] && microtime( true ) < $deadline );
		$this->persist();
		return 'done' === $this->state['phase'];
	}

	/**
	 * Releases the lock and deletes the checkpoint of an abandoned forget.
	 */
	public function abort(): void {
		if ( '' !== $this->state['lock'] ) {
			$this->locking->release( Id::from_hex( $this->state['lock'] ) );
			$this->state['lock'] = '';
		}
		Files::remove_tree( $this->work_dir );
	}

	/**
	 * Outcome so far.
	 *
	 * @return array{snapshots: int, keep: int, remove: int, removed: int} Counts of this site's snapshots.
	 */
	public function result(): array {
		$considered = count( $this->state['snapshots'] );
		$remove     = count( $this->state['remove'] );
		return [
			'snapshots' => $considered,
			'keep'      => $considered - $remove,
			'remove'    => $remove,
			'removed'   => $this->state['removed'],
		];
	}

	/**
	 * Refreshes the lock, or takes a new one if it disappeared or is older than 30 minutes, when
	 * other clients no longer honour it.
	 */
	private function keep_lock(): void {
		$lock = $this->state['lock'];
		if ( '' === $lock || null === $this->repository->backend()->size( ObjectType::Locks, $lock ) || $this->locking->lapsed( $this->state['locked_at'] ) ) {
			$this->state['lock']      = $this->locking->acquire( true )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
			return;
		}
		if ( $this->locking->due( $this->state['locked_at'] ) ) {
			$this->state['lock']      = $this->locking->refresh( Id::from_hex( $lock ), true )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
		}
	}

	/**
	 * Loads one snapshot and keeps it if it belongs to this site; decides after the last one.
	 */
	private function load_one(): void {
		$name = $this->state['names'][ $this->state['position'] ] ?? null;
		if ( null === $name ) {
			$this->decide();
			return;
		}
		++$this->state['position'];
		try {
			$json = $this->repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $name ) );
		} catch ( NotFound ) {
			// Removed by someone else in the meantime: nothing left to decide about.
			return;
		}
		$snapshot = Snapshot::from_json( $json );
		if ( $snapshot->hostname === $this->identity->hostname && $snapshot->has_tags( $this->tags ) ) {
			$this->state['snapshots'][ $name ] = $json;
		}
	}

	/**
	 * Applies the policy to the selected snapshots as one group, as restic does with
	 * --group-by host: they all have this site's host name and the required tags.
	 */
	private function decide(): void {
		$snapshots = array_map( Snapshot::from_json( ... ), $this->state['snapshots'] );
		$remove    = [];
		foreach ( Forget::group( $snapshots, true, false, false ) as $group ) {
			array_push( $remove, ...Forget::apply( $group, $this->policy, $this->clock->now() )['remove'] );
		}
		sort( $remove );
		$this->state['remove']   = $remove;
		$this->state['position'] = 0;
		$this->state['phase']    = 'delete';
	}

	/**
	 * Deletes one removed snapshot file.
	 */
	private function delete_one(): void {
		$name = $this->state['remove'][ $this->state['position'] ] ?? null;
		if ( null === $name ) {
			$this->finish();
			return;
		}
		$this->repository->backend()->delete( ObjectType::Snapshots, $name );
		++$this->state['position'];
		++$this->state['removed'];
	}

	/**
	 * Releases the lock.
	 */
	private function finish(): void {
		if ( '' !== $this->state['lock'] ) {
			$this->locking->release( Id::from_hex( $this->state['lock'] ) );
			$this->state['lock'] = '';
		}
		$this->state['phase'] = 'done';
	}

	/**
	 * Writes the checkpoint atomically.
	 */
	private function persist(): void {
		Files::write_atomic( $this->work_dir . '/state.json', json_encode( [ 'version' => self::STATE_VERSION ] + $this->state, JSON_THROW_ON_ERROR ) );
	}
}
