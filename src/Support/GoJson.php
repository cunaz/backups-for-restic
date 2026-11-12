<?php
/**
 * JSON encoding compatible with Go's encoding/json.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes and decodes JSON the way restic (Go) does.
 *
 * The restic format stores trees as JSON and identifies them by the SHA-256 of their bytes. Producing the
 * same bytes as Go lets identical directories deduplicate across the plugin and the restic CLI.
 * Go differs from PHP's json_encode() in details: it escapes <, > and & as <, > and
 * & (lowercase hex), never escapes slashes or non-ASCII text, escapes U+2028 and U+2029,
 * and replaces invalid UTF-8 with �.
 *
 * Supported values: null, bool, int, string, lists, string-keyed arrays (objects, key order
 * preserved), \stdClass (objects) and RawJson fragments.
 */
final class GoJson {

	/**
	 * Maximum nesting depth accepted when decoding data from the server.
	 */
	private const int MAX_DEPTH = 64;

	/**
	 * Matches well-formed UTF-8 sequences (no overlongs, no surrogates), as Go defines them.
	 */
	private const string UTF8_SEQUENCE = '/\G(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})+/';

	/**
	 * Encodes a value.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON text.
	 * @throws InvalidArgument If the value contains an unsupported type.
	 */
	public static function encode( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return self::encode_string( $value );
		}
		if ( $value instanceof RawJson ) {
			return $value->json;
		}
		if ( $value instanceof \stdClass ) {
			return self::encode_object( get_object_vars( $value ) );
		}
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? self::encode_list( $value ) : self::encode_object( $value );
		}

		InvalidArgument::raise( 'Value cannot be encoded as restic JSON.', [ 'type' => get_debug_type( $value ) ] );
	}

	/**
	 * Encodes a string exactly like Go's encoding/json with HTML escaping.
	 *
	 * @param string $value Arbitrary bytes.
	 * @return string Quoted JSON string.
	 */
	public static function encode_string( string $value ): string {
		// Fast path: printable ASCII without characters that need escaping.
		if ( 1 === preg_match( '/^[\x20\x21\x23-\x25\x27-\x3B\x3D\x3F-\x5B\x5D-\x7F]*$/', $value ) ) {
			return '"' . $value . '"';
		}

		if ( mb_check_encoding( $value, 'UTF-8' ) ) {
			return '"' . self::escape_valid_utf8( $value ) . '"';
		}

		// Go writes every byte that is not part of a well-formed sequence as the escape \ufffd.
		$result = '';
		$offset = 0;
		$length = strlen( $value );
		while ( $offset < $length ) {
			if ( 1 === preg_match( self::UTF8_SEQUENCE, $value, $match, 0, $offset ) ) {
				$result .= self::escape_valid_utf8( $match[0] );
				$offset += strlen( $match[0] );
				continue;
			}
			$result .= '\\ufffd';
			++$offset;
		}
		return '"' . $result . '"';
	}

	/**
	 * Escapes a valid UTF-8 string like Go's encoding/json.
	 *
	 * @param string $value Valid UTF-8.
	 * @return string Escaped body without quotes.
	 */
	private static function escape_valid_utf8( string $value ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x1F"\\\\<>&]|\x{2028}|\x{2029}/u',
			static function ( array $found ): string {
				return match ( $found[0] ) {
					'"'        => '\\"',
					'\\'       => '\\\\',
					"\x08"     => '\\b',
					"\f"       => '\\f',
					"\n"       => '\\n',
					"\r"       => '\\r',
					"\t"       => '\\t',
					"\u{2028}" => '\\u2028',
					"\u{2029}" => '\\u2029',
					default    => sprintf( '\\u%04x', ord( $found[0] ) ),
				};
			},
			$value
		);
	}

	/**
	 * Decodes JSON from the repository into arrays.
	 *
	 * Large integers (inode numbers) are kept as strings instead of losing precision as floats.
	 *
	 * @param string $json   JSON text.
	 * @param int    $nested Levels the text lies below the top of its document, when a part of
	 *                       a document is decoded on its own: the depth limit counts from the top.
	 * @return mixed Decoded value.
	 * @throws FormatException If the text is not valid JSON.
	 */
	public static function decode( string $json, int $nested = 0 ): mixed {
		try {
			return json_decode( $json, true, max( 1, self::MAX_DEPTH - $nested ), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $exception ) {
			FormatException::raise( 'Repository object is not valid JSON.', [ 'json_error' => $exception->getMessage() ], $exception );
		}
	}

	/**
	 * Decodes JSON that must be an object.
	 *
	 * @param string $json   JSON text.
	 * @param int    $nested Levels the text lies below the top of its document.
	 * @return array<mixed> Decoded object.
	 * @throws FormatException If the text is not a JSON object.
	 */
	public static function decode_object( string $json, int $nested = 0 ): array {
		$value = self::decode( $json, $nested );
		if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) ) ) {
			throw new FormatException( 'Repository object is not a JSON object.' );
		}
		return $value;
	}

	/**
	 * Encodes a list.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return string JSON array.
	 */
	private static function encode_list( array $items ): string {
		$parts = [];
		foreach ( $items as $item ) {
			$parts[] = self::encode( $item );
		}
		return '[' . implode( ',', $parts ) . ']';
	}

	/**
	 * Encodes an object, preserving key order.
	 *
	 * @param array<mixed> $fields Fields.
	 * @return string JSON object.
	 */
	private static function encode_object( array $fields ): string {
		$parts = [];
		foreach ( $fields as $key => $field ) {
			$parts[] = self::encode_string( (string) $key ) . ':' . self::encode( $field );
		}
		return '{' . implode( ',', $parts ) . '}';
	}
}
