<?php
/**
 * Sequential byte input.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Something the chunker can read from: a file, a database table being dumped, a test string.
 */
interface ByteSource {

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; an empty string means end of input.
	 */
	public function read( int $length ): string;
}
