<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * The text of a large index file as restic writes it, built without decoding any entry.
 *
 * restic 0.14.0 to 0.16.x close an index file of a compressed repository at
 * indexMaxBlobsCompressed = 3 * indexMaxBlobs = 150,000 blobs (internal/repository/index.go
 * at v0.14.0, internal/index/index.go at v0.16.0); later releases are back at 50,000. restic
 * writes the file with json.Encoder: one line without spaces, the members of a blob in the
 * order id, type, offset, length, uncompressed_length, and a newline at the end. With random
 * IDs such a file is about 22 MiB of JSON and 7.5 MiB as zstd at restic's default level.
 */
final class ResticIndexFile {

	/** Blobs in a full index file of restic 0.14.0 to 0.16.x in a compressed repository. */
	public const int COMPRESSED_BLOBS = 150000;

	/**
	 * Builds the JSON of an index file with packs of 40 blobs and random IDs and sizes.
	 *
	 * @param int $blobs Number of blobs.
	 * @return string JSON.
	 */
	public static function json( int $blobs ): string {
		$json = '{"packs":[';
		for ( $done = 0; $done < $blobs; ) {
			$json  .= ( 0 === $done ? '' : ',' ) . '{"id":"' . bin2hex( random_bytes( 32 ) ) . '","blobs":[';
			$offset = 0;
			for ( $i = 0; $i < 40 && $done < $blobs; ++$i, ++$done ) {
				$tree         = 0 === $done % 12;
				$uncompressed = $tree ? random_int( 300, 60000 ) : random_int( 200, 1048576 );
				$length       = 32 + intdiv( $uncompressed * random_int( 30, 100 ), 100 );
				$json        .= ( 0 === $i ? '' : ',' ) . sprintf( '{"id":"%s","type":"%s","offset":%d,"length":%d,"uncompressed_length":%d}', bin2hex( random_bytes( 32 ) ), $tree ? 'tree' : 'data', $offset, $length, $uncompressed );
				$offset      += $length;
			}
			$json .= ']}';
		}
		return $json . "]}\n";
	}
}
