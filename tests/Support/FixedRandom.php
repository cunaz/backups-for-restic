<?php
/**
 * Deterministic randomness for reproducible tests.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Crypto\RandomSource;

/**
 * Serves bytes from a SHA-256 counter stream, or from a given string.
 */
final class FixedRandom implements RandomSource {

	private string $buffer = '';

	private int $counter = 0;

	public function __construct( private readonly string $seed = 'tests', private string $prefix = '' ) {
	}

	public function bytes( int $length ): string {
		$available = strlen( $this->prefix ) + strlen( $this->buffer );
		while ( $available < $length ) {
			$this->buffer .= hash( 'sha256', $this->seed . pack( 'P', $this->counter++ ), true );
			$available    += 32;
		}
		$data         = $this->prefix . $this->buffer;
		$this->prefix = '';
		$this->buffer = substr( $data, $length );
		return substr( $data, 0, $length );
	}
}
