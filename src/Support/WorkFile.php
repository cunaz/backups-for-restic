<?php
/**
 * Checked access to a private working file.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps short writes, failed seeks and unreadable files from becoming valid checkpoints.
 */
final class WorkFile {

	/**
	 * Open file, or null after close().
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Opens an existing file or creates a private, empty one exclusively.
	 *
	 * @param string $path   Working file path.
	 * @param bool   $create Whether this must be a new file.
	 * @throws StorageFailed If opening or setting permissions fails.
	 */
	public function __construct( private readonly string $path, bool $create = false ) {
		$handle = fopen( $path, $create ? 'x+b' : 'r+b' );
		if ( false === $handle ) {
			$this->fail();
		}
		$this->handle = $handle;
		if ( $create && ! chmod( $path, 0600 ) ) {
			$this->close();
			$this->fail();
		}
	}

	/** Releases a handle when an earlier operation threw. */
	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * Reads up to a bounded number of bytes; only EOF may return fewer.
	 *
	 * @param int $length Maximum bytes.
	 * @return string Bytes.
	 */
	public function read( int $length ): string {
		$bytes = '';
		$left  = $length;
		while ( $left > 0 ) {
			$part = fread( $this->resource(), $left );
			if ( false === $part || ( '' === $part && ! feof( $this->resource() ) ) ) {
				$this->fail();
			}
			if ( '' === $part ) {
				break;
			}
			$bytes .= $part;
			$left  -= strlen( $part );
		}
		return $bytes;
	}

	/**
	 * Writes every byte, retrying short writes.
	 *
	 * @param string $bytes Bytes.
	 */
	public function write( string $bytes ): void {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			$written = fwrite( $this->resource(), substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				$this->fail();
			}
			$offset += $written;
		}
	}

	/**
	 * Seeks to an absolute byte position.
	 *
	 * @param int $offset Byte position.
	 */
	public function seek( int $offset ): void {
		if ( $offset < 0 || 0 !== fseek( $this->resource(), $offset ) ) {
			$this->fail();
		}
	}

	/**
	 * Changes the file size, creating a sparse file when supported.
	 *
	 * @param int $length New size.
	 */
	public function truncate( int $length ): void {
		if ( $length < 0 || ! ftruncate( $this->resource(), $length ) ) {
			$this->fail();
		}
	}

	/**
	 * Returns the size without PHP's path stat cache.
	 *
	 * @return int Bytes.
	 */
	public function size(): int {
		$stat = fstat( $this->resource() );
		if ( false === $stat || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
			$this->fail();
		}
		return $stat['size'];
	}

	/** Makes buffered writes visible before a checkpoint advances. */
	public function flush(): void {
		if ( ! fflush( $this->resource() ) ) {
			$this->fail();
		}
	}

	/** Closes explicitly so that delayed write errors reach the caller. */
	public function close(): void {
		$handle       = $this->resource();
		$this->handle = null;
		if ( ! fclose( $handle ) ) {
			$this->fail();
		}
	}

	/**
	 * Returns the open handle.
	 *
	 * @return resource Handle.
	 */
	private function resource() {
		if ( ! is_resource( $this->handle ) ) {
			$this->fail();
		}
		return $this->handle;
	}

	/**
	 * Reports a local failure without exposing the private directory's path.
	 *
	 * @throws StorageFailed Always.
	 */
	private function fail(): never {
		StorageFailed::raise( 'A working file could not be read or written.', [ 'file' => basename( $this->path ) ] );
	}
}
