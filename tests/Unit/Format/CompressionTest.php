<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\ZstdFrame;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/** @return iterable<string, array{string, int}> */
	public static function streamed_frame_limits(): iterable {
		// A 2 KiB window plus one eighth, carrying just five raw bytes.
		yield 'fractional window' => [ "\x28\xb5\x2f\xfd\x00\x09\x29\x00\x00books", 2304 ];
		// A 1 KiB window and two RLE blocks producing 600 and 700 bytes.
		yield 'sum across blocks' => [ "\x28\xb5\x2f\xfd\x00\x00\xc2\x12\x00a\xe3\x15\x00b", 1300 ];
	}

	#[DataProvider( 'streamed_frame_limits' )]
	public function test_streaming_preflight_accepts_the_exact_window_and_output_budget( string $data, int $budget ): void {
		$frame = ZstdFrame::read( $data, 0, $budget );
		self::assertNull( $frame->size );
		self::assertSame( strlen( $data ), $frame->end );
		try {
			ZstdFrame::read( $data, 0, $budget - 1 );
			self::fail( 'The preflight accepted one byte less than the required capacity.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
		}
	}

	/** @return iterable<string, array{string, int}> */
	public static function sized_frames(): iterable {
		$raw = "\x29\x00\x00books";
		// The optional dictionary IDs are zero. The frames need no external dictionary.
		yield 'one-byte ID and size' => [ "\x21\x00\x05" . $raw, 5 ];
		yield 'two-byte ID and four-byte size' => [ "\xa2\x00\x00" . pack( 'V', 5 ) . $raw, 5 ];
		yield 'four-byte ID and eight-byte size' => [ "\xe3\x00\x00\x00\x00" . pack( 'V2', 5, 0 ) . $raw, 5 ];
		// The two-byte content size has a bias of 256. One byte encodes the RLE payload.
		yield 'two-byte size and RLE' => [ "\x60\x2c\x00\x63\x09\x00b", 300 ];
	}

	#[DataProvider( 'sized_frames' )]
	public function test_preflight_finds_the_next_frame_after_optional_header_fields( string $body, int $size ): void {
		$padding = "\x5f\x2a\x4d\x18" . pack( 'V', 3 ) . 'pad';
		$data    = $padding . "\x28\xb5\x2f\xfd" . $body . self::STREAMED;
		$skipped = ZstdFrame::read( $data, 0, 0 );
		self::assertTrue( $skipped->skip );
		self::assertSame( strlen( $padding ), $skipped->end );
		$frame = ZstdFrame::read( $data, $skipped->end, $size );
		self::assertFalse( $frame->skip );
		self::assertSame( $size, $frame->size );
		self::assertSame( strlen( $data ) - strlen( self::STREAMED ), $frame->end );
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
