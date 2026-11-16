<?php
/**
 * The records of a zip archive whose entries are stored without compression.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage\Zip;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Builds local headers, central directory records and the end records, and decides which
 * names an archive may hold.
 *
 * Zip64 fields are written where a size, an offset or the number of entries does not fit
 * the classic field, and only there, so that small archives stay readable by every program.
 * The two limits are constructor arguments because a test cannot write four gibibytes.
 *
 * Names are stored as UTF-8 with the language encoding flag. A name is refused, never
 * changed, when it is not valid UTF-8, when it is absolute or starts with a drive letter,
 * when a component is empty, "." or "..", or when it holds a NUL or a backslash: programs on
 * Windows read a backslash as a separator, so such a name could leave the directory an
 * archive is unpacked to. Times are stored twice: as an MS-DOS time in UTC, which every
 * reader knows, and as a Unix time in an extended timestamp field, which is exact.
 */
final class ZipRecords {

	/**
	 * From this value on, a size or an offset needs a zip64 field.
	 */
	public const int LARGE = 0xFFFFFFFF;

	/**
	 * From this number of entries on, an archive needs the zip64 end records.
	 */
	public const int MANY = 0xFFFF;

	/**
	 * Where the CRC-32 stands in a local header, so that the writer can fill it in later.
	 */
	public const int CRC_OFFSET = 14;

	/**
	 * Longest name the 16-bit length field of an entry can describe.
	 */
	private const int NAME_LIMIT = 0xFFFF;

	/**
	 * General purpose flag 11: the name is UTF-8.
	 */
	private const int UTF8 = 0x0800;

	/**
	 * Sets the limits from which zip64 fields are written.
	 *
	 * @param int $large Smallest size or offset that needs a zip64 field.
	 * @param int $many  Smallest number of entries that needs the zip64 end records.
	 */
	public function __construct( private readonly int $large = self::LARGE, private readonly int $many = self::MANY ) {
	}

	/**
	 * Returns the name of an entry for a path below the archived directory.
	 *
	 * @param string $relative  Path with forward slashes, relative to the archived directory.
	 * @param bool   $directory Whether the entry is a directory, which ends with a slash.
	 * @return string Name as it is stored.
	 * @throws InvalidArgument If the name cannot be stored safely.
	 */
	public static function name( string $relative, bool $directory ): string {
		$safe = mb_check_encoding( $relative, 'UTF-8' ) && 1 !== preg_match( '/\A[A-Za-z]:/', $relative ) && strlen( $relative ) < self::NAME_LIMIT;
		foreach ( explode( '/', $relative ) as $part ) {
			$safe = $safe && '' !== $part && '.' !== $part && '..' !== $part && ! str_contains( $part, "\0" ) && ! str_contains( $part, '\\' );
		}
		if ( ! $safe ) {
			InvalidArgument::raise( 'A file name cannot be stored in a zip archive.', [ 'name' => substr( rawurlencode( $relative ), 0, 300 ) ] );
		}
		return $directory ? $relative . '/' : $relative;
	}

	/**
	 * Builds the header that stands before the content of an entry.
	 *
	 * @param string $name  Name from name().
	 * @param int    $size  Size of the content in bytes.
	 * @param int    $mtime Modification time as Unix time.
	 * @param int    $crc   CRC-32 of the content; zero while it is not known.
	 * @return string Local file header.
	 */
	public function local( string $name, int $size, int $mtime, int $crc ): string {
		$field   = $size;
		$version = 20;
		$extra   = '';
		if ( $size >= $this->large ) {
			// A local header carries both sizes or none.
			$extra   = pack( 'vvPP', 1, 16, $size, $size );
			$field   = self::LARGE;
			$version = 45;
		}
		$extra          .= self::timestamp( $mtime );
		[ $date, $time ] = self::dos_time( $mtime );
		return pack( 'VvvvvvVVVvv', 0x04034b50, $version, self::UTF8, 0, $time, $date, $crc, $field, $field, strlen( $name ), strlen( $extra ) ) . $name . $extra;
	}

