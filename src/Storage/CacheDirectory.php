<?php
/**
 * The cache directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The cache/ directory in the private directory: one subdirectory per repository ID with the
 * encrypted merged index (Engine\IndexCache). Everything in it can be rebuilt from the repository, so
 * it may be deleted at any time; the next job reads the index files again.
 */
final class CacheDirectory {

	/**
	 * Uses a directory.
	 *
	 * @param string $path The cache directory.
	 */
	public function __construct( public readonly string $path ) {
	}

	/**
	 * Bytes of all files in the cache.
	 *
	 * @return int Bytes.
	 */
	public function size(): int {
		if ( ! is_dir( $this->path ) ) {
			return 0;
		}
		$bytes = 0;
		try {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() ) {
					$bytes += (int) $file->getSize();
				}
			}
		} catch ( \UnexpectedValueException | \RuntimeException ) {
			// A job removed part of the cache while it was measured: the size is only a hint.
			return $bytes;
		}
		return $bytes;
	}

	/**
	 * Deletes the caches of repositories other than the current one, for example after the
	 * site was connected to a new repository.
	 *
	 * @param string $current ID of the current repository; with an empty ID nothing is deleted.
	 * @return int Number of caches removed.
	 * @throws \BackupsForRestic\Exception\StorageFailed If a cache cannot be removed.
	 */
	public function remove_others( string $current ): int {
		if ( '' === $current || ! is_dir( $this->path ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) scandir( $this->path ) as $name ) {
			if ( ! is_string( $name ) || '.' === $name || '..' === $name || $name === $current || ! is_dir( $this->path . '/' . $name ) ) {
				continue;
			}
			Files::remove_tree( $this->path . '/' . $name );
			clearstatcache( true, $this->path . '/' . $name );
			if ( ! is_dir( $this->path . '/' . $name ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Deletes every cache.
	 */
	public function clear(): void {
		Files::remove_tree( $this->path );
	}
}
