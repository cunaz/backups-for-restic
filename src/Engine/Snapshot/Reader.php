<?php
/**
 * Read-only snapshot browsing under a repository lock.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Snapshot;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\TreeCache;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Every public read takes a fresh shared lock before metadata, index or tree access and
 * releases it even on failure. An index is loaded lazily and discarded when the lock ends.
 */
final class Reader {

	/** Maximum directories inspected when an old snapshot has no manifest locator. */
	private const int MANIFEST_SEARCH_LIMIT = 4096;

	/**
	 * Index valid only during the current locked operation.
	 *
	 * @var IndexSet|null
	 */
	private ?IndexSet $index = null;

	/**
	 * Checks continuity and refreshes the current shared lock.
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $keep_lock = null;

	/**
	 * Opens a reader. Cache directories must belong to this repository's private directory.
	 *
	 * @param Repository      $repository Open repository.
	 * @param Identity        $identity   Identity for the short-lived shared lock.
	 * @param Clock           $clock      Lock time source.
	 * @param IndexCache|null $index_cache Optional encrypted index cache.
	 * @param TreeCache|null  $tree_cache  Optional encrypted tree cache.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly Identity $identity,
		private readonly Clock $clock,
		private readonly ?IndexCache $index_cache = null,
		private readonly ?TreeCache $tree_cache = null
	) {
	}

	/**
	 * Lists all snapshots or those matching a host and every required tag, newest first.
	 *
	 * @param string|null  $hostname Host filter, or null for all hosts.
	 * @param list<string> $tags    Required tags; callers pass the plugin and site tags.
	 * @return array<string, Snapshot> Snapshots keyed by full ID.
	 */
	public function snapshots( ?string $hostname = null, array $tags = [] ): array {
		return $this->locked(
			function () use ( $hostname, $tags ): array {
				return array_filter(
					$this->repository->snapshots(),
					static fn ( Snapshot $snapshot ): bool => ( null === $hostname || $snapshot->hostname === $hostname ) && $snapshot->has_tags( $tags )
				);
			}
		);
	}

	/**
	 * Loads one snapshot without using a stale list or a shortened ID.
	 *
	 * @param Id $id Full snapshot ID.
	 * @return Snapshot Snapshot metadata.
	 */
	public function snapshot( Id $id ): Snapshot {
		return $this->locked( fn (): Snapshot => $this->load_snapshot( $id ) );
	}

	/**
	 * Reads one directory. A symlink is an entry to display, never a path to follow.
	 *
	 * @param Id     $snapshot Snapshot ID.
	 * @param string $path     Absolute path inside the snapshot, defaulting to its root.
	 * @return list<Node> Directory entries in their stored order.
	 * @throws InvalidArgument If the path is absent or not a directory.
	 * @throws FormatException If a directory was recorded with a read error.
	 */
	public function directory( Id $snapshot, string $path = '/' ): array {
		$parts = Path::parts( $path );
		return $this->locked(
			function () use ( $snapshot, $parts ): array {
				$tree = $this->load_snapshot( $snapshot )->tree;
				foreach ( $parts as $part ) {
					$node = $this->find( $tree, $part );
					if ( null === $node || NodeType::Dir !== $node->type || null === $node->subtree ) {
						throw new InvalidArgument( 'The path is not a directory in this snapshot; links are not followed.' );
					}
					if ( '' !== $node->error ) {
						throw new FormatException( 'This snapshot directory was recorded with a read error.' );
					}
					$tree = $node->subtree;
				}
				return $this->tree( $tree );
			}
		);
	}

