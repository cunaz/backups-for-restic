<?php
/**
 * Go's strconv.Quote and strconv.Unquote for node names.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * File names are stored as the body of a Go string literal: strconv.Quote() with the outer
 * quotes removed. That makes arbitrary byte sequences representable in JSON.
 *
 * A rune is printed as is when Go's strconv.IsPrint() accepts it: letters, marks, numbers,
 * punctuation, symbols and the ASCII space. Everything else is escaped. Unicode categories
 * come from PCRE, so characters assigned in very recent Unicode versions may be classified
 * differently than in Go; that only affects deduplication parity, never correctness.
 */
final class GoQuote {

	private const string HEX = '0123456789abcdef';

	/**
	 * Quotes a name like strconv.Quote() and strips the surrounding quotes.
	 *
	 * @param string $name Raw file name bytes.
	 * @return string Escaped name as stored in restic trees.
	 */
	public static function quote( string $name ): string {
		// Fast path: printable ASCII without quote or backslash.
		if ( 1 === preg_match( '/^[\x20\x21\x23-\x5B\x5D-\x7E]*$/', $name ) ) {
			return $name;
		}

		$result = '';
		$offset = 0;
		$length = strlen( $name );
		while ( $offset < $length ) {
			$width = self::sequence_width( $name, $offset );
			if ( 0 === $width ) {
				// Invalid UTF-8 byte: \xHH.
				$result .= self::hex_escape( ord( $name[ $offset ] ) );
				++$offset;
				continue;
			}

			$character = substr( $name, $offset, $width );
			$offset   += $width;
			$result   .= self::escape_rune( $character );
		}
		return $result;
	}

	/**
	 * Reverses quote(): interprets the body of a Go double-quoted string literal like
	 * strconv.Unquote() does, including its replacement of stray bytes with U+FFFD.
	 *
	 * @param string $quoted Escaped name from a restic tree.
	 * @return string Raw file name bytes.
	 * @throws FormatException If the escape syntax is invalid.
	 */
	public static function unquote( string $quoted ): string {
		if ( ! str_contains( $quoted, '\\' ) && ! str_contains( $quoted, '"' ) && ! str_contains( $quoted, "\n" ) && mb_check_encoding( $quoted, 'UTF-8' ) ) {
			return $quoted;
		}

		$result = '';
		$length = strlen( $quoted );
		$offset = 0;
		while ( $offset < $length ) {
			$character = $quoted[ $offset ];
			if ( '"' === $character || "\n" === $character ) {
				throw new FormatException( 'Node name is not a valid quoted string.' );
			}
			if ( '\\' !== $character ) {
				$width = self::sequence_width( $quoted, $offset );
				if ( 0 === $width ) {
					// Go decodes a stray byte as utf8.RuneError and appends U+FFFD.
					$result .= "\u{FFFD}";
					++$offset;
					continue;
				}
				$result .= substr( $quoted, $offset, $width );
				$offset += $width;
				continue;
			}

			if ( $offset + 1 >= $length ) {
				throw new FormatException( 'Node name ends with an incomplete escape.' );
			}
			$escape  = $quoted[ $offset + 1 ];
			$offset += 2;
			switch ( $escape ) {
				case 'a':
					$result .= "\x07";
					break;
				case 'b':
					$result .= "\x08";
					break;
				case 'f':
					$result .= "\f";
					break;
				case 'n':
					$result .= "\n";
					break;
				case 'r':
					$result .= "\r";
					break;
				case 't':
					$result .= "\t";
					break;
				case 'v':
					$result .= "\x0B";
					break;
				case '\\':
				case '"':
					$result .= $escape;
					break;
				case 'x':
					$result .= chr( self::parse_hex( $quoted, $offset, 2 ) );
					$offset += 2;
					break;
				case 'u':
				case 'U':
					$digits    = 'u' === $escape ? 4 : 8;
					$codepoint = self::parse_hex( $quoted, $offset, $digits );
					$offset   += $digits;
					if ( $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
						throw new FormatException( 'Node name contains an invalid Unicode escape.' );
					}
					$result .= mb_chr( $codepoint, 'UTF-8' );
					break;
				default:
					if ( $escape >= '0' && $escape <= '7' ) {
						$octal = substr( $quoted, $offset - 1, 3 );
						if ( 1 !== preg_match( '/^[0-7]{3}$/', $octal ) || octdec( $octal ) > 255 ) {
							throw new FormatException( 'Node name contains an invalid octal escape.' );
						}
						$result .= chr( (int) octdec( $octal ) );
						$offset += 2;
						break;
					}
					throw new FormatException( 'Node name contains an unknown escape.' );
			}
		}
		return $result;
	}

