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
	 * @param string   $data Compressed bytes.
	 * @param int|null $expected Content length supplied by the pack entry, if present.
	 * @return string Decompressed bytes.
	 * @throws UnsupportedRepository If zstd is unavailable.
	 * @throws FormatException If the data is not valid zstd or too large.
	 */
	public static function decompress( string $data, ?int $expected = null ): string {
		if ( ! self::available() ) {
			throw new UnsupportedRepository( 'The repository contains compressed data and PHP lacks the zstd extension.' );
		}
		// ext-zstd keeps its decoder buffer, a growing output string and a return copy.
		// Leave room for allocation rounding, the window, and reporting a refusal.
		$memory = ini_parse_quantity( ini_get( 'memory_limit' ) );
		$limit  = self::MAX_DECOMPRESSED_SIZE;
		if ( $memory > 0 ) {
			$limit = min( $limit, intdiv( max( 0, $memory - memory_get_usage( true ) - 2097152 ), 4 ) );
		}
		if ( null !== $expected && $expected > $limit ) {
			FormatException::raise( 'The uncompressed blob exceeds the decompression limit.', [ 'code' => 'decompression_limit' ] );
		}
		$output = '';
		$start  = 0;
		$length = strlen( $data );
		do {
			$frame = ZstdFrame::read( $data, $start, $limit - strlen( $output ) );
			if ( ! $frame->skip ) {
				if ( null !== $expected && null !== $frame->size && $frame->size > $expected - strlen( $output ) ) {
					throw new FormatException( 'Decompressed blob has an unexpected length.' );
				}
				if ( ( 0 !== $start || $frame->end !== $length ) && $frame->end - $start > $limit ) {
					FormatException::raise( 'The compressed frame exceeds the available decoding memory.', [ 'code' => 'decompression_limit' ] );
				}
				$bytes = 0 === $start && $frame->end === $length ? $data : substr( $data, $start, $frame->end - $start );
				$part  = self::decode_frame( $bytes );
				if ( ( null !== $frame->size && $frame->size !== strlen( $part ) ) || strlen( $part ) > $limit - strlen( $output ) ) {
					throw new FormatException( 'The zstd frame has an unexpected content length.' );
				}
				$output .= $part;
				unset( $part, $bytes );
			}
			$start = $frame->end;
		} while ( $start < $length );
		if ( null !== $expected && $expected !== strlen( $output ) ) {
			throw new FormatException( 'Decompressed blob has an unexpected length.' );
		}
		return $output;
	}

	/**
	 * Converts native decoder warnings into the format error used by jobs and checks.
	 *
	 * @param string $frame A complete zstd frame.
	 * @return string Decoded bytes.
	 * @throws FormatException If the native decoder rejects the frame.
	 */
	private static function decode_frame( string $frame ): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The native decoder reports invalid input through E_WARNING.
		set_error_handler( static fn (): never => throw new FormatException( 'Compressed repository data is invalid.' ), E_WARNING );
		try {
			$result = call_user_func( 'zstd_uncompress', $frame );
		} finally {
			restore_error_handler();
		}
		if ( ! is_string( $result ) ) {
			throw new FormatException( 'Compressed repository data is invalid.' );
		}
		return $result;
	}
}
