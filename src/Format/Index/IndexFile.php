<?php
/**
 * Index files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Index;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted JSON in index/ that maps blobs to packs:
 *
 *     {"packs":[{"id":…,"blobs":[{"id":…,"type":"data","offset":0,"length":…}]}]}
 *
 * Older restic versions also wrote "supersedes", which is accepted and ignored.
 */
final class IndexFile {

	/** Blob count after which restic starts a new index file. */
	public const int MAX_BLOBS = 50000;

	/** The characters JSON allows between its tokens. */
	private const string SPACE = " \t\r\n";

	/**
	 * Encodes packs into an index file.
	 *
	 * Only one pack's decoded JSON is kept beside the output. A generator can therefore
	 * rewrite an index without keeping every decoded blob in memory.
	 *
	 * @param iterable<string, list<PackEntry>> $packs Entries by hex pack ID (keys must be strings).
	 * @return string JSON.
	 */
	public static function encode( iterable $packs ): string {
		$json  = '{"packs":[';
		$comma = '';
		foreach ( $packs as $pack_id => $entries ) {
			$blobs = [];
			foreach ( $entries as $entry ) {
				$blob = [
					'id'     => $entry->id->hex(),
					'type'   => $entry->type->value,
					'offset' => $entry->offset,
					'length' => $entry->length,
				];
				if ( $entry->uncompressed_length > 0 ) {
					$blob['uncompressed_length'] = $entry->uncompressed_length;
				}
				$blobs[] = $blob;
			}
			$json .= $comma . GoJson::encode(
				[
					'id'    => $pack_id,
					'blobs' => $blobs,
				]
			);
			$comma = ',';
		}
		// restic writes index files with json.Encoder, which appends a newline.
		return $json . "]}\n";
	}

	/**
	 * Decodes an index file.
	 *
	 * @param string $json JSON.
	 * @return array<string, list<PackEntry>> Entries by hex pack ID.
	 * @throws FormatException If the structure is invalid.
	 */
	public static function decode( string $json ): array {
		$result = [];
		foreach ( self::packs( $json ) as $pack_id => $entries ) {
			$result[ $pack_id ] = array_merge( $result[ $pack_id ] ?? [], $entries );
		}
		return $result;
	}

	/**
	 * Decodes an index file pack by pack. An index file of 50000 blobs is six megabytes of
	 * JSON and more than 40 megabytes once decoded as a whole; decoded this way, only one
	 * pack's blobs are in memory at a time (ADR 0031).
	 *
	 * The text is first cut into the elements of its "packs" list, without interpreting it.
	 * What is left, with a placeholder in the place of every element, and every element by
	 * itself then go through the same strict decoder as any other repository file, so
	 * whatever is wrong with the file is reported by one of them. An error in a later pack
	 * shows only when the iteration gets there: a caller that cannot use half an index file
	 * must not keep what it got before the exception.
	 *
	 * @param string $json JSON.
	 * @return \Generator<string, list<PackEntry>> Entries by hex pack ID; a pack that is listed twice comes twice.
	 * @throws FormatException If the structure is invalid.
	 */
	public static function packs( string $json ): \Generator {
		[ $rest, $elements ] = self::split( $json );
		$packs               = GoJson::decode_object( $rest )['packs'] ?? null;
		if ( ! is_array( $packs ) ) {
			throw new FormatException( 'Index file has no pack list.' );
		}
		// Every element of the list must be one of the objects that were cut out.
		if ( count( $packs ) !== count( $elements ) || [] !== array_filter( $packs, static fn ( mixed $pack ): bool => true !== $pack ) ) {
			throw new FormatException( 'Index file contains an invalid pack.' );
		}
		unset( $rest, $packs );

		foreach ( $elements as [ $start, $length ] ) {
			$pack = GoJson::decode_object( substr( $json, $start, $length ), 2 );
			if ( ! is_string( $pack['id'] ?? null ) || ! is_array( $pack['blobs'] ?? null ) ) {
				throw new FormatException( 'Index file contains an invalid pack.' );
			}
			$entries = [];
			foreach ( $pack['blobs'] as $blob ) {
				$entries[] = self::decode_blob( $blob );
			}
			yield Id::from_hex( $pack['id'] )->hex() => $entries;
		}
	}

