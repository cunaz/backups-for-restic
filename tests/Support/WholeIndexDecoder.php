<?php
/**
 * The index file decoder as it was before files were decoded pack by pack.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Support\GoJson;

/**
 * Decodes an index file in one go, with the strict JSON decoder over the whole text. It needs
 * several times the memory of IndexFile::packs() and exists as the reference that the
 * pack-by-pack decoder is compared with, in unit tests and by the fuzzer: for every input
 * both must give the same packs, or both must refuse it.
 */
final class WholeIndexDecoder {

	/**
	 * Decodes an index file.
	 *
	 * @param string $json JSON.
	 * @return array<string, list<PackEntry>> Entries by hex pack ID.
	 * @throws FormatException If the structure is invalid.
	 */
	public static function decode( string $json ): array {
		$packs = GoJson::decode_object( $json )['packs'] ?? null;
		if ( ! is_array( $packs ) ) {
			throw new FormatException( 'Index file has no pack list.' );
		}
		// restic's decoder refuses an object where it expects the list of packs.
		if ( ! array_is_list( $packs ) ) {
			throw new FormatException( 'Index file contains an invalid pack.' );
		}
		$result = [];
		foreach ( $packs as $pack ) {
			if ( ! is_array( $pack ) || ( [] !== $pack && array_is_list( $pack ) ) || ! is_string( $pack['id'] ?? null ) || ! is_array( $pack['blobs'] ?? null ) ) {
				throw new FormatException( 'Index file contains an invalid pack.' );
			}
			$pack_id = Id::from_hex( $pack['id'] )->hex();
			$entries = $result[ $pack_id ] ?? [];
			foreach ( $pack['blobs'] as $blob ) {
				if ( ! is_array( $blob ) || ! is_string( $blob['id'] ?? null ) || ! is_int( $blob['offset'] ?? null ) || ! is_int( $blob['length'] ?? null ) ) {
					throw new FormatException( 'Index file contains an invalid blob.' );
				}
				$type         = BlobType::tryFrom( is_string( $blob['type'] ?? null ) ? $blob['type'] : '' );
				$uncompressed = $blob['uncompressed_length'] ?? 0;
				if ( null === $type || ! is_int( $uncompressed ) || $blob['offset'] < 0 || $blob['length'] <= 0 || $uncompressed < 0 ) {
					throw new FormatException( 'Index file contains an invalid blob.' );
				}
				$entries[] = new PackEntry( $type, Id::from_hex( $blob['id'] ), $blob['offset'], $blob['length'], $uncompressed );
			}
			$result[ $pack_id ] = $entries;
		}
		return $result;
	}

	/**
	 * Decodes an index file both ways and compares the outcomes.
	 *
	 * @param string $json JSON.
	 * @return array<string, list<PackEntry>> What both decoders gave.
	 * @throws FormatException If both decoders refuse the input.
	 * @throws \LogicException If one decoder accepts what the other refuses, or they give different packs.
	 */
	public static function compare( string $json ): array {
		$whole = null;
		try {
			$whole = self::decode( $json );
		} catch ( FormatException $refused ) {
			$whole = $refused;
		}
		try {
			$by_pack = \BackupsForRestic\Format\Index\IndexFile::decode( $json );
		} catch ( FormatException $refused ) {
			if ( $whole instanceof FormatException ) {
				throw $refused;
			}
			throw new \LogicException( 'The pack-by-pack decoder refuses an index file that is valid: ' . $refused->getMessage(), 0, $refused );
		}
		if ( $whole instanceof FormatException ) {
			throw new \LogicException( 'The pack-by-pack decoder accepts an index file that is invalid: ' . $whole->getMessage(), 0, $whole );
		}
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Value objects are compared by their fields.
		if ( $whole != $by_pack ) {
			throw new \LogicException( 'The pack-by-pack decoder gives other packs than the whole-document decoder.' );
		}
		return $by_pack;
	}
}
