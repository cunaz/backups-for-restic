<?php
/**
 * Disk-backed set of packs reported by the server.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Prune;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * An open-addressed hash table with fixed-size slots. A private random salt prevents a server
 * from choosing colliding pack names. The listing's byte size bounds the number of names,
 * so the table remains at most half full without holding any set of IDs in PHP memory.
 *
 * Each slot holds a flag, the binary ID and a signed size. New payloads are flushed before
 * their occupied flag is written. Replaying an interrupted listing batch is idempotent; the
 * last duplicate wins, just as with Backend::list(). Classification starts only after the
 * complete listing has been validated. No deletion is allowed during insertion.
 */
final class PackTable {

	private const string MAGIC     = 'BFRPACK1';
	private const int HEADER_BYTES = 28;
	private const int SLOT_BYTES   = 41;
	private const int MAX_CAPACITY = 4194304;
	/**
	 * The open table file.
	 *
	 * @var WorkFile
	 */
	private readonly WorkFile $file;

	/**
	 * Number of slots, always a power of two.
	 *
	 * @var int
	 */
	private readonly int $capacity;

	/**
	 * Private hash salt persisted in the header.
	 *
	 * @var string
	 */
	private readonly string $salt;

	/**
	 * Opens and validates an existing table.
	 *
	 * @param string $path Table path.
	 * @throws StorageFailed If the file is missing or malformed.
	 */
	public function __construct( string $path ) {
		$this->file = new WorkFile( $path );
		$header     = $this->file->read( self::HEADER_BYTES );
		if ( strlen( $header ) !== self::HEADER_BYTES || substr( $header, 0, 8 ) !== self::MAGIC ) {
			$this->invalid();
		}
		$decoded  = unpack( 'Vcapacity', substr( $header, 8, 4 ) );
		$capacity = false === $decoded ? 0 : $decoded['capacity'];
		if ( $capacity < 16 || $capacity > self::MAX_CAPACITY || 0 !== ( $capacity & ( $capacity - 1 ) ) || $this->file->size() !== self::HEADER_BYTES + $capacity * self::SLOT_BYTES ) {
			$this->invalid();
		}
		$this->capacity = $capacity;
		$this->salt     = substr( $header, 12, 16 );
	}

	/**
	 * Creates an empty table atomically. The caller enters its prepare phase before replacing
	 * an old table, so a killed request cannot reuse old cursors with a new file.
	 *
	 * @param string $path          Table path.
	 * @param int    $listing_bytes Size of the downloaded listing.
	 * @throws StorageFailed If the table cannot be created or the listing exceeds its bound.
	 */
	public static function create( string $path, int $listing_bytes ): void {
		$capacity = 16;
		while ( $capacity < 2 * intdiv( $listing_bytes, 64 ) && $capacity < self::MAX_CAPACITY ) {
			$capacity *= 2;
		}
		if ( $listing_bytes < 0 || $listing_bytes > 134217728 ) {
			StorageFailed::raise( 'The downloaded pack listing is too large for its working table.' );
		}
		$temporary = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		try {
			$file = new WorkFile( $temporary, true );
			$file->write( self::MAGIC . pack( 'V', $capacity ) . random_bytes( 16 ) );
			$file->truncate( self::HEADER_BYTES + $capacity * self::SLOT_BYTES );
			$file->flush();
			$file->close();
			if ( ! rename( $temporary, $path ) ) {
				StorageFailed::raise( 'The pack table could not be replaced.' );
			}
		} finally {
			if ( is_file( $temporary ) && ! unlink( $temporary ) ) {
				StorageFailed::raise( 'An incomplete pack table could not be removed.' );
			}
		}
	}

