<?php
/**
 * Bounded grouped range requests for consecutive file blobs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\InsufficientDecodingMemory;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackReader;

defined( 'ABSPATH' ) || exit;

/**
 * Groups only contiguous stored ranges in file order. Every member authenticates separately.
 * A group is as large as the memory left can take, at most MAX_BYTES and MAX_BLOBS (ADR 0036).
 */
final class BlobBatch {

	public const int MAX_BYTES = 16777216;
	public const int MAX_BLOBS = 128;

	/**
	 * The largest stored length of a data blob: a chunk of at most 8 MiB, encrypted, and when
	 * it is compressed, in a zstd frame no larger than libzstd's bound for 8 MiB. restic
	 * compresses every data blob of a compressed repository, also one that does not compress;
	 * a full chunk of such data is then stored in raw blocks, 202 bytes longer than itself.
	 *
	 * @param PackEntry $entry Index entry of a data blob.
	 * @return int Bytes.
	 */
	public static function max_length( PackEntry $entry ): int {
		return ( 0 === $entry->uncompressed_length ? RabinChunker::MAX_SIZE : Compression::bound( RabinChunker::MAX_SIZE ) ) + Cipher::OVERHEAD;
	}

	/**
	 * Downloads one bounded group and hands its blobs, verified, to the writer one at a time.
	 *
	 * With a memory budget the group also stays within what the memory left can take
	 * (MemoryBudget::range()): it ends before a blob that would not fit, and a first blob that
	 * does not fit by itself is refused before anything is downloaded. Only when the group
	 * would be cut or refused does PHP first give back what it keeps for reuse. Once the group
	 * has arrived, and other code kept memory meanwhile, the memory left is measured again
	 * before each blob is opened, and a blob whose opening no longer fits is refused instead of
	 * running into PHP's limit. A blob's bytes are let go once the writer returns, and the
	 * group once its last blob is taken out.
	 *
	 * @param Repository                  $repository Locked repository.
	 * @param IndexSet                    $index Current index loaded under that lock.
	 * @param list<Id>                    $content The file's ordered content IDs.
	 * @param int                         $first First uncommitted blob ordinal.
	 * @param \Closure(int, string): void $write Takes the blob's ordinal and its authenticated bytes, in file order; keeps no reference to them.
	 * @throws FormatException If a location, size, response or content hash is invalid.
	 * @throws UnsupportedRepository If a blob is compressed and PHP lacks the zstd extension.
	 * @throws IndexTooLarge With the code restore_blob_memory if the first blob does not fit into the memory left, or a blob no longer does once the group has arrived.
	 */
	public static function read( Repository $repository, IndexSet $index, array $content, int $first, \Closure $write ): void {
		if ( $first < 0 || ! isset( $content[ $first ] ) ) {
			throw new FormatException( 'The restore blob cursor is outside its file.' );
		}
		$memory    = $repository->memory();
		$room      = PHP_INT_MAX;
		$given     = false;
		$locations = [];
		$pack      = null;
		$offset    = 0;
		$length    = 0;
		// Of the blobs before the latest one, the one whose opening takes the most.
		$before = null;
		$latest = null;
		if ( null !== $memory ) {
			$room = $memory->measure();
		}
		for ( $number = $first; isset( $content[ $number ] ) && $number - $first < self::MAX_BLOBS; ++$number ) {
			$location = $index->find( $content[ $number ], BlobType::Data );
			if ( null === $location ) {
				throw new FormatException( 'A selected file references a missing data blob.' );
			}
			$entry = $location->entry;
			if ( $entry->length < Cipher::OVERHEAD || $entry->length > self::max_length( $entry ) || $entry->uncompressed_length > RabinChunker::MAX_SIZE || $entry->offset < 0 || $entry->offset > PHP_INT_MAX - $entry->length ) {
				throw new FormatException( 'A selected file has an invalid or oversized data blob.' );
			}
			if ( $entry->uncompressed_length > 0 && ! Compression::available() ) {
				// Refused before the memory left is asked for a decoding that cannot happen.
				throw new UnsupportedRepository( 'The repository contains compressed data and PHP lacks the zstd extension.' );
			}
			if ( null !== $pack && ( ! $pack->equals( $location->pack ) || $entry->offset !== $offset + $length || $entry->length > self::MAX_BYTES - $length ) ) {
				break;
			}
			$prior = null;
			if ( null !== $memory ) {
				$prior = MemoryBudget::larger( $before, $latest );
				$need  = MemoryBudget::range( $length + $entry->length, $prior, $entry, $room );
				if ( $need > $room && ! $given ) {
					$given = true;
					$room  = $memory->measure( true );
					$need  = MemoryBudget::range( $length + $entry->length, $prior, $entry, $room );
				}
				if ( $need > $room ) {
					if ( null === $pack ) {
						MemoryBudget::refuse_blob(
							[
								'memory_limit' => $memory->limit,
								'available'    => $room,
								'blob_bytes'   => $need,
							]
						);
					}
					break;
				}
			}
			if ( null === $pack ) {
				$pack   = $location->pack;
				$offset = $entry->offset;
			}
			$locations[ $number ] = $entry;
			$length              += $entry->length;
			$before               = $prior;
			$latest               = $entry;
		}
		if ( null === $pack ) {
			throw new FormatException( 'The restore could not plan a data range.' );
		}
		$reserved = memory_get_usage( true );
		$used     = memory_get_usage();
		$sealed   = $repository->backend()->load( ObjectType::Data, $pack->hex(), $offset, $length );
		if ( strlen( $sealed ) !== $length ) {
			throw new FormatException( 'A restore range response has an unexpected length.' );
		}
		// When no other code kept memory while the response arrived, the plan holds.
		$alone = null === $memory || MemoryBudget::arrived_alone( $reserved, $used, $length );
		$final = array_key_last( $locations );
		foreach ( $locations as $number => $entry ) {
			$opening = null !== $memory && ! $alone ? self::still_fits( $memory, $entry, $length, $number === $final ) : MemoryBudget::rest( $entry, $length, $number === $final );
			try {
				$plain = PackReader::open_blob( $repository->cipher(), $entry, MemoryBudget::slice( $sealed, $entry->offset - $offset, $entry->length, $number === $final ) );
			} catch ( InsufficientDecodingMemory $error ) {
				// Not damage: decoding takes more than the budget counted for the blob's entry, as for
				// a frame without a declared size and with a large window, or other code took memory.
				MemoryBudget::refuse_blob( [ 'blob_bytes' => $opening ], $error );
			}
			if ( strlen( $plain ) > RabinChunker::MAX_SIZE ) {
				throw new FormatException( 'A restored data blob exceeds the chunk size limit.' );
			}
			$write( $number, $plain );
			unset( $plain );
		}
	}

	/**
	 * Measures again, once the group has arrived, whether opening the next blob fits into the
	 * memory left (MemoryBudget::rest()): other code may have taken memory while the response
	 * arrived.
	 *
	 * @param MemoryBudget $memory The job's budget.
	 * @param PackEntry    $entry  The blob.
	 * @param int          $group  Bytes of the group, which is held.
	 * @param bool         $last   Whether the blob is the group's last one.
	 * @return int What opening the blob still takes.
	 * @throws IndexTooLarge With the code restore_blob_memory if it no longer fits.
	 */
	private static function still_fits( MemoryBudget $memory, PackEntry $entry, int $group, bool $last ): int {
		$room = $memory->measure();
		$need = MemoryBudget::rest( $entry, $group, $last, $room );
		if ( $need > $room ) {
			$room = $memory->measure( true );
			$need = MemoryBudget::rest( $entry, $group, $last, $room );
			if ( $need > $room ) {
				MemoryBudget::refuse_blob(
					[
						'memory_limit' => $memory->limit,
						'available'    => $room,
						'blob_bytes'   => $need,
					]
				);
			}
		}
		return $need;
	}
}
