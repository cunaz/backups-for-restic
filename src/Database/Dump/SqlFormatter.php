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
		if ( self::NUMERIC === $kind && 1 === preg_match( '/\A-?\d+(\.\d+)?([eE][-+]?\d+)?\z/', $value ) ) {
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
	 * Puts a statement from SHOW CREATE on one line. Line breaks there are only whitespace
	 * between clauses: the server escapes them inside string literals.
	 *
	 * @param string $statement Statement.
	 * @return string One line.
	 */
	public static function one_line( string $statement ): string {
		return trim( (string) preg_replace( '/\s*\R\s*/', ' ', $statement ) );
	}
}
