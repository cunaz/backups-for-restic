<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\ResticFrame;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Pack headers come from the server, so every length is checked before use. The layout is
 * restic's (internal/repository/pack): per entry a type byte (0 data, 1 tree, 2 compressed
 * data, 3 compressed tree), the stored length, the uncompressed length for types 2 and 3, and
 * the blob ID.
 */
final class PackReaderTest extends TestCase {

	private const int MIB = 1048576;

	private static function entry( int $type, int $stored, Id $id, int $uncompressed = 0 ): string {
		return chr( $type ) . Bytes::uint32_le( $stored ) . ( $type >= 2 ? Bytes::uint32_le( $uncompressed ) : '' ) . $id->bytes();
	}

	public function test_header_length_must_cover_nonce_and_tag_and_fit_into_the_pack(): void {
		self::assertSame( Cipher::OVERHEAD, PackReader::header_length( Bytes::uint32_le( Cipher::OVERHEAD ), Cipher::OVERHEAD + 4 ), 'An empty header fills a pack without blobs exactly.' );
		self::assertSame( 100, PackReader::header_length( Bytes::uint32_le( 100 ), 104 ) );

		foreach ( [ [ 0, 1000 ], [ Cipher::OVERHEAD - 1, 1000 ], [ 100, 103 ] ] as [ $length, $pack_size ] ) {
			try {
				PackReader::header_length( Bytes::uint32_le( $length ), $pack_size );
				self::fail( 'Header length ' . $length . ' was accepted for a pack of ' . $pack_size . ' bytes.' );
			} catch ( FormatException $exception ) {
				self::assertSame( [ 'length' => $length ], $exception->context() );
			}
		}
	}

	public function test_all_four_entry_types_are_read_with_their_offsets(): void {
		$cipher = Vectors::cipher();
		$ids    = [ Id::of( 'a' ), Id::of( 'b' ), Id::of( 'c' ), Id::of( 'd' ) ];
		$sealed = $cipher->seal( self::entry( 2, 100, $ids[0], 250 ) . self::entry( 3, 60, $ids[1], 80 ) . self::entry( 0, 50, $ids[2] ) . self::entry( 1, 40, $ids[3] ) );

		self::assertEquals(
			[
				new PackEntry( BlobType::Data, $ids[0], 0, 100, 250 ),
				new PackEntry( BlobType::Tree, $ids[1], 100, 60, 80 ),
				new PackEntry( BlobType::Data, $ids[2], 160, 50 ),
				new PackEntry( BlobType::Tree, $ids[3], 210, 40 ),
			],
			PackReader::read_header( $cipher, $sealed, 250 + strlen( $sealed ) + 4 )
		);
	}

	public function test_blobs_must_end_before_the_header(): void {
		$cipher = Vectors::cipher();
		$sealed = $cipher->seal( self::entry( 0, 100, Id::of( 'a' ) ) . self::entry( 1, 50, Id::of( 'b' ) ) );
		$size   = 150 + strlen( $sealed ) + 4;

		self::assertCount( 2, PackReader::read_header( $cipher, $sealed, $size ) );
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Pack header entry points outside the pack.' );
		PackReader::read_header( $cipher, $sealed, $size - 1 );
	}

	/**
	 * @return iterable<string, array{int, bool}>
	 */
	public static function stored_lengths(): iterable {
		yield 'shorter than nonce and tag' => [ Cipher::OVERHEAD - 1, false ];
		yield 'an empty blob' => [ Cipher::OVERHEAD, true ];
		yield 'the largest blob' => [ PackReader::MAX_BLOB_LENGTH, true ];
		yield 'beyond the largest blob' => [ PackReader::MAX_BLOB_LENGTH + 1, false ];
	}

