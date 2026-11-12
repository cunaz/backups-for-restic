<?php
/**
 * Resumable selected-file restore into private staging.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Support\Throttle;

defined( 'ABSPATH' ) || exit;

/**
 * Plans before writing, preserves selected paths, and records links instead of creating them.
 * A private caller-owned target and work directory are required (ADR 0036).
 */
final class RestoreRun {

	/**
	 * Index loaded under the current lease.
	 *
	 * @var IndexSet|null
	 */
	private ?IndexSet $index = null;
	/**
	 * ID of the one cached directory.
	 *
	 * @var string|null
	 */
	private ?string $tree_id = null;

	/**
	 * Path for which the cached directory was validated.
	 *
	 * @var string|null
	 */
	private ?string $tree_dir = null;

	/**
	 * Only one decoded directory is retained.
	 *
	 * @var list<Node>
	 */
	private array $nodes = [];

	/**
	 * Repository lock owner for this run.
	 *
	 * @var Locking
	 */
	private readonly Locking $locking;

	/**
	 * Reports available space without allocating a payload-sized buffer.
	 *
	 * @var \Closure(string): (float|false)
	 */
	private readonly \Closure $free_space;

	/**
	 * Creates an instance without contacting the repository.
	 *
	 * @param Repository                             $repository Opened repository.
	 * @param State                                  $state Checkpoint.
	 * @param Clock                                  $clock Lease clock.
	 * @param Throttle|null                          $throttle Optional CPU pacing.
	 * @param (\Closure(string): (float|false))|null $free_space Disk-space probe; native by default.
	 */
	private function __construct(
		private readonly Repository $repository,
		private State $state,
		private readonly Clock $clock,
		private readonly ?Throttle $throttle,
		?\Closure $free_space
	) {
		$this->locking    = new Locking( $repository, new Identity( $state->hostname ), $clock, $state->pid );
		$this->free_space = $free_space ?? disk_free_space( ... );
		$this->verify_state();
	}

	/**
	 * Records the identity locally before taking a repository lock on the first advance().
	 *
	 * @param Repository                             $repository Opened repository.
	 * @param Id                                     $snapshot Snapshot to restore.
	 * @param string                                 $target Existing empty, private staging directory.
	 * @param string                                 $work_dir Separate private directory for checkpoints and the report.
	 * @param string|list<string>                    $selection Absolute snapshot paths, or one path for compatibility.
	 * @param Identity|null                          $identity Site identity for repository locks.
	 * @param Clock|null                             $clock Lease clock.
	 * @param Throttle|null                          $throttle Optional CPU pacing.
	 * @param (\Closure(string): (float|false))|null $free_space Disk-space probe.
	 * @return self New run; no file has been restored yet.
	 * @throws InvalidArgument If restore inputs or directories are unsuitable.
	 */
	public static function start( Repository $repository, Id $snapshot, string $target, string $work_dir, string|array $selection = '/', ?Identity $identity = null, ?Clock $clock = null, ?Throttle $throttle = null, ?\Closure $free_space = null ): self {
		$selected    = new Selection( is_string( $selection ) ? [ $selection ] : $selection );
		$destination = Files::bind( $target );
		if ( ! Files::exists( $work_dir ) && ! mkdir( $work_dir, 0700, true ) ) {
			throw new InvalidArgument( 'The restore working directory cannot be created.' );
		}
		$work       = Files::bind( $work_dir );
		$target_key = Names::key( '\\' === DIRECTORY_SEPARATOR ? str_replace( '\\', '/', $destination['path'] ) : $destination['path'] );
		$work_key   = Names::key( '\\' === DIRECTORY_SEPARATOR ? str_replace( '\\', '/', $work['path'] ) : $work['path'] );
		if ( $target_key === $work_key || str_starts_with( $target_key . '/', $work_key . '/' ) || str_starts_with( $work_key . '/', $target_key . '/' ) ) {
			throw new InvalidArgument( 'Restore staging and working directories must be separate.' );
		}
		$handle = Files::lock( $work['path'] );
		try {
			Files::empty_directory( $destination['path'] );
			Files::empty_directory( $work['path'], true );
			$state = new State( $repository->config()->id, $snapshot, $selected, $destination, $work, ( $identity ?? new Identity( 'backups-for-restic-restore' ) )->hostname, random_int( 1, 2147483647 ) );
			$run   = new self( $repository, $state, $clock ?? new SystemClock(), $throttle, $free_space );
			Files::persist( $state );
			return $run;
		} finally {
			Files::close( $handle );
		}
	}

