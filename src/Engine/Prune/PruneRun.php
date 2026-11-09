<?php
/**
 * Prune without repacking.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Prune;

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
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the packs that no snapshot uses, as restic's prune does for completely unused packs
 * (ADR 0022). A pack that still holds one used blob stays, so nothing is downloaded or
 * rewritten; `restic prune` on another computer reclaims the rest.
 *
 * The run works in phases, each resumable in short ticks:
 *
 * 1. mark: walks the trees of all snapshots and records every pack that holds a used blob.
 * 2. plan: reads the index files and records the unused packs and the index files that list
 *    them.
 * 3. rewrite: writes each of those index files again without the unused packs.
 * 4. delete_indexes: deletes the old index files.
 * 5. delete_packs: deletes the unused packs.
 *
 * Every state between two requests is a repository restic accepts: new index files are written
 * before the old ones go, and packs go last. The run holds an exclusive lock from the first tree
 * it reads to the last pack it deletes. If the lock disappeared or was not refreshed in time,
 * another client may have added snapshots that use an unused pack, so the run starts again with
 * marking; until then it had deleted only packs that no snapshot could use.
 *
 * Packs that hold used and unused blobs stay. The run estimates the space they waste, which
 * only a full `restic prune` from another computer reclaims: the bytes of all blobs in kept
 * packs minus the bytes of the used blobs. Trees are counted exactly; data blobs are counted
 * once with the help of a Bloom filter of about two bytes per indexed blob, because an exact
 * set of every used blob would not fit the memory of small hosts. A false positive of the
 * filter leaves a used blob uncounted, so the estimate can only be slightly too high.
 */
final class PruneRun {

	public const int STATE_VERSION = 1;

	/** Locks older than 30 minutes are stale for restic; the run refreshes well before. */
	public const int CONTINUITY_SECONDS = 1500;

	/** At most this many IDs of missing blobs are kept for the error report. */
	private const int MISSING_EXAMPLES = 5;

	/** Smallest and largest Bloom filter, in bits: 8 KiB and 8 MiB. */
	private const int FILTER_MIN_BITS = 65536;

	private const int FILTER_MAX_BITS = 67108864;

	/**
	 * Lock handling.
	 *
	 * @var Locking
	 */
	private Locking $locking;

	/**
	 * Number written into lock files.
	 *
	 * @var int
	 */
	private int $pid;

	/**
	 * Hex ID of the held lock, or ''.
	 *
	 * @var string
	 */
	private string $lock;

	/**
	 * Unix time the lock was written.
	 *
	 * @var int
	 */
	private int $locked_at;

	/**
	 * Current phase: mark, plan, rewrite, delete_indexes, delete_packs or done.
	 *
	 * @var string
	 */
	private string $phase;

	/**
	 * Hex IDs of trees still to walk.
	 *
	 * @var list<string>
	 */
	private array $frontier;

	/**
	 * Hex IDs of walked trees.
	 *
	 * @var array<string, true>
	 */
	private array $seen;

	/**
	 * Hex IDs of packs that hold a used blob.
	 *
	 * @var array<string, true>
	 */
	private array $live;

	/**
	 * Bloom filter of the data blobs counted in used_bytes; empty if the estimate is unavailable.
	 *
	 * @var string
	 */
	private string $filter;

	/**
	 * Bytes of the used blobs, each counted once.
	 *
	 * @var int
	 */
	private int $used_bytes;

	/**
	 * Bytes of all blobs in kept packs, from the index files.
	 *
	 * @var int
	 */
	private int $kept_bytes;

	/**
	 * Hex IDs of kept packs already counted in kept_bytes.
	 *
	 * @var array<string, true>
	 */
	private array $counted;

	/**
	 * Number of used blobs that are missing from the index.
	 *
	 * @var int
	 */
	private int $missing;

	/**
	 * Some missing blob IDs, for the error report.
	 *
	 * @var list<string>
	 */
	private array $missing_examples;

	/**
	 * Number of snapshots when marking started.
	 *
	 * @var int
	 */
	private int $snapshots;

