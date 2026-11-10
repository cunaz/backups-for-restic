<?php
/**
 * Resumable backup.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Backup;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\ChunkReader;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\SnapshotSummary;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\StreamByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * A backup that runs in bounded slices and survives being killed between them (ADR 0018).
 *
 * The file system is walked depth first. Every directory on the walk is a frame on a stack that
 * is checkpointed to state.json; finished entries are appended as node JSON lines to a file
 * per frame. Large files resume at their last chunk boundary, which yields the same chunks as
 * an uninterrupted run because the chunker restarts at every boundary. Packs are uploaded at
 * every checkpoint, index files at least every ten minutes and at the end, and the snapshot
 * last, so the repository never references data that is not stored.
 *
 * Absolute paths are stored like restic stores them: the snapshot's root tree contains the
 * leading directories (for example var/www) with their metadata, then the backed-up content.
 *
 * File names are arbitrary bytes, so the checkpoint stores names and paths Go-quoted, which is
 * lossless and valid JSON.
 */
final class BackupRun {

	private const int STATE_VERSION          = 1;
	private const int INDEX_INTERVAL_SECONDS = 600;
	private const int MAX_WARNINGS           = 50;

	/**
	 * Checkpointed state.
	 *
	 * @var array<string, mixed>
	 */
	private array $state;

	/**
	 * Blob writer.
	 *
	 * @var BlobStore
	 */
	private BlobStore $store;

	/**
	 * Chunker for the repository polynomial.
	 *
	 * @var RabinChunker
	 */
	private RabinChunker $chunker;

	/**
	 * Node builder.
	 *
	 * @var NodeFactory
	 */
	private NodeFactory $factory;

	/**
	 * Lock handling.
	 *
	 * @var Locking
	 */
	private Locking $locking;

	/**
	 * Parent trees by tree ID: nodes by name.
	 *
	 * @var array<string, array<string, Node>>
	 */
	private array $parent_trees = [];

	/**
	 * End of the current slice.
	 *
	 * @var float
	 */
	private float $deadline = INF;

	/**
	 * Index shared with the blob store, which adds uploaded packs to it.
	 *
	 * @var IndexSet
	 */
	private IndexSet $index;

	/**
	 * Creates the run.
	 *
	 * @param Repository           $repository Repository.
	 * @param IndexSet             $index      Index.
	 * @param BackupPlan           $plan       Plan.
	 * @param string               $work_dir   Private working directory.
	 * @param Identity             $identity   Writer identity.
	 * @param Clock                $clock      Time source.
	 * @param array<string, mixed> $state      State.
	 * @param int                  $pack_size  Target pack size.
	 */
	private function __construct(
		private readonly Repository $repository,
		IndexSet $index,
		private readonly BackupPlan $plan,
		private readonly string $work_dir,
		private readonly Identity $identity,
		private readonly Clock $clock,
		array $state,
		int $pack_size
	) {
		$this->state   = $state;
		$this->index   = $index;
		$this->store   = new BlobStore( $repository, $index, $pack_size );
		$this->chunker = new RabinChunker( $repository->config()->chunker_polynomial );
		$this->factory = new NodeFactory();
		$this->locking = new Locking( $repository, $identity, $clock, Decoded::int( $state['pid'] ?? 0 ) );
	}

