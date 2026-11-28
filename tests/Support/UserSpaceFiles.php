<?php
/**
 * A user-space file:// wrapper like the one that Infection puts in place in a mutant process.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * Opens every file through PHP's own wrapper and serves a replacement when the original file is
 * included, as Infection's include interceptor does. Like that interceptor it supports no stream
 * option, so fsync() fails on every stream it opens. A test can also have a callback run before
 * each directory is opened for listing, to change files between two listings of a directory as
 * another request could.
 */
final class UserSpaceFiles {

	/** PHP passes this option when it opens a file to include it (main/php_streams.h). */
	private const int OPEN_FOR_INCLUDE = 0x80;

	/**
	 * Set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The stream opened through PHP's own wrapper.
	 *
	 * @var resource|false
	 */
	private $handle = false;

	/**
	 * The directory handle opened through PHP's own wrapper.
	 *
	 * @var resource|false
	 */
	private $directory = false;

	private static string $original = '';

	private static string $replacement = '';

	/**
	 * Runs with the path before each directory is opened for listing, or null.
	 *
	 * @var (\Closure(string): void)|null
	 */
	private static ?\Closure $listing = null;

	/**
	 * Puts this wrapper in place of PHP's own.
	 *
	 * @param string                        $original    A file for which another one is served when it is included.
	 * @param string                        $replacement The file served instead.
	 * @param (\Closure(string): void)|null $listing     Runs with the path before each directory is opened for listing, through PHP's own wrapper.
	 */
	public static function enable( string $original = '', string $replacement = '', ?\Closure $listing = null ): void {
		self::$original    = $original;
		self::$replacement = $replacement;
		self::$listing     = $listing;
		self::register();
	}

	/** Gives PHP its own wrapper back, if it is not in place. */
	public static function disable(): void {
		self::$listing = null;
		@stream_wrapper_restore( 'file' );
	}

	/** Registers this wrapper for file://. */
	private static function register(): void {
		stream_wrapper_unregister( 'file' );
		stream_wrapper_register( 'file', self::class );
	}

	/**
	 * Runs one operation through PHP's own wrapper.
	 *
	 * @template T
	 * @param callable(): T $operation The operation.
	 * @return T
	 */
	private static function plain( callable $operation ): mixed {
		stream_wrapper_restore( 'file' );
		try {
			return $operation();
		} finally {
			self::register();
		}
	}

	public function stream_open( string $path, string $mode, int $options ): bool {
		if ( '' !== self::$original && 0 !== ( $options & self::OPEN_FOR_INCLUDE ) && ( $path === self::$original || realpath( $path ) === self::$original ) ) {
			$path = self::$replacement;
		}
		$this->handle = self::plain( static fn() => @fopen( $path, $mode ) );
		return false !== $this->handle;
	}

	public function stream_read( int $count ): string|false {
		return false === $this->handle ? false : fread( $this->handle, max( 1, $count ) );
	}

	public function stream_write( string $data ): int|false {
		return false === $this->handle ? false : fwrite( $this->handle, $data );
	}

	public function stream_flush(): bool {
		return false !== $this->handle && fflush( $this->handle );
	}

	public function stream_eof(): bool {
		return false === $this->handle || feof( $this->handle );
	}

	public function stream_seek( int $offset, int $whence ): bool {
		return false !== $this->handle && 0 === fseek( $this->handle, $offset, $whence );
	}

	public function stream_tell(): int|false {
		return false === $this->handle ? false : ftell( $this->handle );
	}

	/**
	 * The stream's status.
	 *
	 * @return array<int|string, int>|false
	 */
	public function stream_stat(): array|false {
		return false === $this->handle ? false : fstat( $this->handle );
	}

	public function stream_lock( int $operation ): bool {
		return false !== $this->handle && flock( $this->handle, $operation );
	}

	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}

	public function stream_close(): void {
		if ( false !== $this->handle ) {
			fclose( $this->handle );
		}
	}

	/**
	 * A file's status.
	 *
	 * @param string $path  The file.
	 * @param int    $flags STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
	 * @return array<int|string, int>|false
	 */
	public function url_stat( string $path, int $flags ): array|false {
		return self::plain( static fn() => 0 !== ( $flags & STREAM_URL_STAT_LINK ) ? @lstat( $path ) : @stat( $path ) );
	}

	public function unlink( string $path ): bool {
		return self::plain( static fn() => @unlink( $path ) );
	}

	public function rename( string $from, string $to ): bool {
		return self::plain( static fn() => @rename( $from, $to ) );
	}

	public function mkdir( string $path, int $mode, int $options ): bool {
		return self::plain( static fn() => @mkdir( $path, $mode, 0 !== ( $options & STREAM_MKDIR_RECURSIVE ) ) );
	}

	public function rmdir( string $path ): bool {
		return self::plain( static fn() => @rmdir( $path ) );
	}

	public function dir_opendir( string $path ): bool {
		$listing         = self::$listing;
		$this->directory = self::plain(
			static function () use ( $path, $listing ) {
				if ( null !== $listing ) {
					$listing( $path );
				}
				return @opendir( $path );
			}
		);
		return false !== $this->directory;
	}

	public function dir_readdir(): string|false {
		return false === $this->directory ? false : readdir( $this->directory );
	}

	public function dir_rewinddir(): bool {
		if ( false !== $this->directory ) {
			rewinddir( $this->directory );
		}
		return true;
	}

	public function dir_closedir(): bool {
		if ( false !== $this->directory ) {
			closedir( $this->directory );
		}
		return true;
	}

	/**
	 * Changes a file's metadata (touch, chmod).
	 *
	 * @param string $path   The file.
	 * @param int    $option STREAM_META_TOUCH or STREAM_META_ACCESS.
	 * @param mixed  $value  The times or the mode.
	 */
	public function stream_metadata( string $path, int $option, mixed $value ): bool {
		return self::plain(
			static function () use ( $path, $option, $value ): bool {
				if ( STREAM_META_TOUCH === $option ) {
					return is_array( $value ) && isset( $value[0], $value[1] ) ? @touch( $path, (int) $value[0], (int) $value[1] ) : @touch( $path );
				}
				return STREAM_META_ACCESS === $option && is_int( $value ) && @chmod( $path, $value );
			}
		);
	}
}
