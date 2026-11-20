<?php
/**
 * Allocation checks for RFC 8878 frames.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the frame envelope before ext-zstd allocates its window and output buffers.
 * A stream without a declared size is bounded by its block types and window size.
 */
final readonly class ZstdFrame {

	/**
	 * Describes one complete frame.
	 *
	 * @param int      $end Offset immediately after the frame.
	 * @param int|null $size Declared content size, when present.
	 * @param bool     $skip Whether this is a skippable frame.
	 */
	private function __construct( public int $end, public ?int $size, public bool $skip = false ) {
	}

	/**
	 * Validates the encoded lengths and the largest output the decoder can allocate.
	 *
	 * @param string $data Compressed stream.
	 * @param int    $start Offset of this frame.
	 * @param int    $capacity Remaining output capacity.
	 * @return self Frame boundaries.
	 * @throws FormatException If a length, window or frame is invalid.
	 */
	public static function read( string $data, int $start, int $capacity ): self {
		$length = strlen( $data );
		if ( $start + 5 > $length ) {
			throw new FormatException( 'Compressed repository data has an incomplete header.' );
		}
		$magic = substr( $data, $start, 4 );
		if ( ( ord( $magic[0] ) & 0xf0 ) === 0x50 && "\x2a\x4d\x18" === substr( $magic, 1 ) ) {
			$cursor = $start + 4;
			$skip   = self::integer( $data, $cursor, 4, $length - $cursor - 4 );
			return new self( $cursor + $skip, 0, true );
		}
		$flags = ord( $data[ $start + 4 ] );
		if ( "\x28\xb5\x2f\xfd" !== $magic || 0 !== ( $flags & 8 ) ) {
			throw new FormatException( 'Compressed repository data has an invalid frame header.' );
		}
		$single = 0 !== ( $flags & 32 );
		$cursor = $start + 5;
		$window = 0;
		if ( ! $single ) {
			$descriptor = self::integer( $data, $cursor, 1, 255 );
			// No supported frame window exceeds 128 MiB. Check before a machine-sized shift.
			self::require_capacity( $descriptor >> 3, 17 );
			$base   = 1 << ( 10 + ( $descriptor >> 3 ) );
			$window = $base + intdiv( $base, 8 ) * ( $descriptor & 7 );
			self::require_capacity( $window, $capacity );
		}
		$cursor += [ 0, 1, 2, 4 ][ $flags & 3 ];
		$width   = [ $single ? 1 : 0, 2, 4, 8 ][ $flags >> 6 ];
		$size    = 0 === $width ? null : self::integer( $data, $cursor, $width, $capacity ) + ( 2 === $width ? 256 : 0 );
		if ( null !== $size ) {
			self::require_capacity( $size, $capacity );
		}
		// RFC 8878 section 3.1.1.2.2 limits each regenerated block to 128 KiB or the window.
		$block_limit = min( 131072, $single ? ( $size ?? 0 ) : $window );
		$bound       = 0;
		do {
			$header = self::integer( $data, $cursor, 3, 0xffffff );
			$type   = ( $header >> 1 ) & 3;
			$bytes  = $header >> 3;
			if ( 3 === $type || $bytes > $block_limit ) {
				throw new FormatException( 'Compressed repository data has an invalid block.' );
			}
			$bound  += 2 === $type ? $block_limit : $bytes;
			$cursor += 1 === $type ? 1 : $bytes;
			self::require_capacity( $bound, $capacity );
		} while ( 0 === ( $header & 1 ) );
		$cursor += 0 !== ( $flags & 4 ) ? 4 : 0;
		if ( $cursor > $length ) {
			throw new FormatException( 'Compressed repository data ends inside a frame.' );
		}
		return new self( $cursor, $size );
	}

	/**
	 * Reads an unsigned little-endian field, including 64-bit sizes larger than PHP_INT_MAX.
	 *
	 * @param string $data Compressed stream.
	 * @param int    $cursor Offset, advanced by the width.
	 * @param int    $width Encoded length.
	 * @param int    $limit Maximum allowed value.
	 * @return int Checked value.
	 * @throws FormatException If a field is truncated or exceeds the limit.
	 */
	private static function integer( string $data, int &$cursor, int $width, int $limit ): int {
		$cursor += $width;
		if ( $cursor > strlen( $data ) ) {
			throw new FormatException( 'Compressed repository data ends inside a header.' );
		}
		$value = 0;
		for ( $i = 1; $i <= $width; ++$i ) {
			$byte = ord( $data[ $cursor - $i ] );
			if ( $byte > $limit || $value > intdiv( $limit - $byte, 256 ) ) {
				FormatException::raise( 'Compressed repository data exceeds the decompression limit.', [ 'code' => 'decompression_limit' ] );
			}
			$value = $value * 256 + $byte;
		}
		return $value;
	}

	/**
	 * Requires a size within the limit before passing it to native code.
	 *
	 * @param int $bytes Size in bytes.
	 * @param int $limit Remaining capacity.
	 * @throws FormatException If the size is excessive.
	 */
	private static function require_capacity( int $bytes, int $limit ): void {
		if ( $bytes > $limit ) {
			FormatException::raise( 'Compressed repository data exceeds the decompression limit.', [ 'code' => 'decompression_limit' ] );
		}
	}
}
