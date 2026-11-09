<?php
/**
 * Operating system CSPRNG.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Random bytes from PHP's random_bytes(), which uses the operating system's CSPRNG.
 */
final class SystemRandom implements RandomSource {

	/**
	 * Returns cryptographically secure random bytes.
	 *
	 * @param int $length Number of bytes (at least 1).
	 * @return string Random bytes.
	 */
	public function bytes( int $length ): string {
		return random_bytes( max( 1, $length ) );
	}
}