	/**
	 * Reads the site's manifest. Older snapshots are searched with a bounded directory walk.
	 * An explicit path lets the browser select it when that search is too large or ambiguous.
	 *
	 * @param Id          $id   Snapshot ID.
	 * @param string|null $path Explicit manifest file, or null to use the locator/search.
	 * @return array{path: string, data: array<string, mixed>}|null Manifest, or null when none exists.
	 */
	public function manifest( Id $id, ?string $path = null ): ?array {
		if ( null !== $path ) {
			Path::parts( $path );
		}
		return $this->locked(
			function () use ( $id, $path ): ?array {
				$snapshot = $this->load_snapshot( $id );
				$path   ??= Manifest::path( $snapshot->tags );
				if ( null === $path ) {
					$path = $this->find_manifest( $snapshot->tree );
				}
				if ( null === $path ) {
					return null;
				}
				return [
					'path' => $path,
					'data' => Manifest::decode( $this->file( $snapshot->tree, $path ) ),
				];
			}
		);
	}

	/**
	 * Searches only directory nodes, never links, and does not assume the current site layout.
	 *
	 * @param Id $root Root tree.
	 * @return string|null Unique spool/manifest.json path, or null.
	 * @throws InvalidArgument If discovery is too large or ambiguous.
	 */
	private function find_manifest( Id $root ): ?string {
		$pending = [ [ $root, '' ] ];
		$visited = 0;
		$found   = null;
		while ( [] !== $pending ) {
			[ $tree, $path ] = array_pop( $pending );
			++$visited;
			foreach ( $this->tree( $tree ) as $node ) {
				$child = $path . '/' . $node->name;
				if ( NodeType::File === $node->type && 'manifest.json' === $node->name && str_ends_with( $path, '/spool' ) ) {
					if ( null !== $found ) {
						throw new InvalidArgument( 'The snapshot contains several spool manifests; select the site manifest explicitly.' );
					}
					$found = $child;
				}
				if ( NodeType::Dir === $node->type ) {
					if ( '' !== $node->error || null === $node->subtree ) {
						throw new FormatException( 'An incomplete snapshot directory prevents a complete manifest search.' );
					}
					Path::parts( $child );
					if ( $visited + count( $pending ) >= self::MANIFEST_SEARCH_LIMIT ) {
						throw new InvalidArgument( 'The snapshot is too large to locate its manifest in one request; select the manifest explicitly.' );
					}
					$pending[] = [ $node->subtree, $child ];
				}
			}
		}
		return $found;
	}

	/**
	 * Reads only a bounded regular file and checks both declared and actual lengths.
	 *
	 * @param Id     $tree Root tree.
	 * @param string $path Manifest path.
	 * @return string Verified file bytes.
	 * @throws InvalidArgument If the path does not name a regular file.
	 * @throws FormatException If its content is incomplete or exceeds the manifest limit.
	 */
	private function file( Id $tree, string $path ): string {
		$parts = Path::parts( $path );
		$name  = array_pop( $parts );
		if ( null === $name || str_ends_with( $path, '/' ) ) {
			throw new InvalidArgument( 'The site manifest path must name a regular file.' );
		}
		foreach ( $parts as $part ) {
			$node = $this->find( $tree, $part );
			if ( null === $node || NodeType::Dir !== $node->type || null === $node->subtree ) {
				throw new InvalidArgument( 'The site manifest path is absent or passes through a link.' );
			}
			if ( '' !== $node->error ) {
				throw new FormatException( 'A directory containing the site manifest was recorded with a read error.' );
			}
			$tree = $node->subtree;
		}
		$node = $this->find( $tree, $name );
		if ( null === $node || NodeType::File !== $node->type ) {
			throw new InvalidArgument( 'The site manifest path must name a regular file.' );
		}
		if ( '' !== $node->error || $node->size > Manifest::MAX_BYTES || null === $node->content || count( $node->content ) > Manifest::MAX_BLOBS ) {
			throw new FormatException( 'The site manifest file is incomplete or exceeds its one MiB or 128-blob limit.' );
		}
		$plain = '';
		$index = $this->index();
		foreach ( $node->content as $blob ) {
			$this->keep_lock?->__invoke();
			$location = $index->find( $blob, BlobType::Data );
			if ( null === $location || $location->entry->length > Manifest::MAX_BYTES + Cipher::OVERHEAD || $location->entry->uncompressed_length > Manifest::MAX_BYTES ) {
				throw new FormatException( 'A site manifest blob is absent or exceeds its one MiB limit.' );
			}
			$part = $this->repository->load_blob( $index, BlobType::Data, $blob );
			if ( strlen( $part ) > Manifest::MAX_BYTES - strlen( $plain ) ) {
				throw new FormatException( 'The site manifest file exceeds its one MiB limit.' );
			}
			$plain .= $part;
		}
		if ( strlen( $plain ) !== $node->size ) {
			throw new FormatException( 'The site manifest length does not match its tree entry.' );
		}
		return $plain;
	}

