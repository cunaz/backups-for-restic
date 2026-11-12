<?php
/**
 * Authenticated tree blobs cached in the private directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\Id;

defined( 'ABSPATH' ) || exit;

/**
 * Stores only encrypted tree bytes, never decoded nodes or plaintext metadata. Index bytes
 * take priority within the shared per-repository cache limit. Eviction needs no in-memory
 * list of cached IDs; any evicted tree can be fetched again by its content hash.
 */
final class TreeCache {

	/** Large directories may be read, but are not retained in this small-object cache. */
	private const int MAX_PLAIN_BYTES = 4194304;

	/**
	 * Whether this instance has enforced its configured limit.
	 *
	 * @var bool
	 */
	private bool $trimmed = false;

	/**
	 * Uses a per-repository cache directory.
	 *
	 * @param string $directory cache/<repository-id>, shared with IndexCache.
	 * @param int    $limit     Combined limit for published index and tree files, in bytes.
	 */
	public function __construct( private readonly string $directory, private readonly int $limit = PHP_INT_MAX ) {
	}

	/**
	 * Returns authenticated bytes with the requested hash, or a miss for absent/corrupt data.
	 *
	 * @param Repository $repository Repository whose key protects the cache.
	 * @param Id         $id         Plaintext tree ID.
	 * @return string|null Tree bytes or a cache miss.
	 * @throws StorageFailed If the cache directory is unsafe or cannot be trimmed.
	 */
	public function read( Repository $repository, Id $id ): ?string {
		if ( is_link( $this->directory . '/trees' ) ) {
			throw new StorageFailed( 'The tree cache directory is a symbolic link.' );
		}
		if ( ! $this->trimmed ) {
			$this->trim();
		}
		$file = $this->directory . '/trees/' . $id->hex() . '.bin';
		if ( is_link( $file ) || ! is_file( $file ) ) {
			return null;
		}
		$cap = min( $this->limit, self::MAX_PLAIN_BYTES + Cipher::OVERHEAD );
		if ( $cap < Cipher::OVERHEAD || (int) filesize( $file ) > $cap ) {
			return null;
		}
		// A different request may evict the file after it was found. Treat that as a miss.
		$sealed = @file_get_contents( $file, false, null, 0, $cap + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent eviction is an ordinary cache miss.
		if ( false === $sealed || strlen( $sealed ) > $cap ) {
			return null;
		}
		try {
			$plain = $repository->cipher()->open( $sealed );
			return Id::of( $plain )->equals( $id ) ? $plain : null;
		} catch ( AuthenticationFailed | CryptoException ) {
			return null;
		}
	}

	/**
	 * Caches a tree only when it fits beside the index. All temporary bytes are ciphertext.
	 *
	 * @param Repository $repository Repository key.
	 * @param Id         $id         Verified plaintext ID.
	 * @param string     $plain      Verified and decoded tree blob.
	 * @throws StorageFailed If the cache cannot be written safely.
	 */
	public function write( Repository $repository, Id $id, string $plain ): void {
		$bytes = strlen( $plain ) + Cipher::OVERHEAD;
		if ( strlen( $plain ) > self::MAX_PLAIN_BYTES || $bytes > $this->limit - $this->index_bytes() ) {
			$this->trim();
			return;
		}
		$this->trim( $bytes );
		$directory = $this->directory . '/trees';
		if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) ) {
			throw new StorageFailed( 'The tree cache directory could not be created safely.' );
		}
		$this->publish( $directory . '/' . $id->hex() . '.bin', $repository->cipher()->seal( $plain ) );
		// Other readers or an index writer may have populated the cache meanwhile.
		$this->trim();
	}

	/**
	 * Makes room for a write or enforces a reduced limit. The index always takes priority.
	 * IndexCache calls this after loading/writing its own file as well.
	 *
	 * @param int $reserve Bytes about to be published, zero when only enforcing the limit.
	 * @throws StorageFailed If the cache cannot be measured or trimmed safely.
	 */
	public function trim( int $reserve = 0 ): void {
		$directory = $this->directory . '/trees';
		if ( is_link( $directory ) ) {
			throw new StorageFailed( 'The tree cache directory is a symbolic link.' );
		}
		if ( ! is_dir( $directory ) ) {
			$this->trimmed = true;
			return;
		}
		try {
			$bytes = $this->index_bytes();
			foreach ( new \DirectoryIterator( $directory ) as $entry ) {
				if ( $entry->isFile() && ! $entry->isLink() && 1 === preg_match( '/^[0-9a-f]{64}\.bin$/D', $entry->getFilename() ) ) {
					$bytes += $entry->getSize();
				}
			}
			$target = max( 0, $this->limit - $reserve );
			foreach ( new \DirectoryIterator( $directory ) as $entry ) {
				if ( $bytes <= $target ) {
					break;
				}
				if ( $entry->isFile() && ! $entry->isLink() && 1 === preg_match( '/^[0-9a-f]{64}\.bin$/D', $entry->getFilename() ) ) {
					$size = $entry->getSize();
					$file = $entry->getPathname();
					if ( ! @unlink( $file ) && file_exists( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Another request may already have evicted it; a real refusal is reported.
						throw new StorageFailed( 'A tree cache file could not be removed.' );
					}
					$bytes -= $size;
				}
			}
		} catch ( \UnexpectedValueException | \RuntimeException $error ) {
			StorageFailed::raise( 'The tree cache could not be measured or trimmed.', [], $error );
		}
		$this->trimmed = true;
	}

	/**
	 * Measures the index before reserving room for trees.
	 *
	 * @return int Bytes already reserved by the published index.
	 */
	private function index_bytes(): int {
		$file = $this->directory . '/index.bin';
		clearstatcache( true, $file );
		return is_file( $file ) ? (int) filesize( $file ) : 0;
	}

	/**
	 * Writes all ciphertext to a private temporary file and publishes only after close.
	 *
	 * @param string $file   Destination.
	 * @param string $sealed Ciphertext.
	 * @throws StorageFailed If a write, close or replacement fails.
	 */
	private function publish( string $file, string $sealed ): void {
		$temporary = $file . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$handle    = fopen( $temporary, 'x+b' );
		if ( false === $handle ) {
			throw new StorageFailed( 'A temporary tree cache file could not be created.' );
		}
		try {
			if ( ! chmod( $temporary, 0600 ) ) {
				throw new StorageFailed( 'A tree cache file could not be made private.' );
			}
			for ( $position = 0, $length = strlen( $sealed ); $position < $length; $position += $written ) {
				$written = fwrite( $handle, substr( $sealed, $position ) );
				if ( false === $written || 0 === $written ) {
					throw new StorageFailed( 'A tree cache file could not be completely written.' );
				}
			}
			$closed = fclose( $handle );
			$handle = null;
			if ( ! $closed || ! rename( $temporary, $file ) ) {
				throw new StorageFailed( 'A tree cache file could not be published.' );
			}
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( is_file( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}
}
