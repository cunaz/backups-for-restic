<?php
/**
 * Pack parsing.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Pack;

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads pack headers and opens blobs. All sizes come from the server and are checked before
 * use, so a malicious or corrupted pack cannot make the plugin read out of bounds or allocate
 * unbounded memory.
 */
final class PackReader {

	/** Largest stored blob accepted (restic chunks are at most 8 MiB; trees can be larger). */
	public const int MAX_BLOB_LENGTH = 67108864;

	/**
	 * Reads the header length from the last four bytes of a pack.
	 *
	 * @param string $last_four_bytes Last four bytes.
	 * @param int    $pack_size       Size of the pack.
	 * @return int Length of the sealed header.
	 * @throws FormatException If the length is impossible.
	 */
	public static function header_length( string $last_four_bytes, int $pack_size ): int {
		$length = Bytes::read_uint32_le( $last_four_bytes );
		if ( $length < Cipher::OVERHEAD || $length + 4 > $pack_size || $length > PackWriter::MAX_HEADER_SIZE ) {
			FormatException::raise( 'Pack header length is invalid.', [ 'length' => $length ] );
		}
		return $length;
	}

	/**
	 * Decrypts and parses a pack header.
	 *
	 * @param Cipher $cipher        Cipher with the master key.
	 * @param string $sealed_header Sealed header (without the trailing length).
	 * @param int    $pack_size     Size of the pack.
	 * @return list<PackEntry> Entries with offsets.
	 * @throws FormatException If the header is malformed or entries exceed the pack.
	 */
	public static function read_header( Cipher $cipher, string $sealed_header, int $pack_size ): array {
		$header  = $cipher->open( $sealed_header );
		$limit   = $pack_size - strlen( $sealed_header ) - 4;
		$entries = [];
		$offset  = 0;
		$cursor  = 0;
		$length  = strlen( $header );

		while ( $cursor < $length ) {
			$type_byte  = ord( $header[ $cursor ] );
			$compressed = $type_byte >= 2;
			$entry_size = $compressed ? 41 : 37;
			if ( $type_byte > 3 || $cursor + $entry_size > $length ) {
				throw new FormatException( 'Pack header contains an invalid entry.' );
			}

			$stored       = Bytes::read_uint32_le( $header, $cursor + 1 );
			$uncompressed = $compressed ? Bytes::read_uint32_le( $header, $cursor + 5 ) : 0;
			$id           = Id::from_bytes( substr( $header, $cursor + $entry_size - 32, 32 ) );
			if ( $stored < Cipher::OVERHEAD || $stored > self::MAX_BLOB_LENGTH || $offset + $stored > $limit ) {
				throw new FormatException( 'Pack header entry points outside the pack.' );
			}

			$entries[] = new PackEntry( 1 === ( $type_byte & 1 ) ? BlobType::Tree : BlobType::Data, $id, $offset, $stored, $uncompressed );
			$offset   += $stored;
			$cursor   += $entry_size;
		}

		return $entries;
	}

	/**
	 * Opens a stored blob and verifies that its content matches its ID.
	 *
	 * @param Cipher    $cipher Cipher with the master key.
	 * @param PackEntry $entry  Header entry.
	 * @param string    $sealed Stored bytes of the blob.
	 * @return string Plaintext.
	 * @throws FormatException If the content does not match the ID.
	 */
	public static function open_blob( Cipher $cipher, PackEntry $entry, string $sealed ): string {
		if ( strlen( $sealed ) !== $entry->length ) {
			throw new FormatException( 'Blob has an unexpected length.' );
		}

		$plaintext = $cipher->open( $sealed );
		if ( $entry->uncompressed_length > 0 ) {
			$plaintext = Compression::decompress( $plaintext );
			if ( strlen( $plaintext ) !== $entry->uncompressed_length ) {
				throw new FormatException( 'Decompressed blob has an unexpected length.' );
			}
		}

		if ( ! Id::of( $plaintext )->equals( $entry->id ) ) {
			FormatException::raise( 'Blob content does not match its ID.', [ 'id' => $entry->id->hex() ] );
		}
		return $plaintext;
	}
}
