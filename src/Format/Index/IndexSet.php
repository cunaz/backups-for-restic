<?php
/**
 * Merged in-memory index.
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
 * A PHP array with one element per blob costs about 160 bytes per blob, which a host with
 * 128 MB cannot afford for a site with half a million files (ADR 0031). The blobs are
 * therefore kept as fixed-size records of 48 bytes, concatenated in 65536 buckets per blob
 * type: a record is the blob ID followed by pack( 'V4', pack number, offset, length,
 * uncompressed length ). Two bytes of the ID choose the bucket, and strpos() finds the ID
 * inside it; a match counts only at the start of a record. With the buckets' own overhead
 * that is 50 to 60 bytes per blob.
 *
 * Which two bytes choose the bucket is drawn at random for every index and kept with its
 * serialized form. Someone who can place files on the site can choose a few bits of their
 * blob IDs by trial; with fixed bytes they could send thousands of blobs into one bucket and
 * make every lookup and every addition slow.
 */
final class IndexSet {

	private const string MAGIC = "backups-for-restic index 2\n";

	/** Bytes of a blob ID. */
	private const int ID_BYTES = 32;

	/** Bytes of a record: the ID and four unsigned 32-bit numbers. */
	private const int RECORD = 48;

	/** The largest pack number, offset and length a record holds. */
	private const int MAX_NUMBER = 4294967295;

	/** Bytes of bucket data after which export() starts a new piece. */
	private const int PIECE_BYTES = 1048576;

	/** Pack IDs in one exported piece: one megabyte of them. */
	private const int PACKS_PER_PIECE = 32768;

	/**
	 * Positions of the two ID bytes that choose a bucket.
	 *
	 * @var int
	 */
	private int $high;

	/**
	 * Second position; never the same as the first.
	 *
	 * @var int
	 */
	private int $low;

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
	 * Records of the data blobs, concatenated, by bucket number.
	 *
	 * @var array<int, string>
	 */
	private array $data = [];

	/**
	 * Records of the tree blobs, concatenated, by bucket number.
	 *
	 * @var array<int, string>
	 */
	private array $trees = [];

	/**
	 * Number of blobs.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Creates an empty index.
	 *
	 * @param int|null $high Position of the ID byte that gives the high byte of the bucket number; random if null.
	 * @param int|null $low  Position of the ID byte that gives the low byte; random if null.
	 * @throws FormatException If the positions are not two different bytes of an ID.
	 */
	public function __construct( ?int $high = null, ?int $low = null ) {
		$high ??= random_int( 0, self::ID_BYTES - 1 );
		// One of the other 31 positions, each as likely as the next.
		$low ??= ( $high + random_int( 1, self::ID_BYTES - 1 ) ) % self::ID_BYTES;
		if ( $high < 0 || $high >= self::ID_BYTES || $low < 0 || $low >= self::ID_BYTES || $high === $low ) {
			throw new FormatException( 'The index names bucket bytes that an ID does not have.' );
		}
		$this->high = $high;
		$this->low  = $low;
	}