	/**
	 * Index files in the order of the plan phase.
	 *
	 * @var list<string>
	 */
	private array $index_files;

	/**
	 * Position within the list of the current phase.
	 *
	 * @var int
	 */
	private int $position;

	/**
	 * Unused packs with their size in bytes (-1 if the server does not report sizes).
	 *
	 * @var array<string, int>
	 */
	private array $unused;

	/**
	 * Index files that list an unused pack.
	 *
	 * @var list<string>
	 */
	private array $affected;

	/**
	 * Index files written without the unused packs.
	 *
	 * @var int
	 */
	private int $index_files_written;

	/**
	 * Packs deleted so far.
	 *
	 * @var int
	 */
	private int $packs_deleted;

	/**
	 * Bytes of the deleted packs, as far as the server reported sizes.
	 *
	 * @var int
	 */
	private int $bytes_deleted;

	/**
	 * Number of times marking started again because the lock was lost.
	 *
	 * @var int
	 */
	private int $restarts;

	/**
	 * Creates the run from a state.
	 *
	 * @param Repository           $repository Repository.
	 * @param IndexSet|null        $index      Current index; loaded from the server when needed.
	 * @param string               $work_dir   Directory for the checkpoint.
	 * @param Identity             $identity   Written into lock files.
	 * @param Clock                $clock      Time source.
	 * @param array<string, mixed> $state      Decoded checkpoint, or [] for a new run.
	 */
	private function __construct(
		private readonly Repository $repository,
		private ?IndexSet $index,
		private readonly string $work_dir,
		Identity $identity,
		private readonly Clock $clock,
		array $state
	) {
		$this->pid       = Decoded::int( $state['pid'] ?? null, random_int( 100000, 2147483647 ) );
		$this->locking   = new Locking( $repository, $identity, $clock, $this->pid );
		$this->lock      = Decoded::string( $state['lock'] ?? null );
		$this->locked_at = Decoded::int( $state['locked_at'] ?? null );
		$this->phase     = Decoded::string( $state['phase'] ?? null, 'mark' );
		$this->frontier  = Decoded::strings( $state['frontier'] ?? [] );
		$this->seen      = array_fill_keys( Decoded::strings( $state['seen'] ?? [] ), true );
		$this->live      = array_fill_keys( Decoded::strings( $state['live'] ?? [] ), true );
		try {
			$this->filter = Bytes::base64_decode( Decoded::string( $state['filter'] ?? null ) );
		} catch ( FormatException ) {
			// A checkpoint from before the estimate: the run continues without one.
			$this->filter = '';
		}
		$this->used_bytes          = Decoded::count( $state['used_bytes'] ?? null );
		$this->kept_bytes          = Decoded::count( $state['kept_bytes'] ?? null );
		$this->counted             = array_fill_keys( Decoded::strings( $state['counted'] ?? [] ), true );
		$this->missing             = Decoded::count( $state['missing'] ?? null );
		$this->missing_examples    = Decoded::strings( $state['missing_examples'] ?? [] );
		$this->snapshots           = Decoded::count( $state['snapshots'] ?? null );
		$this->index_files         = Decoded::strings( $state['index_files'] ?? [] );
		$this->position            = Decoded::count( $state['position'] ?? null );
		$this->unused              = [];
		$this->affected            = Decoded::strings( $state['affected'] ?? [] );
		$this->index_files_written = Decoded::count( $state['index_files_written'] ?? null );
		$this->packs_deleted       = Decoded::count( $state['packs_deleted'] ?? null );
		$this->bytes_deleted       = Decoded::count( $state['bytes_deleted'] ?? null );
		$this->restarts            = Decoded::count( $state['restarts'] ?? null );
		foreach ( is_array( $state['unused'] ?? null ) ? $state['unused'] : [] as $pack => $size ) {
			$this->unused[ (string) $pack ] = Decoded::int( $size, -1 );
		}
	}

