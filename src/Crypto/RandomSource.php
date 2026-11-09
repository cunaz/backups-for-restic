<?php
/**
 * Source of random bytes.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies random bytes for nonces, keys, salts and identifiers.
 *
 * Production code always uses SystemRandom; tests may inject a deterministic source to produce
 * reproducible vectors.
 */
interface RandomSource {

	/**
	 * Returns cryptographically secure random bytes.
	 *
	 * @param int $length Number of bytes.
	 * @return string Random bytes.
	 */
	public function bytes( int $length ): string;
}
