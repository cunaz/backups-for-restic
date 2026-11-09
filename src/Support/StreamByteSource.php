<?php
/**
 * Byte source backed by a PHP stream.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Reads from an open stream, for example a file being backed up.
 */
final class StreamByteSource implements ByteSource {

	/**
	 * Wraps a stream.
	 *
	 * @param resource $stream Readable stream.
	 * @throws InvalidArgument If the argument is not a stream.
	 */
	public function __construct( private $stream ) {
		if ( ! is_resource( $stream ) ) {
			throw new InvalidArgument( 'A readable stream is required.' );
		}
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; empty at end of file.
	 */
	public function read( int $length ): string {
		if ( feof( $this->stream ) ) {
			return '';
		}
		$data = fread( $this->stream, max( 1, $length ) );
		return false === $data ? '' : $data;
	}
}