	/**
	 * Starts a prune: refuses append-only repositories, takes the exclusive lock and lists the
	 * snapshots to walk.
	 *
	 * @param Repository    $repository Repository.
	 * @param string        $work_dir   Empty directory for the checkpoint.
	 * @param Identity      $identity   Written into lock files.
	 * @param Clock         $clock      Time source.
	 * @param RandomSource  $random     For the append-only probe.
	 * @param IndexSet|null $index      Current index, for example from the IndexCache.
	 * @return self Run.
	 * @throws InvalidArgument If the directory already holds a run or the repository has no snapshots.
	 * @throws AppendOnlyRefused If the server refuses deletions.
	 */
	public static function start( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RandomSource $random, ?IndexSet $index = null ): self {
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) && ! is_dir( $work_dir ) ) {
			InvalidArgument::raise( 'The working directory cannot be created.' );
		}
		if ( is_file( $work_dir . '/state.json' ) ) {
			InvalidArgument::raise( 'The working directory already holds a prune.' );
		}
		if ( AppendOnlyStatus::AppendOnly === AppendOnlyProbe::detect( $repository->backend(), $random ) ) {
			AppendOnlyRefused::raise( 'The repository is append-only; prune runs on another computer.' );
		}
		$run = new self( $repository, $index, $work_dir, $identity, $clock, [] );
		$run->begin();
		$run->persist();
		return $run;
	}

	/**
	 * Continues a prune from its checkpoint.
	 *
	 * @param Repository    $repository Repository.
	 * @param string        $work_dir   Directory with the checkpoint.
	 * @param Identity      $identity   Written into lock files.
	 * @param Clock         $clock      Time source.
	 * @param IndexSet|null $index      Current index, for example from the IndexCache.
	 * @return self Run.
	 * @throws FormatException If the checkpoint is unusable.
	 */
	public static function resume( Repository $repository, string $work_dir, Identity $identity, Clock $clock, ?IndexSet $index = null ): self {
		$json  = is_file( $work_dir . '/state.json' ) ? file_get_contents( $work_dir . '/state.json' ) : false;
		$state = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $state ) || self::STATE_VERSION !== ( $state['version'] ?? null ) ) {
			FormatException::raise( 'The prune checkpoint is missing or from another version.' );
		}
		$fields = [];
		foreach ( $state as $key => $value ) {
			$fields[ (string) $key ] = $value;
		}
		return new self( $repository, $index, $work_dir, $identity, $clock, $fields );
	}

	/**
	 * Works until the prune is finished or the deadline has passed. Each call does at least one
	 * unit of work, so a slow host still makes progress.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when finished and the lock is released.
	 * @throws FormatException If a snapshot uses a blob that is missing from the index.
	 */
	public function advance( float $deadline ): bool {
		if ( 'done' === $this->phase ) {
			return true;
		}
		$this->keep_lock();
		do {
			$this->step();
		} while ( 'done' !== $this->phase && microtime( true ) < $deadline );
		$this->persist();
		return 'done' === $this->phase;
	}

	/**
	 * Releases the lock and deletes the checkpoint of an abandoned prune. The repository stays
	 * valid in every phase.
	 */
	public function abort(): void {
		if ( '' !== $this->lock ) {
			$this->locking->release( Id::from_hex( $this->lock ) );
			$this->lock = '';
		}
		Files::remove_tree( $this->work_dir );
	}

	/**
	 * Progress for the dashboard.
	 *
	 * @return array{phase: string, snapshots: int, trees: int, pending_trees: int, unused_packs: int, packs_deleted: int, bytes_deleted: int} Progress.
	 */
	public function progress(): array {
		return [
			'phase'         => $this->phase,
			'snapshots'     => $this->snapshots,
			'trees'         => count( $this->seen ),
			'pending_trees' => count( $this->frontier ),
			'unused_packs'  => count( $this->unused ),
			'packs_deleted' => $this->packs_deleted,
			'bytes_deleted' => $this->bytes_deleted,
		];
	}

	/**
	 * Outcome once finished. reclaimable_bytes estimates the unused space in kept packs that
	 * only a full prune with repacking frees; -1 if unknown.
	 *
	 * @return array{packs_deleted: int, bytes_deleted: int, packs_kept: int, reclaimable_bytes: int, index_files_written: int, restarts: int} Result.
	 */
	public function result(): array {
		return [
			'packs_deleted'       => $this->packs_deleted,
			'bytes_deleted'       => $this->bytes_deleted,
			'packs_kept'          => count( $this->live ),
			'reclaimable_bytes'   => '' === $this->filter ? -1 : max( 0, $this->kept_bytes - $this->used_bytes ),
			'index_files_written' => $this->index_files_written,
			'restarts'            => $this->restarts,
		];
	}

	/**
	 * Takes the exclusive lock and starts marking from the trees of all snapshots.
	 *
	 * @throws InvalidArgument If the repository has no snapshots.
	 */
	private function begin(): void {
		$this->locking->remove_own_stale();
		$this->lock      = $this->locking->acquire( true )->hex();
		$this->locked_at = $this->clock->now()[0];

		$roots = [];
		foreach ( $this->repository->snapshots() as $snapshot ) {
			$roots[ $snapshot->tree->hex() ] = true;
		}
		if ( [] === $roots ) {
			// An empty listing would make every pack unused; never delete everything.
			$this->locking->release( Id::from_hex( $this->lock ) );
			$this->lock = '';
			InvalidArgument::raise( 'The repository has no snapshots; prune would delete everything.', [ 'code' => 'no_snapshots' ] );
		}

		$this->phase               = 'mark';
		$this->snapshots           = count( $this->repository->backend()->list( ObjectType::Snapshots ) );
		$this->frontier            = array_keys( $roots );
		$this->seen                = [];
		$this->live                = [];
		$this->filter              = str_repeat( "\0", intdiv( self::filter_bits( $this->index()->count() ), 8 ) );
		$this->used_bytes          = 0;
		$this->kept_bytes          = 0;
		$this->counted             = [];
		$this->missing             = 0;
		$this->missing_examples    = [];
		$this->index_files         = [];
		$this->position            = 0;
		$this->unused              = [];
		$this->affected            = [];
		$this->index_files_written = 0;
	}

	/**
	 * Refreshes the lock, or starts over with marking if it was lost or not refreshed in time.
	 */
	private function keep_lock(): void {
		$now  = $this->clock->now()[0];
		$held = '' !== $this->lock && null !== $this->repository->backend()->size( ObjectType::Locks, $this->lock );
		if ( $held && $now - $this->locked_at < self::CONTINUITY_SECONDS ) {
			if ( $this->locking->due( $this->locked_at ) ) {
				$this->lock      = $this->locking->refresh( Id::from_hex( $this->lock ), true )->hex();
				$this->locked_at = $now;
			}
			return;
		}
		if ( $held ) {
			$this->locking->release( Id::from_hex( $this->lock ) );
		}
		$this->lock  = '';
		$this->index = null;
		++$this->restarts;
		$this->begin();
	}

	/**
	 * Does one unit of work of the current phase.
	 *
	 * @throws FormatException If marking found blobs that are missing from the index.
	 */
	private function step(): void {
		match ( $this->phase ) {
			'mark'           => $this->mark_one(),
			'plan'           => $this->plan_one(),
			'rewrite'        => $this->rewrite_one(),
			'delete_indexes' => $this->delete_index_one(),
			'delete_packs'   => $this->delete_pack_one(),
			default          => $this->finish(),
		};
	}

	/**
	 * Walks one tree: marks its pack and the packs of its file contents, and queues subtrees.
	 *
	 * @throws FormatException If marking ends with blobs that are missing from the index.
	 */
	private function mark_one(): void {
		$hex = array_pop( $this->frontier );
		if ( null === $hex ) {
			if ( $this->missing > 0 ) {
				FormatException::raise(
					'Snapshots use blobs that are missing from the index; prune stopped without deleting anything.',
					[
						'code'     => 'repository_damaged',
						'missing'  => $this->missing,
						'examples' => implode( ' ', $this->missing_examples ),
					]
				);
			}
			$this->phase       = 'plan';
			$this->index_files = array_keys( $this->repository->backend()->list( ObjectType::Index ) );
			sort( $this->index_files );
			$this->position = 0;
			return;
		}
		if ( isset( $this->seen[ $hex ] ) ) {
			return;
		}
		$this->seen[ $hex ] = true;

		$index = $this->index();
		$tree  = Id::from_hex( $hex );
		if ( ! $this->mark( $index, $tree, BlobType::Tree ) ) {
			return;
		}
		try {
			$nodes = $this->repository->load_tree( $index, $tree );
		} catch ( NotFound ) {
			FormatException::raise(
				'A tree is missing on the server; prune stopped without deleting anything. Run a check.',
				[
					'code' => 'repository_damaged',
					'tree' => $hex,
				]
			);
		}
		foreach ( $nodes as $node ) {
			if ( null !== $node->subtree && ! isset( $this->seen[ $node->subtree->hex() ] ) ) {
				$this->frontier[] = $node->subtree->hex();
			}
			foreach ( $node->content ?? [] as $blob ) {
				$this->mark( $index, $blob, BlobType::Data );
			}
		}
	}

	/**
	 * Marks the pack of a blob as used.
	 *
	 * @param IndexSet $index Index.
	 * @param Id       $blob  Blob ID.
	 * @param BlobType $type  Blob type.
	 * @return bool False if the blob is missing from the index.
	 */
	private function mark( IndexSet $index, Id $blob, BlobType $type ): bool {
		$location = $index->find( $blob, $type );
		if ( null === $location ) {
			++$this->missing;
			if ( count( $this->missing_examples ) < self::MISSING_EXAMPLES ) {
				$this->missing_examples[] = $blob->hex();
			}
			return false;
		}
		$this->live[ $location->pack->hex() ] = true;
		// Trees reach here once each; data blobs that several files share are counted once.
		if ( BlobType::Tree === $type || $this->first_use( $blob ) ) {
			$this->used_bytes += $location->entry->length;
		}
		return true;
	}

	/**
	 * Enters a data blob into the Bloom filter.
	 *
	 * @param Id $blob Blob ID, a SHA-256 hash whose words serve as the three hash values.
	 * @return bool True if the blob was not in the filter yet; false if it was, or probably was.
	 */
	private function first_use( Id $blob ): bool {
		if ( '' === $this->filter ) {
			return false;
		}
		$mask  = strlen( $this->filter ) * 8 - 1;
		$words = unpack( 'V3', $blob->bytes() );
		$new   = false;
		foreach ( false === $words ? [] : $words as $word ) {
			$bit  = $word & $mask;
			$byte = $bit >> 3;
			$old  = ord( $this->filter[ $byte ] );
			$flag = 1 << ( $bit & 7 );
			if ( 0 === ( $old & $flag ) ) {
				$this->filter[ $byte ] = chr( $old | $flag );
				$new                   = true;
			}
		}
		return $new;
	}

	/**
	 * Size of the Bloom filter for an index: a power of two with about 16 bits per blob, for
	 * a false-positive rate below one percent with three hash values.
	 *
	 * @param int $blobs Blobs in the index.
	 * @return int Bits.
	 */
	private static function filter_bits( int $blobs ): int {
		$bits = self::FILTER_MIN_BITS;
		while ( $bits < 16 * $blobs && $bits < self::FILTER_MAX_BITS ) {
			$bits *= 2;
		}
		return $bits;
	}

	/**
	 * Reads one index file and records its unused packs.
	 */
	private function plan_one(): void {
		$name = $this->index_files[ $this->position ] ?? null;
		if ( null === $name ) {
			$this->finish_plan();
			return;
		}
		$affected = false;
		foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
			if ( isset( $this->live[ $pack ] ) ) {
				if ( ! isset( $this->counted[ $pack ] ) ) {
					$this->counted[ $pack ] = true;
					foreach ( $entries as $entry ) {
						$this->kept_bytes += $entry->length;
					}
				}
				continue;
			}
			$this->unused[ $pack ] = -1;
			$affected              = true;
		}
		if ( $affected ) {
			$this->affected[] = $name;
		}
		++$this->position;
	}

	/**
	 * Looks up the sizes of the unused packs and moves on to rewriting, or finishes.
	 */
	private function finish_plan(): void {
		if ( [] === $this->unused ) {
			$this->finish();
			return;
		}
		$sizes = $this->repository->backend()->list( ObjectType::Data );
		foreach ( array_keys( $this->unused ) as $pack ) {
			$this->unused[ $pack ] = $sizes[ $pack ] ?? -1;
		}
		$this->phase    = 'rewrite';
		$this->position = 0;
	}

	/**
	 * Writes one affected index file again without the unused packs.
	 */
	private function rewrite_one(): void {
		$name = $this->affected[ $this->position ] ?? null;
		if ( null === $name ) {
			$this->phase    = 'delete_indexes';
			$this->position = 0;
			return;
		}
		$kept = [];
		foreach ( IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
			if ( ! isset( $this->unused[ $pack ] ) ) {
				$kept[ $pack ] = $entries;
			}
		}
		if ( [] !== $kept ) {
			$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( $kept ) );
			++$this->index_files_written;
		}
		++$this->position;
	}

	/**
	 * Deletes one superseded index file.
	 */
	private function delete_index_one(): void {
		$name = $this->affected[ $this->position ] ?? null;
		if ( null === $name ) {
			$this->phase    = 'delete_packs';
			$this->position = 0;
			return;
		}
		$this->repository->backend()->delete( ObjectType::Index, $name );
		++$this->position;
	}

	/**
	 * Deletes one unused pack.
	 */
	private function delete_pack_one(): void {
		$packs = array_keys( $this->unused );
		$pack  = $packs[ $this->position ] ?? null;
		if ( null === $pack ) {
			$this->finish();
			return;
		}
		$this->repository->backend()->delete( ObjectType::Data, $pack );
		++$this->packs_deleted;
		$this->bytes_deleted += max( 0, $this->unused[ $pack ] );
		++$this->position;
	}

	/**
	 * Releases the lock.
	 */
	private function finish(): void {
		if ( '' !== $this->lock ) {
			$this->locking->release( Id::from_hex( $this->lock ) );
			$this->lock = '';
		}
		$this->phase = 'done';
	}

	/**
	 * Returns the index, loading it from the server if none was given.
	 *
	 * @return IndexSet Index.
	 */
	private function index(): IndexSet {
		if ( null === $this->index ) {
			$this->index = $this->repository->load_index();
		}
		return $this->index;
	}

	/**
	 * Writes the checkpoint atomically.
	 */
	private function persist(): void {
		Files::write_atomic(
			$this->work_dir . '/state.json',
			json_encode(
				[
					'version'             => self::STATE_VERSION,
					'pid'                 => $this->pid,
					'lock'                => $this->lock,
					'locked_at'           => $this->locked_at,
					'phase'               => $this->phase,
					'frontier'            => $this->frontier,
					'seen'                => array_keys( $this->seen ),
					'live'                => array_keys( $this->live ),
					'filter'              => Bytes::base64_encode( $this->filter ),
					'used_bytes'          => $this->used_bytes,
					'kept_bytes'          => $this->kept_bytes,
					'counted'             => array_keys( $this->counted ),
					'missing'             => $this->missing,
					'missing_examples'    => $this->missing_examples,
					'snapshots'           => $this->snapshots,
					'index_files'         => $this->index_files,
					'position'            => $this->position,
					'unused'              => $this->unused,
					'affected'            => $this->affected,
					'index_files_written' => $this->index_files_written,
					'packs_deleted'       => $this->packs_deleted,
					'bytes_deleted'       => $this->bytes_deleted,
					'restarts'            => $this->restarts,
				],
				JSON_THROW_ON_ERROR
			)
		);
	}
}
