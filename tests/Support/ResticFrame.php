<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * The zstd frame that restic writes for data that does not compress.
 *
 * restic compresses every data blob of a compressed repository with klauspost/compress,
 * without a checksum and with a window of 512 KiB. For a chunk of 8 MiB of random data,
 * restic 0.20.0 wrote this frame in the conformance run: the header 28 b5 2f fd 80 48, the
 * size in four bytes, and 64 raw blocks of 128 KiB. That is 202 bytes more than the chunk.
 */
final class ResticFrame {

	/**
	 * Builds the frame of content stored in raw blocks of 128 KiB.
	 *
	 * @param string $content Content, not empty.
	 * @return string Frame.
	 */
	public static function raw( string $content ): string {
		$frame  = "\x28\xb5\x2f\xfd\x80\x48" . pack( 'V', strlen( $content ) );
		$blocks = str_split( $content, 131072 );
		$last   = count( $blocks ) - 1;
		foreach ( $blocks as $number => $block ) {
			$frame .= substr( pack( 'V', strlen( $block ) << 3 | ( $last === $number ? 1 : 0 ) ), 0, 3 ) . $block;
		}
		return $frame;
	}
}
