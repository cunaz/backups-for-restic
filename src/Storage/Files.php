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
	 * Readers see either the old or the new content, never a torn write.
	 *
	 * @param string $file    Target file.
	 * @param string $content Content.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public static function write_atomic( string $file, string $content ): void {
		$temporary = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === file_put_contents( $temporary, $content ) ) {
			StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
		}
		chmod( $temporary, 0600 );
		if ( ! rename( $temporary, $file ) ) {
			unlink( $temporary );
			StorageFailed::raise( 'A file in the private directory could not be replaced.', [ 'file' => basename( $file ) ] );
		}
	}

	/**
	 * Writes a file atomically from pieces, so that a large content never has to exist as one
	 * string. The temporary file is private before the first byte is written.
	 *
	 * @param string           $file   Target file.
	 * @param iterable<string> $pieces Content in order.
	 * @param int              $limit  Largest size to write, in bytes.
	 * @return bool False if the content is larger than the limit; the target is then left as it was.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public static function write_atomic_pieces( string $file, iterable $pieces, int $limit = PHP_INT_MAX ): bool {
		$temporary = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$handle    = fopen( $temporary, 'xb' );
		if ( false === $handle ) {
			StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
		}
		chmod( $temporary, 0600 );
		$written = 0;
		$fits    = true;
		$failed  = false;
		try {
			foreach ( $pieces as $piece ) {
				$written += strlen( $piece );
				if ( $written > $limit ) {
					$fits = false;
					break;
				}
				if ( strlen( $piece ) !== fwrite( $handle, $piece ) ) {
					$failed = true;
					break;
				}
			}
		} finally {
			$failed = ! fclose( $handle ) || $failed;
			if ( $failed || ! $fits ) {
				unlink( $temporary );
			}
		}
		if ( $failed ) {
			StorageFailed::raise( 'A file in the private directory could not be written.', [ 'file' => basename( $file ) ] );
		}
		if ( ! $fits ) {
			return false;
		}
		if ( ! rename( $temporary, $file ) ) {
			unlink( $temporary );
			StorageFailed::raise( 'A file in the private directory could not be replaced.', [ 'file' => basename( $file ) ] );
		}
		return true;
	}

	/**
	 * Deletes a directory with everything in it. Symbolic links are removed, not followed.
	 *
	 * @param string $path Directory or file.
	 */
	public static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? [] : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}
