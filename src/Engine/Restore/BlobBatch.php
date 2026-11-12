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
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackReader;

defined( 'ABSPATH' ) || exit;

/**
 * Groups only contiguous stored ranges in file order. Every member authenticates separately.
 */
final class BlobBatch {

	public const int MAX_BYTES = 16777216;
	public const int MAX_BLOBS = 128;

	/**
	 * Downloads one bounded group, yielding verified plaintext one blob at a time.
	 *
	 * @param Repository $repository Locked repository.
	 * @param IndexSet   $index Current index loaded under that lock.
	 * @param list<Id>   $content The file's ordered content IDs.
	 * @param int        $first First uncommitted blob ordinal.
	 * @return \Generator<int, string> Blob ordinal to authenticated bytes.
	 * @throws FormatException If a location, size, response or content hash is invalid.
	 */
	public static function read( Repository $repository, IndexSet $index, array $content, int $first ): \Generator {
		if ( $first < 0 || ! isset( $content[ $first ] ) ) {
			throw new FormatException( 'The restore blob cursor is outside its file.' );
		}
		$locations = [];
		$pack      = null;
		$offset    = 0;
		$length    = 0;
		for ( $number = $first; isset( $content[ $number ] ) && $number - $first < self::MAX_BLOBS; ++$number ) {
			$location = $index->find( $content[ $number ], BlobType::Data );
			if ( null === $location ) {
				throw new FormatException( 'A selected file references a missing data blob.' );
			}
			$entry = $location->entry;
			if ( $entry->length < Cipher::OVERHEAD || $entry->length > RabinChunker::MAX_SIZE + Cipher::OVERHEAD || $entry->uncompressed_length > RabinChunker::MAX_SIZE || $entry->offset < 0 || $entry->offset > PHP_INT_MAX - $entry->length ) {
				throw new FormatException( 'A selected file has an invalid or oversized data blob.' );
			}
			if ( null === $pack ) {
				$pack   = $location->pack;
				$offset = $entry->offset;
			} elseif ( ! $pack->equals( $location->pack ) || $entry->offset !== $offset + $length || $entry->length > self::MAX_BYTES - $length ) {
				break;
			}
			$locations[ $number ] = $entry;
			$length              += $entry->length;
		}
		if ( null === $pack ) {
			throw new FormatException( 'The restore could not plan a data range.' );
		}
		$sealed = $repository->backend()->load( ObjectType::Data, $pack->hex(), $offset, $length );
		if ( strlen( $sealed ) !== $length ) {
			throw new FormatException( 'A restore range response has an unexpected length.' );
		}
		foreach ( $locations as $number => $entry ) {
			$plain = PackReader::open_blob( $repository->cipher(), $entry, substr( $sealed, $entry->offset - $offset, $entry->length ) );
			if ( strlen( $plain ) > RabinChunker::MAX_SIZE ) {
				throw new FormatException( 'A restored data blob exceeds the chunk size limit.' );
			}
			yield $number => $plain;
		}
	}
}
