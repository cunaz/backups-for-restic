<?php
/**
 * Byte source backed by a string.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a string in pieces; used for generated content and tests.
 */
final class StringByteSource implements ByteSource {

	/**
	 * Current position.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Wraps a string.
	 *
	 * @param string $data Content.
	 */
	public function __construct( private readonly string $data ) {
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; empty at the end.
	 */
	public function read( int $length ): string {
		$chunk         = substr( $this->data, $this->offset, max( 1, $length ) );
		$this->offset += strlen( $chunk );
		return $chunk;
	}
}
