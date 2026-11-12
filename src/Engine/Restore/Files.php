<?php
/**
 * Checked I/O inside a restore's private directories.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\LockConflict;

defined( 'ABSPATH' ) || exit;

/**
 * No operation follows a destination link or accepts a multiply linked working file.
 * The caller must keep these directories private; PHP has no portable openat/no-follow API.
 *
 * @phpstan-import-type Directory from State
 */
final class Files {

	/**
	 * Resolves a directory once and records its identity.
	 *
	 * @param string $path Existing directory.
	 * @return Directory Canonical identity.
	 * @throws InvalidArgument If restore inputs or directories are unsuitable.
	 */
	public static function bind( string $path ): array {
		$real = realpath( $path );
		if ( false === $real || is_link( rtrim( $path, '\\' === DIRECTORY_SEPARATOR ? '/\\' : '/' ) ) || ! is_dir( $real ) ) {
			throw new InvalidArgument( 'Restore directories must exist and must not be symbolic links.' );
		}
		$stat = self::stat( $real );
		return [
			'path' => $real,
			'dev'  => $stat['dev'],
			'ino'  => $stat['ino'],
		];
	}

	/**
	 * Refuses replacing or redirecting a bound directory between requests.
	 *
	 * @param Directory $directory Bound identity.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function verify( array $directory ): void {
		$stat = self::stat( $directory['path'] );
		if ( ( $stat['mode'] & 0170000 ) !== 0040000 || realpath( $directory['path'] ) !== $directory['path'] || $stat['dev'] !== $directory['dev'] || $stat['ino'] !== $directory['ino'] ) {
			throw new FormatException( 'A restore directory has changed since the run started.' );
		}
	}

	/**
	 * Requires an empty directory, optionally allowing the run's local lock.
	 *
	 * @param string $path Directory.
	 * @param bool   $allow_lock Whether restore.lock is allowed.
	 * @throws InvalidArgument If restore inputs or directories are unsuitable.
	 */
	public static function empty_directory( string $path, bool $allow_lock = false ): void {
		$handle = opendir( $path );
		if ( false === $handle ) {
			throw new InvalidArgument( 'A restore directory cannot be inspected.' );
		}
		try {
			for ( $name = readdir( $handle ); false !== $name; $name = readdir( $handle ) ) {
				if ( '.' !== $name && '..' !== $name && ( ! $allow_lock || 'restore.lock' !== $name ) ) {
					throw new InvalidArgument( 'A new restore requires empty staging and working directories.' );
				}
			}
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Serializes requests; callers reread the checkpoint after taking this lock.
	 *
	 * @param string $work Canonical private working directory.
	 * @return resource Held lock handle.
	 * @throws LockConflict If another request holds the local restore lock.
	 */
	public static function lock( string $work ) {
		$path   = $work . '/restore.lock';
		$handle = self::open( $path, ! self::exists( $path ) );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new LockConflict( 'Another request is working on this restore.' );
		}
		return $handle;
	}

	/**
	 * Builds a path only after verifying all existing parent components.
	 *
	 * @param State  $state Run state.
	 * @param string $relative Raw relative path below the target.
	 * @return string Destination path.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function destination( State $state, string $relative ): string {
		self::verify( $state->target );
		$parts = explode( '/', $relative );
		if ( count( $parts ) > 256 || strlen( $relative ) > 4095 ) {
			throw new FormatException( 'A restore path exceeds its depth or length limit.' );
		}
		$path = $state->target['path'];
		$last = count( $parts ) - 1;
		foreach ( $parts as $number => $part ) {
			Names::validate( $part );
			$path .= '/' . $part;
			if ( $number < $last ) {
				$stat = self::stat( $path );
				if ( ( $stat['mode'] & 0170000 ) !== 0040000 ) {
					throw new FormatException( 'A restore parent is missing, a link or not a directory.' );
				}
			}
		}
		return $path;
	}

	/**
	 * Reads a bounded checkpoint without following a local link.
	 *
	 * @param string $work Canonical private working directory.
	 * @return State Parsed checkpoint.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function read( string $work ): State {
		$handle = self::open( $work . '/restore.json' );
		try {
			$json = stream_get_contents( $handle, State::MAX_BYTES + 1 );
			if ( false === $json || strlen( $json ) > State::MAX_BYTES ) {
				throw new FormatException( 'The restore checkpoint cannot be read within its size limit.' );
			}
			return State::from_json( $json );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Publishes complete checkpoint bytes, leaving the old checkpoint intact on failure.
	 *
	 * @param State $state State to publish.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function persist( State $state ): void {
		self::verify( $state->work );
		$path = $state->work['path'] . '/restore.json';
		$temp = $path . '.tmp';
		if ( self::exists( $temp ) ) {
			self::regular( $temp );
			if ( ! unlink( $temp ) ) {
				throw new FormatException( 'A restore checkpoint temporary file cannot be removed.' );
			}
		}
		if ( self::exists( $path ) ) {
			self::regular( $path );
		}
		$handle = self::open( $temp, true );
		try {
			self::write( $handle, $state->to_json() );
			self::flush( $handle );
		} finally {
			self::close( $handle );
		}
		if ( ! rename( $temp, $path ) ) {
			throw new FormatException( 'The restore checkpoint cannot be published.' );
		}
	}

	/**
	 * Opens a single-link regular file without truncating it.
	 *
	 * @param string $path Private path whose parents the caller verified.
	 * @param bool   $create Create exclusively, otherwise require an existing regular file.
	 * @return resource Checked handle.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 * @throws \Throwable Preserves the original failure after releasing owned resources.
	 */
	public static function open( string $path, bool $create = false ) {
		$before = $create ? null : self::regular( $path );
		$handle = fopen( $path, $create ? 'x+b' : 'r+b' );
		if ( false === $handle ) {
			throw new FormatException( 'A restore file cannot be opened.' );
		}
		try {
			$stat  = fstat( $handle );
			$after = self::regular( $path );
			if ( false === $stat || $stat['dev'] !== $after['dev'] || $stat['ino'] !== $after['ino'] || ( null !== $before && ( $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] ) ) ) {
				throw new FormatException( 'A restore file changed while it was being opened.' );
			}
			if ( $create && ! chmod( $path, 0600 ) ) {
				throw new FormatException( 'A restore file cannot be made private.' );
			}
		} catch ( \Throwable $error ) {
			fclose( $handle );
			throw $error;
		}
		return $handle;
	}

