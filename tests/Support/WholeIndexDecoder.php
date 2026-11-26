<?php
/**
 * The index file decoder that reads a file as a whole, as it was done before files were decoded pack by pack.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;

/**
 * Decodes an index file in one go, with PHP's JSON decoder over the whole text and the limits
 * of the strict decoder. It needs several times the memory of IndexFile::packs() and exists
 * as the reference that the pack-by-pack decoder is compared with, in unit tests and by the
 * fuzzer: for every input both must give the same packs, or both must refuse it.
 *
 * The text is decoded into objects, not into arrays. As arrays, {} and [] are the same value
 * and so are {"0":true} and [true]; restic refuses an object where its format has a list, and
 * a reference that cannot tell them apart accepts index files that restic does not read.
 */
final class WholeIndexDecoder {

	/** The nesting GoJson::decode() accepts. */
	private const int MAX_DEPTH = 64;

	/**
	 * Decodes an index file.
	 *
	 * @param string $json JSON.
	 * @return array<string, list<PackEntry>> Entries by hex pack ID.
	 * @throws FormatException If the structure is invalid.
	 */
	public static function decode( string $json ): array {
		// PHP has no property whose name begins with a NUL character and refuses such a key
		// in an object, which Go and the array decoder accept as any other unknown member.
		// The character is therefore replaced before decoding. In a valid document a quote is
		// followed by this escape only at the start of a string or after an escaped quote
		// inside one; no member name, ID or blob type of the format holds either character, so
		// what was unknown or invalid stays so.
		try {
			$document = json_decode( str_replace( '"\\u0000', '"\\u0001', $json ), false, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $exception ) {
			FormatException::raise( 'Repository object is not valid JSON.', [ 'json_error' => $exception->getMessage() ], $exception );
		}
		$packs = self::members( $document, 'Repository object is not a JSON object.' )['packs'] ?? null;
		// Only a JSON list is an array here: an object in its place is refused like a number.
		if ( ! is_array( $packs ) ) {
			throw new FormatException( 'Index file has no pack list.' );
		}
		$result = [];
		foreach ( $packs as $pack ) {
			$pack = self::members( $pack, 'Index file contains an invalid pack.' );
			if ( ! is_string( $pack['id'] ?? null ) || ! is_array( $pack['blobs'] ?? null ) ) {
				throw new FormatException( 'Index file contains an invalid pack.' );
			}
			$pack_id = Id::from_hex( $pack['id'] )->hex();
			$entries = $result[ $pack_id ] ?? [];
			foreach ( $pack['blobs'] as $blob ) {
				$blob = self::members( $blob, 'Index file contains an invalid blob.' );
				if ( ! is_string( $blob['id'] ?? null ) || ! is_int( $blob['offset'] ?? null ) || ! is_int( $blob['length'] ?? null ) ) {
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
	 * Returns the members of a decoded JSON object.
	 *
	 * @param mixed  $value   Decoded JSON.
	 * @param string $message What to say if the value is anything else than an object.
	 * @return array<mixed> Members by name.
	 * @throws FormatException If the value is not an object.
	 */
	private static function members( mixed $value, string $message ): array {
		if ( ! $value instanceof \stdClass ) {
			throw new FormatException( $message );
		}
		return get_object_vars( $value );
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
		if ( ! self::same( $whole, $by_pack ) ) {
			throw new \LogicException( 'The pack-by-pack decoder gives other packs than the whole-document decoder.' );
		}
		return $by_pack;
	}

	/**
	 * Whether two results hold the same entries for the same packs, in whatever order of the
	 * packs. The fields are compared strictly: a loose comparison of the value objects compares
	 * two IDs whose bytes both read as numbers, such as 0…01000 and 0…001e3, as those numbers.
	 *
	 * @param array<string, list<PackEntry>> $one   Entries by hex pack ID.
	 * @param array<string, list<PackEntry>> $other Entries by hex pack ID.
	 */
	public static function same( array $one, array $other ): bool {
		return self::fields( $one ) === self::fields( $other );
	}

	/**
	 * The fields of the entries, by pack in the order of the IDs.
	 *
	 * @param array<string, list<PackEntry>> $packs Entries by hex pack ID.
	 * @return array<string, list<array{string, string, int, int, int}>> Type, ID, offset, length and uncompressed length.
	 */
	private static function fields( array $packs ): array {
		$fields = [];
		foreach ( $packs as $pack => $entries ) {
			$fields[ $pack ] = array_map( static fn ( PackEntry $entry ): array => [ $entry->type->value, $entry->id->hex(), $entry->offset, $entry->length, $entry->uncompressed_length ], $entries );
		}
		ksort( $fields, SORT_STRING );
		return $fields;
	}
}
