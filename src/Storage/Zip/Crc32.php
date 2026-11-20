<?php
/**
 * CRC-32 of a file whose parts were hashed at different times.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage\Zip;

defined( 'ABSPATH' ) || exit;

/**
 * Joins the CRC-32 values of two neighbouring pieces into the value of both together.
 *
 * A zip entry needs the checksum of the whole file, and a large file is copied in several
 * requests. PHP cannot continue a CRC-32 from a number, so the writer keeps the checksum of
 * what is copied as an integer in its checkpoint and joins every new piece to it. The method
 * is the one zlib uses in crc32_combine(): the first value is carried over as many zero
 * bytes as the second piece is long, with matrices over GF(2), and the second is added.
 */
final class Crc32 {

	/**
	 * Operators that carry a checksum over 2^n zero bytes, built when first needed.
	 *
	 * @var list<list<int>>
	 */
	private static array $powers = [];

	/**
	 * Returns the CRC-32 of two pieces that follow each other.
	 *
	 * @param int $first  CRC-32 of the first piece.
	 * @param int $second CRC-32 of the second piece.
	 * @param int $length Length of the second piece in bytes.
	 * @return int CRC-32 of both pieces together.
	 */
	public static function combine( int $first, int $second, int $length ): int {
		$power = 0;
		while ( $length > 0 ) {
			if ( 1 === ( $length & 1 ) ) {
				$first = self::times( self::power( $power ), $first );
			}
			$length >>= 1;
			++$power;
		}
		return $first ^ $second;
	}

	/**
	 * Returns the operator for 2^n zero bytes.
	 *
	 * @param int $power The n.
	 * @return list<int> Matrix with 32 rows.
	 */
	private static function power( int $power ): array {
		if ( [] === self::$powers ) {
			// One zero bit: the reflected polynomial in the first row, a shift in the others.
			$bit = [ 0xEDB88320 ];
			for ( $row = 0; $row < 31; $row++ ) {
				$bit[] = 1 << $row;
			}
			self::$powers[] = self::square( self::square( self::square( $bit ) ) );
		}
		$known = count( self::$powers );
		while ( $known <= $power ) {
			self::$powers[] = self::square( self::$powers[ $known - 1 ] );
			++$known;
		}
		return self::$powers[ $power ];
	}

	/**
	 * Multiplies a matrix with a vector over GF(2).
	 *
	 * @param list<int> $matrix Matrix with 32 rows.
	 * @param int       $vector Vector of 32 bits.
	 * @return int Product.
	 */
	private static function times( array $matrix, int $vector ): int {
		$sum = 0;
		$row = 0;
		while ( 0 !== $vector ) {
			if ( 1 === ( $vector & 1 ) ) {
				$sum ^= $matrix[ $row ];
			}
			$vector >>= 1;
			++$row;
		}
		return $sum;
	}

	/**
	 * Multiplies a matrix with itself over GF(2).
	 *
	 * @param list<int> $matrix Matrix with 32 rows.
	 * @return list<int> Square.
	 */
	private static function square( array $matrix ): array {
		$square = [];
		foreach ( $matrix as $row ) {
			$square[] = self::times( $matrix, $row );
		}
		return $square;
	}
}
