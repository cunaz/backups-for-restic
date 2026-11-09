<?php
/**
 * Polynomials over GF(2).
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Chunker;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\CryptoException;

defined( 'ABSPATH' ) || exit;

/**
 * Arithmetic on polynomials over GF(2), each stored as the bits of an integer, as in
 * restic/chunker's Pol type. Degrees stay below 64 - 8 so that PHP's signed integers never
 * overflow.
 */
final class Polynomial {

	/** Number of random candidates tried before giving up, as in restic. */
	private const int MAX_TRIES = 1000000;

	/**
	 * Returns the degree (index of the highest set bit), or -1 for zero.
	 *
	 * @param int $x Polynomial.
	 * @return int Degree.
	 */
	public static function degree( int $x ): int {
		if ( $x < 0 ) {
			return 63;
		}
		if ( 0 === $x ) {
			return -1;
		}
		$degree = 0;
		foreach ( [ 32, 16, 8, 4, 2, 1 ] as $shift ) {
			if ( 0 !== $x >> $shift ) {
				$x     >>= $shift;
				$degree += $shift;
			}
		}
		return $degree;
	}

	/**
	 * Returns x mod d.
	 *
	 * @param int $x Dividend.
	 * @param int $d Divisor (not zero).
	 * @return int Remainder.
	 */
	public static function mod( int $x, int $d ): int {
		$divisor_degree = self::degree( $d );
		$difference     = self::degree( $x ) - $divisor_degree;
		while ( $difference >= 0 ) {
			$x         ^= $d << $difference;
			$difference = self::degree( $x ) - $divisor_degree;
		}
		return $x;
	}

	/**
	 * Returns x * f mod g.
	 *
	 * @param int $x First factor.
	 * @param int $f Second factor.
	 * @param int $g Modulus.
	 * @return int Product modulo g.
	 */
	public static function mul_mod( int $x, int $f, int $g ): int {
		$result = 0;
		$a      = self::mod( $x, $g );
		while ( 0 !== $f ) {
			if ( 1 === ( $f & 1 ) ) {
				$result ^= $a;
			}
			$a   = self::mod( $a << 1, $g );
			$f >>= 1;
		}
		return $result;
	}

	/**
	 * Greatest common divisor.
	 *
	 * @param int $x First polynomial.
	 * @param int $f Second polynomial.
	 * @return int GCD.
	 */
	public static function gcd( int $x, int $f ): int {
		while ( 0 !== $f ) {
			if ( 0 === $x ) {
				return $f;
			}
			if ( self::degree( $x ) < self::degree( $f ) ) {
				[ $x, $f ] = [ $f, $x ];
			}
			[ $x, $f ] = [ $f, self::mod( $x, $f ) ];
		}
		return $x;
	}

	/**
	 * Ben-Or's irreducibility test, as in restic/chunker.
	 *
	 * @param int $x Polynomial.
	 * @return bool True if irreducible.
	 */
	public static function is_irreducible( int $x ): bool {
		$half = intdiv( self::degree( $x ), 2 );
		for ( $i = 1; $i <= $half; $i++ ) {
			if ( 1 !== self::gcd( $x, self::qp( $i, $x ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Picks a random irreducible polynomial of degree 53, like chunker.DerivePolynomial().
	 *
	 * @param RandomSource $random Source of 8-byte little-endian candidates.
	 * @return int Polynomial.
	 * @throws CryptoException If no irreducible polynomial is found.
	 */
	public static function random( RandomSource $random ): int {
		for ( $try = 0; $try < self::MAX_TRIES; $try++ ) {
			$words = unpack( 'P', $random->bytes( 8 ) );
			$value = is_array( $words ) ? $words[1] : 0;
			$value = ( $value & ( ( 1 << 54 ) - 1 ) ) | ( 1 << 53 ) | 1;
			if ( self::is_irreducible( $value ) ) {
				return $value;
			}
		}
		throw new CryptoException( 'No irreducible polynomial found.' );
	}

	/**
	 * Computes (x^(2^p) - x) mod g.
	 *
	 * @param int $p Exponent.
	 * @param int $g Modulus.
	 * @return int Result.
	 */
	private static function qp( int $p, int $g ): int {
		$result = 2;
		for ( $i = 0; $i < $p; $i++ ) {
			$result = self::mul_mod( $result, $result, $g );
		}
		return self::mod( $result ^ 2, $g );
	}
}
