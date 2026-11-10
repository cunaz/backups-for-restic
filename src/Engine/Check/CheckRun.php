<?php
/**
 * Repository check.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Check;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;
use BackupsForRestic\Support\Throttle;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a repository the way `restic check --read-data-subset` does, in resumable ticks
 * (ADR 0022):
 *
 * 1. index: every index file decrypts and parses; each pack gets its expected size.
 * 2. packs: every indexed pack exists on the server with that size.
 * 3. trees: every snapshot decrypts, and every tree and data blob its trees reference is
 *    indexed; every tree decrypts and parses.
 * 4. read: a random sample of packs is downloaded and verified completely: the file name is
 *    the hash of the content, the header matches the index, and every blob decrypts to the
 *    content its ID names. A pack streams in pieces of READ_PIECE bytes (a blob is never
 *    split), so memory stays bounded by the piece and the largest blob, not by the pack.
 *
 * Damage becomes a finding and the check goes on; nothing is ever repaired. Errors are what
 * restic check reports as errors; warnings are its hints, such as packs that no index lists.
 * Transport errors end the tick, and the job retries. The check holds a shared lock, so
 * backups may run meanwhile, as with restic.
 */
final class CheckRun {

	public const int STATE_VERSION = 1;

	/** The phases in their order. */
	private const array PHASES = [ 'index', 'packs', 'snapshots', 'trees', 'read', 'done' ];

	/** At most this many findings are kept with details, errors rather than warnings; all are counted. */
	public const int MAX_FINDINGS = 50;

	/** Bytes of a pack header entry without and with an uncompressed length. */
	private const int ENTRY_SIZE            = 37;
	private const int COMPRESSED_ENTRY_SIZE = 41;

	/** Bytes read from a pack at once by default; a piece holds whole blobs, so one large blob may exceed it. */
	public const int READ_PIECE = 8388608;

	/**
	 * Requests that may end while reading the same pack before the check reports the pack as
	 * unverified and goes on. A request that hits the host's time limit cannot record anything,
	 * so only a marker written before the read tells the next request what happened.
	 */
	public const int READ_ATTEMPTS = 3;

	/** Within a call, the checkpoint is written again once it is this many seconds old. */
	private const int CHECKPOINT_SECONDS = 5;

	/**
	 * Unix time of the last checkpoint written by this object.
	 *
	 * @var int
	 */
	private int $persisted_at;

	/**
	 * Lock handling.
	 *
	 * @var Locking
	 */
	private Locking $locking;

	/**
	 * Pauses for the gentle CPU mode during the current call, if any.
	 *
	 * @var Throttle|null
	 */
	private ?Throttle $throttle = null;

	/**
	 * Checkpoint fields; see persist() for the shape.
	 *
	 * @var array{pid: int, lock: string, locked_at: int, phase: string, position: int, percent: int, piece: int, seed: string, index_files: list<string>, packs: array<string, array{int, int}>, missing: array<string, true>, snapshot_names: list<string>, roots: array<string, true>, frontier: list<string>, seen: array<string, true>, sample: list<string>, findings: list<array{severity: string, code: string, object: string, message: string}>, counts: array<string, int>, stats: array<string, int>}
	 */
	private array $state;