	/**
	 * Inserts or updates a pack. The caller flushes before advancing its byte cursor.
	 *
	 * @param string $name Hex pack ID.
	 * @param int    $size Size, or -1 for v1 listings.
	 */
	public function put( string $name, int $size ): void {
		$id   = Id::from_hex( $name )->bytes();
		$hash = unpack( 'Vslot', hash_hmac( 'sha256', $id, $this->salt, true ) );
		$slot = ( false === $hash ? 0 : $hash['slot'] ) & ( $this->capacity - 1 );
		// At most half full with random placement: 512 probes is far beyond an ordinary
		// collision chain, and prevents a damaged local table from stalling a tick forever.
		$probes = min( 512, $this->capacity );
		for ( $probe = 0; $probe < $probes; ++$probe ) {
			$record = $this->record( $slot );
			if ( "\0" === $record[0] ) {
				$this->file->seek( $this->offset( $slot ) + 1 );
				$this->file->write( $id . pack( 'P', $size ) );
				$this->file->flush();
				$this->file->seek( $this->offset( $slot ) );
				$this->file->write( "\1" );
				return;
			}
			if ( substr( $record, 1, 32 ) === $id ) {
				$this->file->seek( $this->offset( $slot ) + 33 );
				$this->file->write( pack( 'P', $size ) );
				return;
			}
			++$slot;
			$slot &= $this->capacity - 1;
		}
		$this->invalid();
	}

	/**
	 * Reads at most a bounded number of slots, including empty ones.
	 *
	 * @param int $position Slot cursor.
	 * @param int $limit    Maximum slots.
	 * @return array{entries: list<array{slot: int, name: string, size: int, unindexed: bool}>, position: int, done: bool} Batch.
	 */
	public function batch( int $position, int $limit = 256 ): array {
		if ( $position < 0 || $position > $this->capacity ) {
			$this->invalid();
		}
		$entries = [];
		$end     = min( $this->capacity, $position + max( 1, $limit ) );
		for ( ; $position < $end; ++$position ) {
			$record = $this->record( $position );
			if ( "\0" === $record[0] ) {
				continue;
			}
			$decoded = unpack( 'Psize', substr( $record, 33, 8 ) );
			$size    = false === $decoded ? null : $decoded['size'];
			if ( ! is_int( $size ) || $size < -1 ) {
				$this->invalid();
			}
			$entries[] = [
				'slot'      => $position,
				'name'      => bin2hex( substr( $record, 1, 32 ) ),
				'size'      => $size,
				'unindexed' => "\2" === $record[0],
			];
		}
		return [
			'entries'  => $entries,
			'position' => $position,
			'done'     => $position === $this->capacity,
		];
	}

	/**
	 * Marks a fully parsed entry as unindexed. Repeated classification is harmless.
	 *
	 * @param int $slot Occupied slot.
	 */
	public function mark_unindexed( int $slot ): void {
		if ( "\0" === $this->record( $slot )[0] ) {
			$this->invalid();
		}
		$this->file->seek( $this->offset( $slot ) );
		$this->file->write( "\2" );
	}

	/** Flushes and closes before the caller checkpoints its cursor. */
	public function close(): void {
		$this->file->flush();
		$this->file->close();
	}

	/**
	 * Reads a complete slot and validates its occupancy flag.
	 *
	 * @param int $slot Slot number.
	 * @return string Fixed-size record.
	 */
	private function record( int $slot ): string {
		$this->file->seek( $this->offset( $slot ) );
		$record = $this->file->read( self::SLOT_BYTES );
		if ( strlen( $record ) !== self::SLOT_BYTES || ! in_array( $record[0], [ "\0", "\1", "\2" ], true ) ) {
			$this->invalid();
		}
		return $record;
	}

	/**
	 * Bounds a slot number before seeking.
	 *
	 * @param int $slot Slot number.
	 * @return int Byte offset.
	 */
	private function offset( int $slot ): int {
		if ( $slot < 0 || $slot >= $this->capacity ) {
			$this->invalid();
		}
		return self::HEADER_BYTES + $slot * self::SLOT_BYTES;
	}

	/**
	 * Refuses to use a malformed table as deletion authority.
	 *
	 * @throws StorageFailed Always.
	 */
	private function invalid(): never {
		StorageFailed::raise( 'The pack listing working table is incomplete or invalid.' );
	}
}
