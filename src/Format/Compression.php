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
 *
 * Before a frame is decoded, the memory its decoding takes must fit into what PHP's memory
 * limit leaves. ext-zstd decodes a frame that declares its size in one pass into a buffer of
 * that size and copies the buffer into the string it returns: twice the size, and no more
 * (measured with ext-zstd 0.18.0 and libzstd 1.5.7, ADR 0019). A frame without a declared size
 * goes through a small buffer into a string that grows by reallocation, and libzstd allocates
 * its window outside PHP's limit; such a frame keeps a bound of four times the larger of its
 * window and the output its blocks allow.
 */
final class Compression {

	/** Largest decompressed object accepted from the server (restic blobs are at most 8 MiB). */
	public const int MAX_DECOMPRESSED_SIZE = 134217728;

	/** Memory that stays free beside a decoder, for allocation rounding and reporting a refusal. */
	public const int RESERVE = 2097152;

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
	 * @param string                     $data     Compressed bytes.
	 * @param int|null                   $expected Content length supplied by the pack entry, if present.
	 * @param (\Closure(int): void)|null $reserve  Told, before each frame is decoded, how many bytes its decoding takes beside the memory in use; may refuse.
	 * @return string Decompressed bytes.
	 * @throws UnsupportedRepository If zstd is unavailable.
	 * @throws FormatException If the data is not valid zstd or too large.
	 */
	public static function decompress( string $data, ?int $expected = null, ?\Closure $reserve = null ): string {
		if ( ! self::available() ) {
			throw new UnsupportedRepository( 'The repository contains compressed data and PHP lacks the zstd extension.' );
		}
		if ( null !== $expected && ( $expected > self::MAX_DECOMPRESSED_SIZE || $expected > intdiv( self::free_memory(), 2 ) ) ) {
			FormatException::raise( 'The uncompressed blob exceeds the decompression limit.', [ 'code' => 'decompression_limit' ] );
		}
		$output = '';
		$start  = 0;
		$length = strlen( $data );
		do {
			$frame = ZstdFrame::read( $data, $start, self::MAX_DECOMPRESSED_SIZE - strlen( $output ) );
			if ( ! $frame->skip ) {
				if ( null !== $expected && null !== $frame->size && $frame->size > $expected - strlen( $output ) ) {
					throw new FormatException( 'Decompressed blob has an unexpected length.' );
				}
				$whole  = 0 === $start && $frame->end === $length;
				$needed = self::needed( $frame, $whole ? 0 : $frame->end - $start, strlen( $output ) );
				if ( null !== $reserve ) {
					$reserve( $needed );
				}
				if ( $needed > self::free_memory() ) {
					FormatException::raise( 'The compressed frame exceeds the available decoding memory.', [ 'code' => 'decompression_limit' ] );
				}
				$bytes = $whole ? $data : substr( $data, $start, $frame->end - $start );
				$part  = self::decode_frame( $bytes );
				if ( ( null !== $frame->size && $frame->size !== strlen( $part ) ) || strlen( $part ) > $frame->bound ) {
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
	 * The bytes that decoding a frame takes beside the memory in use. The decoder takes twice
	 * a declared size, or four times the larger of the window and the block bound of a frame
	 * without one. A frame that is not the whole input is copied out of it first, and appending
	 * the output of a later frame to what came before can copy that once.
	 *
	 * @param ZstdFrame $frame   A frame that is not skippable.
	 * @param int       $copy    Bytes copied out of the input to decode it; 0 for the whole input.
	 * @param int       $decoded Bytes decoded from the frames before it.
	 * @return int Bytes.
	 */
	private static function needed( ZstdFrame $frame, int $copy, int $decoded ): int {
		$decoder = null === $frame->size ? 4 * max( $frame->bound, $frame->window ) : 2 * $frame->size;
		return $copy + $decoded + $decoder;
	}

	/**
	 * The bytes PHP can still allocate below its memory limit, less the reserve. PHP counts
	 * against its limit the memory it has reserved, so that is what is subtracted.
	 *
	 * @return int Bytes, negative when less than the reserve is left; PHP_INT_MAX if PHP does not limit memory.
	 */
	private static function free_memory(): int {
		$limit = ini_parse_quantity( ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}
		return $limit - memory_get_usage( true ) - self::RESERVE;
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
