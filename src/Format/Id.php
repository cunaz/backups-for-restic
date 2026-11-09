<?php
/**
 * Content identifier.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * A restic ID: the SHA-256 of a blob's plaintext or of a stored file's bytes.
 */
final readonly class Id {

	/**
	 * Creates an ID from raw bytes.
	 *
	 * @param string $bytes 32 bytes.
	 */
	private function __construct( private string $bytes ) {
	}

	/**
	 * Hashes data.
	 *
	 * @param string $data Data.
	 * @return self SHA-256 of the data.
	 */
	public static function of( string $data ): self {
		return new self( hash( 'sha256', $data, true ) );
	}

	/**
	 * Parses a hex ID.
	 *
	 * @param string $hex 64 hex digits.
	 * @return self ID.
	 * @throws FormatException If the text is not a 64-digit hex string.
	 */
	public static function from_hex( string $hex ): self {
		if ( 64 !== strlen( $hex ) || 64 !== strspn( $hex, '0123456789abcdefABCDEF' ) ) {
			throw new FormatException( 'Identifier is not a 64-digit hex string.' );
		}
		return new self( (string) hex2bin( $hex ) );
	}

	/**
	 * Wraps raw bytes.
	 *
	 * @param string $bytes 32 bytes.
	 * @return self ID.
	 * @throws FormatException If the length is wrong.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( 32 !== strlen( $bytes ) ) {
			throw new FormatException( 'Identifier must be 32 bytes long.' );
		}
		return new self( $bytes );
	}

	/**
	 * Returns the raw bytes.
	 *
	 * @return string 32 bytes.
	 */
	public function bytes(): string {
		return $this->bytes;
	}

	/**
	 * Returns the lowercase hex form used in file names and JSON.
	 *
	 * @return string 64 hex digits.
	 */
	public function hex(): string {
		return bin2hex( $this->bytes );
	}

	/**
	 * Returns the short form restic prints (first 8 hex digits).
	 *
	 * @return string 8 hex digits.
	 */
	public function short(): string {
		return substr( $this->hex(), 0, 8 );
	}

	/**
	 * Compares two IDs.
	 *
	 * @param Id $other Other ID.
	 * @return bool Whether both are equal.
	 */
	public function equals( Id $other ): bool {
		return $this->bytes === $other->bytes;
	}
}
