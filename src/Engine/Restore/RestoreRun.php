<?php
/**
 * Resumable restore.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Support\Decoded;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a snapshot or a part of it into a staging directory, in bounded slices (ADR 0013).
 *
 * Names come from the repository and are validated before use: a name must not be empty, "."
 * or "..", and must not contain "/" or NUL. Existing symlinks at a destination are removed
 * instead of being written through. Permissions are restored without setuid, setgid and
 * sticky bits.
 */
final class RestoreRun {

	private const int STATE_VERSION = 1;

	/**
	 * Checkpointed state.
	 *
	 * @var array<string, mixed>
	 */
	private array $state;

	/**
	 * Trees loaded in this slice.
	 *
	 * @var array<string, list<Node>>
	 */
	private array $trees = [];

	/**
	 * End of the current slice.
	 *
	 * @var float
	 */
	private float $deadline = INF;

	/**
	 * Creates the run.
	 *
	 * @param Repository           $repository Repository.
	 * @param IndexSet             $index      Index.
	 * @param string               $work_dir   Directory for the checkpoint.
	 * @param array<string, mixed> $state      State.
	 */
	private function __construct(
		private readonly Repository $repository,
		private readonly IndexSet $index,
		private readonly string $work_dir,
		array $state
	) {
		$this->state = $state;
	}

	/**
	 * Starts a restore.
	 *
	 * @param Repository $repository Repository.
	 * @param Id         $snapshot   Snapshot.
	 * @param string     $target     Existing, empty staging directory.
	 * @param string     $work_dir   Directory for the checkpoint.
	 * @param string     $subpath    Absolute path inside the snapshot to restore, or "/" for all.
	 * @return self Run.
	 * @throws InvalidArgument If the target is not a directory or the path does not exist.
	 */
	public static function start( Repository $repository, Id $snapshot, string $target, string $work_dir, string $subpath = '/' ): self {
		$real_target = realpath( $target );
		if ( false === $real_target || ! is_dir( $real_target ) ) {
			throw new InvalidArgument( 'The restore target must be an existing directory.' );
		}
		if ( ! is_dir( $work_dir ) && ! mkdir( $work_dir, 0700, true ) ) {
			throw new InvalidArgument( 'The working directory cannot be created.' );
		}

		$index = $repository->load_index();
		$tree  = Snapshot::from_json( $repository->load_unpacked( ObjectType::Snapshots, $snapshot ) )->tree;
		$only  = null;
		$parts = array_values( array_filter( explode( '/', $subpath ), static fn ( string $part ): bool => '' !== $part ) );
		foreach ( $parts as $position => $part ) {
			$node = self::find( $repository->load_tree( $index, $tree ), $part );
			if ( null === $node ) {
				throw new InvalidArgument( 'The path does not exist in the snapshot.' );
			}
			if ( NodeType::Dir === $node->type && null !== $node->subtree ) {
				$tree = $node->subtree;
				continue;
			}
			if ( count( $parts ) - 1 !== $position ) {
				throw new InvalidArgument( 'The path does not exist in the snapshot.' );
			}
			$only = $part;
		}

		$state = [
			'version' => self::STATE_VERSION,
			'target'  => $real_target,
			'stack'   => [
				[
					'tree' => $tree->hex(),
					'dir'  => '',
					'next' => 0,
					'only' => null === $only ? null : GoQuote::quote( $only ),
				],
			],
			'file'    => null,
			'files'   => 0,
			'bytes'   => 0,
		];
		$run   = new self( $repository, $index, $work_dir, $state );
		$run->persist();
		return $run;
	}

