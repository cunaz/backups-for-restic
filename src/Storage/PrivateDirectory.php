<?php
/**
 * The plugin's private working directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Where jobs, caches, packs under construction and the scrypt table live (ADR 0007).
 *
 * Created with owner-only permissions and deny rules for Apache and IIS. Deny files are a
 * second line of defence only: the Doctor fetches a probe file over HTTP and blocks setup
 * and backups while the directory is reachable from the web.
 */
final readonly class PrivateDirectory {

	public const string NAME = 'backups-for-restic';

	public const array SUBDIRECTORIES = [ 'jobs', 'cache', 'tmp', 'kdf', 'logs', 'probe', 'restores' ];

	private const string HTACCESS = "# Nothing in this directory may be served.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	private const string WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * Uses a directory.
	 *
	 * @param string $path Absolute path without trailing slash.
	 */
	public function __construct( public string $path ) {
	}

	/**
	 * Chooses the location: the constant if defined, otherwise wp-content, otherwise uploads.
	 *
	 * @param string|null $configured  BACKUPS_FOR_RESTIC_PRIVATE_DIR, if defined.
	 * @param string      $content_dir WP_CONTENT_DIR.
	 * @param string      $uploads_dir Base directory of uploads.
	 * @return self Directory.
	 */
	public static function locate( ?string $configured, string $content_dir, string $uploads_dir ): self {
		if ( null !== $configured && '' !== trim( $configured ) ) {
			return new self( rtrim( $configured, '/' ) );
		}
		$preferred = rtrim( $content_dir, '/' ) . '/' . self::NAME;
		if ( is_dir( $preferred ) || is_writable( $content_dir ) ) {
			return new self( $preferred );
		}
		return new self( rtrim( $uploads_dir, '/' ) . '/' . self::NAME );
	}

	/**
	 * Creates the directory, its deny files and subdirectories. Safe to call on every request.
	 *
	 * @throws StorageFailed If the directory cannot be created.
	 */
	public function ensure(): void {
		$umask = umask( 0077 );
		try {
			$this->make_directory( $this->path );
			$this->write_if_missing( '.htaccess', self::HTACCESS );
			$this->write_if_missing( 'web.config', self::WEB_CONFIG );
			$this->write_if_missing( 'index.php', "<?php\n// Silence is golden.\n" );
			foreach ( self::SUBDIRECTORIES as $name ) {
				$this->make_directory( $this->path . '/' . $name );
			}
		} finally {
			umask( $umask );
		}
	}

	/**
	 * Absolute path of a subdirectory.
	 *
	 * @param string $name One of SUBDIRECTORIES.
	 * @return string Path.
	 */
	public function subdirectory( string $name ): string {
		return $this->path . '/' . $name;
	}

	/**
	 * Whether the directory lies inside another one, such as the web root.
	 *
	 * @param string $root Absolute path.
	 * @return bool True if inside or equal.
	 */
	public function is_inside( string $root ): bool {
		$root = rtrim( $root, '/' ) . '/';
		return str_starts_with( $this->path . '/', $root );
	}

	/**
	 * Writes a probe file with random content. The Doctor requests its URL; if the content
	 * comes back, the directory is exposed.
	 *
	 * @param RandomSource $random Randomness for name and content.
	 * @return array{file: string, token: string} Absolute path and expected content.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public function write_probe( RandomSource $random ): array {
		$this->ensure();
		$token = bin2hex( $random->bytes( 16 ) );
		$file  = $this->subdirectory( 'probe' ) . '/' . bin2hex( $random->bytes( 8 ) ) . '.txt';
		if ( false === file_put_contents( $file, $token ) ) {
			StorageFailed::raise( 'The probe file could not be written.' );
		}
		return [
			'file'  => $file,
			'token' => $token,
		];
	}

	/**
	 * Deletes everything the plugin put into the directory, then the directory if it is empty.
	 * Only known names are touched, so a directory chosen with BACKUPS_FOR_RESTIC_PRIVATE_DIR that
	 * also holds other files keeps them.
	 */
	public function remove_contents(): void {
		foreach ( [ ...self::SUBDIRECTORIES, 'spool' ] as $name ) {
			Files::remove_tree( $this->path . '/' . $name );
		}
		foreach ( [ '.htaccess', 'web.config', 'index.php', 'runner.lock', 'ca.pem' ] as $name ) {
			if ( is_file( $this->path . '/' . $name ) ) {
				unlink( $this->path . '/' . $name );
			}
		}
		$entries = is_dir( $this->path ) ? scandir( $this->path ) : false;
		if ( [ '.', '..' ] === $entries ) {
			rmdir( $this->path );
		}
	}

	/**
	 * Free disk space where the directory lives.
	 *
	 * @return int|null Bytes, or null if the host does not tell.
	 */
	public function free_space(): ?int {
		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $this->path ) : false;
		return false === $free ? null : (int) $free;
	}

	/**
	 * Creates one directory with owner-only permissions.
	 *
	 * @param string $directory Path.
	 * @throws StorageFailed If it cannot be created.
	 */
	private function make_directory( string $directory ): void {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
			StorageFailed::raise( 'The private directory could not be created.', [ 'path' => $directory ] );
		}
		chmod( $directory, 0700 );
	}

	/**
	 * Writes a file unless it exists.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @throws StorageFailed If it cannot be written.
	 */
	private function write_if_missing( string $name, string $content ): void {
		$file = $this->path . '/' . $name;
		if ( ! is_file( $file ) && false === file_put_contents( $file, $content ) ) {
			StorageFailed::raise( 'A protection file could not be written.', [ 'file' => $name ] );
		}
	}
}