	/**
	 * Rewinds only bytes beyond a committed offset; never invents missing committed data.
	 *
	 * @param resource $handle Open file.
	 * @param int      $offset Committed length.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function rewind( $handle, int $offset ): void {
		$stat = fstat( $handle );
		if ( $offset < 0 || false === $stat || $stat['size'] < $offset || ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) {
			throw new FormatException( 'A restore file cannot be resumed at its committed offset.' );
		}
	}

	/**
	 * Completes short writes and refuses zero progress.
	 *
	 * @param resource $handle Open file.
	 * @param string   $bytes Bytes to write.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function write( $handle, string $bytes ): void {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new FormatException( 'A restore file cannot be written completely.' );
			}
			$offset += $written;
		}
	}

	/**
	 * Checks buffered writes before publishing a cursor.
	 *
	 * @param resource $handle Open file.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function flush( $handle ): void {
		if ( ! fflush( $handle ) ) {
			throw new FormatException( 'A restore file cannot be flushed.' );
		}
	}

	/**
	 * Reports delayed write errors.
	 *
	 * @param resource $handle Open file.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function close( $handle ): void {
		if ( ! fclose( $handle ) ) {
			throw new FormatException( 'A restore file cannot be closed.' );
		}
	}

	/**
	 * Tests directory entries including dangling symlinks, bypassing PHP's stat cache.
	 *
	 * @param string $path Path.
	 * @return bool Whether an entry exists.
	 */
	public static function exists( string $path ): bool {
		clearstatcache( true, $path );
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Reads the entry itself; symbolic links are never dereferenced.
	 *
	 * @param string $path Path.
	 * @return array{dev: int, ino: int, mode: int, nlink: int, size: int} Relevant stat fields.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function stat( string $path ): array {
		clearstatcache( true, $path );
		$stat = lstat( $path );
		if ( false === $stat ) {
			throw new FormatException( 'A restore directory or file cannot be inspected.' );
		}
		return [
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'nlink' => $stat['nlink'],
			'size'  => $stat['size'],
		];
	}

	/**
	 * Rejects links and special files before opening them.
	 *
	 * @param string $path Existing path.
	 * @return array{dev: int, ino: int, mode: int, nlink: int, size: int} Relevant stat fields.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public static function regular( string $path ): array {
		$stat = self::stat( $path );
		if ( ( $stat['mode'] & 0170000 ) !== 0100000 || 1 !== $stat['nlink'] ) {
			throw new FormatException( 'A restore file is a link or is not a regular private file.' );
		}
		return $stat;
	}
}
