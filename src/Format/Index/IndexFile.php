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

	/**
	 * Encodes packs into an index file.
	 *
	 * @param array<string, list<PackEntry>> $packs Entries by hex pack ID (keys must be strings).
	 * @return string JSON.
	 */
	public static function encode( array $packs ): string {
		$list = [];
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
			$list[] = [
				'id'    => $pack_id,
				'blobs' => $blobs,
			];
		}
		// restic writes index files with json.Encoder, which appends a newline.
		return GoJson::encode( [ 'packs' => $list ] ) . "\n";
	}

	/**
	 * Decodes an index file.
	 *
	 * @param string $json JSON.
	 * @return array<string, list<PackEntry>> Entries by hex pack ID.
	 * @throws FormatException If the structure is invalid.
	 */
	public static function decode( string $json ): array {
		$data  = GoJson::decode_object( $json );
		$packs = $data['packs'] ?? null;
		if ( ! is_array( $packs ) ) {
			throw new FormatException( 'Index file has no pack list.' );
		}

		$result = [];
		foreach ( $packs as $pack ) {
			if ( ! is_array( $pack ) || ! is_string( $pack['id'] ?? null ) || ! is_array( $pack['blobs'] ?? null ) ) {
				throw new FormatException( 'Index file contains an invalid pack.' );
			}
			$pack_id = Id::from_hex( $pack['id'] )->hex();
			$entries = $result[ $pack_id ] ?? [];
			foreach ( $pack['blobs'] as $blob ) {
				$entries[] = self::decode_blob( $blob );
			}
			$result[ $pack_id ] = $entries;
		}
		return $result;
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
