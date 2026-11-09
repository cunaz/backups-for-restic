<?php
/**
 * Binary helpers.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Little-endian integers and strict base64, as used by the restic format.
 */
final class Bytes {

	/**
	 * Encodes an unsigned 32-bit integer, little-endian.
	 *
	 * @param int $value Value between 0 and 2^32 - 1.
	 * @return string Four bytes.
	 */
	public static function uint32_le( int $value ): string {
		return pack( 'V', $value );
	}

	/**
	 * Reads an unsigned 32-bit little-endian integer.
	 *
	 * @param string $bytes  Input.
	 * @param int    $offset Position.
	 * @return int Value.
	 * @throws FormatException If fewer than four bytes are available.
	 */
	public static function read_uint32_le( string $bytes, int $offset = 0 ): int {
		if ( $offset < 0 || strlen( $bytes ) < $offset + 4 ) {
			throw new FormatException( 'Unexpected end of data while reading an integer.' );
		}
		$value = unpack( 'V', $bytes, $offset );
		return is_array( $value ) ? $value[1] : 0;
	}

	/**
	 * Decodes standard base64, rejecting characters outside the alphabet.
	 *
	 * @param string $text Base64 text.
	 * @return string Bytes.
	 * @throws FormatException If the text is not strict base64.
	 */
	public static function base64_decode( string $text ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- restic stores keys and salts as base64.
		$bytes = base64_decode( $text, true );
		if ( false === $bytes ) {
			throw new FormatException( 'Value is not valid base64.' );
		}
		return $bytes;
	}

	/**
	 * Encodes standard base64 with padding, as Go encodes []byte in JSON.
	 *
	 * @param string $bytes Bytes.
	 * @return string Base64 text.
	 */
	public static function base64_encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- restic stores keys and salts as base64.
		return base64_encode( $bytes );
	}

	/**
	 * Encodes URL-safe base64 without padding (RFC 4648 §5).
	 *
	 * @param string $bytes Bytes.
	 * @return string Base64url text.
	 */
	public static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( self::base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes URL-safe base64 without padding, rejecting anything else.
	 *
	 * @param string $text Base64url text.
	 * @return string Bytes.
	 * @throws FormatException If the text is not strict base64url.
	 */
	public static function base64url_decode( string $text ): string {
		if ( strspn( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_' ) !== strlen( $text ) || 1 === strlen( $text ) % 4 ) {
			throw new FormatException( 'Value is not valid base64url.' );
		}
		return self::base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ) );
	}
}