	/**
	 * Starts a backup.
	 *
	 * @param Repository    $repository Repository.
	 * @param BackupPlan    $plan       Plan.
	 * @param string        $work_dir   Empty private directory for the checkpoint.
	 * @param Identity      $identity   Writer identity.
	 * @param Clock         $clock      Time source.
	 * @param Id|null       $parent_id  Parent snapshot whose unchanged files are reused.
	 * @param int           $pack_size Target pack size.
	 * @param IndexSet|null $index     Current index, for example from the IndexCache; loaded from the server if null.
	 * @return self Run.
	 * @throws InvalidArgument If the directory already holds a backup.
	 */
	public static function start( Repository $repository, BackupPlan $plan, string $work_dir, Identity $identity, Clock $clock, ?Id $parent_id = null, int $pack_size = BlobStore::DEFAULT_PACK_SIZE, ?IndexSet $index = null ): self {
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) ) {
			throw new InvalidArgument( 'The working directory cannot be created.' );
		}
		if ( is_file( $work_dir . '/state.json' ) ) {
			throw new InvalidArgument( 'The working directory already holds a backup.' );
		}

		[ $seconds, $nanoseconds ] = $clock->now();
		$state                     = [
			'version'          => self::STATE_VERSION,
			'started'          => Rfc3339::format( $seconds, $nanoseconds ),
			'paths'            => array_map( GoQuote::quote( ... ), $plan->paths ),
			'tags'             => $plan->tags,
			'parent'           => $parent_id?->hex(),
			'pid'              => random_int( 100000, 2147483647 ),
			'lock'             => '',
			'locked_at'        => 0,
			'stack'            => [],
			'sequence'         => 0,
			'root_tree'        => null,
			'snapshot'         => null,
			'unindexed'        => [],
			'stats'            => [],
			'counters'         => [],
			'warnings'         => [],
			'warning_count'    => 0,
			'index_written_at' => $seconds,
		];

		$run = new self( $repository, $index ?? $repository->load_index(), $plan, $work_dir, $identity, $clock, $state, $pack_size );
		$run->locking->remove_own_stale();
		$run->state['lock']      = $run->locking->acquire( false )->hex();
		$run->state['locked_at'] = $seconds;

		try {
			$root_tree = null;
			if ( null !== $parent_id ) {
				$root_tree = Snapshot::from_json( $repository->load_unpacked( ObjectType::Snapshots, $parent_id ) )->tree->hex();
			}
			$run->push_frame( '', 'root', $root_tree, null );
			$run->persist();
		} catch ( \Throwable $failure ) {
			// No checkpoint names the lock yet, so nothing else would ever release it.
			try {
				$run->abort();
			} catch ( PluginException $unreleased ) {
				unset( $unreleased ); // Stale after 30 minutes; the first failure is the one to report.
			}
			throw $failure;
		}
		return $run;
	}

	/**
	 * Continues a backup from its checkpoint.
	 *
	 * @param Repository    $repository Repository.
	 * @param BackupPlan    $plan       Plan (rebuilt from the settings).
	 * @param string        $work_dir   Directory with the checkpoint.
	 * @param Identity      $identity   Writer identity.
	 * @param Clock         $clock      Time source.
	 * @param int           $pack_size Target pack size.
	 * @param IndexSet|null $index     Current index, for example from the IndexCache; loaded from the server if null.
	 * @return self Run.
	 * @throws FormatException If the checkpoint is unusable.
	 */
	public static function resume( Repository $repository, BackupPlan $plan, string $work_dir, Identity $identity, Clock $clock, int $pack_size = BlobStore::DEFAULT_PACK_SIZE, ?IndexSet $index = null ): self {
		$json  = @file_get_contents( $work_dir . '/state.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing checkpoint is reported below.
		$state = false === $json ? null : GoJson::decode( $json );
		if ( ! is_array( $state ) || self::STATE_VERSION !== ( $state['version'] ?? null ) ) {
			throw new FormatException( 'The backup checkpoint is missing or from another version.' );
		}
		/**
		 * Validated above to be an array.
		 *
		 * @var array<string, mixed> $state
		 */
		$run = new self( $repository, $index ?? $repository->load_index(), $plan, $work_dir, $identity, $clock, $state, $pack_size );
		$run->store->import_unindexed( is_array( $state['unindexed'] ?? null ) ? $state['unindexed'] : [] );
		$run->store->import_stats( is_array( $state['stats'] ?? null ) ? $state['stats'] : [] );

		// Drop node lines written after the checkpoint.
		foreach ( $run->frames() as $frame ) {
			$file = $run->nodes_file( Decoded::int( $frame['sequence'] ) );
			$size = is_file( $file ) ? (int) filesize( $file ) : 0;
			if ( $size < Decoded::int( $frame['nodes_bytes'] ) ) {
				throw new FormatException( 'The backup checkpoint lost entries.' );
			}
			if ( $size > Decoded::int( $frame['nodes_bytes'] ) ) {
				$handle = fopen( $file, 'c' );
				if ( false !== $handle ) {
					ftruncate( $handle, max( 0, Decoded::int( $frame['nodes_bytes'] ) ) );
					fclose( $handle );
				}
			}
		}
		return $run;
	}

	/**
	 * Works until the backup is finished or the deadline has passed.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when the snapshot has been written.
	 */
	public function advance( float $deadline ): bool {
		if ( null !== $this->state['snapshot'] ) {
			return true;
		}
		$this->deadline = $deadline;
		$this->keep_lock();

		// At least one step per slice, so that progress is guaranteed even on a slow host.
		if ( [] !== $this->frames() ) {
			do {
				$this->step();
			} while ( [] !== $this->frames() && microtime( true ) < $deadline );
		}

		if ( [] === $this->frames() ) {
			$this->finish();
			return true;
		}
		$this->checkpoint();
		return false;
	}

	/**
	 * Releases the lock and deletes the checkpoint of an abandoned backup.
	 */
	public function abort(): void {
		if ( '' !== $this->state['lock'] ) {
			$this->locking->release( Id::from_hex( Decoded::string( $this->state['lock'] ) ) );
		}
		$this->remove_work_files( true );
	}

	/**
	 * Returns the snapshot ID once finished.
	 *
	 * @return Id|null Snapshot.
	 */
	public function snapshot_id(): ?Id {
		return is_string( $this->state['snapshot'] ) ? Id::from_hex( $this->state['snapshot'] ) : null;
	}

	/**
	 * Returns counters for progress display and logs.
	 *
	 * @return array<string, int|string> Counters, the current directory and warnings count.
	 */
	public function progress(): array {
		$frames  = $this->frames();
		$current = [] === $frames ? '' : GoQuote::unquote( Decoded::string( end( $frames )['path'] ) );
		return array_merge(
			$this->counters(),
			$this->store->stats(),
			[
				'current'  => $current,
				'warnings' => Decoded::int( $this->state['warning_count'] ),
			]
		);
	}

	/**
	 * Returns the recorded warnings (first fifty).
	 *
	 * @return list<string> Messages with paths.
	 */
	public function warnings(): array {
		return array_values( array_filter( is_array( $this->state['warnings'] ) ? $this->state['warnings'] : [], 'is_string' ) );
	}

	/**
	 * Advances the top frame by one entry or finishes it.
	 */
	private function step(): void {
		$depth = count( $this->frames() ) - 1;
		$frame = $this->frames()[ $depth ];
		$next  = Decoded::int( $frame['next'] );

		if ( $next >= count( Decoded::strings( $frame['entries'] ) ) ) {
			$this->finish_frame( $depth );
			return;
		}

		$name      = GoQuote::unquote( Decoded::strings( $frame['entries'] )[ $next ] );
		$directory = GoQuote::unquote( Decoded::string( $frame['path'] ) );
		$path      = $directory . '/' . $name;

		if ( in_array( GoQuote::quote( $name ), Decoded::strings( $frame['virtual'] ?? [] ), true ) ) {
			$this->back_up_virtual( $depth, $name, $directory );
			return;
		}

		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Vanished entries are recorded as warnings.
		if ( false === $stat ) {
			$this->warn( 'Entry vanished or cannot be read: ' . $path );
			$this->skip( $depth );
			return;
		}

		$type = FileMode::type_from_posix( $stat['mode'] );
		if ( 'target' === $frame['kind'] && $this->plan->excluded( $path, NodeType::Dir === $type ) ) {
			$this->skip( $depth );
			return;
		}

		switch ( $type ) {
			case NodeType::Dir:
				$this->enter_directory( $depth, $name, $path, $stat );
				break;
			case NodeType::File:
				$this->back_up_file( $depth, $name, $path, $stat );
				break;
			case NodeType::Symlink:
				$this->back_up_symlink( $depth, $name, $path, $stat );
				break;
			default:
				$this->skip_special( $depth, $path );
		}
	}

	/**
	 * Pushes a frame for a directory.
	 *
	 * @param int                    $depth Depth of the parent frame.
	 * @param string                 $name  Directory name.
	 * @param string                 $path  Absolute path.
	 * @param array<int|string, int> $stat  Result of lstat().
	 */
	private function enter_directory( int $depth, string $name, string $path, array $stat ): void {
		$parent_frame = $this->frames()[ $depth ];
		$kind         = 'target' === $parent_frame['kind'] || in_array( $path, $this->paths(), true ) ? 'target' : 'intermediate';
		$previous     = $this->parent_node( $depth, $name );
		$subtree      = null !== $previous && NodeType::Dir === $previous->type ? $previous->subtree?->hex() : null;
		$this->push_frame( $path, $kind, $subtree, $stat );
	}

	/**
	 * Backs up a regular file, possibly across several slices.
	 *
	 * @param int                    $depth Frame depth.
	 * @param string                 $name  File name.
	 * @param string                 $path  Absolute path.
	 * @param array<int|string, int> $stat  Result of lstat().
	 */
	private function back_up_file( int $depth, string $name, string $path, array $stat ): void {
		$frame       = $this->frames()[ $depth ];
		$previous    = $this->parent_node( $depth, $name );
		$fingerprint = implode( ':', [ $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] ] );
		$file        = is_array( $frame['file'] ) && GoQuote::quote( $name ) === $frame['file']['name'] && $fingerprint === $frame['file']['fingerprint'] ? $frame['file'] : null;

		if ( null === $file && $this->unchanged( $previous, $stat ) && null !== $previous ) {
			$this->count( 'files_unmodified' );
			$this->count( 'total_files_processed' );
			$this->count( 'total_bytes_processed', $stat['size'] );
			$this->append_node( $depth, $this->factory->from_stat( $name, $stat, $previous->content ) );
			return;
		}

		$file ??= [
			'name'        => GoQuote::quote( $name ),
			'fingerprint' => $fingerprint,
			'offset'      => 0,
			'content'     => [],
		];

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are recorded as warnings.
		if ( false === $handle ) {
			$this->warn( 'File cannot be read: ' . $path );
			$this->set_file( $depth, null );
			$this->skip( $depth );
			return;
		}

		$offset  = Decoded::int( $file['offset'] );
		$content = Decoded::strings( $file['content'] );
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$reader = new ChunkReader( new StreamByteSource( $handle ), $this->chunker );
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			$content[] = $this->store->save( BlobType::Data, $chunk )->hex();
			$offset   += strlen( $chunk );
			if ( microtime( true ) >= $this->deadline ) {
				fclose( $handle );
				$file['offset']  = $offset;
				$file['content'] = $content;
				$this->set_file( $depth, $file );
				return;
			}
		}
		fclose( $handle );

		if ( $offset !== $stat['size'] ) {
			$this->warn( 'File changed while it was read: ' . $path );
			$stat['size'] = $offset;
		}

		$this->count( null === $previous ? 'files_new' : 'files_changed' );
		$this->count( 'total_files_processed' );
		$this->count( 'total_bytes_processed', $offset );
		$this->set_file( $depth, null );
		$this->append_node( $depth, $this->factory->from_stat( $name, $stat, array_map( Id::from_hex( ... ), $content ) ) );
	}

	/**
	 * Backs up a virtual file. A resumable source stops at a clean boundary once the deadline
	 * has passed and continues from its cursor in the next slice; any other source is read in
	 * one go and restarts from its beginning if the backup is interrupted inside it.
	 *
	 * @param int    $depth     Frame depth.
	 * @param string $name      File name.
	 * @param string $directory Absolute directory path.
	 */
	private function back_up_virtual( int $depth, string $name, string $directory ): void {
		$file = $this->plan->virtual_entries( $directory )[ $name ] ?? null;
		if ( null === $file ) {
			$this->warn( 'Virtual file is no longer available: ' . $directory . '/' . $name );
			$this->set_file( $depth, null );
			$this->skip( $depth );
			return;
		}

		$frame   = $this->frames()[ $depth ];
		$partial = is_array( $frame['file'] ) && GoQuote::quote( $name ) === $frame['file']['name'] && true === ( $frame['file']['virtual'] ?? false ) ? $frame['file'] : null;
		$cursor  = null !== $partial && is_string( $partial['cursor'] ?? null ) && '' !== $partial['cursor'] ? $partial['cursor'] : null;
		$content = null === $partial ? [] : Decoded::strings( $partial['content'] );
		$size    = null === $partial ? 0 : Decoded::int( $partial['offset'] );

		$source   = ( $file->source )( $cursor );
		$reader   = new ChunkReader( $source, $this->chunker );
		$stopping = false;
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			$content[] = $this->store->save( BlobType::Data, $chunk )->hex();
			$size     += strlen( $chunk );
			if ( ! $stopping && $source instanceof ResumableByteSource && microtime( true ) >= $this->deadline ) {
				$source->stop_at_boundary();
				$stopping = true;
			}
		}

		$next = $source instanceof ResumableByteSource ? $source->cursor() : null;
		if ( null !== $next ) {
			$this->set_file(
				$depth,
				[
					'name'    => GoQuote::quote( $name ),
					'virtual' => true,
					'cursor'  => $next,
					'offset'  => $size,
					'content' => $content,
				]
			);
			return;
		}

		$this->set_file( $depth, null );
		$this->count( null === $this->parent_node( $depth, $name ) ? 'files_new' : 'files_changed' );
		$this->count( 'total_files_processed' );
		$this->count( 'total_bytes_processed', $size );
		$this->append_node( $depth, $this->factory->virtual( $file, $size, array_map( Id::from_hex( ... ), $content ) ) );
	}

	/**
	 * Records a symlink without following it.
	 *
	 * @param int                    $depth Frame depth.
	 * @param string                 $name  Link name.
	 * @param string                 $path  Absolute path.
	 * @param array<int|string, int> $stat  Result of lstat().
	 */
	private function back_up_symlink( int $depth, string $name, string $path, array $stat ): void {
		$target = @readlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable links are recorded as warnings.
		if ( false === $target ) {
			$this->warn( 'Symlink cannot be read: ' . $path );
			$this->skip( $depth );
			return;
		}
		$this->append_node( $depth, $this->factory->from_stat( $name, $stat, null, null, $target ) );
	}

	/**
	 * Skips devices, sockets and pipes, which have no place in a web site backup.
	 *
	 * @param int    $depth Frame depth.
	 * @param string $path  Absolute path.
	 */
	private function skip_special( int $depth, string $path ): void {
		$this->warn( 'Special file skipped: ' . $path );
		$this->skip( $depth );
	}

	/**
	 * Builds the tree of the top frame, pops it and adds the directory node to its parent.
	 *
	 * @param int $depth Frame depth.
	 */
	private function finish_frame( int $depth ): void {
		$frame = $this->frames()[ $depth ];
		$lines = '';
		if ( Decoded::int( $frame['nodes_bytes'] ) > 0 ) {
			$lines = (string) file_get_contents( $this->nodes_file( Decoded::int( $frame['sequence'] ) ), false, null, 0, Decoded::int( $frame['nodes_bytes'] ) );
		}
		$nodes = '' === $lines ? [] : explode( "\n", rtrim( $lines, "\n" ) );
		$tree  = $this->store->save( BlobType::Tree, '{"nodes":[' . implode( ',', $nodes ) . "]}\n" );

		$stack = $this->frames();
		array_pop( $stack );
		$this->state['stack'] = $stack;

		if ( 0 === $depth ) {
			$this->state['root_tree'] = $tree->hex();
			return;
		}

		$path     = GoQuote::unquote( Decoded::string( $frame['path'] ) );
		$name     = substr( $path, (int) strrpos( $path, '/' ) + 1 );
		$previous = $this->parent_node( $depth - 1, $name );
		if ( null === $previous ) {
			$this->count( 'dirs_new' );
		} else {
			$this->count( null !== $previous->subtree && $previous->subtree->equals( $tree ) ? 'dirs_unmodified' : 'dirs_changed' );
		}

		$stat = array_filter( is_array( $frame['stat'] ) ? $frame['stat'] : [], 'is_int' );
		$this->append_node( $depth - 1, $this->factory->from_stat( $name, $stat, null, $tree ) );
	}

	/**
	 * Uploads remaining data, writes index and snapshot, releases the lock.
	 */
	private function finish(): void {
		$this->store->flush();
		$this->store->write_index();

		[ $seconds, $nanoseconds ] = $this->clock->now();
		$counters                  = $this->counters();
		$stats                     = $this->store->stats();
		$summary                   = new SnapshotSummary(
			Decoded::string( $this->state['started'] ),
			Rfc3339::format( $seconds, $nanoseconds ),
			$counters['files_new'],
			$counters['files_changed'],
			$counters['files_unmodified'],
			$counters['dirs_new'],
			$counters['dirs_changed'],
			$counters['dirs_unmodified'],
			$stats['data_blobs'],
			$stats['tree_blobs'],
			$stats['data_added'],
			$stats['data_added_packed'],
			$counters['total_files_processed'],
			$counters['total_bytes_processed']
		);
		$snapshot                  = new Snapshot(
			Decoded::string( $this->state['started'] ),
			Id::from_hex( Decoded::string( $this->state['root_tree'] ) ),
			$this->paths(),
			$this->identity->hostname,
			$this->identity->username,
			$this->identity->uid,
			$this->identity->gid,
			[],
			Decoded::strings( $this->state['tags'] ),
			is_string( $this->state['parent'] ) ? Id::from_hex( $this->state['parent'] ) : null,
			null,
			$this->identity->program,
			$summary
		);

		$this->state['snapshot'] = $this->repository->save_unpacked( ObjectType::Snapshots, $snapshot->to_json() )->hex();
		$this->locking->release( Id::from_hex( Decoded::string( $this->state['lock'] ) ) );
		$this->state['lock']      = '';
		$this->state['unindexed'] = [];
		$this->persist();
		$this->remove_work_files( false );
	}

	/**
	 * Uploads packs, writes an index file when due and persists the state.
	 */
	private function checkpoint(): void {
		$this->store->flush();
		$now = $this->clock->now()[0];
		if ( $this->store->unindexed_blob_count() >= IndexFile::MAX_BLOBS || $now - Decoded::int( $this->state['index_written_at'] ) >= self::INDEX_INTERVAL_SECONDS ) {
			$this->store->write_index();
			$this->state['index_written_at'] = $now;
		}
		$this->state['unindexed'] = $this->store->export_unindexed();
		$this->state['stats']     = $this->store->stats();
		$this->persist();
		$this->remove_work_files( false );
	}

	/**
	 * Refreshes the lock when due. After a pause of more than 30 minutes, or when the lock is
	 * gone, the lock is taken again, which waits for a client that holds an exclusive lock by
	 * now. A client that removed data during such a pause is not noticed here; the next check
	 * reports what a snapshot then misses.
	 *
	 * @throws LockConflict If another client holds an exclusive lock.
	 */
	private function keep_lock(): void {
		$lock = Decoded::string( $this->state['lock'] );
		if ( '' === $lock ) {
			return;
		}
		$gone = null === $this->repository->backend()->size( ObjectType::Locks, $lock );
		if ( $gone || $this->locking->lapsed( Decoded::int( $this->state['locked_at'] ) ) ) {
			$this->state['lock']      = $this->locking->acquire( false )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
			return;
		}
		if ( $this->locking->due( Decoded::int( $this->state['locked_at'] ) ) ) {
			$this->state['lock']      = $this->locking->refresh( Id::from_hex( $lock ), false )->hex();
			$this->state['locked_at'] = $this->clock->now()[0];
		}
	}

	/**
	 * Whether a file can reuse the content recorded in the parent snapshot (restic's rules).
	 *
	 * @param Node|null              $previous Node from the parent snapshot.
	 * @param array<int|string, int> $stat     Result of lstat().
	 * @return bool True if size, times and inode are unchanged and all blobs are stored.
	 */
	private function unchanged( ?Node $previous, array $stat ): bool {
		if ( null === $previous || NodeType::File !== $previous->type || null === $previous->content ) {
			return false;
		}
		if ( $previous->size !== $stat['size'] || $previous->mtime !== Rfc3339::format( $stat['mtime'] )
			|| $previous->ctime !== Rfc3339::format( $stat['ctime'] ) || (string) $previous->inode !== (string) $stat['ino'] ) {
			return false;
		}
		foreach ( $previous->content as $id ) {
			if ( ! $this->store->has( BlobType::Data, $id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Finds an entry of the parent snapshot for the directory at a depth.
	 *
	 * @param int    $depth Frame depth.
	 * @param string $name  Entry name.
	 * @return Node|null Node, or null if the parent snapshot lacks it.
	 */
	private function parent_node( int $depth, string $name ): ?Node {
		$tree = $this->frames()[ $depth ]['parent_tree'] ?? null;
		if ( ! is_string( $tree ) ) {
			return null;
		}
		if ( ! isset( $this->parent_trees[ $tree ] ) ) {
			$nodes = [];
			try {
				foreach ( $this->repository->load_tree( $this->index, Id::from_hex( $tree ) ) as $node ) {
					$nodes[ $node->name ] = $node;
				}
			} catch ( FormatException ) {
				$this->warn( 'The parent snapshot is incomplete; files are read again.' );
			}
			if ( count( $this->parent_trees ) > 64 ) {
				$this->parent_trees = [];
			}
			$this->parent_trees[ $tree ] = $nodes;
		}
		return $this->parent_trees[ $tree ][ $name ] ?? null;
	}

	/**
	 * Pushes a new frame.
	 *
	 * @param string                      $path        Absolute directory path, empty for the root.
	 * @param string                      $kind        root, intermediate or target.
	 * @param string|null                 $parent_tree Tree of this directory in the parent snapshot.
	 * @param array<int|string, int>|null $stat        Result of lstat() for the directory.
	 */
	private function push_frame( string $path, string $kind, ?string $parent_tree, ?array $stat ): void {
		$virtual                 = 'target' === $kind ? array_keys( $this->plan->virtual_entries( $path ) ) : [];
		$entries                 = 'target' === $kind ? $this->list_directory( $path, $virtual ) : $this->leading_entries( $path );
		$this->state['sequence'] = Decoded::int( $this->state['sequence'] ) + 1;
		$sequence                = Decoded::int( $this->state['sequence'] );
		file_put_contents( $this->nodes_file( $sequence ), '' );

		$stack                = $this->frames();
		$stack[]              = [
			'sequence'    => $sequence,
			'path'        => GoQuote::quote( $path ),
			'kind'        => $kind,
			'entries'     => array_map( GoQuote::quote( ... ), $entries ),
			'virtual'     => array_map( GoQuote::quote( ... ), array_map( 'strval', $virtual ) ),
			'next'        => 0,
			'nodes_bytes' => 0,
			'parent_tree' => $parent_tree,
			'stat'        => null === $stat ? null : array_filter( $stat, 'is_string', ARRAY_FILTER_USE_KEY ),
			'file'        => null,
		];
		$this->state['stack'] = $stack;
	}

	/**
	 * Lists a directory in restic's order (byte order), including virtual files.
	 *
	 * @param string       $path    Absolute path.
	 * @param list<string> $virtual Names of virtual files.
	 * @return list<string> Names.
	 */
	private function list_directory( string $path, array $virtual ): array {
		$names = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable directories are recorded as warnings.
		if ( false === $names ) {
			$this->warn( 'Directory cannot be read: ' . $path );
			$names = [];
		}
		$names = array_merge( array_diff( $names, [ '.', '..' ] ), $virtual );
		$names = array_values( array_unique( array_map( 'strval', $names ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Returns the next path components that lead from a directory to the backup targets.
	 *
	 * @param string $path Absolute path, empty for the root.
	 * @return list<string> Names.
	 */
	private function leading_entries( string $path ): array {
		$names = [];
		foreach ( $this->paths() as $target ) {
			if ( str_starts_with( $target, $path . '/' ) ) {
				$names[] = explode( '/', substr( $target, strlen( $path ) + 1 ) )[0];
			}
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Appends a node to a frame and advances it.
	 *
	 * @param int  $depth Frame depth.
	 * @param Node $node  Node.
	 * @throws FormatException If the node file cannot be written.
	 */
	private function append_node( int $depth, Node $node ): void {
		$frame = $this->frames()[ $depth ];
		$line  = $node->to_json() . "\n";
		if ( false === file_put_contents( $this->nodes_file( Decoded::int( $frame['sequence'] ) ), $line, FILE_APPEND ) ) {
			throw new FormatException( 'The backup checkpoint cannot be written.' );
		}
		$stack                          = $this->frames();
		$stack[ $depth ]['nodes_bytes'] = Decoded::int( $frame['nodes_bytes'] ) + strlen( $line );
		$stack[ $depth ]['next']        = Decoded::int( $frame['next'] ) + 1;
		$this->state['stack']           = $stack;
	}

	/**
	 * Advances a frame without adding a node.
	 *
	 * @param int $depth Frame depth.
	 */
	private function skip( int $depth ): void {
		$stack                   = $this->frames();
		$stack[ $depth ]['next'] = Decoded::int( $stack[ $depth ]['next'] ) + 1;
		$this->state['stack']    = $stack;
	}

	/**
	 * Stores the progress of a file in its frame.
	 *
	 * @param int               $depth Frame depth.
	 * @param array<mixed>|null $file  File progress.
	 */
	private function set_file( int $depth, ?array $file ): void {
		$stack                   = $this->frames();
		$stack[ $depth ]['file'] = $file;
		$this->state['stack']    = $stack;
	}

	/**
	 * Records a warning; the first fifty are kept with their path.
	 *
	 * @param string $message Message.
	 */
	private function warn( string $message ): void {
		$this->state['warning_count'] = Decoded::int( $this->state['warning_count'] ) + 1;
		$warnings                     = Decoded::strings( $this->state['warnings'] );
		if ( count( $warnings ) < self::MAX_WARNINGS ) {
			$warnings[]              = $message;
			$this->state['warnings'] = $warnings;
		}
	}

	/**
	 * Increments a counter.
	 *
	 * @param string $name  Counter.
	 * @param int    $value Amount.
	 */
	private function count( string $name, int $value = 1 ): void {
		$counters                = $this->counters();
		$counters[ $name ]      += $value;
		$this->state['counters'] = $counters;
	}

	/**
	 * Returns the summary counters.
	 *
	 * @return array{files_new: int, files_changed: int, files_unmodified: int, dirs_new: int, dirs_changed: int, dirs_unmodified: int, total_files_processed: int, total_bytes_processed: int}
	 */
	private function counters(): array {
		$saved  = is_array( $this->state['counters'] ) ? $this->state['counters'] : [];
		$result = [
			'files_new'             => 0,
			'files_changed'         => 0,
			'files_unmodified'      => 0,
			'dirs_new'              => 0,
			'dirs_changed'          => 0,
			'dirs_unmodified'       => 0,
			'total_files_processed' => 0,
			'total_bytes_processed' => 0,
		];
		foreach ( array_keys( $result ) as $key ) {
			$result[ $key ] = Decoded::int( $saved[ $key ] ?? 0 );
		}
		return $result;
	}

	/**
	 * Returns the backup paths from the checkpoint.
	 *
	 * @return list<string> Paths.
	 */
	private function paths(): array {
		return array_map( GoQuote::unquote( ... ), Decoded::strings( $this->state['paths'] ) );
	}

	/**
	 * Returns the frame stack.
	 *
	 * @return list<array<mixed>> Frames.
	 */
	private function frames(): array {
		return Decoded::arrays( $this->state['stack'] );
	}

	/**
	 * Returns the node file of a frame.
	 *
	 * @param int $sequence Frame number.
	 * @return string Path.
	 */
	private function nodes_file( int $sequence ): string {
		return $this->work_dir . '/nodes-' . $sequence . '.jsonl';
	}

	/**
	 * Writes the checkpoint atomically.
	 *
	 * @throws FormatException If it cannot be written.
	 */
	private function persist(): void {
		$temporary = $this->work_dir . '/state.json.tmp';
		if ( false === file_put_contents( $temporary, GoJson::encode( $this->state ) ) || ! rename( $temporary, $this->work_dir . '/state.json' ) ) {
			throw new FormatException( 'The backup checkpoint cannot be written.' );
		}
	}

	/**
	 * Deletes node files that the persisted checkpoint no longer references.
	 *
	 * @param bool $everything Also delete the checkpoint itself.
	 */
	private function remove_work_files( bool $everything ): void {
		$live = [];
		foreach ( $this->frames() as $frame ) {
			$live[ $this->nodes_file( Decoded::int( $frame['sequence'] ) ) ] = true;
		}
		$files = glob( $this->work_dir . '/nodes-*.jsonl' );
		foreach ( false === $files ? [] : $files as $file ) {
			if ( $everything || ! isset( $live[ $file ] ) ) {
				unlink( $file );
			}
		}
		if ( $everything ) {
			foreach ( [ 'state.json', 'state.json.tmp' ] as $name ) {
				if ( is_file( $this->work_dir . '/' . $name ) ) {
					unlink( $this->work_dir . '/' . $name );
				}
			}
		}
	}
}
