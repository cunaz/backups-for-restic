<?php
/**
 * Resumable content verification for files larger than a web worker's time budget.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * A versioned SHA-256 chain over fixed-size blocks. The seed binds the file length and
 * protocol version. Each link binds the preceding digest, block length and block SHA-256.
 * The checkpoint contains plain scalars and can be carried between PHP versions.
 */
final class ChunkDigest {

	public const int BLOCK = 1048576;

	/**
	 * Advances a file verification, processing at least one block. The caller persists
	 * the returned cursor before yielding a worker. A changed inode or metadata invalidates
	 * previously read blocks.
	 *
	 * @param string              $path Canonical regular file.
	 * @param float               $deadline Worker deadline.
	 * @param array<string,mixed> $cursor Previous checkpoint, initially empty.
	 * @param int                 $links Expected hard link count.
	 * @return string|null Finished digest prefixed with its version, or null to continue.
	 * @throws StorageFailed If the file or checkpoint changed.
	 */
	public static function advance( string $path, float $deadline, array &$cursor, int $links = 1 ): ?string {
		clearstatcache( true, $path );
		$before = ! is_link( $path ) && is_file( $path ) && realpath( $path ) === $path ? lstat( $path ) : false;
		if ( false === $before || $before['nlink'] !== $links ) {
			throw new StorageFailed( 'A file selected for verification is no longer regular.' );
		}
		$identity = ApplyManifest::identity( $path );
		$size     = $before['size'];
		$offset   = $cursor['offset'] ?? 0;
		$chain    = $cursor['chain'] ?? hash( 'sha256', "backups-for-restic file v1\0" . $size );
		if ( ( [] !== $cursor && $identity !== ( $cursor['identity'] ?? null ) ) || ! is_int( $offset ) || $offset < 0 || $offset > $size || ( $offset !== $size && 0 !== $offset % self::BLOCK ) || ! is_string( $chain ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $chain ) ) {
			throw new StorageFailed( 'A file changed during its incremental verification.' );
		}
		// PHP exposes only whole-second ctime. A prefix read in its inode's last
		// change second cannot be reused across requests: a same-sized in-place edit
		// could otherwise preserve every value returned by stat(). Start it again
		// once any subsequent inode change would necessarily advance ctime.
		if ( $offset > 0 && true !== ( $cursor['stable'] ?? null ) ) {
			if ( time() <= $before['ctime'] ) {
				if ( $before['ctime'] > time() + 1 ) {
					throw new StorageFailed( 'The file change timestamp is ahead of the worker clock.' );
				}
				return null;
			}
			$offset = 0;
			$chain  = hash( 'sha256', "backups-for-restic file v1\0" . $size );
		}
		$stable = time() > $before['ctime'];
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			throw new StorageFailed( 'A file could not be opened for verification.' );
		}
		try {
			$opened = fstat( $stream );
			if ( false === $opened || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || 0 !== fseek( $stream, $offset ) ) {
				throw new StorageFailed( 'A verified file changed while it was being opened.' );
			}
			while ( $offset < $size ) {
				$length = min( self::BLOCK, $size - $offset );
				$block  = stream_get_contents( $stream, $length );
				if ( false === $block || strlen( $block ) !== $length ) {
					throw new StorageFailed( 'A verified file could not be read completely.' );
				}
				$chain   = hash( 'sha256', $chain . pack( 'N', $length ) . hash( 'sha256', $block, true ) );
				$offset += $length;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
		} finally {
			fclose( $stream );
		}
		if ( ApplyManifest::identity( $path ) !== $identity ) {
			throw new StorageFailed( 'A file changed while its content was being verified.' );
		}
		$cursor = [
			'offset'   => $offset,
			'chain'    => $chain,
			'identity' => $identity,
			'stable'   => $stable,
		];
		return $offset === $size ? 'c1:' . $chain : null;
	}

	/**
	 * Whether a digest uses this protocol.
	 *
	 * @param string $hash Stored digest.
	 * @return bool Recognized digest.
	 */
	public static function valid( string $hash ): bool {
		return 1 === preg_match( '/\Ac1:[0-9a-f]{64}\z/', $hash );
	}
}
