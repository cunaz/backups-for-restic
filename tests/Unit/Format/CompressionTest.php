<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\ZstdFrame;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase {

	/** A raw last block holding "books", in a streamed frame with a 1 KiB window. */
	private const string STREAMED = "\x28\xb5\x2f\xfd\x00\x00\x29\x00\x00books";

	public function test_streamed_and_concatenated_frames_include_every_byte(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$padding = "\x50\x2a\x4d\x18" . pack( 'V', 3 ) . 'pad';
		self::assertSame( 'booksbooks', Compression::decompress( self::STREAMED . $padding . self::STREAMED, 10 ) );
		self::assertSame( '', Compression::decompress( $padding ) );
	}

	public function test_frame_size_and_window_are_checked_before_decoding(): void {
		$frame = "\x28\xb5\x2f\xfd\x20\x05\x29\x00\x00books";
		self::assertSame( 5, ZstdFrame::read( $frame, 0, 5 )->size );
		foreach ( [ $frame, self::STREAMED ] as $encoded ) {
			try {
				ZstdFrame::read( $encoded, 0, 4 );
				self::fail( 'The frame exceeds the four-byte budget.' );
			} catch ( FormatException $error ) {
				self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
			}
		}
	}

	public function test_a_truncated_frame_never_returns_partial_plaintext(): void {
		$this->expectException( FormatException::class );
		ZstdFrame::read( substr( self::STREAMED, 0, -1 ), 0, 1024 );
	}

	public function test_a_native_checksum_warning_is_reported_as_a_format_error(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$frame = "\x28\xb5\x2f\xfd\x24\x05\x29\x00\x00books";
		$this->expectException( FormatException::class );
		Compression::decompress( $frame . "\x00\x00\x00\x00" );
	}

	public function test_the_pack_length_is_checked_even_for_an_unsized_frame(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$this->expectException( FormatException::class );
		Compression::decompress( self::STREAMED, 10 );
	}
}
