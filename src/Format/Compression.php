<?php
/**
 * Optional zstd support.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\UnsupportedRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Decompresses zstd data when the PECL zstd extension is installed. Without it, repositories
 * that contain compressed data cannot be read (ADR 0019).
 */
final class Compression {

	/** Largest decompressed object accepted from the server (restic blobs are at most 8 MiB). */
	public const int MAX_DECOMPRESSED_SIZE = 134217728;

	/**
	 * Whether compressed repositories can be read on this server.
	 *
	 * @return bool True if the zstd extension is loaded.
	 */
	public static function available(): bool {
		return function_exists( 'zstd_uncompress' );
	}

	/**
	 * Decompresses zstd data.
	 *
	 * @param string $data Compressed bytes.
	 * @return string Decompressed bytes.
	 * @throws UnsupportedRepository If zstd is unavailable.
	 * @throws FormatException If the data is not valid zstd or too large.
	 */
	public static function decompress( string $data ): string {
		if ( ! self::available() ) {
			throw new UnsupportedRepository( 'The repository contains compressed data and PHP lacks the zstd extension.' );
		}
		$result = call_user_func( 'zstd_uncompress', $data );
		if ( ! is_string( $result ) || strlen( $result ) > self::MAX_DECOMPRESSED_SIZE ) {
			throw new FormatException( 'Compressed repository data is invalid.' );
		}
		return $result;
	}
}
