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
