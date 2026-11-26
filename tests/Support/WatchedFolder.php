<?php
/**
 * A stream wrapper that serves real folders and changes one while code reads its names.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * Serves a real path under a scheme of its own (`bfr-watched://` followed by the absolute path)
 * and runs a change once a given number of names has been read from a folder, so that a test
 * can change the folder in the middle of a reading. Every other operation is the one of PHP's
 * own wrapper on the real path.
 */
final class WatchedFolder {

	public const string SCHEME = 'bfr-watched';

	/**
	 * Set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The directory handle of the real folder.
	 *
	 * @var resource|false
	 */
	private $directory = false;

	/** Names that the readers of folders may read before the change runs. */
	private static int $names = 0;

	/**
	 * The change, run once.
	 *
	 * @var (\Closure(): void)|null
	 */
	private static ?\Closure $change = null;

	/**
	 * Registers the scheme and the change that runs after a number of names was read.
	 *
	 * @param int      $names  Names read before the change.
	 * @param \Closure $change The change.
	 */
	public static function watch( int $names, \Closure $change ): void {
		self::$names  = $names;
		self::$change = $change;
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
	}

	/** Removes the scheme and a change that did not run. */
	public static function stop(): void {
		self::$change = null;
		if ( in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::SCHEME );
		}
	}

	/** Whether the change has run. */
	public static function changed(): bool {
		return null === self::$change;
	}

	/**
	 * The address of a real path under this scheme.
	 *
	 * @param string $path Absolute path.
	 */
	public static function url( string $path ): string {
		return self::SCHEME . '://' . $path;
	}

	/**
	 * The real path of an address of this scheme.
	 *
	 * @param string $url Address.
	 */
	private static function path( string $url ): string {
		return substr( $url, strlen( self::SCHEME ) + 3 );
	}

	public function dir_opendir( string $url ): bool {
		$this->directory = @opendir( self::path( $url ) );
		return false !== $this->directory;
	}

	public function dir_readdir(): string|false {
		if ( false === $this->directory ) {
			return false;
		}
		$name = readdir( $this->directory );
		if ( false !== $name && null !== self::$change && --self::$names < 0 ) {
			$change       = self::$change;
			self::$change = null;
			$change();
		}
		return $name;
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
		$this->directory = false;
		return true;
	}

	/**
	 * Stats the real path.
	 *
	 * @param string $url   Address.
	 * @param int    $flags STREAM_URL_STAT_LINK for lstat().
	 * @return array<int|string, int>|false Status.
	 */
	public function url_stat( string $url, int $flags ): array|false {
		$path = self::path( $url );
		return 0 !== ( $flags & STREAM_URL_STAT_LINK ) ? @lstat( $path ) : @stat( $path );
	}
}
