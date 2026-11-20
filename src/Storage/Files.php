<?php
/**
 * Small file helpers.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic writes and recursive deletion inside the private directory.
 */
final class Files {

	/**
	 * Writes a file atomically: a temporary file in the same directory, then rename().
	 * Readers see either the old or the new content, never a torn write. A killed process
	 * leaves one of the two; after a power loss the file system decides what survives.
	 *
	 * @param string $file    Target file.
	 * @param string $content Content.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public static function write_atomic( string $file, string $content ): void {
		self::write_atomic_pieces( $file, [ $content ] );
	}

	/**
	 * Writes a file atomically and durably, for a record that authorises a later change. The
	 * temporary file is synchronised before the rename, so a crash cannot leave an empty or
	 * partial record under the target name, and the directory is synchronised after it, so
	 * the record survives a power loss once this returns. Hosts that cannot synchronise a
	 * directory keep the rename that the file system already ordered.
	 *
	 * @param string $file    Target file.
	 * @param string $content Content.
	 * @throws StorageFailed If the file cannot be written or synchronised.
	 */
	public static function write_durable( string $file, string $content ): void {
		self::write_atomic_pieces( $file, [ $content ], PHP_INT_MAX, true );
	}

	/**
	 * Writes a file atomically from pieces, so that a large content never has to exist as one
	 * string. The temporary file is private before the first byte is written.
	 *
	 * @param string           $file    Target file.
	 * @param iterable<string> $pieces  Content in order.
	 * @param int              $limit   Largest size to write, in bytes.
	 * @param bool             $durable Whether to synchronise the file and its directory (write_durable()).
	 * @return bool False if the content is larger than the limit; the target is then left as it was.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public static function write_atomic_pieces( string $file, iterable $pieces, int $limit = PHP_INT_MAX, bool $durable = false ): bool {
		$temporary = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$umask     = umask( 0077 );
		try {
			$handle = @fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked storage error replaces PHP's warning about the private path.
		} finally {
			umask( $umask );
		}
		if ( false === $handle ) {
			StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
		}
		$complete = false;
		try {
			if ( ! chmod( $temporary, 0600 ) ) {
				StorageFailed::raise( 'A temporary file could not be made private.', [ 'file' => basename( $file ) ] );
			}
			$written = 0;
			foreach ( $pieces as $piece ) {
				$written += strlen( $piece );
				if ( $written > $limit ) {
					return false;
				}
				if ( strlen( $piece ) !== @fwrite( $handle, $piece ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A full filesystem can also emit a notice for a short write.
					StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
				}
			}
			if ( ! fflush( $handle ) ) {
				StorageFailed::raise( 'A file in the private directory could not be flushed.', [ 'file' => basename( $file ) ] );
			}
			if ( $durable && ! fsync( $handle ) ) {
				StorageFailed::raise( 'A file in the private directory could not be synchronised to disk.', [ 'file' => basename( $file ) ] );
			}
			$closed = fclose( $handle );
			$handle = null;
			if ( ! $closed ) {
				StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
			}
			if ( ! rename( $temporary, $file ) ) {
				StorageFailed::raise( 'A file in the private directory could not be replaced.', [ 'file' => basename( $file ) ] );
			}
			$complete = true;
			if ( $durable ) {
				self::sync_directory( dirname( $file ) );
			}
			return true;
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( ! $complete ) {
				unlink( $temporary );
			}
		}
	}

	/**
	 * Asks the file system to put a regular file's data and metadata on disk, for example a
	 * copy that a durable record is about to name. Linux, the BSDs and macOS synchronise a
	 * file opened for reading, so this also works for a copy that is no longer writable.
	 *
	 * @param string $file Existing regular file.
	 * @throws StorageFailed If the file cannot be opened or synchronised.
	 */
	public static function sync_file( string $file ): void {
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked storage error replaces PHP's warning about the private path.
		if ( false === $handle ) {
			StorageFailed::raise( 'A file in the private directory could not be opened for synchronisation.', [ 'file' => basename( $file ) ] );
		}
		try {
			if ( ! fsync( $handle ) ) {
				StorageFailed::raise( 'A file in the private directory could not be synchronised to disk.', [ 'file' => basename( $file ) ] );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Asks the file system to put a directory's entries on disk, so that a created, renamed
	 * or removed name survives a power loss. PHP can open a directory as a stream on Linux and
	 * the BSDs; where it cannot, or where the file system refuses to synchronise a directory,
	 * this returns without it and the file system's own ordering applies.
	 *
	 * @param string $directory Existing directory.
	 */
	public static function sync_directory( string $directory ): void {
		$handle = @fopen( $directory, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not every platform opens a directory as a stream; synchronising it is then skipped.
		if ( false === $handle ) {
			return;
		}
		try {
			@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some file systems refuse to synchronise a directory; the rename stays as the file system ordered it.
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Deletes a directory with everything in it. Symbolic links are removed, not followed.
	 *
	 * @param string $path Directory or file.
	 * @throws StorageFailed If listing or removing an entry fails. Earlier removals are not undone.
	 */
	public static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! unlink( $path ) ) {
				StorageFailed::raise( 'A file in the private directory could not be removed.', [ 'file' => basename( $path ) ] );
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		if ( false === $entries ) {
			StorageFailed::raise( 'A directory in the private directory could not be listed.', [ 'file' => basename( $path ) ] );
		}
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_tree( $path . '/' . $entry );
			}
		}
		if ( ! rmdir( $path ) ) {
			StorageFailed::raise( 'A directory in the private directory could not be removed.', [ 'file' => basename( $path ) ] );
		}
	}
}