	/**
	 * Adds the entries of one pack. The first location of a blob wins.
	 *
	 * @param Id              $pack    Pack ID.
	 * @param list<PackEntry> $entries Entries.
	 * @throws FormatException If an entry lies beyond 4 GiB, which no pack reaches.
	 */
	public function add_pack( Id $pack, array $entries ): void {
		foreach ( $entries as $entry ) {
			if ( $entry->offset < 0 || $entry->offset > self::MAX_NUMBER || $entry->length < 0 || $entry->length > self::MAX_NUMBER
				|| $entry->uncompressed_length < 0 || $entry->uncompressed_length > self::MAX_NUMBER ) {
				throw new FormatException( 'The index describes a blob beyond the size of any pack.' );
			}
		}
		$raw    = $pack->bytes();
		$number = $this->pack_numbers[ $raw ] ?? null;
		if ( null === $number ) {
			$number = count( $this->packs );
			if ( $number > self::MAX_NUMBER ) {
				throw new FormatException( 'The index lists more packs than it can number.' );
			}
			$this->packs[]              = $raw;
			$this->pack_numbers[ $raw ] = $number;
		}

		foreach ( $entries as $entry ) {
			$id     = $entry->id->bytes();
			$bucket = ( ord( $id[ $this->high ] ) << 8 ) | ord( $id[ $this->low ] );
			$record = $id . pack( 'V4', $number, $entry->offset, $entry->length, $entry->uncompressed_length );
			if ( BlobType::Tree === $entry->type ) {
				$records = $this->trees[ $bucket ] ?? '';
				if ( null === self::position( $records, $id ) ) {
					$this->trees[ $bucket ] = $records . $record;
					++$this->count;
				}
				continue;
			}
			$records = $this->data[ $bucket ] ?? '';
			if ( null === self::position( $records, $id ) ) {
				$this->data[ $bucket ] = $records . $record;
				++$this->count;
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
		$raw     = $id->bytes();
		$bucket  = ( ord( $raw[ $this->high ] ) << 8 ) | ord( $raw[ $this->low ] );
		$records = BlobType::Tree === $type ? ( $this->trees[ $bucket ] ?? '' ) : ( $this->data[ $bucket ] ?? '' );
		return null !== self::position( $records, $raw );
	}

	/**
	 * Finds a blob.
	 *
	 * @param Id       $id   Blob ID.
	 * @param BlobType $type Blob type.
	 * @return BlobLocation|null Location, or null if unknown.
	 * @throws FormatException If the record names a pack the index does not have.
	 */
	public function find( Id $id, BlobType $type ): ?BlobLocation {
		$raw      = $id->bytes();
		$bucket   = ( ord( $raw[ $this->high ] ) << 8 ) | ord( $raw[ $this->low ] );
		$records  = BlobType::Tree === $type ? ( $this->trees[ $bucket ] ?? '' ) : ( $this->data[ $bucket ] ?? '' );
		$position = self::position( $records, $raw );
		return null === $position ? null : $this->location( $type, $records, $position );
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
		return $this->count;
	}

	/**
	 * Every blob with its location, tree blobs first, in no particular order within a type.
	 *
	 * @return \Generator<int, BlobLocation> Locations.
	 */
	public function locations(): \Generator {
		foreach ( [ [ BlobType::Tree, $this->trees ], [ BlobType::Data, $this->data ] ] as [ $type, $buckets ] ) {
			foreach ( $buckets as $records ) {
				$bytes = strlen( $records );
				for ( $position = 0; $position < $bytes; $position += self::RECORD ) {
					yield $this->location( $type, $records, $position );
				}
			}
		}
	}

	/**
	 * Serializes the index for the local cache in pieces of about one megabyte, so that the
	 * cache can be written without a second copy of the index in memory. Each piece is
	 * complete in itself; import() takes them in this order.
	 *
	 * The first piece is the magic line, the two bucket positions and the numbers of packs
	 * and blobs. Pieces that begin with "P" hold pack IDs in the order of their numbers.
	 * Pieces that begin with "T" (trees) or "D" (data) hold buckets: the bucket number as
	 * pack( 'v' ), the length of its records as pack( 'V' ), then the records.
	 *
	 * @return \Generator<int, string> Pieces.
	 */
	public function export(): \Generator {
		yield self::MAGIC . pack( 'C2V2', $this->high, $this->low, count( $this->packs ), $this->count );
		foreach ( array_chunk( $this->packs, self::PACKS_PER_PIECE ) as $packs ) {
			yield 'P' . implode( '', $packs );
		}
		foreach ( [ [ 'T', $this->trees ], [ 'D', $this->data ] ] as [ $tag, $buckets ] ) {
			$piece = $tag;
			foreach ( $buckets as $bucket => $records ) {
				$piece .= pack( 'vV', $bucket, strlen( $records ) ) . $records;
				if ( strlen( $piece ) >= self::PIECE_BYTES ) {
					yield $piece;
					$piece = $tag;
				}
			}
			if ( $tag !== $piece ) {
				yield $piece;
			}
		}
	}

	/**
	 * Restores an index from the pieces of export().
	 *
	 * @param iterable<string> $pieces Pieces in the order export() gave them.
	 * @return self Index.
	 * @throws FormatException If a piece is malformed, or if packs or blobs are missing.
	 */
	public static function import( iterable $pieces ): self {
		$index    = null;
		$expected = [ 0, 0 ];
		foreach ( $pieces as $piece ) {
			if ( null === $index ) {
				$fields = strlen( $piece ) === strlen( self::MAGIC ) + 10 && str_starts_with( $piece, self::MAGIC ) ? unpack( 'Chigh/Clow/Vpacks/Vblobs', $piece, strlen( self::MAGIC ) ) : false;
				if ( ! is_array( $fields ) ) {
					throw new FormatException( 'The cached index is not recognised.' );
				}
				$index    = new self( $fields['high'], $fields['low'] );
				$expected = [ $fields['packs'], $fields['blobs'] ];
				continue;
			}
			match ( $piece[0] ?? '' ) {
				'P'     => $index->import_packs( $piece ),
				'T'     => $index->import_buckets( $piece, true ),
				'D'     => $index->import_buckets( $piece, false ),
				default => throw new FormatException( 'The cached index holds an unknown piece.' ),
			};
		}
		if ( null === $index ) {
			throw new FormatException( 'The cached index is empty.' );
		}
		if ( count( $index->packs ) !== $expected[0] || $index->count !== $expected[1] ) {
			throw new FormatException( 'The cached index is incomplete.' );
		}
		return $index;
	}

	/**
	 * Takes the pack IDs of one piece.
	 *
	 * @param string $piece "P" and the IDs.
	 * @throws FormatException If the piece is malformed or repeats a pack.
	 */
	private function import_packs( string $piece ): void {
		if ( 1 !== strlen( $piece ) % self::ID_BYTES ) {
			throw new FormatException( 'The cached index holds a truncated pack list.' );
		}
		foreach ( 1 === strlen( $piece ) ? [] : str_split( substr( $piece, 1 ), self::ID_BYTES ) as $raw ) {
			if ( isset( $this->pack_numbers[ $raw ] ) ) {
				throw new FormatException( 'The cached index lists a pack twice.' );
			}
			$this->pack_numbers[ $raw ] = count( $this->packs );
			$this->packs[]              = $raw;
		}
	}

	/**
	 * Takes the buckets of one piece.
	 *
	 * @param string $piece "T" or "D" and the buckets.
	 * @param bool   $trees Whether the buckets hold tree blobs.
	 * @throws FormatException If the piece is malformed or repeats a bucket.
	 */
	private function import_buckets( string $piece, bool $trees ): void {
		$length = strlen( $piece );
		$offset = 1;
		while ( $offset < $length ) {
			$fields = $offset + 6 <= $length ? unpack( 'vbucket/Vbytes', $piece, $offset ) : false;
			if ( ! is_array( $fields ) || 0 === $fields['bytes'] || 0 !== $fields['bytes'] % self::RECORD || $offset + 6 + $fields['bytes'] > $length ) {
				throw new FormatException( 'The cached index holds a damaged bucket.' );
			}
			$bucket = $fields['bucket'];
			if ( array_key_exists( $bucket, $trees ? $this->trees : $this->data ) ) {
				throw new FormatException( 'The cached index holds a bucket twice.' );
			}
			$records = substr( $piece, $offset + 6, $fields['bytes'] );
			if ( $trees ) {
				$this->trees[ $bucket ] = $records;
			} else {
				$this->data[ $bucket ] = $records;
			}
			$this->count += intdiv( $fields['bytes'], self::RECORD );
			$offset      += 6 + $fields['bytes'];
		}
	}

	/**
	 * Builds the location of the record at a position.
	 *
	 * @param BlobType $type     Blob type of the bucket.
	 * @param string   $records  Records of a bucket.
	 * @param int      $position Start of the record.
	 * @return BlobLocation Location.
	 * @throws FormatException If the record names a pack the index does not have.
	 */
	private function location( BlobType $type, string $records, int $position ): BlobLocation {
		$fields = unpack( 'Vpack/Voffset/Vlength/Vuncompressed', $records, $position + self::ID_BYTES );
		$pack   = is_array( $fields ) ? ( $this->packs[ $fields['pack'] ] ?? null ) : null;
		if ( ! is_array( $fields ) || null === $pack ) {
			throw new FormatException( 'The index names a pack it does not list.' );
		}
		return new BlobLocation(
			Id::from_bytes( $pack ),
			new PackEntry( $type, Id::from_bytes( substr( $records, $position, self::ID_BYTES ) ), $fields['offset'], $fields['length'], $fields['uncompressed'] )
		);
	}

	/**
	 * Finds the record of an ID among the records of a bucket.
	 *
	 * @param string $records Concatenated records.
	 * @param string $id      Raw blob ID.
	 * @return int|null Start of the record, or null if the bucket does not hold the ID.
	 */
	private static function position( string $records, string $id ): ?int {
		// A match that does not start a record lies inside the numbers or across two records.
		for ( $found = strpos( $records, $id ); false !== $found; $found = strpos( $records, $id, $found + 1 ) ) {
			if ( 0 === $found % self::RECORD ) {
				return $found;
			}
		}
		return null;
	}
}