	/**
	 * Reads only local state; repository data is read under the next tick's shared lock.
	 *
	 * @param Repository                             $repository Opened repository.
	 * @param string                                 $work_dir Private working directory.
	 * @param Clock|null                             $clock Lease clock.
	 * @param Throttle|null                          $throttle Optional CPU pacing.
	 * @param (\Closure(string): (float|false))|null $free_space Disk-space probe.
	 * @return self Resumed run.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function resume( Repository $repository, string $work_dir, ?Clock $clock = null, ?Throttle $throttle = null, ?\Closure $free_space = null ): self {
		$work  = Files::bind( $work_dir );
		$state = Files::read( $work['path'] );
		if ( $work !== $state->work ) {
			throw new FormatException( 'The restore checkpoint belongs to another working directory.' );
		}
		return new self( $repository, $state, $clock ?? new SystemClock(), $throttle, $free_space );
	}

	/**
	 * Performs at least one resumable unit, then stops at the next boundary after the deadline.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool Whether the complete selected payload has been staged.
	 * @throws InvalidArgument If restore inputs or directories are unsuitable.
	 * @throws \Throwable Preserves the original failure after releasing owned resources.
	 */
	public function advance( float $deadline ): bool {
		Files::verify( $this->state->work );
		$handle = Files::lock( $this->state->work['path'] );
		try {
			$this->reload();
			Files::verify( $this->state->target );
			if ( 'aborted' === $this->state->phase ) {
				throw new InvalidArgument( 'An aborted restore cannot continue.' );
			}
			if ( 'complete' === $this->state->phase ) {
				$this->release();
				return true;
			}
			$this->keep_lock( true );
			$this->prepare();
			$this->rewind_report();
			do {
				$this->keep_lock();
				$this->step();
				Files::persist( $this->state );
				$this->throttle?->pause();
			} while ( 'complete' !== $this->state->phase && microtime( true ) < $deadline );
			if ( 'complete' === $this->state->phase ) {
				$this->release();
			}
			return 'complete' === $this->state->phase;
		} catch ( \Throwable $error ) {
			// Keep the last committed cursors. A failed cleanup can still be retried by pid.
			try {
				$this->locking->release( null === $this->state->lock ? null : Id::from_hex( $this->state->lock ) );
			} catch ( \Throwable ) {
				// The checkpoint retains ownership; abort() retries even an unrecorded lock.
				$this->index = null;
				throw $error;
			}
			$this->index = null;
			throw $error;
		} finally {
			Files::close( $handle );
		}
	}

	/**
	 * Ends the run and releases even a lock left by a killed acquire/refresh. No index needed.
	 *
	 * @throws \Throwable Preserves a failed abort checkpoint even if lock cleanup fails too.
	 */
	public function abort(): void {
		Files::verify( $this->state->work );
		$handle = Files::lock( $this->state->work['path'] );
		try {
			$this->reload();
			try {
				if ( 'complete' !== $this->state->phase ) {
					$this->state->phase = 'aborted';
					Files::persist( $this->state );
				}
			} catch ( \Throwable $error ) {
				try {
					$this->release();
				} catch ( \Throwable ) {
					// Preserve the failed abort checkpoint; ownership cleanup can be retried.
					throw $error;
				}
				throw $error;
			}
			$this->release();
		} finally {
			Files::close( $handle );
		}
	}

