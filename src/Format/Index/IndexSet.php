<?php
/**
 * In-memory index.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Index;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lookup table from blob to pack, merged from all index files.
 *
 * Entries are stored as compact binary strings (about 100 bytes each including PHP's array
 * overhead) so that sites with hundreds of thousands of files fit into shared-hosting memory
 * limits.
 */
final class IndexSet {

	private const string MAGIC = "backups-for-restic index 1\n";

	/**
	 * Pack IDs (raw bytes) by number.
	 *
	 * @var list<string>
	 */
	private array $packs = [];

	/**
	 * Pack numbers by raw pack ID.
	 *
	 * @var array<string, int>
	 */
	private array $pack_numbers = [];

	/**
	 * Packed locations: key is type char + raw blob ID, value is pack('VJVV', pack number, offset, length, uncompressed length).
	 *
	 * @var array<string, string>
	 */
	private array $blobs = [];

	/**
	 * Adds the entries of one pack. The first location of a blob wins.
	 *
	 * @param Id              $pack    Pack ID.
	 * @param list<PackEntry> $entries Entries.
	 */
	public function add_pack( Id $pack, array $entries ): void {
		$raw    = $pack->bytes();
		$number = $this->pack_numbers[ $raw ] ?? null;
		if ( null === $number ) {
			$number                     = count( $this->packs );
			$this->packs[]              = $raw;
			$this->pack_numbers[ $raw ] = $number;
		}

		foreach ( $entries as $entry ) {
			$key = self::key( $entry->id, $entry->type );
			if ( ! isset( $this->blobs[ $key ] ) ) {
				$this->blobs[ $key ] = pack( 'VJVV', $number, $entry->offset, $entry->length, $entry->uncompressed_length );
			}
		}
	}

	/**
	 * Whether a blob is known.
	 *
	 * @param Id       $id   Blob ID.
	 * @param BlobType $type Blob type.
	 * @return bool True if indexed.
	 */
	public function has( Id $id, BlobType $type ): bool {
		return isset( $this->blobs[ self::key( $id, $type ) ] );
	}

	/**
	 * Finds a blob.
	 *
	 * @param Id       $id   Blob ID.
	 * @param BlobType $type Blob type.
	 * @return BlobLocation|null Location, or null if unknown.
	 */
	public function find( Id $id, BlobType $type ): ?BlobLocation {
		$packed = $this->blobs[ self::key( $id, $type ) ] ?? null;
		if ( null === $packed ) {
			return null;
		}
		$fields = unpack( 'Vpack/Joffset/Vlength/Vuncompressed', $packed );
		if ( ! is_array( $fields ) ) {
			return null;
		}
		$pack = Id::from_bytes( $this->packs[ $fields['pack'] ] );
		return new BlobLocation( $pack, new PackEntry( $type, $id, $fields['offset'], $fields['length'], $fields['uncompressed'] ) );
	}

	/**
	 * Whether a pack is indexed.
	 *
	 * @param Id $pack Pack ID.
	 * @return bool True if known.
	 */
	public function has_pack( Id $pack ): bool {
		return isset( $this->pack_numbers[ $pack->bytes() ] );
	}

	/**
	 * Returns all indexed pack IDs.
	 *
	 * @return list<Id> Pack IDs.
	 */
	public function pack_ids(): array {
		return array_map( static fn ( string $raw ): Id => Id::from_bytes( $raw ), $this->packs );
	}

	/**
	 * Returns the number of indexed blobs.
	 *
	 * @return int Count.
	 */
	public function count(): int {
		return count( $this->blobs );
	}

	/**
	 * Serializes the index compactly for the local cache: a magic line, the pack IDs, then
	 * 53 bytes per blob (type, ID, packed location).
	 *
	 * @return string Binary representation.
	 */
	public function to_binary(): string {
		$binary = self::MAGIC . pack( 'V', count( $this->packs ) ) . implode( '', $this->packs ) . pack( 'V', count( $this->blobs ) );
		foreach ( $this->blobs as $key => $location ) {
			$binary .= $key . $location;
		}
		return $binary;
	}

	/**
	 * Restores an index from to_binary().
	 *
	 * @param string $binary Binary representation.
	 * @return self Index.
	 * @throws FormatException If the data is truncated or malformed.
	 */
	public static function from_binary( string $binary ): self {
		$length = strlen( $binary );
		$offset = strlen( self::MAGIC );
		if ( $length < $offset + 8 || ! str_starts_with( $binary, self::MAGIC ) ) {
			throw new FormatException( 'The cached index is not recognised.' );
		}
		$index   = new self();
		$packs   = self::count_at( $binary, $offset );
		$offset += 4;
		if ( $length < $offset + 32 * $packs + 4 ) {
			throw new FormatException( 'The cached index is truncated.' );
		}
		for ( $number = 0; $number < $packs; $number++ ) {
			$raw                         = substr( $binary, $offset, 32 );
			$index->packs[]              = $raw;
			$index->pack_numbers[ $raw ] = $number;
			$offset                     += 32;
		}
		$blobs   = self::count_at( $binary, $offset );
		$offset += 4;
		if ( $length !== $offset + 53 * $blobs ) {
			throw new FormatException( 'The cached index has the wrong length.' );
		}
		for ( $i = 0; $i < $blobs; $i++ ) {
			$key      = substr( $binary, $offset, 33 );
			$location = substr( $binary, $offset + 33, 20 );
			$fields   = unpack( 'Vpack', $location );
			if ( ( 't' !== $key[0] && 'd' !== $key[0] ) || ! is_array( $fields ) || $fields['pack'] >= $packs ) {
				throw new FormatException( 'The cached index is damaged.' );
			}
			$index->blobs[ $key ] = $location;
			$offset              += 53;
		}
		return $index;
	}

	/**
	 * Reads a 32-bit count.
	 *
	 * @param string $binary Data.
	 * @param int    $offset Position.
	 * @return int Count.
	 */
	private static function count_at( string $binary, int $offset ): int {
		$fields = unpack( 'Vcount', $binary, $offset );
		return is_array( $fields ) ? $fields['count'] : 0;
	}

	/**
	 * Builds the lookup key.
	 *
	 * @param Id       $id   Blob ID.
	 * @param BlobType $type Blob type.
	 * @return string Key.
	 */
	private static function key( Id $id, BlobType $type ): string {
		return ( BlobType::Tree === $type ? 't' : 'd' ) . $id->bytes();
	}
}
