<?php
/**
 * Typed access to decoded JSON.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads values from decoded JSON (checkpoints, settings) with a fallback instead of a cast, so
 * that a damaged file yields safe defaults rather than type errors.
 */
final class Decoded {

	/**
	 * Returns an integer, or the fallback.
	 *
	 * @param mixed $value    Decoded value.
	 * @param int   $fallback Default.
	 * @return int Integer.
	 */
	public static function int( mixed $value, int $fallback = 0 ): int {
		return is_int( $value ) ? $value : $fallback;
	}

	/**
	 * Returns a number as a float, or the fallback. JSON has one number type, and PHP encodes a
	 * float with a whole value such as 1.0 as 1, which decodes to an integer.
	 *
	 * @param mixed $value    Decoded value.
	 * @param float $fallback Default.
	 * @return float Number.
	 */
	public static function number( mixed $value, float $fallback = 0.0 ): float {
		return is_int( $value ) || is_float( $value ) ? (float) $value : $fallback;
	}

	/**
	 * Returns a non-negative integer, or zero.
	 *
	 * @param mixed $value Decoded value.
	 * @return int<0, max> Integer.
	 */
	public static function count( mixed $value ): int {
		return is_int( $value ) && $value >= 0 ? $value : 0;
	}

	/**
	 * Returns a string, or the fallback.
	 *
	 * @param mixed  $value    Decoded value.
	 * @param string $fallback Default.
	 * @return string String.
	 */
	public static function string( mixed $value, string $fallback = '' ): string {
		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Returns the strings of a list, dropping anything else.
	 *
	 * @param mixed $value Decoded value.
	 * @return list<string> Strings.
	 */
	public static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * Returns the arrays of a list, dropping anything else.
	 *
	 * @param mixed $value Decoded value.
	 * @return list<array<mixed>> Arrays.
	 */
	public static function arrays( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : [];
	}
}
