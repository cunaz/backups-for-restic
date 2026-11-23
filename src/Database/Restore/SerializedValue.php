<?php
/**
 * The values that WordPress stores with PHP's serialize(), read without unserialize().
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the part of PHP's serialization format that WordPress writes for its roles, its list
 * of active plugins and a user's capabilities: arrays with integer or string keys, strings,
 * integers, booleans and null, exactly as serialize() writes them. Everything else is
 * refused, objects first: such a value comes from a backup or from a table that other code
 * can write, and unserialize() would create objects of the classes that the value names.
 * A value is read once, with bounded nesting and a bounded number of elements, and has to
 * end where its outermost element ends.
 */
final class SerializedValue {

	/**
	 * Elements in one value: every value, nested ones included, keys not. Every element takes at
	 * least six bytes with its key, so no value within the size limits of RoleMap and PluginList,
	 * at most 1 MiB, reaches this bound; it guards other callers.
	 */
	public const int MAX_ELEMENTS = 262144;

	/** A boolean: b:0; or b:1;. */
	private const string BOOLEAN = '/\Gb:([01]);/';

	/** An integer: no sign but a minus, no leading zero, at most 19 digits. */
	private const string INTEGER = '/\Gi:(-?(?:0|[1-9][0-9]{0,18}));/';

	/** The head of a string: its length in bytes and the opening quote. */
	private const string TEXT = '/\Gs:(0|[1-9][0-9]{0,9}):"/';

	/** The head of an array: its element count and the opening brace. */
	private const string MAP = '/\Ga:(0|[1-9][0-9]{0,9}):\{/';

	/**
	 * Decodes one stored value.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $depth Deepest nesting of arrays allowed: 0 for a scalar, 1 for a flat array.
	 * @return array<array-key,mixed>|string|int|bool|null The value as unserialize() returns it.
	 * @throws FormatException If the value is anything else, nests deeper or holds more elements.
	 */
	public static function decode( string $bytes, int $depth ): array|string|int|bool|null {
		$offset = 0;
		$budget = self::MAX_ELEMENTS;
		$value  = self::value( $bytes, $offset, $depth, $budget );
		if ( strlen( $bytes ) !== $offset ) {
			throw new FormatException( 'A stored WordPress value continues after its end.' );
		}
		return $value;
	}

	/**
	 * Reads the element that starts at the offset.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the element.
	 * @param int    $depth Nesting still allowed.
	 * @param int    $budget Elements still allowed.
	 * @return array<array-key,mixed>|string|int|bool|null Element.
	 * @throws FormatException If the element is not one that serialize() writes for these values.
	 */
	private static function value( string $bytes, int &$offset, int $depth, int &$budget ): array|string|int|bool|null {
		--$budget;
		if ( $budget < 0 ) {
			throw new FormatException( 'A stored WordPress value holds too many elements.' );
		}
		return match ( $bytes[ $offset ] ?? '' ) {
			'N'     => self::token( $bytes, $offset, 'N;' ),
			'b'     => '1' === self::header( $bytes, $offset, self::BOOLEAN ),
			'i'     => self::integer( $bytes, $offset ),
			's'     => self::text( $bytes, $offset ),
			'a'     => self::map( $bytes, $offset, $depth, $budget ),
			default => throw new FormatException( 'A stored WordPress value is not one that WordPress writes.' ),
		};
	}

	/**
	 * Reads an integer as serialize() writes it: no sign but a minus, no leading zero, in range.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the integer.
	 * @return int Integer.
	 * @throws FormatException If the digits are not the ones serialize() writes for an integer.
	 */
	private static function integer( string $bytes, int &$offset ): int {
		$digits = self::header( $bytes, $offset, self::INTEGER );
		$number = (int) $digits;
		if ( (string) $number !== $digits ) {
			throw new FormatException( 'A stored WordPress integer is out of range.' );
		}
		return $number;
	}

	/**
	 * Reads a string: its byte length, then exactly that many bytes between quotes. A length
	 * beyond the value leaves nothing for the closing quote, which is then missing.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the string.
	 * @return string Bytes.
	 * @throws FormatException If the string does not end where its length says.
	 */
	private static function text( string $bytes, int &$offset ): string {
		$length  = (int) self::header( $bytes, $offset, self::TEXT );
		$text    = substr( $bytes, $offset, $length );
		$offset += $length;
		self::token( $bytes, $offset, '";' );
		return $text;
	}

	/**
	 * Reads an array: its element count, then that many keys and values between braces.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the array.
	 * @param int    $depth Nesting still allowed, this array included.
	 * @param int    $budget Elements still allowed.
	 * @return array<array-key,mixed> Array.
	 * @throws FormatException If the array nests too deep, repeats a key or does not end after its count.
	 */
	private static function map( string $bytes, int &$offset, int $depth, int &$budget ): array {
		$count = (int) self::header( $bytes, $offset, self::MAP );
		if ( $depth < 1 || $count > $budget ) {
			throw new FormatException( 'A stored WordPress array nests too deep or holds too many elements.' );
		}
		$map = [];
		for ( $i = 0; $i < $count; ++$i ) {
			$key = match ( $bytes[ $offset ] ?? '' ) {
				'i'     => self::integer( $bytes, $offset ),
				's'     => self::text( $bytes, $offset ),
				default => throw new FormatException( 'A stored WordPress array has a key that is neither an integer nor a string.' ),
			};
			// PHP's own array rules apply: a string of a decimal integer is that integer.
			if ( array_key_exists( $key, $map ) ) {
				throw new FormatException( 'A stored WordPress array repeats a key.' );
			}
			$map[ $key ] = self::value( $bytes, $offset, $depth - 1, $budget );
		}
		self::token( $bytes, $offset, '}' );
		return $map;
	}

	/**
	 * Reads a type letter, a colon, a field and the characters after it.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the end characters.
	 * @param string $pattern One of the patterns above, anchored at the offset, with the field as its group.
	 * @return string Field.
	 * @throws FormatException If the header does not match.
	 */
	private static function header( string $bytes, int &$offset, string $pattern ): string {
		if ( 1 !== preg_match( $pattern, $bytes, $match, 0, $offset ) ) {
			throw new FormatException( 'A stored WordPress value is not one that WordPress writes.' );
		}
		$offset += strlen( $match[0] );
		return $match[1];
	}

	/**
	 * Reads fixed characters.
	 *
	 * @param string $bytes Stored value.
	 * @param int    $offset Position, moved past the characters.
	 * @param string $token Characters.
	 * @return null Nothing; null is also the value that the token N; stands for.
	 * @throws FormatException If other characters follow.
	 */
	private static function token( string $bytes, int &$offset, string $token ): null {
		if ( substr( $bytes, $offset, strlen( $token ) ) !== $token ) {
			throw new FormatException( 'A stored WordPress value is not one that WordPress writes.' );
		}
		$offset += strlen( $token );
		return null;
	}
}