	/**
	 * Returns committed progress and totals once planning has finished.
	 *
	 * @return array{phase: string, files: int, bytes: int, total_files: int, total_bytes: int, skipped: int} Progress.
	 */
	public function progress(): array {
		return [
			'phase'       => $this->state->phase,
			'files'       => $this->state->files,
			'bytes'       => $this->state->bytes,
			'total_files' => $this->state->planned_files,
			'total_bytes' => $this->state->planned_bytes,
			'skipped'     => $this->state->skipped,
		];
	}

	/**
	 * Rereads state under the local lock, so an older object cannot overwrite a newer tick.
	 *
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function reload(): void {
		$latest = Files::read( $this->state->work['path'] );
		if ( $latest->pid !== $this->state->pid || $latest->hostname !== $this->state->hostname || $latest->work !== $this->state->work || $latest->target !== $this->state->target || ! $latest->snapshot->equals( $this->state->snapshot ) || $latest->selection->paths !== $this->state->selection->paths ) {
			throw new FormatException( 'The restore checkpoint identity has changed.' );
		}
		if ( $latest->lock !== $this->state->lock ) {
			$this->index   = null;
			$this->tree_id = null;
		}
		$this->state = $latest;
		$this->verify_state();
	}

	/**
	 * Rejects a checkpoint supplied to a different repository.
	 *
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function verify_state(): void {
		if ( $this->state->repository !== $this->repository->config()->id ) {
			throw new FormatException( 'The restore checkpoint belongs to another repository.' );
		}
		Files::verify( $this->state->work );
	}

	/**
	 * Refreshes a continuous lease, or reacquires it only at a tick boundary.
	 *
	 * @param bool $allow_gap Whether old in-memory repository locations may be discarded.
	 * @throws RepositoryChanged If the selected snapshot or trusted lease has been lost.
	 */
	private function keep_lock( bool $allow_gap = false ): void {
		$lock = $this->state->lock;
		if ( null !== $lock && ! $this->locking->lapsed( $this->state->locked_at ) && null !== $this->repository->backend()->size( ObjectType::Locks, $lock ) ) {
			if ( $this->locking->lapsed( $this->state->locked_at ) ) {
				throw new RepositoryChanged( 'A restore lock check exceeded the trusted lease.' );
			}
			if ( $this->locking->due( $this->state->locked_at ) ) {
				$taken                  = $this->clock->now()[0];
				$this->state->lock      = $this->locking->refresh( Id::from_hex( $lock ), false )->hex();
				$this->state->locked_at = $taken;
				Files::persist( $this->state );
				if ( $this->locking->lapsed( $taken ) ) {
					throw new RepositoryChanged( 'Refreshing the restore lock exceeded the trusted lease.' );
				}
			}
			return;
		}
		if ( ! $allow_gap ) {
			throw new RepositoryChanged( 'The restore repository lock was lost during a read; resume the run to revalidate it.' );
		}
		$taken                  = $this->clock->now()[0];
		$this->state->lock      = $this->locking->acquire( false )->hex();
		$this->state->locked_at = $taken;
		Files::persist( $this->state );
		$this->index   = null;
		$this->tree_id = null;
		$this->nodes   = [];
		if ( null === $this->repository->backend()->size( ObjectType::Snapshots, $this->state->snapshot->hex() ) ) {
			throw new RepositoryChanged( 'The selected snapshot no longer exists.' );
		}
		$this->keep_lock();
	}

