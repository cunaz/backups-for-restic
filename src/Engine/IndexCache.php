<?php
/**
 * The index, cached locally.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the merged index in cache/<repository-id>/index.bin, encrypted with the repository's
 * own key, together with the names of the index files it covers. A tick then lists the
 * index files and downloads only new ones instead of all of them.
 *
 * If an index file disappeared from the server (restic prune or rebuild-index ran elsewhere),
 * the cache cannot tell which entries became stale, so it is rebuilt from scratch. An index
 * larger than the cache limit (advanced.cache_limit_mib) is not kept: every tick then reads
 * the index files again, which is slower but never fills the disk.
 */
final class IndexCache {

	/**
	 * Uses a cache directory.
	 *
	 * @param string $directory cache/<repository-id> in the private directory.
	 * @param int    $limit     Largest cache file to keep, in bytes.
	 */
	public function __construct( private readonly string $directory, private readonly int $limit = PHP_INT_MAX ) {
	}

	/**
	 * Returns the current index of the repository.
	 *
	 * @param Repository $repository Open repository.
	 * @return IndexSet Index.
	 */
	public function load( Repository $repository ): IndexSet {
		$listed = array_map( 'strval', array_keys( $repository->backend()->list( ObjectType::Index ) ) );
		sort( $listed );
		$cached  = $this->read( $repository );
		$index   = new IndexSet();
		$covered = [];
		if ( null !== $cached && [] === array_diff( $cached[1], $listed ) ) {
			[ $index, $covered ] = $cached;
		}
		$new = array_values( array_diff( $listed, $covered ) );
		foreach ( $new as $name ) {
			foreach ( IndexFile::decode( $repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
				$index->add_pack( Id::from_hex( $pack ), $entries );
			}
		}
		if ( [] !== $new || null === $cached || count( $covered ) !== count( $listed ) ) {
			$this->write( $repository, $index, $listed );
		}
		return $index;
	}

	/**
	 * Deletes the cache.
	 */
	public function clear(): void {
		Files::remove_tree( $this->directory );
	}

	/**
	 * Reads the cache.
	 *
	 * @param Repository $repository Repository whose key encrypts the cache.
	 * @return array{IndexSet, list<string>}|null Index and covered index file names, or null.
	 */
	private function read( Repository $repository ): ?array {
		$file = $this->directory . '/index.bin';
		if ( is_file( $file ) && (int) filesize( $file ) > $this->limit ) {
			// Written under a higher limit than the one set now.
			$this->clear();
			return null;
		}
		$sealed = is_file( $file ) ? file_get_contents( $file ) : false;
		if ( false === $sealed ) {
			return null;
		}
		try {
			$plain  = $repository->cipher()->open( $sealed );
			$fields = unpack( 'Vcount', $plain );
			$count  = is_array( $fields ) ? $fields['count'] : 0;
			$names  = [];
			for ( $i = 0; $i < $count; $i++ ) {
				$names[] = bin2hex( substr( $plain, 4 + 32 * $i, 32 ) );
			}
			return [ IndexSet::from_binary( substr( $plain, 4 + 32 * $count ) ), $names ];
		} catch ( AuthenticationFailed | CryptoException | FormatException ) {
			return null;
		}
	}

	/**
	 * Writes the cache.
	 *
	 * @param Repository   $repository Repository whose key encrypts the cache.
	 * @param IndexSet     $index      Index.
	 * @param list<string> $names      Index files it covers.
	 */
	private function write( Repository $repository, IndexSet $index, array $names ): void {
		$plain = pack( 'V', count( $names ) );
		foreach ( $names as $name ) {
			$plain .= Id::from_hex( $name )->bytes();
		}
		$sealed = $repository->cipher()->seal( $plain . $index->to_binary() );
		if ( strlen( $sealed ) > $this->limit ) {
			$this->clear();
			return;
		}
		if ( ! is_dir( $this->directory ) ) {
			mkdir( $this->directory, 0700, true );
		}
		Files::write_atomic( $this->directory . '/index.bin', $sealed );
	}
}
