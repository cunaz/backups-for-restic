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
use BackupsForRestic\Backend\ListingFile;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Decoded;
use BackupsForRestic\Support\Throttle;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the packs that no snapshot uses, as restic's prune does for completely unused packs
 * (ADR 0022), and the packs that no index file lists. A pack that still holds one used blob
 * stays, so nothing is downloaded or rewritten; `restic prune` on another computer reclaims
 * the rest.
 *
 * The run works in phases, each resumable in short ticks:
 *
 * 1. mark: walks the trees of all snapshots and records every pack that holds a used blob.
 * 2. plan: reads the index files and records the unused packs and the index files that list
 *    them.
 * 3. download, parse_listing, classify: streams the pack listing to disk, validates bounded
 *    batches and records unindexed packs in a disk-backed table (ADR 0033).
 * 4. rewrite: writes each of those index files again without the unused packs.
 * 5. delete_indexes: deletes the old index files.
 * 6. delete_packs, delete_unindexed: deletes indexed unused packs, then unindexed packs.
 *
 * A pack that no index file lists is what an interrupted backup left behind: it uploaded the
 * pack and never wrote the index file for it. No snapshot can use it, because marking ends
 * with an error when a snapshot uses a blob the index does not lead to, and every used pack
 * must be among the listed ones before anything is deleted. Under the exclusive lock nobody
 * is uploading packs either. restic's prune removes such packs in the same way.
 *
 * Every state between two requests is a repository restic accepts: new index files are written
 * before the old ones go, and packs go last. The run holds an exclusive lock from the first tree
 * it reads to the last pack it deletes. If the lock disappeared or was not refreshed in time,
 * another client may have written in the meantime. The run then takes the lock again and
 * looks: if the snapshots are the ones it marked, and the index files are the ones it read
 * apart from those it has written or deleted itself, nothing it found has changed and it goes
 * on where it was. Otherwise it starts again with marking, at most MAX_RESTARTS times; until
 * then it had deleted only packs that no snapshot could use (ADR 0030).
 *
 * The checkpoint is written before the lock is taken, at every change of phase and at the end of
 * every call. A request that ends at any point therefore leaves a run that resume() continues
 * in the phase it had reached, and a lock that the run still recognises as its own.
 *
 * Packs that hold used and unused blobs stay. The run estimates the space they waste, which
 * only a full `restic prune` from another computer reclaims: the bytes of all blobs in kept
 * packs minus the bytes of the used blobs. Trees are counted exactly; data blobs are counted
 * once with the help of a Bloom filter of about two bytes per indexed blob, because an exact
 * set of every used blob would not fit the memory of small hosts. A false positive of the
 * filter leaves a used blob uncounted, so the estimate can only be slightly too high.
 */
final class PruneRun {

	public const int STATE_VERSION = 4;

	/** How often marking starts over because other clients changed the repository meanwhile. */
	public const int MAX_RESTARTS = 3;

	/** Locks older than 30 minutes are stale for restic; the run refreshes well before. */
	public const int CONTINUITY_SECONDS = 1500;

	/** At most this many IDs of missing blobs are kept for the error report. */
	private const int MISSING_EXAMPLES = 5;

	/** Smallest and largest Bloom filter, in bits: 8 KiB and 8 MiB. */
	private const int FILTER_MIN_BITS = 65536;

	private const int FILTER_MAX_BITS = 67108864;

	/** The phases in their order. */
	private const array PHASES = [ 'mark', 'plan', 'download', 'parse_listing', 'classify', 'rewrite', 'delete_indexes', 'delete_packs', 'delete_unindexed', 'done' ];

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
	 * Current phase, one of PHASES.
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
	 * Hex IDs of the packs that the index files read while planning list.
	 *
	 * @var array<string, true>
	 */
	private array $listed;

	/**
	 * Whether marking already ran again because the index was out of date.
	 *
	 * @var bool
	 */
	private bool $remarked;

	/**
	 * The unused packs in deletion order, built once per request.
	 *
	 * @var list<string>|null
	 */
	private ?array $deletion_order = null;

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
	 * Hex names of the snapshots when marking started, in order.
	 *
	 * @var list<string>
	 */
	private array $snapshot_names;