	/**
	 * Continues a restore from its checkpoint.
	 *
	 * @param Repository $repository Repository.
	 * @param string     $work_dir   Directory with the checkpoint.
	 * @return self Run.
	 * @throws FormatException If the checkpoint is unusable.
	 */
	public static function resume( Repository $repository, string $work_dir ): self {
		$json  = @file_get_contents( $work_dir . '/restore.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing checkpoint is reported below.
		$state = false === $json ? null : GoJson::decode( $json );
		if ( ! is_array( $state ) || self::STATE_VERSION !== ( $state['version'] ?? null ) ) {
			throw new FormatException( 'The restore checkpoint is missing or from another version.' );
		}
		/**
		 * Validated above to be an array.
		 *
		 * @var array<string, mixed> $state
		 */
		return new self( $repository, $repository->load_index(), $work_dir, $state );
	}

	/**
	 * Works until everything is restored or the deadline has passed.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when finished.
	 */
	public function advance( float $deadline ): bool {
		$this->deadline = $deadline;
		// At least one step per slice, so that progress is guaranteed even on a slow host.
		if ( [] !== $this->stack() ) {
			do {
				$this->step();
			} while ( [] !== $this->stack() && microtime( true ) < $deadline );
		}
		$this->persist();
		return [] === $this->stack();
	}

	/**
	 * Returns restored files and bytes.
	 *
	 * @return array{files: int, bytes: int} Counters.
	 */
	public function progress(): array {
		return [
			'files' => Decoded::int( $this->state['files'] ),
			'bytes' => Decoded::int( $this->state['bytes'] ),
		];
	}

	/**
	 * Handles one entry of the top frame.
	 */
	private function step(): void {
		$stack = $this->stack();
		$depth = count( $stack ) - 1;
		$frame = $stack[ $depth ];
		$nodes = $this->tree( Decoded::string( $frame['tree'] ) );
		$next  = Decoded::int( $frame['next'] );
		$dir   = GoQuote::unquote( Decoded::string( $frame['dir'] ) );

		if ( $next >= count( $nodes ) ) {
			$this->finish_directory( $depth, $dir );
			return;
		}

		$node = $nodes[ $next ];
		if ( null !== $frame['only'] && GoQuote::quote( $node->name ) !== $frame['only'] ) {
			$this->advance_frame( $depth );
			return;
		}

		self::validate_name( $node->name );
		$relative = '' === $dir ? $node->name : $dir . '/' . $node->name;
		$path     = $this->target() . '/' . $relative;

		switch ( $node->type ) {
			case NodeType::Dir:
				$this->remove_link( $path );
				if ( ! is_dir( $path ) && ! mkdir( $path, 0700 ) ) {
					FormatException::raise( 'A directory cannot be created in the restore target.' );
				}
				$this->advance_frame( $depth );
				$this->push( (string) $node->subtree?->hex(), $relative, $node );
				break;
			case NodeType::File:
				$this->restore_file( $depth, $node, $path );
				break;
			case NodeType::Symlink:
				$this->remove_link( $path );
				if ( ! file_exists( $path ) ) {
					symlink( $node->link_target, $path );
				}
				$this->advance_frame( $depth );
				break;
			default:
				$this->advance_frame( $depth );
		}
	}

	/**
	 * Writes a file blob by blob; continues where the last slice stopped.
	 *
	 * @param int    $depth Frame depth.
	 * @param Node   $node  File node.
	 * @param string $path  Destination.
	 * @throws FormatException If the destination cannot be written.
	 */
	private function restore_file( int $depth, Node $node, string $path ): void {
		$progress = is_array( $this->state['file'] ) ? $this->state['file'] : [
			'blob'    => 0,
			'written' => 0,
		];
		$blob     = Decoded::count( $progress['blob'] ?? 0 );
		$written  = Decoded::count( $progress['written'] ?? 0 );

		if ( 0 === $blob ) {
			$this->remove_link( $path );
		}
		$handle = fopen( $path, 0 === $blob ? 'wb' : 'cb' );
		if ( false === $handle ) {
			FormatException::raise( 'A file cannot be written in the restore target.' );
		}
		ftruncate( $handle, $written );
		fseek( $handle, $written );

		$content = $node->content ?? [];
		$count   = count( $content );
		while ( $blob < $count ) {
			$data = $this->repository->load_blob( $this->index, BlobType::Data, $content[ $blob ] );
			if ( fwrite( $handle, $data ) !== strlen( $data ) ) {
				fclose( $handle );
				FormatException::raise( 'A file cannot be written in the restore target.' );
			}
			++$blob;
			$written += strlen( $data );
			if ( $blob < $count && microtime( true ) >= $this->deadline ) {
				fclose( $handle );
				$this->state['file'] = [
					'blob'    => $blob,
					'written' => $written,
				];
				return;
			}
		}
		fclose( $handle );

		chmod( $path, FileMode::to_posix_permissions( $node->mode ) & 0777 );
		touch( $path, Rfc3339::parse( $node->mtime )[0] );
		$this->state['file']  = null;
		$this->state['files'] = Decoded::int( $this->state['files'] ) + 1;
		$this->state['bytes'] = Decoded::int( $this->state['bytes'] ) + $written;
		$this->advance_frame( $depth );
	}

	/**
	 * Applies a directory's metadata once its children are restored, then pops it.
	 *
	 * @param int    $depth Frame depth.
	 * @param string $dir   Relative path of the directory.
	 */
	private function finish_directory( int $depth, string $dir ): void {
		$stack = $this->stack();
		$frame = $stack[ $depth ];
		if ( '' !== $dir && is_int( $frame['mode'] ?? null ) && is_string( $frame['mtime'] ?? null ) ) {
			$path = $this->target() . '/' . $dir;
			chmod( $path, FileMode::to_posix_permissions( $frame['mode'] ) & 0777 );
			touch( $path, Rfc3339::parse( $frame['mtime'] )[0] );
		}
		array_pop( $stack );
		$this->state['stack'] = $stack;
	}

	/**
	 * Pushes a directory frame.
	 *
	 * @param string $tree     Tree ID.
	 * @param string $relative Relative path.
	 * @param Node   $node     Directory node.
	 */
	private function push( string $tree, string $relative, Node $node ): void {
		$stack                = $this->stack();
		$stack[]              = [
			'tree'  => $tree,
			'dir'   => GoQuote::quote( $relative ),
			'next'  => 0,
			'only'  => null,
			'mode'  => $node->mode,
			'mtime' => $node->mtime,
		];
		$this->state['stack'] = $stack;
	}

	/**
	 * Advances the frame at a depth.
	 *
	 * @param int $depth Frame depth.
	 */
	private function advance_frame( int $depth ): void {
		$stack                   = $this->stack();
		$stack[ $depth ]['next'] = Decoded::int( $stack[ $depth ]['next'] ) + 1;
		$this->state['stack']    = $stack;
	}

	/**
	 * Loads a tree once per slice.
	 *
	 * @param string $hex Tree ID.
	 * @return list<Node> Nodes.
	 */
	private function tree( string $hex ): array {
		if ( ! isset( $this->trees[ $hex ] ) ) {
			if ( count( $this->trees ) > 64 ) {
				$this->trees = [];
			}
			$this->trees[ $hex ] = $this->repository->load_tree( $this->index, Id::from_hex( $hex ) );
		}
		return $this->trees[ $hex ];
	}

	/**
	 * Deletes a symlink at a destination so that nothing is written through it.
	 *
	 * @param string $path Destination.
	 */
	private function remove_link( string $path ): void {
		if ( is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Rejects names that could escape the restore target.
	 *
	 * @param string $name Name from the repository.
	 * @throws FormatException If the name is unsafe.
	 */
	private static function validate_name( string $name ): void {
		if ( '' === $name || '.' === $name || '..' === $name || str_contains( $name, '/' ) || str_contains( $name, "\0" ) ) {
			throw new FormatException( 'The snapshot contains an unsafe file name.' );
		}
	}

	/**
	 * Finds a node by name.
	 *
	 * @param list<Node> $nodes Nodes.
	 * @param string     $name  Name.
	 * @return Node|null Node.
	 */
	private static function find( array $nodes, string $name ): ?Node {
		foreach ( $nodes as $node ) {
			if ( $node->name === $name ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Returns the frame stack.
	 *
	 * @return list<array<mixed>> Frames.
	 */
	private function stack(): array {
		return Decoded::arrays( $this->state['stack'] );
	}

	/**
	 * Returns the restore target.
	 *
	 * @return string Absolute path.
	 */
	private function target(): string {
		return Decoded::string( $this->state['target'] );
	}

	/**
	 * Writes the checkpoint atomically.
	 *
	 * @throws FormatException If it cannot be written.
	 */
	private function persist(): void {
		$temporary = $this->work_dir . '/restore.json.tmp';
		if ( false === file_put_contents( $temporary, GoJson::encode( $this->state ) ) || ! rename( $temporary, $this->work_dir . '/restore.json' ) ) {
			throw new FormatException( 'The restore checkpoint cannot be written.' );
		}
	}
}
