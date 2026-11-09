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

		$year   = (int) $parts[1];
		$month  = (int) $parts[2];
		$day    = (int) $parts[3];
		$hour   = (int) $parts[4];
		$minute = (int) $parts[5];
		$second = (int) $parts[6];
		if ( $month < 1 || $month > 12 || $day < 1 || $day > self::days_in_month( $year, $month ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			throw new FormatException( 'Timestamp is out of range.' );
		}

		$offset = 0;
		if ( 'Z' !== $parts[8] ) {
			$hours   = (int) substr( $parts[8], 1, 2 );
			$minutes = (int) substr( $parts[8], 4, 2 );
			if ( $hours > 23 || $minutes > 59 ) {
				throw new FormatException( 'Timestamp is out of range.' );
			}
			$offset = ( '-' === $parts[8][0] ? -1 : 1 ) * ( $hours * 3600 + $minutes * 60 );
		}

		$seconds  = self::days_from_civil( $year, $month, $day ) * 86400 + $hour * 3600 + $minute * 60 + $second;
		$fraction = '' === $parts[7] ? 0 : (int) str_pad( $parts[7], 9, '0' );
		return [ $seconds - $offset, $fraction ];
	}

	/**
	 * Days since 1970-01-01 in the proleptic Gregorian calendar, for every year from 0 to 9999.
	 * gmmktime() cannot serve: it reads the years 0 to 100 as two-digit years, so Go's zero
	 * time 0001-01-01 became 2001-01-01.
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1-12.
	 * @param int $day   Day of the month.
	 * @return int Days; negative before 1970.
	 */
	private static function days_from_civil( int $year, int $month, int $day ): int {
		$year -= $month <= 2 ? 1 : 0;
		$era   = intdiv( $year >= 0 ? $year : $year - 399, 400 );
		$years = $year - $era * 400;
		$days  = intdiv( 153 * ( $month > 2 ? $month - 3 : $month + 9 ) + 2, 5 ) + $day - 1;
		return $era * 146097 + $years * 365 + intdiv( $years, 4 ) - intdiv( $years, 100 ) + $days - 719468;
	}

	/**
	 * Number of days in a month of the proleptic Gregorian calendar.
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1-12.
	 * @return int Days.
	 */
	private static function days_in_month( int $year, int $month ): int {
		if ( 2 === $month ) {
			return ( 0 === $year % 4 && 0 !== $year % 100 ) || 0 === $year % 400 ? 29 : 28;
		}
		return in_array( $month, [ 4, 6, 9, 11 ], true ) ? 30 : 31;
	}
}