	/**
	 * Creates the run from a state.
	 *
	 * @param Repository           $repository Repository.
	 * @param IndexSet|null        $index      Current index; loaded from the server when needed.
	 * @param string               $work_dir   Directory for the checkpoint.
	 * @param Identity             $identity   Written into lock files.
	 * @param Clock                $clock      Time source.
	 * @param array<string, mixed> $fields     Decoded checkpoint.
	 */
	private function __construct(
		private readonly Repository $repository,
		private ?IndexSet $index,
		private readonly string $work_dir,
		Identity $identity,
		private readonly Clock $clock,
		array $fields
	) {
		$packs = [];
		foreach ( is_array( $fields['packs'] ?? null ) ? $fields['packs'] : [] as $pack => $values ) {
			$values                  = is_array( $values ) ? array_values( $values ) : [];
			$packs[ (string) $pack ] = [ Decoded::int( $values[0] ?? null ), Decoded::count( $values[1] ?? null ) ];
		}
		$findings = [];
		foreach ( Decoded::arrays( $fields['findings'] ?? [] ) as $finding ) {
			$findings[] = [
				'severity' => Decoded::string( $finding['severity'] ?? null ),
				'code'     => Decoded::string( $finding['code'] ?? null ),
				'object'   => Decoded::string( $finding['object'] ?? null ),
				'message'  => Decoded::string( $finding['message'] ?? null ),
			];
		}
		$this->state = [
			'pid'            => Decoded::int( $fields['pid'] ?? null, random_int( 100000, 2147483647 ) ),
			'lock'           => Decoded::string( $fields['lock'] ?? null ),
			'locked_at'      => Decoded::int( $fields['locked_at'] ?? null ),
			'phase'          => Decoded::string( $fields['phase'] ?? null, 'index' ),
			'position'       => Decoded::count( $fields['position'] ?? null ),
			'percent'        => Decoded::count( $fields['percent'] ?? null ),
			'piece'          => max( 1, Decoded::int( $fields['piece'] ?? null, self::READ_PIECE ) ),
			'seed'           => Decoded::string( $fields['seed'] ?? null ),
			'index_files'    => Decoded::strings( $fields['index_files'] ?? [] ),
			'packs'          => $packs,
			'missing'        => array_fill_keys( Decoded::strings( $fields['missing'] ?? [] ), true ),
			'snapshot_names' => Decoded::strings( $fields['snapshot_names'] ?? [] ),
			'roots'          => array_fill_keys( Decoded::strings( $fields['roots'] ?? [] ), true ),
			'frontier'       => Decoded::strings( $fields['frontier'] ?? [] ),
			'seen'           => array_fill_keys( Decoded::strings( $fields['seen'] ?? [] ), true ),
			'sample'         => Decoded::strings( $fields['sample'] ?? [] ),
			'findings'       => $findings,
			'counts'         => self::int_map( $fields['counts'] ?? [] ),
			'stats'          => self::int_map( $fields['stats'] ?? [] ),
		];
		if ( ! in_array( $this->state['phase'], self::PHASES, true ) ) {
			FormatException::raise( 'The check checkpoint names an unknown phase.', [ 'phase' => $this->state['phase'] ] );
		}
		$this->locking      = new Locking( $repository, $identity, $clock, $this->state['pid'] );
		$this->persisted_at = $clock->now()[0];
	}

