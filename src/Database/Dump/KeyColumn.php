<?php
/**
 * One column of the key that orders a table's pages.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * How the dump reads the value of a key column for its cursor, and how it writes that value
 * back into the condition of the next page, so that the condition orders exactly as ORDER BY
 * does (ADR 0021):
 *
 * - integers, YEAR and DECIMAL compare as the exact numbers the server sends, unsigned
 *   64-bit values above PHP_INT_MAX included, because they stay strings;
 * - FLOAT and DOUBLE are read as `col + 0e0`, the exact double, because the server prints a
 *   FLOAT with fewer digits than it has;
 * - BIT, ENUM and SET are read as `col + 0` and compared with that number: ENUM and SET sort
 *   by their index and bits, not by their names, and a number compares the same way;
 * - dates, times and MariaDB's UUID and INET types compare as a quoted literal of their text
 *   form, which the server converts to the column's type; TIMESTAMP values are UTC because
 *   the dump's session uses +00:00;
 * - CHAR and VARCHAR are read as HEX(col), the stored bytes, and compared as a literal of the
 *   column's own character set and collation, so that case, accents and trailing spaces
 *   compare as in the index and no conversion can change a byte;
 * - BINARY and VARBINARY are read as HEX(col) and compared byte by byte.
 *
 * TEXT, BLOB, JSON and spatial columns cannot order pages: an index holds only a prefix of
 * them, and a sort compares only a prefix as well. A key with such a column is not used.
 */
final readonly class KeyColumn {

	public const string INTEGER = 'integer';
	public const string DECIMAL = 'decimal';
	public const string FLOAT   = 'float';
	public const string ORDINAL = 'ordinal';
	public const string QUOTED  = 'quoted';
	public const string STRING  = 'string';
	public const string BINARY  = 'binary';

	/**
	 * Describes the column.
	 *
	 * @param string $name      Column name.
	 * @param string $kind      One of the kind constants.
	 * @param string $collation Collation of a STRING column, empty otherwise.
	 */
	public function __construct( public string $name, public string $kind, public string $collation = '' ) {
	}

	/**
	 * The key column for a column from SHOW FULL COLUMNS, if its type can order pages.
	 *
	 * @param string      $name      Column name.
	 * @param string      $type      Column type, for example "bigint unsigned" or "varchar(191)".
	 * @param string|null $collation Collation, for character columns.
	 * @return self|null Key column, or null for a type that cannot order pages exactly.
	 */
	public static function of( string $name, string $type, ?string $collation ): ?self {
		$kinds = [
			self::INTEGER => '/\A(?:tinyint|smallint|mediumint|int|integer|bigint|year)\b/i',
			self::DECIMAL => '/\A(?:decimal|numeric|dec|fixed)\b/i',
			self::FLOAT   => '/\A(?:float|double|real)\b/i',
			self::ORDINAL => '/\A(?:bit|enum|set)\b/i',
			self::QUOTED  => '/\A(?:date|datetime|timestamp|time|uuid|inet4|inet6)\b/i',
			self::BINARY  => '/\A(?:binary|varbinary)\b/i',
			self::STRING  => '/\A(?:char|varchar)\b/i',
		];
		foreach ( $kinds as $kind => $pattern ) {
			if ( 1 === preg_match( $pattern, $type ) ) {
				if ( self::STRING !== $kind ) {
					return new self( $name, $kind );
				}
				return null !== $collation && 1 === preg_match( '/\A[a-z0-9]+_[a-z0-9_]+\z/i', $collation ) ? new self( $name, $kind, $collation ) : null;
			}
		}
		return null;
	}

	/**
	 * The expression that reads the column's cursor value.
	 *
	 * @return string SQL expression.
	 */
	public function value(): string {
		$column = SqlFormatter::identifier( $this->name );
		return match ( $this->kind ) {
			self::FLOAT                => $column . ' + 0e0',
			self::ORDINAL              => $column . ' + 0',
			self::STRING, self::BINARY => 'HEX(' . $column . ')',
			default                    => $column,
		};
	}

	/**
	 * Whether the cursor value needs an expression of its own in the page query.
	 *
	 * @return bool True if value() is not the column itself.
	 */
	public function computed(): bool {
		return in_array( $this->kind, [ self::FLOAT, self::ORDINAL, self::STRING, self::BINARY ], true );
	}

	/**
	 * Writes a cursor value into SQL after checking its form, so that a changed column type
	 * or a damaged checkpoint can never put anything else into a query.
	 *
	 * @param mixed $value Value read with value(), or from a checkpoint.
	 * @return string SQL literal.
	 * @throws DatabaseFailed If the value does not have the form of its kind.
	 */
	public function literal( mixed $value ): string {
		$patterns = [
			self::INTEGER => '/\A-?[0-9]{1,20}\z/',
			self::DECIMAL => '/\A-?[0-9]{1,65}(?:\.[0-9]{1,30})?\z/',
			self::FLOAT   => '/\A-?(?:[0-9]{1,40}(?:\.[0-9]{0,40})?|\.[0-9]{1,40})(?:[eE][-+]?[0-9]{1,3})?\z/',
			self::ORDINAL => '/\A[0-9]{1,20}\z/',
			self::QUOTED  => '/\A(?=.)-?[0-9A-Za-z]{0,12}(?:[-:. ][0-9A-Za-z]{0,12}){0,16}\z/',
			self::STRING  => '/\A(?:[0-9A-Fa-f]{2})*\z/',
			self::BINARY  => '/\A(?:[0-9A-Fa-f]{2})*\z/',
		];
		if ( ! is_string( $value ) || ! isset( $patterns[ $this->kind ] ) || 1 !== preg_match( $patterns[ $this->kind ], $value ) ) {
			DatabaseFailed::raise( 'A key value of the database dump does not have the form of its column.', [ 'column' => $this->name ] );
		}
		return match ( $this->kind ) {
			self::QUOTED => "'" . $value . "'",
			self::STRING => '_' . strstr( $this->collation, '_', true ) . " X'" . $value . "' COLLATE " . $this->collation,
			self::BINARY => "X'" . $value . "'",
			default      => $value,
		};
	}

	/**
	 * The condition for rows after a key: the row-value comparison (a, b, c) > (x, y, z),
	 * written as (a > x) OR (a = x AND b > y) OR (a = x AND b = y AND c > z). The server
	 * evaluates both alike, but reads ranges of the index only for the second form.
	 *
	 * @param list<self>  $key    Key columns in index order.
	 * @param list<mixed> $values Cursor values in the same order.
	 * @return string SQL condition.
	 * @throws DatabaseFailed If a value does not have the form of its column, or a value is missing.
	 */
	public static function after( array $key, array $values ): string {
		if ( [] === $key || count( $key ) !== count( $values ) ) {
			throw new DatabaseFailed( 'The database dump cursor does not match the key of its table.' );
		}
		$terms = [];
		$equal = [];
		foreach ( $key as $position => $column ) {
			$name    = SqlFormatter::identifier( $column->name );
			$literal = $column->literal( $values[ $position ] );
			$terms[] = '(' . implode( ' AND ', [ ...$equal, $name . ' > ' . $literal ] ) . ')';
			$equal[] = $name . ' = ' . $literal;
		}
		return implode( ' OR ', $terms );
	}
}