	/** Loads the index and root tree only after taking a lock. */
	private function prepare(): void {
		$this->index ??= $this->repository->load_index( keep_lock: $this->keep_lock( ... ) );
		$this->keep_lock();
		if ( null === $this->state->tree ) {
			$snapshot = Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, $this->state->snapshot ) );
			$this->keep_lock();
			$this->state->tree = $snapshot->tree->hex();
			$this->reset_stack();
			if ( in_array( '/', $this->state->selection->paths, true ) ) {
				$this->state->found = [ '/' ];
			}
			Files::persist( $this->state );
		}
	}

	/** Releases ownership before clearing the checkpoint's lock reference. */
	private function release(): void {
		$this->locking->release( null === $this->state->lock ? null : Id::from_hex( $this->state->lock ) );
		$this->state->lock = null;
		$this->index       = null;
		$this->tree_id     = null;
		$this->nodes       = [];
		Files::persist( $this->state );
	}

	/**
	 * Handles one entry or finishes one directory, without an unbounded in-memory plan.
	 *
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function step(): void {
		$depth = count( $this->state->stack ) - 1;
		if ( $depth < 0 ) {
			$this->finish_pass();
			return;
		}
		$frame = $this->state->stack[ $depth ];
		$nodes = $this->tree( $frame['tree'], $frame['dir'] );
		if ( $frame['next'] > count( $nodes ) ) {
			throw new FormatException( 'The restore directory cursor is outside its tree.' );
		}
		if ( $frame['next'] === count( $nodes ) ) {
			if ( 'restore' === $this->state->phase && '' !== $frame['dir'] ) {
				$path = Files::destination( $this->state, $frame['dir'] );
				$this->metadata( $path, $frame['mode'], $frame['mtime'], true );
			}
			array_pop( $this->state->stack );
			return;
		}
		$node     = $nodes[ $frame['next'] ];
		$relative = '' === $frame['dir'] ? $node->name : $frame['dir'] . '/' . $node->name;
		if ( ! $this->state->selection->visits( '/' . $relative ) ) {
			++$this->state->stack[ $depth ]['next'];
			return;
		}
		if ( 'plan' === $this->state->phase ) {
			if ( ! $this->plan_node( $node, $relative ) ) {
				return;
			}
		} else {
			if ( NodeType::File === $node->type ) {
				$this->restore_file( $node, $relative, $depth );
				return;
			}
			if ( NodeType::Dir === $node->type ) {
				$this->create_directory( $relative );
			} else {
				++$this->state->skipped;
			}
			$this->report( $relative, $node );
		}
		++$this->state->stack[ $depth ]['next'];
		if ( NodeType::Dir === $node->type ) {
			if ( null === $node->subtree || count( $this->state->stack ) >= 257 ) {
				throw new FormatException( 'A selected directory lacks a tree or exceeds the restore depth limit.' );
			}
			$this->state->stack[] = [
				'tree'  => $node->subtree->hex(),
				'dir'   => $relative,
				'next'  => 0,
				'mode'  => $node->mode,
				'mtime' => $node->mtime,
			];
		}
	}

	/**
	 * Counts a selected node and checks indexed file sizes before any payload is written.
	 *
	 * @param Node   $node Selected node.
	 * @param string $relative Original relative path.
	 * @return bool Whether this entry has been completely counted.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function plan_node( Node $node, string $relative ): bool {
		if ( '' !== $node->error ) {
			throw new FormatException( 'A selected snapshot entry records a backup error.' );
		}
		Rfc3339::parse( $node->mtime );
		$cursor  = $this->state->planning ?? [
			'path'  => $relative,
			'blob'  => 0,
			'bytes' => 0,
		];
		$content = NodeType::File === $node->type ? ( $node->content ?? [] ) : [];
		if ( $cursor['path'] !== $relative || $cursor['blob'] > count( $content ) || $cursor['bytes'] > $node->size || ( null !== $this->state->planning && NodeType::File !== $node->type ) ) {
			throw new FormatException( 'The restore planning cursor does not match its snapshot entry.' );
		}
		$end = min( count( $content ), $cursor['blob'] + BlobBatch::MAX_BLOBS );
		for ( $number = $cursor['blob']; $number < $end; ++$number ) {
			$entry = $this->current_index()->find( $content[ $number ], BlobType::Data )?->entry;
			if ( null === $entry || $entry->length < Cipher::OVERHEAD || $entry->length > RabinChunker::MAX_SIZE + Cipher::OVERHEAD || $entry->uncompressed_length > RabinChunker::MAX_SIZE ) {
				throw new FormatException( 'A selected file references an absent or oversized blob.' );
			}
			$cursor['bytes'] = self::add( $cursor['bytes'], 0 === $entry->uncompressed_length ? $entry->length - Cipher::OVERHEAD : $entry->uncompressed_length );
			$cursor['blob']  = $number + 1;
			$this->throttle?->pause();
			if ( 0 === $number % BlobBatch::MAX_BLOBS ) {
				$this->keep_lock();
			}
		}
		$this->keep_lock();
		if ( $cursor['blob'] < count( $content ) ) {
			$this->state->planning = $cursor;
			return false;
		}
		if ( NodeType::File === $node->type && $cursor['bytes'] !== $node->size ) {
			throw new FormatException( 'A selected file size does not match its indexed content.' );
		}
		// No lease callback may publish partially counted entries above this boundary.
		$this->state->planning = null;
		if ( in_array( '/' . $relative, $this->state->selection->paths, true ) ) {
			$this->state->found[] = '/' . $relative;
		}
		$this->state->planned_entries = self::add( $this->state->planned_entries, 1 );
		if ( NodeType::File === $node->type ) {
			$this->state->planned_files = self::add( $this->state->planned_files, 1 );
			$this->state->planned_bytes = self::add( $this->state->planned_bytes, $cursor['bytes'] );
		}
		return true;
	}

	/**
	 * Finishes preflight before writing; completion checks actual bytes against the plan.
	 *
	 * @throws InvalidArgument If restore inputs or directories are unsuitable.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function finish_pass(): void {
		if ( null !== $this->state->planning ) {
			throw new FormatException( 'The restore traversal ended with an incomplete planning cursor.' );
		}
		if ( 'plan' === $this->state->phase ) {
			if ( [] !== array_diff( $this->state->selection->paths, $this->state->found ) ) {
				throw new InvalidArgument( 'A selected path does not exist in the snapshot.' );
			}
			Files::empty_directory( $this->state->target['path'] );
			$free = ( $this->free_space )( $this->state->target['path'] );
			// Round each entry up generously and reserve room for the report/checkpoint.
			$needed = $this->state->planned_bytes + (float) $this->state->planned_entries * 8192 + 16777216;
			if ( false === $free || ! is_finite( $free ) || $free < $needed ) {
				throw new FormatException( 'The staging filesystem has insufficient or unknown free space for this restore.' );
			}
			$work_free = ( $this->free_space )( $this->state->work['path'] );
			if ( false === $work_free || ! is_finite( $work_free ) || $work_free < (float) $this->state->planned_entries * 8192 + 16777216 ) {
				throw new FormatException( 'The working filesystem has insufficient or unknown free space for the restore report.' );
			}
			$this->state->phase = 'restore';
			$this->reset_stack();
			return;
		}
		if ( $this->state->files !== $this->state->planned_files || $this->state->bytes !== $this->state->planned_bytes || null !== $this->state->file || null !== $this->state->creating ) {
			throw new FormatException( 'The restored payload does not match its planned totals.' );
		}
		$this->state->phase = 'complete';
	}

	/**
	 * Starts the next traversal from the immutable root tree.
	 *
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function reset_stack(): void {
		if ( null === $this->state->tree ) {
			throw new FormatException( 'The restore has no snapshot tree.' );
		}
		$this->state->stack = [
			[
				'tree'  => $this->state->tree,
				'dir'   => '',
				'next'  => 0,
				'mode'  => 0,
				'mtime' => Node::ZERO_TIME,
			],
		];
	}

	/**
	 * Loads and validates one directory; a different tree evicts the previous one.
	 *
	 * @param string $hex Tree ID.
	 * @param string $dir Raw parent path.
	 * @return list<Node> Validated nodes.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function tree( string $hex, string $dir ): array {
		if ( $this->tree_id === $hex && $this->tree_dir === $dir ) {
			return $this->nodes;
		}
		if ( $this->tree_id !== $hex ) {
			$this->nodes    = [];
			$this->tree_id  = null;
			$this->tree_dir = null;
			$this->nodes    = $this->repository->load_tree( $this->current_index(), Id::from_hex( $hex ) );
			$this->keep_lock();
			$this->tree_id = $hex;
		}
		$keys = [];
		foreach ( $this->nodes as $node ) {
			Names::validate( $node->name, false );
			$relative = '' === $dir ? $node->name : $dir . '/' . $node->name;
			if ( ! $this->state->selection->visits( '/' . $relative ) ) {
				continue;
			}
			Names::validate( $node->name );
			if ( strlen( $relative ) > 4095 || substr_count( $relative, '/' ) >= 256 ) {
				throw new FormatException( 'A selected snapshot path exceeds the restore path limit.' );
			}
			$key = Names::key( $node->name );
			if ( isset( $keys[ $key ] ) ) {
				throw new FormatException( 'Selected snapshot names collide on the destination filesystem.' );
			}
			$keys[ $key ] = true;
		}
		$this->tree_dir = $dir;
		return $this->nodes;
	}

	/**
	 * Records intent before creating a directory, allowing only that intent to be replayed.
	 *
	 * @param string $relative Raw relative destination.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function create_directory( string $relative ): void {
		$path = Files::destination( $this->state, $relative );
		if ( null === $this->state->creating ) {
			if ( Files::exists( $path ) ) {
				throw new FormatException( 'An unexpected entry exists at a restore destination.' );
			}
			$this->state->creating = $relative;
			Files::persist( $this->state );
		} elseif ( $this->state->creating !== $relative ) {
			throw new FormatException( 'The restore directory intent does not match its traversal.' );
		}
		if ( ! Files::exists( $path ) && ! mkdir( $path, 0700 ) ) {
			throw new FormatException( 'A staging directory cannot be created.' );
		}
		if ( ( Files::stat( $path )['mode'] & 0170000 ) !== 0040000 ) {
			throw new FormatException( 'A staging directory was replaced with a link or file.' );
		}
		$this->state->creating = null;
	}

	/**
	 * Writes one bounded group and checkpoints only fully flushed, authenticated bytes.
	 *
	 * @param Node   $node File node.
	 * @param string $relative Raw relative destination.
	 * @param int    $depth Traversal frame.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function restore_file( Node $node, string $relative, int $depth ): void {
		$path   = Files::destination( $this->state, $relative );
		$cursor = $this->state->file;
		if ( null === $cursor ) {
			if ( Files::exists( $path ) ) {
				throw new FormatException( 'An unexpected entry exists at a restore file destination.' );
			}
			$cursor            = [
				'path'    => $relative,
				'blob'    => 0,
				'written' => 0,
				'dev'     => null,
				'ino'     => null,
			];
			$this->state->file = $cursor;
			Files::persist( $this->state );
		}
		$committed = $cursor['written'];
		$content   = $node->content ?? [];
		if ( $cursor['path'] !== $relative || $cursor['blob'] > count( $content ) || $cursor['written'] > $node->size ) {
			throw new FormatException( 'The restore file cursor does not match its snapshot entry.' );
		}
		$handle = Files::open( $path, ! Files::exists( $path ) && null === $cursor['dev'] );
		try {
			$stat = Files::regular( $path );
			if ( null === $cursor['dev'] ) {
				if ( 0 !== $stat['size'] || 0 !== $cursor['written'] || 0 !== $cursor['blob'] ) {
					throw new FormatException( 'A new staging file has unexpected content.' );
				}
				$cursor['dev']     = $stat['dev'];
				$cursor['ino']     = $stat['ino'];
				$this->state->file = $cursor;
				Files::persist( $this->state );
			} elseif ( $stat['dev'] !== $cursor['dev'] || $stat['ino'] !== $cursor['ino'] ) {
				throw new FormatException( 'The partial restore file was replaced.' );
			}
			Files::rewind( $handle, $cursor['written'] );
			if ( $cursor['blob'] < count( $content ) ) {
				foreach ( BlobBatch::read( $this->repository, $this->current_index(), $content, $cursor['blob'] ) as $number => $plain ) {
					$this->keep_lock();
					if ( strlen( $plain ) > $node->size - $cursor['written'] ) {
						throw new FormatException( 'Restored bytes exceed the snapshot file size.' );
					}
					Files::write( $handle, $plain );
					$cursor['written'] += strlen( $plain );
					$cursor['blob']     = $number + 1;
					$this->throttle?->pause();
				}
			}
			Files::flush( $handle );
		} finally {
			Files::close( $handle );
		}
		$this->state->bytes = self::add( $this->state->bytes, $cursor['written'] - $committed );
		$this->state->file  = $cursor;
		Files::persist( $this->state );
		if ( $cursor['blob'] === count( $content ) ) {
			if ( $cursor['written'] !== $node->size ) {
				throw new FormatException( 'The restored file size differs from its snapshot entry.' );
			}
			$this->metadata( $path, $node->mode, $node->mtime, false );
			$this->report( $relative, $node );
			$this->state->file = null;
			++$this->state->files;
			++$this->state->stack[ $depth ]['next'];
		}
	}

	/**
	 * Keeps owner access for later downloads/apply; original metadata remains in the report.
	 *
	 * @param string $path Verified destination.
	 * @param int    $mode Snapshot Go mode.
	 * @param string $mtime Snapshot timestamp.
	 * @param bool   $directory Whether this is a directory.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function metadata( string $path, int $mode, string $mtime, bool $directory ): void {
		$stat = Files::stat( $path );
		if ( ( $stat['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ( ! $directory && 1 !== $stat['nlink'] ) ) {
			throw new FormatException( 'A restored entry was replaced before its metadata was applied.' );
		}
		$permissions = ( FileMode::to_posix_permissions( $mode ) & 0777 ) | ( $directory ? 0700 : 0600 );
		if ( ! chmod( $path, $permissions ) || ! touch( $path, Rfc3339::parse( $mtime )[0] ) ) {
			throw new FormatException( 'Restored entry metadata cannot be applied.' );
		}
	}

	/**
	 * Removes an uncommitted report suffix once at the beginning of a tick.
	 *
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function rewind_report(): void {
		$path = $this->state->work['path'] . '/entries.jsonl';
		if ( ! Files::exists( $path ) && 0 !== $this->state->report_bytes ) {
			throw new FormatException( 'The committed restore entry report is missing.' );
		}
		$handle = Files::open( $path, ! Files::exists( $path ) );
		try {
			Files::rewind( $handle, $this->state->report_bytes );
		} finally {
			Files::close( $handle );
		}
	}

	/**
	 * Appends bounded metadata outside the payload, with a checkpointed byte offset.
	 *
	 * @param string $relative Original path below the snapshot root.
	 * @param Node   $node Original node, including skipped link/special-file metadata.
	 */
	private function report( string $relative, Node $node ): void {
		$line   = GoJson::encode(
			[
				'path'        => GoQuote::quote( '/' . $relative ),
				'type'        => $node->type->value,
				'mode'        => $node->mode,
				'mtime'       => $node->mtime,
				'size'        => $node->size,
				'link_target' => GoQuote::quote( $node->link_target ),
			]
		) . "\n";
		$handle = Files::open( $this->state->work['path'] . '/entries.jsonl' );
		try {
			Files::rewind( $handle, $this->state->report_bytes );
			Files::write( $handle, $line );
			Files::flush( $handle );
		} finally {
			Files::close( $handle );
		}
		$this->state->report_bytes = self::add( $this->state->report_bytes, strlen( $line ) );
	}

	/**
	 * Returns the index only after prepare() acquired and checked the lease.
	 *
	 * @return IndexSet Current index.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private function current_index(): IndexSet {
		if ( null === $this->index ) {
			throw new FormatException( 'The restore has no index under its current lock.' );
		}
		return $this->index;
	}

	/**
	 * Prevents an untrusted total from overflowing into a float.
	 *
	 * @param int $left Current nonnegative total.
	 * @param int $right Nonnegative addition.
	 * @return int Sum.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function add( int $left, int $right ): int {
		if ( $right < 0 || $left > PHP_INT_MAX - $right ) {
			throw new FormatException( 'The restore exceeds the supported counter range.' );
		}
		return $left + $right;
	}
}