	/**
	 * Starts a check: takes a shared lock and lists the index files.
	 *
	 * @param Repository    $repository              Repository.
	 * @param string        $work_dir                Empty directory for the checkpoint.
	 * @param Identity      $identity                Written into lock files.
	 * @param Clock         $clock                   Time source.
	 * @param RandomSource  $random                  Chooses the packs to read.
	 * @param int           $read_data_subset_percent Share of packs to download and verify, 0 to 100.
	 * @param IndexSet|null $index                   Current index, for example from the IndexCache.
	 * @param int           $read_piece              Bytes to read from a pack at once (blobs are never split).
	 * @return self Run.
	 * @throws InvalidArgument If the directory already holds a check, the share is out of range or the piece is empty.
	 * @throws \Throwable Whatever listing the index files or writing the checkpoint throws, after the release.
	 */
	public static function start( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RandomSource $random, int $read_data_subset_percent, ?IndexSet $index = null, int $read_piece = self::READ_PIECE ): self {
		if ( $read_data_subset_percent < 0 || $read_data_subset_percent > 100 ) {
			InvalidArgument::raise( 'The share of packs to read must be between 0 and 100.' );
		}
		if ( $read_piece < 1 ) {
			InvalidArgument::raise( 'The pieces to read must hold at least one byte.' );
		}
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) && ! is_dir( $work_dir ) ) {
			InvalidArgument::raise( 'The working directory cannot be created.' );
		}
		if ( is_file( $work_dir . '/state.json' ) ) {
			InvalidArgument::raise( 'The working directory already holds a check.' );
		}
		$run = new self(
			$repository,
			$index,
			$work_dir,
			$identity,
			$clock,
			[
				'percent' => $read_data_subset_percent,
				'seed'    => bin2hex( $random->bytes( 16 ) ),
				'piece'   => $read_piece,
			]
		);
		$run->locking->remove_own_stale();
		$run->state['lock']      = $run->locking->acquire( false )->hex();
		$run->state['locked_at'] = $clock->now()[0];
		try {
			$run->state['index_files'] = array_keys( $repository->backend()->list( ObjectType::Index ) );
			sort( $run->state['index_files'] );
			$run->persist();
		} catch ( \Throwable $failure ) {
			// Without a checkpoint no later request would release the lock.
			$run->locking->release( Id::from_hex( $run->state['lock'] ) );
			throw $failure;
		}
		return $run;
	}

	/**
	 * Continues a check from its checkpoint.
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
			FormatException::raise( 'The check checkpoint is missing or from another version.' );
		}
		$fields = [];
		foreach ( $state as $key => $value ) {
			$fields[ (string) $key ] = $value;
		}
		return new self( $repository, $index, $work_dir, $identity, $clock, $fields );
	}

	/**
	 * Works until the check is finished or the deadline has passed. Each call does at least one
	 * unit of work, so a slow host still makes progress.
	 *
	 * @param float         $deadline Unix time (microtime(true)) at which to stop.
	 * @param Throttle|null $throttle Pauses for the gentle CPU mode, if any.
	 * @return bool True when finished and the lock is released.
	 */
	public function advance( float $deadline, ?Throttle $throttle = null ): bool {
		if ( 'done' === $this->state['phase'] ) {
			return true;
		}
		$this->throttle = $throttle;
		$this->keep_lock();
		do {
			$phase = $this->state['phase'];
			$this->step();
			if ( 'done' !== $this->state['phase'] && ( $phase !== $this->state['phase'] || $this->clock->now()[0] - $this->persisted_at >= self::CHECKPOINT_SECONDS ) ) {
				// A call that fails or is killed later keeps what it has checked up to here.
				// Only finished units are recorded: a unit that fails ends the call before this.
				$this->persist();
			}
			$throttle?->pause();
			// A long call, such as one without a deadline, must not outlive its lock.
			if ( 'done' !== $this->state['phase'] && $this->locking->due( $this->state['locked_at'] ) ) {
				$this->keep_lock();
			}
		} while ( 'done' !== $this->state['phase'] && microtime( true ) < $deadline );
		$this->persist();
		return 'done' === $this->state['phase'];
	}

	/**
	 * Releases the lock and deletes the checkpoint of an abandoned check.
	 */
	public function abort(): void {
		if ( '' !== $this->state['lock'] ) {
			$this->locking->release( Id::from_hex( $this->state['lock'] ) );
			$this->state['lock'] = '';
		}
		Files::remove_tree( $this->work_dir );
	}

	/**
	 * Progress for the dashboard.
	 *
	 * @return array{phase: string, index_files: int, packs: int, trees: int, pending_trees: int, packs_to_read: int, packs_read: int, errors: int, warnings: int} Progress.
	 */
	public function progress(): array {
		return [
			'phase'         => $this->state['phase'],
			'index_files'   => count( $this->state['index_files'] ),
			'packs'         => count( $this->state['packs'] ),
			'trees'         => count( $this->state['seen'] ),
			'pending_trees' => count( $this->state['frontier'] ),
			'packs_to_read' => count( $this->state['sample'] ),
			'packs_read'    => $this->state['stats']['packs_read'] ?? 0,
			'errors'        => $this->total( 'error' ),
			'warnings'      => $this->total( 'warning' ),
		];
	}

	/**
	 * Outcome once finished.
	 *
	 * @return array{errors: int, warnings: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, stats: array<string, int>} Result.
	 */
	public function result(): array {
		return [
			'errors'   => $this->total( 'error' ),
			'warnings' => $this->total( 'warning' ),
			'counts'   => $this->state['counts'],
			'findings' => $this->state['findings'],
			'stats'    => $this->state['stats'],
		];
	}

	/**
	 * Refreshes the shared lock, or takes a new one if it disappeared or is older than 30
	 * minutes, when other clients no longer honour it. A check only reads, so a gap does no
	 * harm to the repository; what another client removed during a gap can show up as a finding.
	 */
	private function keep_lock(): void {
		$lock = $this->state['lock'];
		if ( '' === $lock || null === $this->repository->backend()->size( ObjectType::Locks, $lock ) || $this->locking->lapsed( $this->state['locked_at'] ) ) {
			$this->state['lock']      = $this->locking->acquire( false )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
			return;
		}
		if ( $this->locking->due( $this->state['locked_at'] ) ) {
			$this->state['lock']      = $this->locking->refresh( Id::from_hex( $lock ), false )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
		}
	}

	/**
	 * Does one unit of work of the current phase.
	 */
	private function step(): void {
		match ( $this->state['phase'] ) {
			'index'     => $this->check_index_file(),
			'packs'     => $this->check_packs(),
			'snapshots' => $this->check_snapshot(),
			'trees'     => $this->check_tree(),
			'read'      => $this->read_pack(),
			default     => $this->finish(),
		};
	}

	/**
	 * Reads one index file and records the expected size and blob count of its packs.
	 *
	 * @throws BackendException If the server cannot be reached.
	 */
	private function check_index_file(): void {
		$name = $this->state['index_files'][ $this->state['position'] ] ?? null;
		if ( null === $name ) {
			$this->state['phase']    = 'packs';
			$this->state['position'] = 0;
			return;
		}
		++$this->state['position'];
		$this->count( 'index_files' );
		try {
			$packs = IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) );
		} catch ( NotFound ) {
			$this->find( 'error', 'index_unreadable', $name, 'The index file disappeared from the server.' );
			return;
		} catch ( BackendException | UnsupportedRepository $exception ) {
			throw $exception;
		} catch ( PluginException $exception ) {
			$this->find( 'error', 'index_unreadable', $name, $exception->getMessage() );
			return;
		}
		foreach ( $packs as $pack => $entries ) {
			if ( isset( $this->state['packs'][ $pack ] ) ) {
				$this->find( 'warning', 'pack_in_several_indexes', $pack, 'The pack is listed in more than one index file.' );
				continue;
			}
			$size = 4 + Cipher::OVERHEAD;
			foreach ( $entries as $entry ) {
				$size += $entry->length + ( $entry->uncompressed_length > 0 ? self::COMPRESSED_ENTRY_SIZE : self::ENTRY_SIZE );
			}
			$this->state['packs'][ $pack ] = [ $size, count( $entries ) ];
		}
	}

	/**
	 * Compares the indexed packs with the server's listing and lists the snapshots to read.
	 *
	 * @throws BackendException If the server cannot be reached.
	 */
	private function check_packs(): void {
		$listed = $this->repository->backend()->list( ObjectType::Data );
		foreach ( $this->state['packs'] as $pack => [ $size ] ) {
			if ( ! isset( $listed[ $pack ] ) ) {
				$this->state['missing'][ $pack ] = true;
				$this->find( 'error', 'pack_missing', $pack, 'An indexed pack is missing on the server.' );
			} elseif ( -1 !== $listed[ $pack ] && $listed[ $pack ] !== $size ) {
				$this->find( 'error', 'pack_size', $pack, sprintf( 'The pack has %d bytes; the index describes %d.', $listed[ $pack ], $size ) );
			}
		}
		foreach ( array_keys( $listed ) as $pack ) {
			if ( ! isset( $this->state['packs'][ $pack ] ) ) {
				$this->find( 'warning', 'pack_unindexed', $pack, 'No index file lists the pack; restic prune removes it.' );
			}
		}
		$this->stat( 'packs', count( $this->state['packs'] ) );

		$names = array_map( 'strval', array_keys( $this->repository->backend()->list( ObjectType::Snapshots ) ) );
		sort( $names );
		$this->state['snapshot_names'] = $names;
		$this->state['roots']          = [];
		$this->state['position']       = 0;
		$this->state['phase']          = 'snapshots';
	}

	/**
	 * Reads one snapshot and queues its tree; one snapshot per unit of work, so that a site with
	 * hundreds of snapshots still advances in short ticks.
	 *
	 * @throws BackendException If the server cannot be reached.
	 */
	private function check_snapshot(): void {
		$name = $this->state['snapshot_names'][ $this->state['position'] ] ?? null;
		if ( null === $name ) {
			$this->state['frontier'] = array_map( 'strval', array_keys( $this->state['roots'] ) );
			$this->state['position'] = 0;
			$this->state['phase']    = 'trees';
			return;
		}
		++$this->state['position'];
		$this->count( 'snapshots' );
		try {
			$this->state['roots'][ Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $name ) ) )->tree->hex() ] = true;
		} catch ( NotFound ) {
			$this->find( 'error', 'snapshot_unreadable', $name, 'The snapshot disappeared from the server.' );
		} catch ( BackendException | UnsupportedRepository $exception ) {
			throw $exception;
		} catch ( PluginException $exception ) {
			$this->find( 'error', 'snapshot_unreadable', $name, $exception->getMessage() );
		}
	}

	/**
	 * Walks one tree.
	 *
	 * @throws BackendException If the server cannot be reached.
	 */
	private function check_tree(): void {
		$hex = array_pop( $this->state['frontier'] );
		if ( null === $hex ) {
			$this->choose_sample();
			return;
		}
		if ( isset( $this->state['seen'][ $hex ] ) ) {
			return;
		}
		$this->state['seen'][ $hex ] = true;
		$this->count( 'trees' );

		$index = $this->index();
		$tree  = Id::from_hex( $hex );
		if ( ! $index->has( $tree, BlobType::Tree ) ) {
			$this->find( 'error', 'blob_missing', $hex, 'A tree is missing from the index.' );
			return;
		}
		try {
			$nodes = $this->repository->load_tree( $index, $tree );
		} catch ( NotFound ) {
			// Damage, not a transport error: the pack that holds the tree is gone.
			$this->find( 'error', 'tree_unreadable', $hex, 'The pack that holds the tree is missing on the server.' );
			return;
		} catch ( BackendException | UnsupportedRepository $exception ) {
			throw $exception;
		} catch ( PluginException $exception ) {
			$this->find( 'error', 'tree_unreadable', $hex, $exception->getMessage() );
			return;
		}
		foreach ( $nodes as $node ) {
			if ( null !== $node->subtree && ! isset( $this->state['seen'][ $node->subtree->hex() ] ) ) {
				$this->state['frontier'][] = $node->subtree->hex();
			}
			foreach ( $node->content ?? [] as $blob ) {
				if ( ! $index->has( $blob, BlobType::Data ) ) {
					$this->find( 'error', 'blob_missing', $blob->hex(), 'A data blob is missing from the index.' );
				}
			}
		}
	}

	/**
	 * Chooses the packs to read: a share of the indexed packs that exist, ordered by a keyed
	 * hash, so the choice is random but stable across ticks.
	 */
	private function choose_sample(): void {
		$missing    = $this->state['missing'];
		$candidates = array_values( array_filter( array_map( 'strval', array_keys( $this->state['packs'] ) ), static fn ( string $pack ): bool => ! isset( $missing[ $pack ] ) ) );
		$wanted     = (int) ceil( count( $candidates ) * $this->state['percent'] / 100 );
		$seed       = $this->state['seed'];
		usort( $candidates, static fn ( string $a, string $b ): int => hash( 'sha256', $seed . $a ) <=> hash( 'sha256', $seed . $b ) );
		$this->state['sample']   = array_slice( $candidates, 0, $wanted );
		$this->state['position'] = 0;
		$this->state['phase']    = 'read';
	}

	/**
	 * Downloads one pack of the sample and verifies it completely. A marker written before the
	 * read counts the requests that ended during it; after READ_ATTEMPTS the pack becomes a
	 * warning instead of stopping the check for good. The checkpoint is written before the
	 * marker, so that the request after a killed one starts with the pack the marker names.
	 *
	 * @throws \Throwable Whatever reading the pack throws; the next request tries it again.
	 */
	private function read_pack(): void {
		$pack = $this->state['sample'][ $this->state['position'] ] ?? null;
		if ( null === $pack ) {
			$this->finish();
			return;
		}
		$marker   = $this->work_dir . '/reading.json';
		$previous = is_file( $marker ) ? json_decode( (string) file_get_contents( $marker ), true ) : null;
		$attempts = 1 + ( is_array( $previous ) && $pack === ( $previous['pack'] ?? null ) ? Decoded::count( $previous['attempts'] ?? null ) : 0 );
		if ( $attempts > self::READ_ATTEMPTS ) {
			$this->find( 'warning', 'pack_unverified', $pack, 'Reading the pack did not finish within a request; run restic check --read-data from another computer to verify it.' );
		} else {
			// The checkpoint names this pack before the marker does. Otherwise a request that
			// ends here is followed by one that starts at an earlier pack, replaces the marker,
			// and the attempts for this pack are never counted.
			$this->persist();
			Files::write_atomic(
				$marker,
				json_encode(
					[
						'pack'     => $pack,
						'attempts' => $attempts,
					],
					JSON_THROW_ON_ERROR
				)
			);
			try {
				$this->verify_pack( $pack );
			} catch ( \Throwable $failure ) {
				// An error, unlike a request that was killed, does not count as an attempt.
				Files::remove_tree( $marker );
				throw $failure;
			}
		}
		Files::remove_tree( $marker );
		++$this->state['position'];
	}

	/**
	 * Streams a pack in pieces and verifies it: the hash of all its bytes, its header against
	 * the index, and every blob. Findings follow restic: a pack whose content does not match its
	 * name is reported as that alone.
	 *
	 * @param string $pack Pack ID (hex).
	 * @throws BackendException If the server cannot be reached.
	 * @throws UnsupportedRepository If a blob is compressed and PHP lacks the zstd extension.
	 */
	private function verify_pack( string $pack ): void {
		$backend = $this->repository->backend();
		$size    = $backend->size( ObjectType::Data, $pack );
		if ( null === $size ) {
			$this->pack_missing( $pack );
			return;
		}
		try {
			[ $entries, $header_error ] = $this->pack_header( $pack, $size );
			$index                      = $this->index();
			$statuses                   = [];
			foreach ( $entries as $position => $entry ) {
				$statuses[ $position ] = $this->entry_status( $index, $pack, $entry );
			}
			$context  = hash_init( 'sha256' );
			$failed   = [];
			$verified = 0;
			$offset   = 0;
			$next     = 0;
			while ( $offset < $size ) {
				[ $end, $last ] = self::piece( $entries, $next, $offset, $size, $this->state['piece'] );
				$bytes          = $backend->load( ObjectType::Data, $pack, $offset, $end - $offset );
				hash_update( $context, $bytes );
				if ( strlen( $bytes ) !== $end - $offset ) {
					break; // The pack changed while it was read; its hash cannot match.
				}
				for ( $position = $next; $position < $last; $position++ ) {
					if ( 'verify' !== $statuses[ $position ] ) {
						continue;
					}
					$entry = $entries[ $position ];
					try {
						PackReader::open_blob( $this->repository->cipher(), $entry, substr( $bytes, $entry->offset - $offset, $entry->length ) );
						++$verified;
					} catch ( UnsupportedRepository $exception ) {
						throw $exception;
					} catch ( PluginException $exception ) {
						$failed[ $position ] = $exception->getMessage();
					}
				}
				$offset = $end;
				$next   = $last;
				$this->throttle?->pause(); // A large pack is a long unit of work.
			}
		} catch ( NotFound ) {
			$this->pack_missing( $pack );
			return;
		}
		$this->count( 'packs_read' );
		$this->count( 'bytes_read', $size );
		if ( hash_final( $context ) !== $pack ) {
			$this->find( 'error', 'pack_hash', $pack, 'The pack content does not match its name.' );
			return;
		}
		if ( '' !== $header_error ) {
			$this->find( 'error', 'pack_header', $pack, $header_error );
			return;
		}
		if ( count( $entries ) !== ( $this->state['packs'][ $pack ][1] ?? -1 ) ) {
			$this->find( 'error', 'pack_header', $pack, 'The pack header and the index list different blobs.' );
		}
		foreach ( $entries as $position => $entry ) {
			if ( 'misplaced' === $statuses[ $position ] ) {
				$this->find( 'error', 'pack_header', $pack, 'A blob in the pack header is not indexed at this place: ' . $entry->id->hex() );
			} elseif ( 'compressed' === $statuses[ $position ] ) {
				$this->find( 'warning', 'compressed_unverified', $pack, 'Compressed blobs cannot be verified without the zstd extension.' );
			} elseif ( isset( $failed[ $position ] ) ) {
				$this->find( 'error', 'blob_corrupt', $entry->id->hex(), $failed[ $position ] );
			}
		}
		$this->count( 'blobs_verified', $verified );
	}

	/**
	 * Records a sampled pack that is gone from the server.
	 *
	 * @param string $pack Pack ID (hex).
	 */
	private function pack_missing( string $pack ): void {
		$this->state['missing'][ $pack ] = true;
		$this->find( 'error', 'pack_missing', $pack, 'An indexed pack is missing on the server.' );
	}

	/**
	 * Reads and decodes the header of a pack with two ranged requests.
	 *
	 * @param string $pack Pack ID (hex).
	 * @param int    $size Size of the pack on the server.
	 * @return array{list<PackEntry>, string} Entries, and why the header is unusable (empty if it is fine).
	 * @throws BackendException If the server cannot be reached or the pack is gone.
	 */
	private function pack_header( string $pack, int $size ): array {
		if ( $size < 4 ) {
			return [ [], 'The pack is too small for a header.' ];
		}
		$backend = $this->repository->backend();
		try {
			$length  = PackReader::header_length( $backend->load( ObjectType::Data, $pack, $size - 4, 4 ), $size );
			$entries = PackReader::read_header( $this->repository->cipher(), $backend->load( ObjectType::Data, $pack, $size - 4 - $length, $length ), $size );
		} catch ( BackendException | UnsupportedRepository $exception ) {
			throw $exception;
		} catch ( PluginException $exception ) {
			return [ [], $exception->getMessage() ];
		}
		return [ $entries, '' ];
	}

	/**
	 * Decides what reading a header entry can tell: misplaced (the index does not lead to it),
	 * compressed (zstd is missing), or verify.
	 *
	 * @param IndexSet  $index Index.
	 * @param string    $pack  Pack ID (hex).
	 * @param PackEntry $entry Header entry.
	 * @return string misplaced, compressed or verify.
	 */
	private function entry_status( IndexSet $index, string $pack, PackEntry $entry ): string {
		$location = $index->find( $entry->id, $entry->type );
		if ( null === $location || ( $location->pack->hex() === $pack && ( $location->entry->offset !== $entry->offset || $location->entry->length !== $entry->length ) ) ) {
			return 'misplaced';
		}
		if ( $entry->uncompressed_length > 0 && ! Compression::available() ) {
			return 'compressed';
		}
		return 'verify';
	}

	/**
	 * The next piece to read: whole blobs from the next entry on, together at most $limit bytes
	 * unless a single blob is larger; after the last blob, the rest of the pack (any unlisted
	 * bytes, the header and its length) in pieces of $limit bytes. The header lists its blobs
	 * back to back from offset 0, so the pieces cover the pack exactly once.
	 *
	 * @param list<PackEntry> $entries Header entries in pack order.
	 * @param int             $next    First entry not read yet.
	 * @param int             $offset  First byte not read yet.
	 * @param int             $size    Size of the pack.
	 * @param int             $limit   Bytes to read at once.
	 * @return array{int, int} End of the piece (exclusive) and the first entry after it.
	 */
	private static function piece( array $entries, int $next, int $offset, int $size, int $limit ): array {
		$end  = $offset;
		$last = $next;
		while ( isset( $entries[ $last ] ) && ( $last === $next || $entries[ $last ]->offset + $entries[ $last ]->length - $offset <= $limit ) ) {
			$end = $entries[ $last ]->offset + $entries[ $last ]->length;
			++$last;
		}
		if ( $last === $next ) {
			$end = min( $size, $offset + $limit );
		}
		return [ $end, $last ];
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
	 * Records a finding; details only for the first MAX_FINDINGS, counts for all. When the list
	 * is full, an error takes the place of the newest warning: warnings of an early phase, such
	 * as many packs that no index lists, must not hide the damage a later phase finds.
	 *
	 * @param string $severity error or warning.
	 * @param string $code     Machine-readable kind.
	 * @param string $subject  Hex ID of the object concerned.
	 * @param string $message  Explanation.
	 */
	private function find( string $severity, string $code, string $subject, string $message ): void {
		$key                           = $severity . ':' . $code;
		$this->state['counts'][ $key ] = ( $this->state['counts'][ $key ] ?? 0 ) + 1;
		$findings                      = $this->state['findings'];
		if ( count( $findings ) >= self::MAX_FINDINGS ) {
			$newest_warning = null;
			foreach ( $findings as $position => $finding ) {
				if ( 'error' !== $finding['severity'] ) {
					$newest_warning = $position;
				}
			}
			if ( 'error' !== $severity || null === $newest_warning ) {
				return;
			}
			unset( $findings[ $newest_warning ] );
		}
		$findings[]              = [
			'severity' => $severity,
			'code'     => $code,
			'object'   => $subject,
			'message'  => $message,
		];
		$this->state['findings'] = array_values( $findings );
	}

	/**
	 * Sums the findings of one severity.
	 *
	 * @param string $severity error or warning.
	 * @return int Number of findings.
	 */
	private function total( string $severity ): int {
		$total = 0;
		foreach ( $this->state['counts'] as $key => $count ) {
			if ( str_starts_with( $key, $severity . ':' ) ) {
				$total += $count;
			}
		}
		return $total;
	}

	/**
	 * Adds to a statistic.
	 *
	 * @param string $name   Statistic.
	 * @param int    $amount Amount.
	 */
	private function count( string $name, int $amount = 1 ): void {
		$this->state['stats'][ $name ] = ( $this->state['stats'][ $name ] ?? 0 ) + $amount;
	}

	/**
	 * Sets a statistic.
	 *
	 * @param string $name  Statistic.
	 * @param int    $value Value.
	 */
	private function stat( string $name, int $value ): void {
		$this->state['stats'][ $name ] = $value;
	}

	/**
	 * Returns the index; without one given, builds it from the index files that can be read.
	 *
	 * @return IndexSet Index.
	 */
	private function index(): IndexSet {
		if ( null === $this->index ) {
			$this->index = $this->readable_index();
		}
		return $this->index;
	}

	/**
	 * The index from every index file that can be read. A damaged index file is a finding of
	 * the index phase; it must not stop the rest of the check.
	 *
	 * @return IndexSet Index.
	 * @throws BackendException If the server cannot be reached.
	 */
	private function readable_index(): IndexSet {
		$index = new IndexSet();
		foreach ( array_keys( $this->repository->backend()->list( ObjectType::Index ) ) as $name ) {
			try {
				$packs = IndexFile::decode( $this->repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) );
			} catch ( NotFound ) {
				continue;
			} catch ( BackendException | UnsupportedRepository $exception ) {
				throw $exception;
			} catch ( PluginException ) {
				continue;
			}
			foreach ( $packs as $pack => $entries ) {
				$index->add_pack( Id::from_hex( $pack ), $entries );
			}
		}
		return $index;
	}

	/**
	 * Reads a map of integers from decoded JSON.
	 *
	 * @param mixed $value Decoded value.
	 * @return array<string, int> Map.
	 */
	private static function int_map( mixed $value ): array {
		$map = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $number ) {
			$map[ (string) $key ] = Decoded::int( $number );
		}
		return $map;
	}

	/**
	 * Writes the checkpoint atomically.
	 */
	private function persist(): void {
		$this->persisted_at = $this->clock->now()[0];
		$state              = $this->state;
		$state['seen']      = array_keys( $state['seen'] );
		$state['roots']     = array_keys( $state['roots'] );
		$state['missing']   = array_keys( $state['missing'] );
		Files::write_atomic( $this->work_dir . '/state.json', json_encode( [ 'version' => self::STATE_VERSION ] + $state, JSON_THROW_ON_ERROR ) );
	}
}