	/**
	 * Escapes one valid UTF-8 encoded rune.
	 *
	 * @param string $character One rune.
	 * @return string Escaped form.
	 */
	private static function escape_rune( string $character ): string {
		if ( '"' === $character || '\\' === $character ) {
			return '\\' . $character;
		}

		$codepoint = mb_ord( $character, 'UTF-8' );
		if ( self::is_print( $codepoint, $character ) ) {
			return $character;
		}

		switch ( $codepoint ) {
			case 0x07:
				return '\\a';
			case 0x08:
				return '\\b';
			case 0x0C:
				return '\\f';
			case 0x0A:
				return '\\n';
			case 0x0D:
				return '\\r';
			case 0x09:
				return '\\t';
			case 0x0B:
				return '\\v';
		}

		if ( $codepoint < 0x20 || 0x7F === $codepoint ) {
			return self::hex_escape( $codepoint );
		}
		if ( $codepoint < 0x10000 ) {
			return sprintf( '\\u%04x', $codepoint );
		}
		return sprintf( '\\U%08x', $codepoint );
	}

	/**
	 * Mirrors strconv.IsPrint().
	 *
	 * @param int    $codepoint Unicode code point.
	 * @param string $character The same rune as UTF-8.
	 * @return bool Whether Go prints the rune unescaped.
	 */
	private static function is_print( int $codepoint, string $character ): bool {
		if ( $codepoint <= 0xFF ) {
			if ( $codepoint >= 0x20 && $codepoint <= 0x7E ) {
				return true;
			}
			return $codepoint >= 0xA1 && 0xAD !== $codepoint;
		}
		return 1 === preg_match( '/^[\p{L}\p{M}\p{N}\p{P}\p{S}]$/u', $character );
	}

	/**
	 * Returns the width of the well-formed UTF-8 sequence at an offset, or 0 if invalid.
	 *
	 * @param string $bytes  Input.
	 * @param int    $offset Position.
	 * @return int Width in bytes (1-4) or 0.
	 */
	private static function sequence_width( string $bytes, int $offset ): int {
		$first = ord( $bytes[ $offset ] );
		if ( $first < 0x80 ) {
			return 1;
		}

		if ( $first >= 0xC2 && $first <= 0xDF ) {
			$pattern = '/\G[\xC2-\xDF][\x80-\xBF]/';
		} elseif ( $first >= 0xE0 && $first <= 0xEF ) {
			$pattern = '/\G(?:\xE0[\xA0-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]|\xED[\x80-\x9F])[\x80-\xBF]/';
		} elseif ( $first >= 0xF0 && $first <= 0xF4 ) {
			$pattern = '/\G(?:\xF0[\x90-\xBF]|[\xF1-\xF3][\x80-\xBF]|\xF4[\x80-\x8F])[\x80-\xBF]{2}/';
		} else {
			return 0;
		}

		return 1 === preg_match( $pattern, $bytes, $match, 0, $offset ) ? strlen( $match[0] ) : 0;
	}

	/**
	 * Formats a byte as \xHH with lowercase hex digits.
	 *
	 * @param int $byte Byte value.
	 * @return string Escape sequence.
	 */
	private static function hex_escape( int $byte ): string {
		return '\\x' . self::HEX[ $byte >> 4 ] . self::HEX[ $byte & 0xF ];
	}

	/**
	 * Parses a fixed number of hex digits.
	 *
	 * @param string $input  Input.
	 * @param int    $offset Start position.
	 * @param int    $digits Number of digits.
	 * @return int Value.
	 * @throws FormatException If the digits are missing or not hex.
	 */
	private static function parse_hex( string $input, int $offset, int $digits ): int {
		$hex = substr( $input, $offset, $digits );
		if ( strlen( $hex ) !== $digits || strspn( $hex, '0123456789abcdefABCDEF' ) !== $digits ) {
			throw new FormatException( 'Node name contains an invalid hex escape.' );
		}
		return (int) hexdec( $hex );
	}
}
