<?php
/**
 * Pack assembly.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Pack;

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a pack file in memory.
 *
 * Layout:
 *
 *     sealed blob 1 || … || sealed blob n || sealed header || uint32le(length of sealed header)
 *
 * Each header entry is type (1 byte: 0 = data, 1 = tree) || uint32le(stored length) || ID.
 * The plugin writes uncompressed blobs only. Packs are bounded by the configured pack size, so
 * keeping them in memory matches the WordPress HTTP API, which uploads strings.
 */
final class PackWriter {

	/** Header limit of restic: 16 MiB plus the length field. */
	public const int MAX_HEADER_SIZE = 16777220;

	/** Size of one uncompressed header entry. */
	private const int ENTRY_SIZE = 37;

	/**
	 * Sealed blobs.
	 *
	 * @var string
	 */
	private string $data = '';

	/**
	 * Header entries.
	 *
	 * @var list<PackEntry>
	 */
	private array $entries = [];

	/**
	 * Creates an empty pack.
	 *
	 * @param Cipher $cipher Cipher with the repository master key.
	 */
	public function __construct( private readonly Cipher $cipher ) {
	}

	/**
	 * Encrypts a blob and appends it.
	 *
	 * @param BlobType $type      Blob type.
	 * @param Id       $id        SHA-256 of the plaintext.
	 * @param string   $plaintext Blob content.
	 * @return int Stored length.
	 */
	public function add( BlobType $type, Id $id, string $plaintext ): int {
		$sealed          = $this->cipher->seal( $plaintext );
		$this->entries[] = new PackEntry( $type, $id, strlen( $this->data ), strlen( $sealed ) );
		$this->data     .= $sealed;
		return strlen( $sealed );
	}

	/**
	 * Returns the size the pack has so far.
	 *
	 * @return int Bytes.
	 */
	public function size(): int {
		return strlen( $this->data ) + count( $this->entries ) * self::ENTRY_SIZE + Cipher::OVERHEAD + 4;
	}

	/**
	 * Returns the number of blobs.
	 *
	 * @return int Count.
	 */
	public function count(): int {
		return count( $this->entries );
	}

	/**
	 * Whether another entry would exceed the header limit.
	 *
	 * @return bool True if the pack must be finished.
	 */
	public function header_full(): bool {
		return Cipher::OVERHEAD + 4 + ( count( $this->entries ) + 1 ) * self::ENTRY_SIZE > self::MAX_HEADER_SIZE;
	}

	/**
	 * Appends the encrypted header and returns the finished pack.
	 *
	 * @return FinishedPack Pack bytes, ID and entries.
	 */
	public function finish(): FinishedPack {
		$header = '';
		foreach ( $this->entries as $entry ) {
			$header .= ( BlobType::Tree === $entry->type ? "\x01" : "\x00" ) . Bytes::uint32_le( $entry->length ) . $entry->id->bytes();
		}
		$sealed_header = $this->cipher->seal( $header );
		$bytes         = $this->data . $sealed_header . Bytes::uint32_le( strlen( $sealed_header ) );

		return new FinishedPack( Id::of( $bytes ), $bytes, $this->entries );
	}
}