	/**
	 * Finds one child without interpreting its raw bytes as a local path.
	 *
	 * @param Id     $tree Tree ID.
	 * @param string $name Raw child name.
	 * @return Node|null Matching child.
	 */
	private function find( Id $tree, string $name ): ?Node {
		foreach ( $this->tree( $tree ) as $node ) {
			if ( $node->name === $name ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Loads a verified tree and validates path components before exposing them to a browser.
	 *
	 * @param Id $id Tree ID.
	 * @return list<Node> Entries.
	 * @throws FormatException If a name cannot be used as a snapshot path component.
	 */
	private function tree( Id $id ): array {
		$this->keep_lock?->__invoke();
		$plain   = $this->tree_cache?->read( $this->repository, $id );
		$cached  = null !== $plain;
		$plain ??= $this->repository->load_blob( $this->index(), BlobType::Tree, $id );
		$this->keep_lock?->__invoke();
		$nodes = TreeCodec::decode( $plain );
		foreach ( $nodes as $node ) {
			if ( '' === $node->name || '.' === $node->name || '..' === $node->name || str_contains( $node->name, '/' ) || str_contains( $node->name, "\0" ) ) {
				throw new FormatException( 'A snapshot tree contains an invalid path component.' );
			}
		}
		if ( ! $cached ) {
			$this->tree_cache?->write( $this->repository, $id, $plain );
		}
		return $nodes;
	}

	/**
	 * Loads the index only when a tree or file misses the cache.
	 *
	 * @return IndexSet Index valid under the current lock.
	 */
	private function index(): IndexSet {
		return $this->index ??= $this->index_cache?->load( $this->repository ) ?? $this->repository->load_index();
	}

	/**
	 * Loads metadata while the caller holds its shared lock.
	 *
	 * @param Id $id Snapshot ID.
	 * @return Snapshot Authenticated metadata.
	 */
	private function load_snapshot( Id $id ): Snapshot {
		return Snapshot::from_json( $this->repository->load_unpacked( ObjectType::Snapshots, $id ) );
	}

	/**
	 * Keeps repository metadata and content stable for one read operation.
	 *
	 * @template T
	 * @param \Closure(): T $read Read operation.
	 * @param-immediately-invoked-callable $read
	 * @return T Result.
	 */
	private function locked( \Closure $read ): mixed {
		$pid             = 1 + ( hexdec( bin2hex( $this->repository->random()->bytes( 4 ) ) ) % 2147483646 );
		$locking         = new Locking( $this->repository, $this->identity, $this->clock, $pid );
		$locked_at       = $this->clock->now()[0];
		$lock            = $locking->acquire( false );
		$this->keep_lock = function () use ( $locking, &$lock, &$locked_at ): void {
			if ( $locking->lapsed( $locked_at ) ) {
				throw new LockConflict( 'The snapshot read outlasted its repository lock; retry the read.' );
			}
			if ( $locking->due( $locked_at ) ) {
				$locked_at = $this->clock->now()[0];
				$lock      = $locking->refresh( $lock, false );
			}
		};
		try {
			( $this->keep_lock )();
			$result = $read();
			( $this->keep_lock )();
			return $result;
		} finally {
			$this->index     = null;
			$this->keep_lock = null;
			$locking->release( $lock );
		}
	}
}
