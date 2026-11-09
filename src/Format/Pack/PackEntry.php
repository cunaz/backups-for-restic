<?php
/**
 * Location of a blob inside a pack.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Pack;

use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;

defined( 'ABSPATH' ) || exit;

/**
 * One header entry: type, ID, position and stored (encrypted) length of a blob.
 * uncompressed_length is non-zero only for compressed blobs of repository version 2.
 */
final readonly class PackEntry {

	/**
	 * Creates an entry.
	 *
	 * @param BlobType $type                Blob type.
	 * @param Id       $id                  SHA-256 of the plaintext.
	 * @param int      $offset              Offset in the pack.
	 * @param int      $length              Stored length (nonce + ciphertext + tag).
	 * @param int      $uncompressed_length Plaintext length of a compressed blob, else 0.
	 */
	public function __construct(
		public BlobType $type,
		public Id $id,
		public int $offset,
		public int $length,
		public int $uncompressed_length = 0
	) {
	}
}