	/**
	 * Builds the record of an entry in the central directory.
	 *
	 * @param string $name      Name from name().
	 * @param int    $size      Size of the content in bytes.
	 * @param int    $mtime     Modification time as Unix time.
	 * @param int    $crc       CRC-32 of the content.
	 * @param int    $offset    Where the local header of the entry starts.
	 * @param bool   $directory Whether the entry is a directory.
	 * @return string Central directory record.
	 */
	public function central( string $name, int $size, int $mtime, int $crc, int $offset, bool $directory ): string {
		$size_field   = $size;
		$offset_field = $offset;
		$wide         = '';
		if ( $size >= $this->large ) {
			$wide      .= pack( 'PP', $size, $size );
			$size_field = self::LARGE;
		}
		if ( $offset >= $this->large ) {
			$wide        .= pack( 'P', $offset );
			$offset_field = self::LARGE;
		}
		$version         = '' === $wide ? 20 : 45;
		$extra           = ( '' === $wide ? '' : pack( 'vv', 1, strlen( $wide ) ) . $wide ) . self::timestamp( $mtime );
		[ $date, $time ] = self::dos_time( $mtime );
		return pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, $version, $version, self::UTF8, 0, $time, $date, $crc, $size_field, $size_field, strlen( $name ), strlen( $extra ), 0, 0, 0, $directory ? 0x10 : 0, $offset_field ) . $name . $extra;
	}

	/**
	 * Builds the records that end an archive.
	 *
	 * @param int $entries Number of entries.
	 * @param int $size    Size of the central directory in bytes.
	 * @param int $offset  Where the central directory starts.
	 * @return string The zip64 end record and its locator where needed, then the end record.
	 */
	public function end( int $entries, int $size, int $offset ): string {
		$many  = $entries >= $this->many;
		$long  = $size >= $this->large;
		$far   = $offset >= $this->large;
		$bytes = '';
		if ( $many || $long || $far ) {
			$bytes .= pack( 'VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $entries, $entries, $size, $offset );
			$bytes .= pack( 'VVPV', 0x07064b50, 0, $offset + $size, 1 );
		}
		$count = $many ? self::MANY : $entries;
		return $bytes . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $long ? self::LARGE : $size, $far ? self::LARGE : $offset, 0 );
	}

	/**
	 * Returns the extended timestamp field, which holds the exact time in UTC.
	 *
	 * @param int $mtime Modification time as Unix time.
	 * @return string Extra field; empty for a time the 32-bit field cannot hold.
	 */
	private static function timestamp( int $mtime ): string {
		return $mtime >= 0 && $mtime <= 0x7FFFFFFF ? pack( 'vvCV', 0x5455, 5, 1, $mtime ) : '';
	}

	/**
	 * Returns the MS-DOS date and time in UTC, which count from 1980 in steps of two seconds.
	 *
	 * @param int $mtime Modification time as Unix time.
	 * @return array{0: int, 1: int} Date and time fields.
	 */
	private static function dos_time( int $mtime ): array {
		$year = (int) gmdate( 'Y', $mtime );
		if ( $year < 1980 ) {
			return [ ( 1 << 5 ) | 1, 0 ];
		}
		if ( $year > 2107 ) {
			return [ ( 127 << 9 ) | ( 12 << 5 ) | 31, ( 23 << 11 ) | ( 59 << 5 ) | 29 ];
		}
		$date = ( ( $year - 1980 ) << 9 ) | ( (int) gmdate( 'n', $mtime ) << 5 ) | (int) gmdate( 'j', $mtime );
		$time = ( (int) gmdate( 'G', $mtime ) << 11 ) | ( (int) gmdate( 'i', $mtime ) << 5 ) | intdiv( (int) gmdate( 's', $mtime ), 2 );
		return [ $date, $time ];
	}
}
