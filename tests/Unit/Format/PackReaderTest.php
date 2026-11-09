<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Pack headers come from the server, so every length is checked before use. The layout is
 * restic's (internal/repository/pack): per entry a type byte (0 data, 1 tree, 2 compressed
 * data, 3 compressed tree), the stored length, the uncompressed length for types 2 and 3, and
 * the blob ID.
 */
final class PackReaderTest extends TestCase {

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
}
