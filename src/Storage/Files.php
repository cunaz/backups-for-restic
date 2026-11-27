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
	 * @param string                                $file     Target file.
	 * @param string                                $content  Content.
	 * @param (\Closure(string, string): void)|null $observer Optional observer of each synchronisation and of the
	 *                                                         rename, with the path, in order.
	 * @throws StorageFailed If the file cannot be written or synchronised.
	 */
	public static function write_durable( string $file, string $content, ?\Closure $observer = null ): void {
		self::write_atomic_pieces( $file, [ $content ], PHP_INT_MAX, true, $observer );
	}

	/**
	 * Writes a file atomically from pieces, so that a large content never has to exist as one
	 * string. The temporary file is private before the first byte is written.
	 *
	 * @param string                                $file     Target file.
	 * @param iterable<string>                      $pieces   Content in order.
	 * @param int                                   $limit    Largest size to write, in bytes.
	 * @param bool                                  $durable  Whether to synchronise the file and its directory (write_durable()).
	 * @param (\Closure(string, string): void)|null $observer Optional observer of each synchronisation and of the
	 *                                                         rename, with the path, in order.
	 * @return bool False if the content is larger than the limit; the target is then left as it was.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public static function write_atomic_pieces( string $file, iterable $pieces, int $limit = PHP_INT_MAX, bool $durable = false, ?\Closure $observer = null ): bool {
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
			if ( $durable ) {
				if ( ! fsync( $handle ) ) {
					StorageFailed::raise( 'A file in the private directory could not be synchronised to disk.', [ 'file' => basename( $file ) ] );
				}
				if ( null !== $observer ) {
					$observer( 'sync', $temporary );
				}
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
			if ( null !== $observer ) {
				$observer( 'rename', $file );
			}
			if ( $durable ) {
				self::sync_directory( dirname( $file ), $observer );
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
	 * @param string                                $directory Existing directory.
	 * @param (\Closure(string, string): void)|null $observer  Optional observer, told of a completed synchronisation.
	 */
	public static function sync_directory( string $directory, ?\Closure $observer = null ): void {
		$handle = @fopen( $directory, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not every platform opens a directory as a stream; synchronising it is then skipped.
		if ( false === $handle ) {
			return;
		}
		try {
			// Some file systems refuse to synchronise a directory; the rename stays as the file
			// system ordered it.
			if ( @fsync( $handle ) && null !== $observer ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A refusal is expected on some file systems and is not an error here.
				$observer( 'sync', $directory );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Whether a marker file is there, for a file whose presence keeps data or allows an action,
	 * such as the records of an apply's undo window or a job document: true for a regular file
	 * in a folder at its canonical path, false where it is established that nothing is there
	 * (missing()), and null where neither is established. PHP's is_file() answers false for a
	 * name that cannot be looked up, below a folder without search permission or after an error
	 * of the file system, and for anything other than a regular file, so that all of them count
	 * as absent; PHP gives no error number for a failed lookup, and the text of its warning
	 * names no reason. Here such a name gives null, and so do a directory or a link at the name
	 * and a name below a link, where the folder that holds it may be anywhere.
	 *
	 * @param string $path Absolute path.
	 * @return bool|null Whether a regular file is there, or null where that cannot be established.
	 */
	public static function marker( string $path ): ?bool {
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A name that is missing or cannot be looked up is answered below.
		if ( false === $stat ) {
			return self::missing( $path ) ? false : null;
		}
		$folder = dirname( $path );
		clearstatcache( true, $folder );
		return 0100000 === ( $stat['mode'] & 0170000 ) && realpath( $folder ) === $folder ? true : null;
	}

	/**
	 * Whether it is established that nothing is at a path. It is when the name is missing from
	 * a directory at its canonical path that this process can search, or when that directory is
	 * missing in the same sense, or when what holds the name is neither a directory nor a link:
	 * nothing can be below a file. lstat() of a name in a directory that the process can search
	 * fails only when the name is missing, or when the file system itself fails with an I/O
	 * error, which is read as missing too. Below a directory without search permission lstat()
	 * also fails for a name that is there, and below a link the name may be anywhere: such a name
	 * is not missing. The rule needs no error number and no extension, and Linux and the BSDs
	 * answer these calls alike.
	 *
	 * @param string $path Absolute path.
	 * @return bool Whether nothing is at the path.
	 */
	public static function missing( string $path ): bool {
		if ( self::found( $path ) ) {
			return false;
		}
		$folder = dirname( $path );
		if ( $folder === $path ) {
			return false;
		}
		clearstatcache( true, $folder );
		$stat = @lstat( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A folder that is missing or cannot be looked up is answered below.
		if ( false === $stat ) {
			return self::missing( $folder );
		}
		$type = $stat['mode'] & 0170000;
		if ( 0040000 !== $type ) {
			return 0120000 !== $type;
		}
		// A name that appeared in the meantime is there after all.
		return realpath( $folder ) === $folder && is_executable( $folder ) && ! self::found( $path );
	}

	/**
	 * Whether lstat() finds a name, a link included, without PHP's stat cache.
	 *
	 * @param string $path Path.
	 * @return bool Whether the name is found.
	 * @phpstan-impure Other requests create and remove names.
	 */
	private static function found( string $path ): bool {
		clearstatcache( true, $path );
		return false !== @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A name that is missing or cannot be looked up is answered by the caller.
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
