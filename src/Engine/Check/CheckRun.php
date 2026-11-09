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
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
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
 *    content its ID names.
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

	/** At most this many findings are kept with details; all are counted. */
	public const int MAX_FINDINGS = 50;

	/** Bytes of a pack header entry without and with an uncompressed length. */
	private const int ENTRY_SIZE            = 37;
	private const int COMPRESSED_ENTRY_SIZE = 41;

	/**
	 * Lock handling.
	 *
	 * @var Locking
	 */
	private Locking $locking;

	/**
	 * Checkpoint fields; see persist() for the shape.
	 *
	 * @var array{pid: int, lock: string, locked_at: int, phase: string, position: int, percent: int, seed: string, index_files: list<string>, packs: array<string, array{int, int}>, missing: array<string, true>, snapshot_names: list<string>, roots: array<string, true>, frontier: list<string>, seen: array<string, true>, sample: list<string>, findings: list<array{severity: string, code: string, object: string, message: string}>, counts: array<string, int>, stats: array<string, int>}
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
		$this->locking = new Locking( $repository, $identity, $clock, $this->state['pid'] );
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
	 * @return self Run.
	 * @throws InvalidArgument If the directory already holds a check or the share is out of range.
	 */
	public static function start( Repository $repository, string $work_dir, Identity $identity, Clock $clock, RandomSource $random, int $read_data_subset_percent, ?IndexSet $index = null ): self {
		if ( $read_data_subset_percent < 0 || $read_data_subset_percent > 100 ) {
			InvalidArgument::raise( 'The share of packs to read must be between 0 and 100.' );
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
			]
		);
		$run->locking->remove_own_stale();
		$run->state['lock']        = $run->locking->acquire( false )->hex();
		$run->state['locked_at']   = $clock->now()[0];
		$run->state['index_files'] = array_keys( $repository->backend()->list( ObjectType::Index ) );
		sort( $run->state['index_files'] );
		$run->persist();
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
		$this->keep_lock();
		do {
			$this->step();
			$throttle?->pause();
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
	 * Refreshes the shared lock, or takes a new one if it disappeared. A check only reads, so a
	 * gap is harmless.
	 */
	private function keep_lock(): void {
		$lock = $this->state['lock'];
		if ( '' === $lock || null === $this->repository->backend()->size( ObjectType::Locks, $lock ) ) {
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
		} catch ( BackendException $exception ) {
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
		} catch ( BackendException $exception ) {
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
		} catch ( BackendException $exception ) {
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
	 * Downloads one pack of the sample and verifies it completely.
	 */
	private function read_pack(): void {
		$pack = $this->state['sample'][ $this->state['position'] ] ?? null;
		if ( null === $pack ) {
			$this->finish();
			return;
		}
		++$this->state['position'];
		try {
			$content = $this->repository->backend()->load( ObjectType::Data, $pack );
		} catch ( NotFound ) {
			$this->state['missing'][ $pack ] = true;
			$this->find( 'error', 'pack_missing', $pack, 'An indexed pack is missing on the server.' );
			return;
		}
		$this->count( 'packs_read' );
		$this->count( 'bytes_read', strlen( $content ) );
		if ( ! Id::of( $content )->equals( Id::from_hex( $pack ) ) ) {
			$this->find( 'error', 'pack_hash', $pack, 'The pack content does not match its name.' );
			return;
		}
		$cipher = $this->repository->cipher();
		try {
			$size    = strlen( $content );
			$length  = PackReader::header_length( substr( $content, -4 ), $size );
			$entries = PackReader::read_header( $cipher, substr( $content, $size - 4 - $length, $length ), $size );
		} catch ( PluginException $exception ) {
			$this->find( 'error', 'pack_header', $pack, $exception->getMessage() );
			return;
		}
		if ( count( $entries ) !== ( $this->state['packs'][ $pack ][1] ?? -1 ) ) {
			$this->find( 'error', 'pack_header', $pack, 'The pack header and the index list different blobs.' );
		}
		$index = $this->index();
		foreach ( $entries as $entry ) {
			$location = $index->find( $entry->id, $entry->type );
			if ( null === $location || ( $location->pack->hex() === $pack && ( $location->entry->offset !== $entry->offset || $location->entry->length !== $entry->length ) ) ) {
				$this->find( 'error', 'pack_header', $pack, 'A blob in the pack header is not indexed at this place: ' . $entry->id->hex() );
				continue;
			}
			if ( $entry->uncompressed_length > 0 && ! Compression::available() ) {
				$this->find( 'warning', 'compressed_unverified', $pack, 'Compressed blobs cannot be verified without the zstd extension.' );
				continue;
			}
			try {
				PackReader::open_blob( $cipher, $entry, substr( $content, $entry->offset, $entry->length ) );
				$this->count( 'blobs_verified' );
			} catch ( PluginException $exception ) {
				$this->find( 'error', 'blob_corrupt', $entry->id->hex(), $exception->getMessage() );
			}
		}
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
	 * Records a finding; details only for the first MAX_FINDINGS, counts for all.
	 *
	 * @param string $severity error or warning.
	 * @param string $code     Machine-readable kind.
	 * @param string $subject  Hex ID of the object concerned.
	 * @param string $message  Explanation.
	 */
	private function find( string $severity, string $code, string $subject, string $message ): void {
		$key                           = $severity . ':' . $code;
		$this->state['counts'][ $key ] = ( $this->state['counts'][ $key ] ?? 0 ) + 1;
		if ( count( $this->state['findings'] ) < self::MAX_FINDINGS ) {
			$this->state['findings'][] = [
				'severity' => $severity,
				'code'     => $code,
				'object'   => $subject,
				'message'  => $message,
			];
		}
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
			} catch ( BackendException $exception ) {
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
		$state            = $this->state;
		$state['seen']    = array_keys( $state['seen'] );
		$state['roots']   = array_keys( $state['roots'] );
		$state['missing'] = array_keys( $state['missing'] );
		Files::write_atomic( $this->work_dir . '/state.json', json_encode( [ 'version' => self::STATE_VERSION ] + $state, JSON_THROW_ON_ERROR ) );
	}
}
