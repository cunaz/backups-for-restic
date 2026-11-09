<?php
/**
 * Poly1305 one-time authenticator.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

use BackupsForRestic\Exception\CryptoException;

defined( 'ABSPATH' ) || exit;

/**
 * Poly1305 (RFC 8439) in pure PHP.
 *
 * PHP's sodium extension does not expose libsodium's crypto_onetimeauth(), and OpenSSL's
 * Poly1305 is not reachable from PHP, so restic's MAC has to be computed here. The algorithm
 * follows poly1305-donna with five 26-bit limbs: every product fits into PHP's 64-bit
 * integers, there are no secret-dependent branches or table lookups, and the final reduction
 * selects with a mask. Correctness is pinned by RFC 8439 vectors and by 400 vectors generated
 * with libsodium (tests/Fixtures/vectors/poly1305-libsodium.json).
 *
 * The message is processed in 4 KiB slices so that unpack() runs once per slice instead of
 * once per 16-byte block, which roughly doubles throughput.
 */
final class Poly1305 {

	public const int KEY_LENGTH = 32;
	public const int TAG_LENGTH = 16;

	private const int SLICE = 4096;
	private const int MASK  = 0x3ffffff;

	/**
	 * Computes the tag of a message.
	 *
	 * @param string $message Message (for restic: the ciphertext).
	 * @param string $key     One-time key: r (16 bytes) || s (16 bytes).
	 * @return string 16-byte tag.
	 * @throws CryptoException If the key has the wrong length.
	 */
	public static function mac( string $message, #[\SensitiveParameter] string $key ): string {
		if ( strlen( $key ) !== self::KEY_LENGTH ) {
			throw new CryptoException( 'Poly1305 key must be 32 bytes long.' );
		}

		$words = unpack( 'V8', $key );
		if ( ! is_array( $words ) ) {
			throw new CryptoException( 'Poly1305 key cannot be read.' );
		}
		[ 1 => $k0, 2 => $k1, 3 => $k2, 4 => $k3, 5 => $s0, 6 => $s1, 7 => $s2, 8 => $s3 ] = $words;

		// Clamp r and split it into 26-bit limbs.
		$r0 = $k0 & 0x3ffffff;
		$r1 = ( ( $k0 >> 26 ) | ( $k1 << 6 ) ) & 0x3ffff03;
		$r2 = ( ( $k1 >> 20 ) | ( $k2 << 12 ) ) & 0x3ffc0ff;
		$r3 = ( ( $k2 >> 14 ) | ( $k3 << 18 ) ) & 0x3f03fff;
		$r4 = ( $k3 >> 8 ) & 0x00fffff;

		// Precomputed 5 * r for the reduction modulo 2^130 - 5.
		$t1 = $r1 * 5;
		$t2 = $r2 * 5;
		$t3 = $r3 * 5;
		$t4 = $r4 * 5;

		$h0 = 0;
		$h1 = 0;
		$h2 = 0;
		$h3 = 0;
		$h4 = 0;

		$length = strlen( $message );
		$full   = $length - ( $length % 16 );
		$offset = 0;

		while ( $offset < $length ) {
			if ( $offset < $full ) {
				$take  = min( self::SLICE, $full - $offset );
				$block = unpack( 'V*', substr( $message, $offset, $take ) );
				$high  = 1 << 24;
			} else {
				// Last partial block: append 0x01, pad with zeros, no implicit high bit.
				$take  = 16;
				$block = unpack( 'V*', str_pad( substr( $message, $offset ) . "\x01", 16, "\0" ) );
				$high  = 0;
			}
			if ( ! is_array( $block ) ) {
				throw new CryptoException( 'Poly1305 input cannot be read.' );
			}

			$count = $take >> 2;
			for ( $i = 1; $i <= $count; $i += 4 ) {
				$w0 = $block[ $i ];
				$w1 = $block[ $i + 1 ];
				$w2 = $block[ $i + 2 ];
				$w3 = $block[ $i + 3 ];

				$h0 += $w0 & self::MASK;
				$h1 += ( ( $w0 >> 26 ) | ( $w1 << 6 ) ) & self::MASK;
				$h2 += ( ( $w1 >> 20 ) | ( $w2 << 12 ) ) & self::MASK;
				$h3 += ( ( $w2 >> 14 ) | ( $w3 << 18 ) ) & self::MASK;
				$h4 += ( $w3 >> 8 ) | $high;

				$d0 = $h0 * $r0 + $h1 * $t4 + $h2 * $t3 + $h3 * $t2 + $h4 * $t1;
				$d1 = $h0 * $r1 + $h1 * $r0 + $h2 * $t4 + $h3 * $t3 + $h4 * $t2;
				$d2 = $h0 * $r2 + $h1 * $r1 + $h2 * $r0 + $h3 * $t4 + $h4 * $t3;
				$d3 = $h0 * $r3 + $h1 * $r2 + $h2 * $r1 + $h3 * $r0 + $h4 * $t4;
				$d4 = $h0 * $r4 + $h1 * $r3 + $h2 * $r2 + $h3 * $r1 + $h4 * $r0;

				$carry = $d0 >> 26;
				$h0    = $d0 & self::MASK;
				$d1   += $carry;
				$carry = $d1 >> 26;
				$h1    = $d1 & self::MASK;
				$d2   += $carry;
				$carry = $d2 >> 26;
				$h2    = $d2 & self::MASK;
				$d3   += $carry;
				$carry = $d3 >> 26;
				$h3    = $d3 & self::MASK;
				$d4   += $carry;
				$carry = $d4 >> 26;
				$h4    = $d4 & self::MASK;
				$h0   += $carry * 5;
				$carry = $h0 >> 26;
				$h0   &= self::MASK;
				$h1   += $carry;
			}
			$offset += $take;
		}

		// Fully carry h.
		$carry = $h1 >> 26;
		$h1   &= self::MASK;
		$h2   += $carry;
		$carry = $h2 >> 26;
		$h2   &= self::MASK;
		$h3   += $carry;
		$carry = $h3 >> 26;
		$h3   &= self::MASK;
		$h4   += $carry;
		$carry = $h4 >> 26;
		$h4   &= self::MASK;
		$h0   += $carry * 5;
		$carry = $h0 >> 26;
		$h0   &= self::MASK;
		$h1   += $carry;

		// Compute h - p and keep it if it is not negative, selecting with a mask.
		$g0    = $h0 + 5;
		$carry = $g0 >> 26;
		$g0   &= self::MASK;
		$g1    = $h1 + $carry;
		$carry = $g1 >> 26;
		$g1   &= self::MASK;
		$g2    = $h2 + $carry;
		$carry = $g2 >> 26;
		$g2   &= self::MASK;
		$g3    = $h3 + $carry;
		$carry = $g3 >> 26;
		$g3   &= self::MASK;
		$g4    = $h4 + $carry - ( 1 << 26 );

		// All ones if h >= p (g4 not negative), all zeros otherwise; PHP shifts arithmetically.
		$select = ~( $g4 >> 63 );
		$h0     = ( $h0 & ~$select ) | ( $g0 & $select );
		$h1     = ( $h1 & ~$select ) | ( $g1 & $select );
		$h2     = ( $h2 & ~$select ) | ( $g2 & $select );
		$h3     = ( $h3 & ~$select ) | ( $g3 & $select );
		$h4     = ( $h4 & ~$select ) | ( $g4 & $select );

		// h mod 2^128, then add s.
		$h0 = ( $h0 | ( $h1 << 26 ) ) & 0xffffffff;
		$h1 = ( ( $h1 >> 6 ) | ( $h2 << 20 ) ) & 0xffffffff;
		$h2 = ( ( $h2 >> 12 ) | ( $h3 << 14 ) ) & 0xffffffff;
		$h3 = ( ( $h3 >> 18 ) | ( $h4 << 8 ) ) & 0xffffffff;

		$sum = $h0 + $s0;
		$h0  = $sum & 0xffffffff;
		$sum = $h1 + $s1 + ( $sum >> 32 );
		$h1  = $sum & 0xffffffff;
		$sum = $h2 + $s2 + ( $sum >> 32 );
		$h2  = $sum & 0xffffffff;
		$sum = $h3 + $s3 + ( $sum >> 32 );
		$h3  = $sum & 0xffffffff;

		return pack( 'V4', $h0, $h1, $h2, $h3 );
	}

	/**
	 * Verifies a tag in constant time.
	 *
	 * @param string $tag     Expected tag.
	 * @param string $message Message.
	 * @param string $key     One-time key.
	 * @return bool True if the tag matches.
	 */
	public static function verify( string $tag, string $message, #[\SensitiveParameter] string $key ): bool {
		return strlen( $tag ) === self::TAG_LENGTH && hash_equals( self::mac( $message, $key ), $tag );
	}
}