	#[DataProvider( 'stored_lengths' )]
	public function test_stored_lengths_are_bounded_even_in_a_large_pack( int $stored, bool $valid ): void {
		$cipher = Vectors::cipher();
		$sealed = $cipher->seal( self::entry( 0, $stored, Id::of( 'a' ) ) );

		if ( ! $valid ) {
			$this->expectException( FormatException::class );
			$this->expectExceptionMessage( 'Pack header entry points outside the pack.' );
		}
		self::assertSame( $stored, PackReader::read_header( $cipher, $sealed, 2 * PackReader::MAX_BLOB_LENGTH )[0]->length );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_entries(): iterable {
		yield 'unknown type' => [ self::entry( 4, 100, Id::of( 'a' ) ) ];
		yield 'truncated entry' => [ substr( self::entry( 0, 100, Id::of( 'a' ) ), 0, -1 ) ];
		yield 'truncated compressed entry' => [ substr( self::entry( 2, 100, Id::of( 'a' ), 200 ), 0, -1 ) ];
		yield 'truncated second entry' => [ self::entry( 0, 100, Id::of( 'a' ) ) . "\x01" ];
	}

	#[DataProvider( 'invalid_entries' )]
	public function test_invalid_entries_are_rejected( string $header ): void {
		$cipher = Vectors::cipher();
		$sealed = $cipher->seal( $header );

		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Pack header contains an invalid entry.' );
		PackReader::read_header( $cipher, $sealed, 1000 + strlen( $sealed ) + 4 );
	}

	public function test_a_blob_that_does_not_match_its_id_names_the_id(): void {
		$cipher = Vectors::cipher();
		$entry  = new PackEntry( BlobType::Data, Id::of( 'expected' ), 0, Cipher::ciphertext_length( 5 ) );

		try {
			PackReader::open_blob( $cipher, $entry, $cipher->seal( 'other' ) );
			self::fail( 'A blob with the wrong content was accepted.' );
		} catch ( FormatException $exception ) {
			self::assertSame( [ 'id' => $entry->id->hex() ], $exception->context() );
		}
	}

	public function test_a_header_may_reach_restics_limit_but_not_pass_it(): void {
		// restic's MaxHeaderSize counts the four length bytes; the header itself gets the rest.
		$limit = PackWriter::MAX_HEADER_SIZE - 4;
		self::assertSame( $limit, PackReader::header_length( Bytes::uint32_le( $limit ), $limit + 4 ) );

		$this->expectException( FormatException::class );
		PackReader::header_length( Bytes::uint32_le( $limit + 1 ), $limit + 5 );
	}

	/**
	 * A data blob of 8 MiB that does not compress, as restic stores it in format 2: decrypted
	 * it is a frame of the same size, and decoding it takes twice the chunk. Passed as a
	 * temporary value, as a restore passes the slice of its group, the stored bytes are let go
	 * before the frame is decoded, so they are not a fourth copy beside the frame and the two
	 * of the decoder.
	 */
	public function test_the_stored_bytes_of_a_compressed_blob_are_let_go_before_it_is_decoded(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$cipher  = Vectors::cipher();
		$content = random_bytes( RabinChunker::MAX_SIZE );
		$id      = Id::of( $content );
		$sealed  = $cipher->seal( ResticFrame::raw( $content ) );
		unset( $content );
		$entry  = new PackEntry( BlobType::Data, $id, 0, strlen( $sealed ), RabinChunker::MAX_SIZE );
		$stored = [ $sealed ];
		unset( $sealed );
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$plain  = PackReader::open_blob( $cipher, $entry, array_pop( $stored ) );
		$peak   = memory_get_peak_usage() - $before;
		self::assertTrue( Id::of( $plain )->equals( $id ) );
		// Beside the stored bytes, which were there before the call: decrypting adds the
		// ciphertext and the frame, and decoding the decoder's buffer and output once the
		// ciphertext and the stored bytes are gone. Twice the chunk; three times if the stored
		// bytes stayed.
		self::assertLessThan( 2 * RabinChunker::MAX_SIZE + self::MIB, $peak );
	}

	/**
	 * In a process of its own: in one that earlier tests left with free pages in many blocks, the
	 * small strings below would fill those pages instead of blocks of their own.
	 */
	#[RunInSeparateProcess]
	public function test_the_pages_that_let_go_strings_keep_are_given_back_before_a_blob_is_decoded(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$cipher  = Vectors::cipher();
		$content = str_repeat( 'a text that compresses ', 200000 );
		$id      = Id::of( $content );
		$sealed  = $cipher->seal( (string) zstd_compress( $content, 3 ) );
		$entry   = new PackEntry( BlobType::Data, $id, 0, strlen( $sealed ), strlen( $content ) );
		unset( $content );
		gc_mem_caches();
		$before = memory_get_usage( true );
		// Small strings that were made and let go, as a request makes them: PHP keeps their
		// pages for reuse, and they count against its limit until it gives them back.
		$small = [];
		for ( $i = 0; $i < 200000; ++$i ) {
			$small[] = str_repeat( 's', 40 ) . $i;
		}
		unset( $small );
		$kept = memory_get_usage( true ) - $before;
		self::assertGreaterThanOrEqual( 4 * 2097152, $kept, 'The strings took blocks of their own.' );
		// The decoder needs twice the content free, and half of what is kept is missing for it.
		// PHP may keep one of the empty blocks, and a temporary of the decryption may go into
		// another one: what is given back covers the half.
		$limit = (string) ini_get( 'memory_limit' );
		try {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The decoder reads PHP's own limit; this test without WordPress sets it as a host would.
			self::assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + Compression::RESERVE + 2 * $entry->uncompressed_length - intdiv( $kept, 2 ) ) ) );
			self::assertTrue( Id::of( PackReader::open_blob( $cipher, $entry, $sealed ) )->equals( $id ) );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
			ini_set( 'memory_limit', $limit );
		}
	}
}
