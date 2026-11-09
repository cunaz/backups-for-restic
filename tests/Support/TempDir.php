<?php
/**
 * Temporary directories for tests.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * Creates and removes scratch directories and compares directory trees.
 */
final class TempDir {

	public static function create( string $prefix ): string {
		$path = sys_get_temp_dir() . '/bfr-' . $prefix . '-' . bin2hex( random_bytes( 6 ) );
		mkdir( $path, 0700, true );
		return $path;
	}

	public static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), [ '.', '..' ] ) as $name ) {
			self::remove( $path . '/' . $name );
		}
		rmdir( $path );
	}

	/**
	 * Describes a directory tree: relative path => type, mode, content hash or link target.
	 *
	 * @return array<string, string>
	 */
	public static function describe( string $root ): array {
		$result   = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $path => $info ) {
			$relative = substr( (string) $path, strlen( $root ) + 1 );
			if ( is_link( (string) $path ) ) {
				$result[ $relative ] = 'link:' . readlink( (string) $path );
			} elseif ( is_dir( (string) $path ) ) {
				$result[ $relative ] = sprintf( 'dir:%o', fileperms( (string) $path ) & 0777 );
			} else {
				$result[ $relative ] = sprintf( 'file:%o:%d:%s', fileperms( (string) $path ) & 0777, filemtime( (string) $path ), hash_file( 'sha256', (string) $path ) );
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Builds the fixture tree used by engine and conformance tests.
	 */
	public static function fixture( string $root, int $large_bytes = 3145728 ): void {
		mkdir( $root . '/wp-content/uploads/2026/11', 0755, true );
		mkdir( $root . '/wp-content/plugins/empty-dir', 0750, true );
		mkdir( $root . '/ümlaut-ördner', 0755 );
		file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $root . '/wp-config.php', "<?php\ndefine( 'DB_NAME', 'WordPress' );\n" );
		file_put_contents( $root . '/empty.txt', '' );
		file_put_contents( $root . '/ümlaut-ördner/日本語.txt', "unicode\n" );
		file_put_contents( $root . "/tab\tand \"quote\".txt", "special name\n" );
		file_put_contents( $root . "/latin1-\xe9.txt", "invalid UTF-8 in the name\n" );
		file_put_contents( $root . '/wp-content/uploads/2026/11/photo.jpg', Vectors::hash_stream( 'photo', $large_bytes ) );
		file_put_contents( $root . '/wp-content/uploads/2026/11/copy.jpg', Vectors::hash_stream( 'photo', $large_bytes ) );
		file_put_contents( $root . '/wp-content/plugins/plugin.php', str_repeat( "<?php // plugin\n", 2000 ) );
		symlink( '../index.php', $root . '/wp-content/link-to-index' );
		chmod( $root . '/wp-config.php', 0600 );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $path => $info ) {
			if ( ! is_link( (string) $path ) ) {
				touch( (string) $path, 1767323045 );
			}
		}
	}
}