	/**
	 * Hex names of the index files the run takes the repository to have, in order: those
	 * listed when marking started, and from the plan phase on those the plan reads.
	 *
	 * @var list<string>
	 */
	private array $index_names;

	/**
	 * Hex names of the index files this run wrote without the unused packs.
	 *
	 * @var list<string>
	 */
	private array $written;

	/**
	 * Number of packs found that no index file lists.
	 *
	 * @var int
	 */
	private int $unindexed;

	/**
	 * Distinct live packs found in the fully parsed server listing so far.
	 *
	 * @var int
	 */
	private int $present_live;

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
	 * @param IndexCache|null      $cache      Cache loaded lazily while the lock holds.
	 */
	private function __construct(
		private readonly Repository $repository,
		private ?IndexSet $index,
		private readonly string $work_dir,
		Identity $identity,
		private readonly Clock $clock,
		array $state,
		private readonly ?IndexCache $cache
	) {
		$this->pid       = Decoded::int( $state['pid'] ?? null, random_int( 100000, 2147483647 ) );
		$this->locking   = new Locking( $repository, $identity, $clock, $this->pid );
		$this->lock      = Decoded::string( $state['lock'] ?? null );
		$this->locked_at = Decoded::int( $state['locked_at'] ?? null );
		$this->phase     = Decoded::string( $state['phase'] ?? null, 'mark' );
		if ( ! in_array( $this->phase, self::PHASES, true ) ) {
			FormatException::raise( 'The prune checkpoint names an unknown phase.', [ 'phase' => $this->phase ] );
		}
		$this->frontier = Decoded::strings( $state['frontier'] ?? [] );
		$this->seen     = array_fill_keys( Decoded::strings( $state['seen'] ?? [] ), true );
		$this->live     = array_fill_keys( Decoded::strings( $state['live'] ?? [] ), true );
		$this->listed   = array_fill_keys( Decoded::strings( $state['listed'] ?? [] ), true );
		$this->remarked = true === ( $state['remarked'] ?? null );
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
		$this->snapshot_names      = Decoded::strings( $state['snapshot_names'] ?? [] );
		$this->index_names         = Decoded::strings( $state['index_names'] ?? [] );
		$this->written             = Decoded::strings( $state['written'] ?? [] );
		$this->unindexed           = Decoded::count( $state['unindexed'] ?? null );
		$this->present_live        = Decoded::count( $state['present_live'] ?? null );
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
	 * @param Repository      $repository Repository.
	 * @param string          $work_dir   Empty directory for the checkpoint.
	 * @param Identity        $identity   Written into lock files.
	 * @param Clock           $clock      Time source.
	 * @param RandomSource    $random     For the append-only probe.
	 * @param IndexSet|null   $index      Current index, for example from the IndexCache.
	 * @param IndexCache|null $cache      Cache to load after taking the exclusive lock.
	 * @return self Run.
	 * @throws InvalidArgument If the directory already holds a run or the repository has no snapshots.
	 * @throws AppendOnlyRefused If the server refuses deletions.
	 * @throws BackendException If it cannot be told whether the server allows deletions.
	 * @throws \Throwable Whatever taking the lock or reading the snapshots throws. The checkpoint
	 *                    then stays: resume() begins again, and abort() gives the run up.
	 */
	public static function start( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RandomSource $random, ?IndexSet $index = null, ?IndexCache $cache = null ): self {
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) && ! is_dir( $work_dir ) ) {
			InvalidArgument::raise( 'The working directory cannot be created.' );
		}
		if ( is_file( $work_dir . '/state.json' ) ) {
			InvalidArgument::raise( 'The working directory already holds a prune.' );
		}
		$status = AppendOnlyProbe::detect( $repository->backend(), $random );
		if ( AppendOnlyStatus::AppendOnly === $status ) {
			AppendOnlyRefused::raise( 'The repository is append-only; prune runs on another computer.' );
		}
		if ( AppendOnlyStatus::Unknown === $status ) {
			// Fail closed: on an append-only server the index rewrite would succeed and the
			// deletions fail, leaving duplicate index files behind.
			BackendException::raise( 'Whether the server allows deletions could not be determined; prune tries again later.' );
		}
		$run = new self( $repository, $index, $work_dir, $identity, $clock, [], $cache );
		// The run's number is on disk before its lock is on the server: if this request ends in
		// between, resume() and abort() still recognise the lock, instead of waiting 30 minutes
		// for a lock that nobody knows.
		$run->persist();
		try {
			$run->begin();
			$run->persist();
		} catch ( \Throwable $failure ) {
			$run->release_lock();
			throw $failure;
		}
		return $run;
	}

	/**
	 * Continues a prune from its checkpoint.
	 *
	 * @param Repository      $repository Repository.
	 * @param string          $work_dir   Directory with the checkpoint.
	 * @param Identity        $identity   Written into lock files.
	 * @param Clock           $clock      Time source.
	 * @param IndexSet|null   $index      Current index, for example from the IndexCache.
	 * @param IndexCache|null $cache      Cache used only when marking needs the index.
	 * @return self Run.
	 * @throws FormatException If the checkpoint is unusable.
	 */
	public static function resume( Repository $repository, string $work_dir, Identity $identity, Clock $clock, ?IndexSet $index = null, ?IndexCache $cache = null ): self {
		$json  = is_file( $work_dir . '/state.json' ) ? file_get_contents( $work_dir . '/state.json' ) : false;
		$state = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $state ) || self::STATE_VERSION !== ( $state['version'] ?? null ) ) {
			FormatException::raise( 'The prune checkpoint is missing or from another version.' );
		}
		$fields = [];
		foreach ( $state as $key => $value ) {
			$fields[ (string) $key ] = $value;
		}
		return new self( $repository, $index, $work_dir, $identity, $clock, $fields, $cache );
	}

	/**
	 * Works until the prune is finished or the deadline has passed. Each call does at least one
	 * unit of work, so a slow host still makes progress.
	 *
	 * @param float         $deadline Unix time (microtime(true)) at which to stop.
	 * @param Throttle|null $throttle Pauses for the gentle CPU mode, if any.
	 * @return bool True when finished and the lock is released.
	 * @throws FormatException If a snapshot uses a blob that is missing from the index.
	 * @throws RepositoryChanged If other clients changed the repository again and again while
	 *                           the run could not hold its lock.
	 */
	public function advance( float $deadline, ?Throttle $throttle = null ): bool {
		if ( 'done' === $this->phase ) {
			return true;
		}
		$this->keep_lock();
		do {
			$phase = $this->phase;
			$this->step();
			if ( $phase !== $this->phase && 'done' !== $this->phase ) {
				// A request that ends while it deletes must not leave the checkpoint of an
				// earlier phase: planning again would look for index files that are gone.
				$this->persist();
			}
			$throttle?->pause();
			// A long call, such as one without a deadline, must not outlive its lock.
			if ( 'done' !== $this->phase && $this->locking->due( $this->locked_at ) ) {
				$this->keep_lock();
			}
		} while ( 'done' !== $this->phase && microtime( true ) < $deadline );
		$this->persist();
		return 'done' === $this->phase;
	}

	/**
	 * Releases the lock and deletes the checkpoint of an abandoned prune. The repository stays
	 * valid in every phase.
	 */
	public function abort(): void {
		if ( 'done' !== $this->phase ) {
			// Also without a recorded lock: a request that ended between writing a lock and
			// recording it left one that only the run's number identifies.
			$this->locking->release( '' === $this->lock ? null : Id::from_hex( $this->lock ) );
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
			'unused_packs'  => count( $this->unused ) + $this->unindexed,
			'packs_deleted' => $this->packs_deleted,
			'bytes_deleted' => $this->bytes_deleted,
		];
	}

	/**
	 * Outcome once finished. reclaimable_bytes estimates the unused space in kept packs that
	 * only a full prune with repacking frees; -1 if unknown.
	 *
	 * @return array{packs_deleted: int, bytes_deleted: int, packs_kept: int, unindexed_packs: int, reclaimable_bytes: int, index_files_written: int, restarts: int} Result.
	 */
	public function result(): array {
		return [
			'packs_deleted'       => $this->packs_deleted,
			'bytes_deleted'       => $this->bytes_deleted,
			'packs_kept'          => count( $this->live ),
			'unindexed_packs'     => $this->unindexed,
			'reclaimable_bytes'   => '' === $this->filter ? -1 : max( 0, $this->kept_bytes - $this->used_bytes ),
			'index_files_written' => $this->index_files_written,
			'restarts'            => $this->restarts,
		];
	}

	/**
	 * Takes the exclusive lock and starts marking from the trees of all snapshots. If marking
	 * cannot start, the lock is released again before the error is passed on.
	 *
	 * @throws InvalidArgument If the repository has no snapshots.
	 * @throws \Throwable Whatever listing or reading the snapshots throws, after the release.
	 */
	private function begin(): void {
		$this->locking->remove_own_stale();
		$this->lock      = $this->locking->acquire( true )->hex();
		$this->locked_at = $this->clock->now()[0];
		$this->remarked  = false;
		try {
			$this->start_marking();
		} catch ( \Throwable $failure ) {
			$this->release_lock();
			throw $failure;
		}
	}

	/**
	 * Resets every phase and queues the root trees of all snapshots for marking. Reading the
	 * snapshots and, for the size of the filter, the index are the only steps that can fail,
	 * and they come first: a run whose marking could not start over is left exactly as it was.
	 *
	 * @throws InvalidArgument If the repository has no snapshots.
	 */
	private function start_marking(): void {
		$snapshots = $this->repository->snapshots();
		if ( [] === $snapshots ) {
			// An empty listing would make every pack unused; never delete everything.
			InvalidArgument::raise( 'The repository has no snapshots; prune would delete everything.', [ 'code' => 'no_snapshots' ] );
		}
		$filter = str_repeat( "\0", intdiv( self::filter_bits( $this->index()->count() ), 8 ) );
		$roots  = [];
		foreach ( $snapshots as $snapshot ) {
			$roots[ $snapshot->tree->hex() ] = true;
		}

		$snapshot_names = array_map( 'strval', array_keys( $snapshots ) );
		sort( $snapshot_names, SORT_STRING );
		$index_names = $this->repository->names( ObjectType::Index );

		$this->phase               = 'mark';
		$this->snapshots           = count( $snapshots );
		$this->snapshot_names      = $snapshot_names;
		$this->index_names         = $index_names;
		$this->written             = [];
		$this->unindexed           = 0;
		$this->present_live        = 0;
		$this->frontier            = array_keys( $roots );
		$this->seen                = [];
		$this->live                = [];
		$this->filter              = $filter;
		$this->used_bytes          = 0;
		$this->kept_bytes          = 0;
		$this->counted             = [];
		$this->listed              = [];
		$this->missing             = 0;
		$this->missing_examples    = [];
		$this->index_files         = [];
		$this->position            = 0;
		$this->unused              = [];
		$this->affected            = [];
		$this->deletion_order      = null;
		$this->index_files_written = 0;
	}

	/**
	 * Releases the held lock. A lock that cannot be deleted now is stale after 30 minutes.
	 */
	private function release_lock(): void {
		if ( '' === $this->lock ) {
			return;
		}
		try {
			$this->locking->release( Id::from_hex( $this->lock ) );
		} catch ( PluginException ) {
			$this->lock = '';
			return; // Stale for every client after 30 minutes.
		}
		$this->lock = '';
	}

	/**
	 * Refreshes the lock. If it was lost or not refreshed in time, takes it again, which fails
	 * while another client holds a lock, and goes on only if the repository still is what the
	 * run takes it for; otherwise marking starts over. A run whose start did not get as far as
	 * marking begins here.
	 *
	 * @throws RepositoryChanged If marking already started over MAX_RESTARTS times.
	 * @throws \Throwable Whatever taking the lock, listing or reading the snapshots throws; the
	 *                    lock is released before the error is passed on.
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
		if ( 0 === $this->snapshots ) {
			// A run that has not marked anything yet begins; it does not start over.
			$this->begin();
			return;
		}
		$this->locking->remove_own_stale();
		$fresh = $this->locking->acquire( true );
		$taken = $this->clock->now()[0];
		// The run holds the new lock from here on, but its checkpoint names it only once the
		// run has looked or marking has started over. A request that ends before that leaves
		// a checkpoint without a lock, so the next one takes the lock again and looks again:
		// a lock written a moment ago says nothing about the time before it. The lock written
		// here is then a leftover of the run, which taking the lock removes.
		try {
			$untouched = $this->untouched();
			if ( ! $untouched ) {
				if ( $this->restarts >= self::MAX_RESTARTS ) {
					RepositoryChanged::raise( 'Other clients changed the repository again and again while prune ran; prune gave up.', [ 'restarts' => $this->restarts ] );
				}
				$this->remarked = false;
				$this->start_marking();
				++$this->restarts;
			}
		} catch ( \Throwable $failure ) {
			$this->locking->release( $fresh );
			throw $failure;
		}
		$this->lock      = $fresh->hex();
		$this->locked_at = $taken;
		$this->persist();
	}

	/**
	 * Whether the repository still is what this run takes it for, after a time in which other
	 * clients could write: the snapshots are the ones marking started from, and the index
	 * files are the ones the run read, apart from those it has written or deleted itself.
	 *
	 * Then nothing the run found has changed. A new snapshot could use a pack the run takes
	 * for unused. A changed index means that another client repacked or pruned, so that the
	 * packs marked as used may be gone and others hold their blobs. Packs that an interrupted
	 * backup uploaded meanwhile without an index file change neither listing and concern
	 * nothing: the run deletes only what its plan recorded.
	 *
	 * A request of this run that ended between writing or deleting an index file and the next
	 * checkpoint also makes the listings differ. Marking then starts over needlessly, which is
	 * safe.
	 *
	 * @return bool True if the run may go on where it was.
	 */
	private function untouched(): bool {
		$deleted = match ( $this->phase ) {
			'delete_indexes' => array_slice( $this->affected, 0, $this->position ),
			'delete_packs', 'delete_unindexed' => $this->affected,
			default          => [],
		};
		$expected = array_values( array_unique( array_merge( array_diff( $this->index_names, $deleted ), $this->written ) ) );
		sort( $expected, SORT_STRING );
		return $this->repository->names( ObjectType::Snapshots ) === $this->snapshot_names
			&& $this->repository->names( ObjectType::Index ) === $expected;
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
			'download'       => $this->download_listing(),
			'parse_listing'  => $this->parse_listing(),
			'classify'       => $this->classify_packs(),
			'rewrite'        => $this->rewrite_one(),
			'delete_indexes' => $this->delete_index_one(),
			'delete_packs'   => $this->delete_pack_one(),
			'delete_unindexed' => $this->delete_unindexed(),
			default          => $this->finish(),
		};
	}

	/**
	 * Walks one tree: marks its pack and the packs of its file contents, and queues subtrees.
	 *
	 * @throws FormatException If marking ends with blobs that are missing from the index.
	 * @throws \Throwable Whatever reading the index or the tree throws; the tree stays queued.
	 */
	private function mark_one(): void {
		$hex = array_pop( $this->frontier );
		if ( null === $hex ) {
			if ( $this->missing > 0 ) {
				$this->mark_again(
					'Snapshots use blobs that are missing from the index; prune stopped without deleting anything.',
					[
						'missing'  => $this->missing,
						'examples' => implode( ' ', $this->missing_examples ),
					]
				);
				return;
			}
			// From here on the run takes the repository to have the index files it reads now.
			$this->index_files = $this->repository->names( ObjectType::Index );
			$this->index_names = $this->index_files;
			$this->position    = 0;
			$this->phase       = 'plan';
			// Later phases need pack membership only. In particular, rewriting must not
			// keep the complete blob index beside the input, output and encrypted JSON.
			$this->index = null;
			return;
		}
		if ( isset( $this->seen[ $hex ] ) ) {
			return;
		}
		try {
			$index = $this->index();
			$tree  = Id::from_hex( $hex );
			// A tree the index does not know is counted as missing by mark() below.
			$nodes = null === $index->find( $tree, BlobType::Tree ) ? [] : $this->repository->load_tree( $index, $tree );
		} catch ( NotFound ) {
			// Queued again first: if marking cannot start over now, the tree must not be skipped.
			$this->frontier[] = $hex;
			$this->mark_again( 'A tree is missing on the server; prune stopped without deleting anything. Run a check.', [ 'tree' => $hex ] );
			return;
		} catch ( \Throwable $failure ) {
			// The tree stays queued and unmarked: if the caller tries again on this run, the
			// tree is still walked, its packs are not taken for unused ones, and the estimate
			// counts it once.
			$this->frontier[] = $hex;
			throw $failure;
		}
		$this->seen[ $hex ] = true;
		if ( ! $this->mark( $index, $tree, BlobType::Tree ) ) {
			return;
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
		// Validate the entire file before changing the plan. Repeated occurrences of a pack
		// in this file contribute all their entries, as decode() did, but cost no blob array.
		$sizes = [];
		foreach ( IndexFile::packs( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
			$sizes[ $pack ] ??= 0;
			foreach ( $entries as $entry ) {
				$sizes[ $pack ] += $entry->length;
			}
		}
		$affected = false;
		foreach ( $sizes as $pack => $bytes ) {
			$this->listed[ $pack ] = true;
			if ( isset( $this->live[ $pack ] ) ) {
				if ( ! isset( $this->counted[ $pack ] ) ) {
					$this->counted[ $pack ] = true;
					$this->kept_bytes      += $bytes;
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
	 * Looks up the sizes of the unused packs and moves on to rewriting, or finishes. Every pack
	 * that marking found in use must appear in the index files just read; otherwise the index
	 * used for marking was out of date, and planning would take a used pack for an unused one.
	 *
	 * @throws FormatException If the index files still miss used packs after marking again.
	 */
	private function finish_plan(): void {
		$unlisted = array_diff_key( $this->live, $this->listed );
		if ( [] !== $unlisted ) {
			$this->mark_again( 'Snapshots use packs that no index file lists; prune stopped without deleting anything.', [ 'packs' => count( $unlisted ) ] );
			return;
		}
		// Persist this phase before touching either working file. A restarted plan must never
		// pair an earlier parse/classification cursor with a newly downloaded listing or table.
		$this->phase    = 'download';
		$this->position = 0;
	}

	/** Downloads the raw listing and prepares its disk-backed set before parsing begins. */
	private function download_listing(): void {
		$this->repository->backend()->list_to_file( ObjectType::Data, $this->work_dir . '/packs.json' );
		$file = new WorkFile( $this->work_dir . '/packs.json' );
		$size = $file->size();
		$file->close();
		PackTable::create( $this->work_dir . '/packs.table', $size );
		$this->phase    = 'parse_listing';
		$this->position = 0;
	}

	/** Validates a bounded batch and stores its packs before checkpointing the byte cursor. */
	private function parse_listing(): void {
		$batch = ListingFile::batch( $this->work_dir . '/packs.json', $this->position );
		$table = new PackTable( $this->work_dir . '/packs.table' );
		try {
			foreach ( $batch['entries'] as [ $name, $size ] ) {
				$table->put( $name, $size );
			}
		} finally {
			$table->close();
		}
		$this->position = $batch['done'] ? 0 : $batch['offset'];
		if ( $batch['done'] ) {
			$this->phase = 'classify';
		}
	}

	/** Classifies a bounded set of slots only after the entire JSON listing was validated. */
	private function classify_packs(): void {
		$table = new PackTable( $this->work_dir . '/packs.table' );
		$count = 0;
		$live  = 0;
		try {
			$batch = $table->batch( $this->position );
			foreach ( $batch['entries'] as $entry ) {
				$name = $entry['name'];
				if ( isset( $this->live[ $name ] ) ) {
					++$live;
				}
				if ( isset( $this->unused[ $name ] ) ) {
					$this->unused[ $name ] = $entry['size'];
				}
				if ( ! isset( $this->listed[ $name ] ) ) {
					$table->mark_unindexed( $entry['slot'] );
					++$count;
				}
			}
		} finally {
			$table->close();
		}
		// Each name occupies one slot, so counting their intersection is equivalent to
		// testing every live name for membership, without another set or an unbounded pass.
		// An unindexed pack could hold the remaining copy of data from a missing live pack.
		if ( $batch['done'] && $this->present_live + $live !== count( $this->live ) ) {
			FormatException::raise(
				'The server does not list every pack that snapshots use; prune stopped without deleting anything.',
				[ 'code' => 'repository_damaged' ]
			);
		}
		$this->present_live += $live;
		$this->unindexed    += $count;
		$this->position      = $batch['done'] ? 0 : $batch['position'];
		if ( $batch['done'] ) {
			$this->phase = 'rewrite';
		}
	}

	/**
	 * Marks again with the index as the server has it now, after marking found a used blob or
	 * tree that the index does not lead to. The index may have been read before another client
	 * changed the repository and before this run took its lock: a backup added blobs, or a
	 * prune repacked data into new packs. If the fresh index does not help either, the
	 * repository is damaged and nothing is deleted.
	 *
	 * Marking starts over completely or not at all: if the index or the snapshots cannot be read
	 * now, the run keeps the marks it has and the caller's next attempt comes here again.
	 *
	 * @param string                     $message Explanation if it happens again.
	 * @param array<string, scalar|null> $context Details if it happens again.
	 * @throws FormatException If marking already ran again.
	 */
	private function mark_again( string $message, array $context ): void {
		if ( $this->remarked ) {
			FormatException::raise( $message, [ 'code' => 'repository_damaged' ] + $context );
		}
		$this->index = $this->repository->load_index();
		$this->start_marking();
		$this->remarked = true;
		++$this->restarts;
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
		$packs = $this->kept_packs( $name );
		$json  = IndexFile::encode( $packs );
		if ( $packs->getReturn() ) {
			$this->written[] = $this->repository->save_unpacked( ObjectType::Index, $json )->hex();
			++$this->index_files_written;
		}
		++$this->position;
	}

	/**
	 * Yields the packs to keep, validating even the entries that will be dropped. Nothing
	 * is uploaded until the generator finishes: a damaged tail cannot publish half an index.
	 *
	 * @param string $name Index file ID.
	 * @return \Generator<string, list<PackEntry>, mixed, bool> Entries, returning whether any were kept.
	 */
	private function kept_packs( string $name ): \Generator {
		$kept = false;
		foreach ( IndexFile::packs( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
			if ( ! isset( $this->unused[ $pack ] ) ) {
				$kept = true;
				yield $pack => $entries;
			}
		}
		return $kept;
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
		$this->deletion_order ??= array_map( 'strval', array_keys( $this->unused ) );
		$pack                   = $this->deletion_order[ $this->position ] ?? null;
		if ( null === $pack ) {
			$this->phase    = 'delete_unindexed';
			$this->position = 0;
			return;
		}
		$this->repository->backend()->delete( ObjectType::Data, $pack );
		++$this->packs_deleted;
		$this->bytes_deleted += max( 0, $this->unused[ $pack ] );
		++$this->position;
	}

	/** Deletes at most one unindexed pack, scanning a bounded number of empty slots per step. */
	private function delete_unindexed(): void {
		$table = new PackTable( $this->work_dir . '/packs.table' );
		try {
			$batch = $table->batch( $this->position );
		} finally {
			$table->close();
		}
		foreach ( $batch['entries'] as $entry ) {
			if ( ! $entry['unindexed'] ) {
				continue;
			}
			if ( isset( $this->listed[ $entry['name'] ] ) || isset( $this->live[ $entry['name'] ] ) ) {
				StorageFailed::raise( 'The pack working table marks an indexed pack for deletion.' );
			}
			$this->repository->backend()->delete( ObjectType::Data, $entry['name'] );
			++$this->packs_deleted;
			$this->bytes_deleted += max( 0, $entry['size'] );
			$this->position       = $entry['slot'] + 1;
			return;
		}
		$this->position = $batch['position'];
		if ( $batch['done'] ) {
			$this->finish();
		}
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
	 * Returns the index, loading it under the lock and only while marking needs it.
	 *
	 * @return IndexSet Index.
	 */
	private function index(): IndexSet {
		if ( null === $this->index ) {
			$this->index = null === $this->cache ? $this->repository->load_index() : $this->cache->load( $this->repository );
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
					'listed'              => array_keys( $this->listed ),
					'remarked'            => $this->remarked,
					'missing'             => $this->missing,
					'missing_examples'    => $this->missing_examples,
					'snapshots'           => $this->snapshots,
					'index_files'         => $this->index_files,
					'snapshot_names'      => $this->snapshot_names,
					'index_names'         => $this->index_names,
					'written'             => $this->written,
					'unindexed'           => $this->unindexed,
					'present_live'        => $this->present_live,
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
