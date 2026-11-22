<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Unpacked;
use BackupsForRestic\Format\ZstdFrame;
use BackupsForRestic\Tests\Support\ResticFrame;
use BackupsForRestic\Tests\Support\ResticIndexFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase {

	private const int MIB = 1048576;

	/** A raw last block holding "books", in a streamed frame with a 1 KiB window. */
	private const string STREAMED = "\x28\xb5\x2f\xfd\x00\x00\x29\x00\x00books";

	/** The same block in a single-segment frame that declares its five bytes. */
	private const string SIZED = "\x28\xb5\x2f\xfd\x20\x05\x29\x00\x00books";

	/** A skippable frame with three bytes of padding. */
	private const string PADDING = "\x50\x2a\x4d\x18\x03\x00\x00\x00pad";

	/** A 1 KiB window and two RLE blocks producing 600 and 700 bytes. */
	private const string RLE_STREAM = "\x28\xb5\x2f\xfd\x00\x00\xc2\x12\x00a\xe3\x15\x00b";

	/**
	 * What WordPress 7.1.5 on PHP 8.3 had reserved once it was loaded, in every tick of the CI
	 * memory runs on acaecdc (ADR 0019).
	 */
	private const int WORDPRESS = 34 * self::MIB;

	/**
	 * PHP's memory limit when the test started.
	 *
	 * @var string
	 */
	private string $memory_limit = '';

	protected function setUp(): void {
		$this->memory_limit = (string) ini_get( 'memory_limit' );
	}

	protected function tearDown(): void {
		self::limit_memory( $this->memory_limit );
	}

	/**
	 * Sets PHP's memory limit; tearDown() restores it.
	 *
	 * @param int|string $value Bytes, or -1 for no limit.
	 */
	private static function limit_memory( int|string $value ): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The decoder reads PHP's own limit; this test without WordPress sets it as a host would.
		if ( false === ini_set( 'memory_limit', (string) $value ) ) {
			throw new \LogicException( 'PHP did not accept the memory limit.' );
		}
	}

	/**
	 * Sets PHP's memory limit so that exactly the given number of bytes beyond the reserve can
	 * still be allocated. Setting the limit can itself reserve memory, so it is set again until
	 * the reserved memory stays the same.
	 *
	 * @param int $bytes Bytes that are free afterwards.
	 */
	private static function leave_free( int $bytes ): void {
		do {
			$reserved = memory_get_usage( true );
			self::limit_memory( $reserved + Compression::RESERVE + $bytes );
		} while ( memory_get_usage( true ) !== $reserved );
	}

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

	/** @return iterable<string, array{string, int, int}> */
	public static function frame_bounds(): iterable {
		yield 'raw block, single segment' => [ self::SIZED, 5, 0 ];
		yield 'raw block, 1 KiB window' => [ self::STREAMED, 5, 1024 ];
		yield 'raw block, window and one eighth of 2 KiB' => [ "\x28\xb5\x2f\xfd\x00\x09\x29\x00\x00books", 5, 2304 ];
		yield 'two RLE blocks' => [ self::RLE_STREAM, 1300, 1024 ];
		// A compressed block may regenerate 128 KiB, the limit below a 1 MiB window.
		yield 'compressed block, 1 MiB window' => [ "\x28\xb5\x2f\xfd\x00\x50\x0d\x00\x00x", 131072, 1048576 ];
	}

	#[DataProvider( 'frame_bounds' )]
	public function test_the_preflight_reports_the_output_bound_and_the_window( string $data, int $bound, int $window ): void {
		$frame = ZstdFrame::read( $data, 0, Compression::MAX_DECOMPRESSED_SIZE );
		self::assertFalse( $frame->skip );
		self::assertSame( $bound, $frame->bound );
		self::assertSame( $window, $frame->window );
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
		self::assertSame( 0, $skipped->size );
		self::assertSame( 0, $skipped->bound );
		self::assertSame( 0, $skipped->window );
		self::assertSame( strlen( $padding ), $skipped->end );
		$frame = ZstdFrame::read( $data, $skipped->end, $size );
		self::assertFalse( $frame->skip );
		self::assertSame( $size, $frame->size );
		self::assertSame( strlen( $data ) - strlen( self::STREAMED ), $frame->end );
	}

	public function test_the_bias_of_a_two_byte_size_counts_against_the_capacity(): void {
		// Declares 256 + 44 = 300 bytes; its only block is an RLE block of one byte.
		$frame = "\x28\xb5\x2f\xfd\x60\x2c\x00\x0b\x00\x00b";
		self::assertSame( 300, ZstdFrame::read( $frame, 0, 300 )->size );
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Compressed repository data exceeds the decompression limit.' );
		ZstdFrame::read( $frame, 0, 299 );
	}

	/** @return iterable<string, array{string}> */
	public static function invalid_envelopes(): iterable {
		yield 'truncated skippable payload' => [ "\x50\x2a\x4d\x18" . pack( 'V', 3 ) . 'ab' ];
		yield 'partial skippable magic' => [ "\x40\x2a\x4d\x18" . pack( 'V', 0 ) ];
		yield 'unknown magic with an otherwise complete frame' => [ "\x25\xb5\x2f\xfd\x20\x05\x29\x00\x00books" ];
		yield 'reserved frame header bit' => [ "\x28\xb5\x2f\xfd\x28\x05\x29\x00\x00books" ];
		yield 'reserved block type' => [ "\x28\xb5\x2f\xfd\x20\x01\x0f\x00\x00x" ];
	}

	#[DataProvider( 'invalid_envelopes' )]
	public function test_preflight_refuses_incomplete_or_reserved_frames( string $data ): void {
		$this->expectException( FormatException::class );
		ZstdFrame::read( $data, 0, 1024 );
	}

	public function test_a_content_checksum_is_part_of_its_frame(): void {
		$checksummed = "\x28\xb5\x2f\xfd\x24\x05\x29\x00\x00books\x1c\x6f\x78\xfd";
		$data        = $checksummed . self::STREAMED;
		$first       = ZstdFrame::read( $data, 0, 5 );
		self::assertSame( strlen( $checksummed ), $first->end );
		self::assertSame( strlen( $data ), ZstdFrame::read( $data, $first->end, 1024 )->end );
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

	/** @return iterable<string, array{string, int, list<int>}> */
	public static function declared_sizes_beyond_the_pack_entry(): iterable {
		yield 'one frame' => [ self::SIZED, 4, [] ];
		// The first frame of 14 bytes is copied out and decoded; the second is not.
		yield 'the second frame' => [ self::SIZED . self::SIZED, 9, [ 14 + 10 ] ];
	}

	/**
	 * @param list<int> $reserved What was reserved before the refusal.
	 */
	#[DataProvider( 'declared_sizes_beyond_the_pack_entry' )]
	public function test_a_declared_size_beyond_the_pack_entry_is_refused_before_decoding( string $data, int $expected, array $reserved ): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$told = [];
		$tell = static function ( int $bytes ) use ( &$told ): void {
			$told[] = $bytes;
		};
		try {
			Compression::decompress( $data, $expected, $tell );
			self::fail( 'The frames declare more than the pack entry.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'Decompressed blob has an unexpected length.', $error->getMessage() );
		}
		self::assertSame( $reserved, $told, 'The frame beyond the entry was neither reserved for nor decoded.' );
	}

	/** @return iterable<string, array{string, list<int>, string}> */
	public static function decoding_needs(): iterable {
		yield 'twice a declared size' => [ self::SIZED, [ 10 ], 'books' ];
		yield 'four times the window of a stream' => [ self::STREAMED, [ 4096 ], 'books' ];
		yield 'four times the blocks of a stream' => [ self::RLE_STREAM, [ 5200 ], str_repeat( 'a', 600 ) . str_repeat( 'b', 700 ) ];
		// Each frame of 14 bytes is copied out of the input, and appending the second frame's
		// output can copy the five bytes decoded before it.
		yield 'frames after padding' => [ self::PADDING . self::SIZED . self::SIZED, [ 14 + 10, 14 + 5 + 10 ], 'booksbooks' ];
		yield 'a stream after a frame' => [ self::SIZED . self::STREAMED, [ 14 + 10, 14 + 5 + 4096 ], 'booksbooks' ];
	}

	/**
	 * @param list<int> $needs Bytes each frame takes.
	 */
	#[DataProvider( 'decoding_needs' )]
	public function test_each_frame_says_what_decoding_it_takes_before_it_is_decoded( string $data, array $needs, string $content ): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$told = [];
		$tell = static function ( int $bytes ) use ( &$told ): void {
			$told[] = $bytes;
		};
		self::assertSame( $content, Compression::decompress( $data, null, $tell ) );
		self::assertSame( $needs, $told );
	}

	/**
	 * @param list<int> $needs Bytes each frame takes.
	 */
	#[DataProvider( 'decoding_needs' )]
	public function test_a_frame_decodes_with_exactly_what_it_takes_free_and_is_refused_one_byte_short( string $data, array $needs, string $content ): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$told    = [];
		$exactly = static function ( int $bytes ) use ( &$told ): void {
			$told[] = $bytes;
			self::leave_free( $bytes );
		};
		self::assertSame( $content, Compression::decompress( $data, null, $exactly ) );
		self::assertSame( $needs, $told );

		$short = static function ( int $bytes ): void {
			self::leave_free( $bytes - 1 );
		};
		try {
			Compression::decompress( $data, null, $short );
			self::fail( 'A frame was decoded with one byte less than it takes.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'The compressed frame exceeds the available decoding memory.', $error->getMessage() );
			self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
		}
	}

	public function test_a_pack_entry_is_refused_up_front_when_twice_its_size_is_not_free(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		self::leave_free( 10 );
		self::assertSame( 'books', Compression::decompress( self::SIZED, 5 ) );
		self::leave_free( 9 );
		try {
			Compression::decompress( self::SIZED, 5 );
			self::fail( 'Five bytes cannot be decoded with nine free.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'The uncompressed blob exceeds the decompression limit.', $error->getMessage() );
			self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
		}
	}

	public function test_without_a_memory_limit_an_object_is_still_limited_to_128_mib(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		self::limit_memory( -1 );
		self::assertSame( 'books', Compression::decompress( self::SIZED ) );
		try {
			Compression::decompress( self::SIZED, Compression::MAX_DECOMPRESSED_SIZE + 1 );
			self::fail( 'A pack entry beyond 128 MiB was accepted.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'The uncompressed blob exceeds the decompression limit.', $error->getMessage() );
		}
		try {
			Compression::decompress( self::SIZED, Compression::MAX_DECOMPRESSED_SIZE );
			self::fail( 'The frame holds five bytes, not 128 MiB.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'Decompressed blob has an unexpected length.', $error->getMessage() );
		}
		// After five bytes, a frame that declares 128 MiB less four bytes goes beyond the limit.
		$large = "\x28\xb5\x2f\xfd\x80\x50" . pack( 'V', Compression::MAX_DECOMPRESSED_SIZE - 4 ) . "\x29\x00\x00books";
		try {
			Compression::decompress( self::SIZED . $large );
			self::fail( 'The two frames decode to more than 128 MiB.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'Compressed repository data exceeds the decompression limit.', $error->getMessage() );
			self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
		}
	}

	public function test_a_frame_that_declares_more_than_php_can_allocate_is_refused_before_anything_is_allocated(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// A content size of 64 MiB, a 1 MiB window and one raw block of five bytes: only a
		// decoder that believed the declared size would allocate it.
		$frame = "\x28\xb5\x2f\xfd\x80\x50" . pack( 'V', 64 * self::MIB ) . "\x29\x00\x00books";
		self::leave_free( 128 * self::MIB - 1 );
		memory_reset_peak_usage();
		$before = memory_get_usage();
		try {
			Compression::decompress( $frame );
			self::fail( 'Decoding the frame takes twice its declared size.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'decompression_limit', $error->context()['code'] ?? null );
		}
		self::assertLessThan( self::MIB, memory_get_peak_usage() - $before, 'Nothing of the declared size was allocated.' );
	}

	/** @return iterable<string, array{bool}> */
	public static function native_decoders(): iterable {
		yield 'a frame that declares its size' => [ true ];
		yield 'a frame without a declared size' => [ false ];
	}

	/**
	 * The bound this class reserves rests on what ext-zstd allocates. If an installed version
	 * took more, this test fails before a job runs into PHP's limit.
	 */
	#[DataProvider( 'native_decoders' )]
	public function test_the_native_decoder_takes_no_more_than_the_frame_says( bool $sized ): void {
		if ( ! Compression::available() || ! function_exists( 'zstd_compress_init' ) ) {
			self::markTestSkipped( 'Needs the zstd extension with its streaming functions.' );
		}
		// Random hex digits compress about two to one.
		$content = bin2hex( random_bytes( 4 * self::MIB ) );
		if ( $sized ) {
			$frame = (string) zstd_compress( $content, 3 );
		} else {
			// Fed in pieces, the encoder does not know the size and leaves it out of the header.
			$context = zstd_compress_init( 3 );
			self::assertNotFalse( $context );
			$frame = '';
			foreach ( str_split( $content, self::MIB ) as $piece ) {
				$frame .= (string) zstd_compress_add( $context, $piece, false );
			}
			$frame .= (string) zstd_compress_add( $context, '', true );
			unset( $context, $piece );
		}
		self::assertSame( $sized, null !== ZstdFrame::read( $frame, 0, Compression::MAX_DECOMPRESSED_SIZE )->size );
		$digest = hash( 'sha256', $content );
		unset( $content );
		$told = [];
		$tell = static function ( int $bytes ) use ( &$told ): void {
			$told[] = $bytes;
		};
		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$decoded = Compression::decompress( $frame, null, $tell );
		$peak    = memory_get_peak_usage() - $before;
		self::assertSame( $digest, hash( 'sha256', $decoded ) );
		self::assertCount( 1, $told );
		if ( $sized ) {
			self::assertSame( 2 * strlen( $decoded ), $told[0] );
		}
		// Within page rounding of the two buffers and the bookkeeping of the call.
		self::assertLessThanOrEqual( $told[0] + 131072, $peak );
	}

	public function test_an_index_file_restic_wrote_in_one_frame_decodes_in_what_wordpress_leaves_of_128_mib(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// Building the file needs more than the suite's limit leaves; decoding it does not.
		self::limit_memory( memory_get_usage( true ) + 128 * self::MIB );
		$json   = ResticIndexFile::json( ResticIndexFile::COMPRESSED_BLOBS );
		$frame  = (string) zstd_compress( $json, 3 );
		$size   = strlen( $json );
		$digest = hash( 'sha256', $json );
		unset( $json );
		self::assertGreaterThan( 21 * self::MIB, $size );
		self::assertSame( $size, ZstdFrame::read( $frame, 0, Compression::MAX_DECOMPRESSED_SIZE )->size );
		gc_mem_caches();
		// A request limited to 128 MiB, with WordPress loaded and the compressed file in memory.
		self::limit_memory( memory_get_usage( true ) + 128 * self::MIB - self::WORDPRESS - strlen( $frame ) );
		self::assertSame( $digest, hash( 'sha256', Compression::decompress( $frame ) ) );
	}

	/** @return iterable<string, array{int, int}> */
	public static function libzstd_bounds(): iterable {
		// ZSTD_COMPRESSBOUND: the content, a 256th of it, and below 128 KiB up to 64 bytes more.
		yield 'nothing' => [ 0, 64 ];
		yield 'one byte' => [ 1, 64 ];
		yield 'one byte less than a block' => [ 131071, 131582 ];
		yield 'one block' => [ 131072, 131584 ];
		yield 'a full chunk' => [ RabinChunker::MAX_SIZE, 8421376 ];
	}

	#[DataProvider( 'libzstd_bounds' )]
	public function test_the_largest_frame_of_some_content_is_the_bound_of_libzstd( int $size, int $bound ): void {
		self::assertSame( $bound, Compression::bound( $size ) );
	}

	public function test_frames_of_data_that_does_not_compress_stay_within_the_bound(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		foreach ( [ 1, 1000, 131071, 131072, 131073, 1048576 ] as $size ) {
			self::assertLessThanOrEqual( Compression::bound( $size ), strlen( (string) zstd_compress( random_bytes( $size ), 3 ) ) );
		}
	}

	public function test_a_full_chunk_in_the_frame_restic_writes_for_it_decodes(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$content = random_bytes( RabinChunker::MAX_SIZE );
		$frame   = ResticFrame::raw( $content );
		self::assertSame( RabinChunker::MAX_SIZE + 202, strlen( $frame ), 'The frame is 202 bytes longer than the chunk.' );
		self::assertLessThanOrEqual( Compression::bound( RabinChunker::MAX_SIZE ), strlen( $frame ) );
		$read = ZstdFrame::read( $frame, 0, Compression::MAX_DECOMPRESSED_SIZE );
		self::assertSame( [ RabinChunker::MAX_SIZE, RabinChunker::MAX_SIZE, 524288 ], [ $read->size, $read->bound, $read->window ] );
		self::assertSame( hash( 'sha256', $content ), hash( 'sha256', Compression::decompress( $frame, RabinChunker::MAX_SIZE ) ) );
	}

	public function test_an_unknown_encoding_byte_is_refused_before_anything_is_decompressed(): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Repository file uses an unknown encoding.' );
		Unpacked::decode( "\x05" . self::SIZED, 2 );
	}

	public function test_the_plaintext_is_let_go_before_its_compressed_bytes_are_decoded(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		// Eight MiB of random hex digits, about four MiB as zstd.
		$json   = '["' . bin2hex( random_bytes( 4 * self::MIB - 2 ) ) . '"]';
		$frame  = (string) zstd_compress( $json, 3 );
		$digest = hash( 'sha256', $json );
		unset( $json );
		memory_reset_peak_usage();
		$before = memory_get_usage();
		// Passed as a temporary value, as the repository passes the decrypted file.
		$decoded = Unpacked::decode( "\x02" . $frame, 2 );
		$peak    = memory_get_peak_usage() - $before;
		self::assertSame( $digest, hash( 'sha256', $decoded ) );
		// Twice the JSON beside one copy of the compressed bytes; the plaintext would be another.
		self::assertLessThan( 2 * strlen( $decoded ) + 1.5 * strlen( $frame ), $peak );
	}
}
