<?php
/**
 * Timestamps in Go's RFC 3339 format with nanoseconds.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Formats and parses timestamps the way Go's time.Time marshals them (RFC3339Nano).
 *
 * The plugin writes UTC ("Z") because WordPress runs PHP in UTC. Timestamps written by restic
 * may carry any offset and nine fractional digits; they are parsed into seconds plus
 * nanoseconds without losing precision.
 */
final class Rfc3339 {

	/**
	 * Formats a Unix timestamp.
	 *
	 * @param int $seconds     Seconds since the epoch.
	 * @param int $nanoseconds Fraction of the second (0-999999999).
	 * @return string For example 2026-11-09T14:30:12.5Z.
	 */
	public static function format( int $seconds, int $nanoseconds = 0 ): string {
		$text = gmdate( 'Y-m-d\TH:i:s', $seconds );
		if ( $nanoseconds > 0 ) {
			$text .= '.' . rtrim( sprintf( '%09d', $nanoseconds ), '0' );
		}
		return $text . 'Z';
	}

	/**
	 * Parses a timestamp.
	 *
	 * @param string $text Timestamp.
	 * @return array{0: int, 1: int} Seconds since the epoch and nanoseconds.
	 * @throws FormatException If the text is not an RFC 3339 timestamp.
	 */
	public static function parse( string $text ): array {
		$pattern = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/';
		if ( 1 !== preg_match( $pattern, $text, $parts ) ) {
			throw new FormatException( 'Timestamp is not in RFC 3339 format.' );
		}

		$offset = 0;
		if ( 'Z' !== $parts[8] ) {
			$sign   = '-' === $parts[8][0] ? -1 : 1;
			$offset = $sign * ( (int) substr( $parts[8], 1, 2 ) * 3600 + (int) substr( $parts[8], 4, 2 ) * 60 );
		}

		$seconds = gmmktime( (int) $parts[4], (int) $parts[5], (int) $parts[6], (int) $parts[2], (int) $parts[3], (int) $parts[1] );
		if ( false === $seconds ) {
			throw new FormatException( 'Timestamp is out of range.' );
		}

		$fraction = '' === $parts[7] ? 0 : (int) str_pad( $parts[7], 9, '0' );
		return [ $seconds - $offset, $fraction ];
	}
}
