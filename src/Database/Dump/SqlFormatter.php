<?php
/**
 * SQL text for the dump.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes identifiers and values so that every statement fits on one line and imports without
 * an SQL parser (ADR 0021). Binary values and text that is not valid UTF-8 become hex
 * literals, which is lossless whatever the connection's character set.
 */
final class SqlFormatter {

	public const string BINARY  = 'binary';
	public const string BIT     = 'bit';
	public const string FLOAT   = 'float';
	public const string NUMERIC = 'numeric';
	public const string TEXT    = 'text';

	private const string BINARY_TYPES  = '/\A(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i';
	private const string NUMERIC_TYPES = '/\A(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|dec|fixed|float|double|real)\b/i';

	/**
	 * Quotes an identifier.
	 *
	 * @param string $name Table or column name.
	 * @return string Quoted identifier.
	 */
	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Classifies a column type from SHOW COLUMNS.
	 *
	 * @param string $type Column type, for example "bigint(20) unsigned" or "longblob".
	 * @return string One of the kind constants.
	 */
	public static function kind( string $type ): string {
		if ( 1 === preg_match( self::BINARY_TYPES, $type ) ) {
			return self::BINARY;
		}
		if ( 1 === preg_match( '/\Abit\b/i', $type ) ) {
			return self::BIT;
		}
		// The server prints a FLOAT with about six digits, fewer than it holds; TableSource
		// reads it as the DOUBLE it converts to exactly, which also imports exactly.
		if ( 1 === preg_match( '/\Afloat\b/i', $type ) ) {
			return self::FLOAT;
		}
		return 1 === preg_match( self::NUMERIC_TYPES, $type ) ? self::NUMERIC : self::TEXT;
	}

	/**
	 * Formats a value.
	 *
	 * @param string|null $value Value as the server sent it.
	 * @param string      $kind  Column kind.
	 * @return string SQL literal.
	 */
	public static function value( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( self::BINARY === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( self::BIT === $kind ) {
			// mysqlnd sends BIT values as decimal numbers, other drivers as raw bytes.
			return '' !== $value && strspn( $value, '0123456789' ) === strlen( $value ) ? $value : '0x' . bin2hex( '' === $value ? "\0" : $value );
		}
		if ( ( self::NUMERIC === $kind || self::FLOAT === $kind ) && 1 === preg_match( '/\A-?\d+(\.\d+)?([eE][-+]?\d+)?\z/', $value ) ) {
			return $value;
		}
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return '0x' . bin2hex( $value );
		}
		return "'" . strtr(
			$value,
			[
				'\\'   => '\\\\',
				"'"    => "\\'",
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"\x1a" => '\\Z',
			]
		) . "'";
	}

	/**
	 * Formats a value as value() does, in pieces of at most twice $slice bytes plus quotes,
	 * so that a large value is never held twice as a whole: once as hex or escaped text and
	 * once more with its prefix or quotes.
	 *
	 * @param string|null $value Value as the server sent it.
	 * @param string      $kind  Column kind.
	 * @param int         $slice Bytes of the value per piece.
	 * @return \Generator<int, string> Pieces of the SQL literal.
	 */
	public static function pieces( ?string $value, string $kind, int $slice = 1048576 ): \Generator {
		$length = null === $value ? 0 : strlen( $value );
		if ( null === $value || $length <= $slice || self::BIT === $kind || ( ( self::NUMERIC === $kind || self::FLOAT === $kind ) && 1 === preg_match( '/\A-?\d+(\.\d+)?([eE][-+]?\d+)?\z/', $value ) ) ) {
			yield self::value( $value, $kind );
			return;
		}
		$hex = self::BINARY === $kind || ! mb_check_encoding( $value, 'UTF-8' );
		yield $hex ? '0x' : "'";
		for ( $offset = 0; $offset < $length; $offset += $slice ) {
			$part = substr( $value, $offset, $slice );
			// Each escape replaces a single byte, so a slice can end anywhere.
			yield $hex ? bin2hex( $part ) : strtr(
				$part,
				[
					'\\'   => '\\\\',
					"'"    => "\\'",
					"\0"   => '\\0',
					"\n"   => '\\n',
					"\r"   => '\\r',
					"\x1a" => '\\Z',
				]
			);
		}
		if ( ! $hex ) {
			yield "'";
		}
	}

	/**
	 * Puts a statement from SHOW CREATE on one line. Line breaks there are only whitespace
	 * between clauses: the server escapes them inside string literals.
	 *
	 * The pattern names its bytes instead of using \s or \R: without the u modifier those
	 * also match 0x85 (and, under some locales, 0xA0), which are continuation bytes of
	 * UTF-8 characters such as "х" (D1 85), "内" (E5 86 85) or "Å" (C3 85). With the u
	 * modifier, a definition that is not valid UTF-8 would make preg_replace() fail.
	 *
	 * @param string $statement Statement.
	 * @return string One line.
	 */
	public static function one_line( string $statement ): string {
		return trim( (string) preg_replace( '/[ \t]*(?:(?:\r\n|\r|\n)[ \t]*)+/', ' ', $statement ) );
	}
}