	/**
	 * Finds the objects that are elements of the list under the top-level key "packs" and
	 * returns the text without them. Only quotes, backslashes and brackets are looked at, as
	 * far as that is enough to tell where a string, a list or an object ends; nothing is
	 * decided here about whether the text is valid.
	 *
	 * @param string $json JSON.
	 * @return array{string, list<array{int, int}>} The text with "true" in the place of each element, and start and length of each element.
	 */
	private static function split( string $json ): array {
		$elements = [];
		$position = strspn( $json, self::SPACE );
		if ( '{' === ( $json[ $position ] ?? '' ) ) {
			// The members of the outermost object: a key, or a value that is skipped as a whole.
			for ( ++$position; ; ) {
				$position += strcspn( $json, '"{}[]', $position );
				$char      = $json[ $position ] ?? '';
				$end       = match ( $char ) {
					'"'      => self::string_end( $json, $position ),
					'{', '[' => self::container_end( $json, $position ),
					default  => null,
				};
				if ( null === $end ) {
					break; // The object ends here, or something never closes: the decoder says which.
				}
				$colon = $end + 1 + strspn( $json, self::SPACE, $end + 1 );
				if ( '"' === $char && ':' === ( $json[ $colon ] ?? '' ) ) {
					$value = $colon + 1 + strspn( $json, self::SPACE, $colon + 1 );
					if ( 'packs' === json_decode( substr( $json, $position, $end + 1 - $position ) ) ) {
						// A second member with this key replaces the first one, as for the decoder.
						$elements = '[' === ( $json[ $value ] ?? '' ) ? self::objects_of_list( $json, $value ) : [];
					}
					$position = $value;
					continue;
				}
				$position = $end + 1;
			}
		}

		$rest   = '';
		$copied = 0;
		foreach ( $elements as [ $start, $bytes ] ) {
			$rest  .= substr( $json, $copied, $start - $copied ) . 'true';
			$copied = $start + $bytes;
		}
		return [ $rest . substr( $json, $copied ), $elements ];
	}

	/**
	 * Finds the objects that are direct elements of a list.
	 *
	 * @param string $json  JSON.
	 * @param int    $start Position of the list's opening bracket.
	 * @return list<array{int, int}> Start and length of each object.
	 */
	private static function objects_of_list( string $json, int $start ): array {
		$objects = [];
		for ( $position = $start + 1; ; $position = $end + 1 ) {
			$position += strcspn( $json, '"{}[]', $position );
			$char      = $json[ $position ] ?? '';
			$end       = match ( $char ) {
				'"'      => self::string_end( $json, $position ),
				'{', '[' => self::container_end( $json, $position ),
				default  => null,
			};
			if ( null === $end ) {
				return $objects; // The list ends here, or something never closes.
			}
			if ( '{' === $char ) {
				$objects[] = [ $position, $end + 1 - $position ];
			}
		}
	}

	/**
	 * Finds the bracket that closes an object or a list, by counting brackets outside strings.
	 *
	 * @param string $json  JSON.
	 * @param int    $start Position of the opening bracket.
	 * @return int|null Position of the closing bracket, or null if there is none.
	 */
	private static function container_end( string $json, int $start ): ?int {
		$depth = 0;
		for ( $position = $start; ; ++$position ) {
			$position += strcspn( $json, '"{}[]', $position );
			$char      = $json[ $position ] ?? '';
			if ( '' === $char ) {
				return null;
			}
			if ( '"' === $char ) {
				$position = self::string_end( $json, $position );
				if ( null === $position ) {
					return null;
				}
				continue;
			}
			$depth += '{' === $char || '[' === $char ? 1 : -1;
			if ( 0 === $depth ) {
				return $position;
			}
		}
	}

	/**
	 * Finds the quote that closes a string.
	 *
	 * @param string $json  JSON.
	 * @param int    $start Position of the opening quote.
	 * @return int|null Position of the closing quote, or null if the string never ends.
	 */
	private static function string_end( string $json, int $start ): ?int {
		for ( $quote = strpos( $json, '"', $start + 1 ); false !== $quote; $quote = strpos( $json, '"', $quote + 1 ) ) {
			// A quote after an odd number of backslashes is part of the string.
			$slashes = 0;
			while ( $quote - 1 - $slashes > $start && '\\' === $json[ $quote - 1 - $slashes ] ) {
				++$slashes;
			}
			if ( 0 === $slashes % 2 ) {
				return $quote;
			}
		}
		return null;
	}

	/**
	 * Decodes one blob entry.
	 *
	 * @param mixed $blob Decoded JSON.
	 * @return PackEntry Entry.
	 * @throws FormatException If fields are missing or out of range.
	 */
	private static function decode_blob( mixed $blob ): PackEntry {
		if ( ! is_array( $blob ) || ! is_string( $blob['id'] ?? null ) || ! is_int( $blob['offset'] ?? null ) || ! is_int( $blob['length'] ?? null ) ) {
			throw new FormatException( 'Index file contains an invalid blob.' );
		}
		$type         = BlobType::tryFrom( is_string( $blob['type'] ?? null ) ? $blob['type'] : '' );
		$uncompressed = $blob['uncompressed_length'] ?? 0;
		if ( null === $type || ! is_int( $uncompressed ) || $blob['offset'] < 0 || $blob['length'] <= 0 || $uncompressed < 0 ) {
			throw new FormatException( 'Index file contains an invalid blob.' );
		}
		return new PackEntry( $type, Id::from_hex( $blob['id'] ), $blob['offset'], $blob['length'], $uncompressed );
	}
}
