<?php
/**
 * A complete pack file.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Pack;

use BackupsForRestic\Format\Id;

defined( 'ABSPATH' ) || exit;

/**
 * Bytes of a finished pack, its ID (SHA-256 of the bytes) and the entries for the index.
 */
final readonly class FinishedPack {

	/**
	 * Creates the value.
	 *
	 * @param Id              $id      Pack ID.
	 * @param string          $bytes   File content.
	 * @param list<PackEntry> $entries Blobs in the pack.
	 */
	public function __construct( public Id $id, public string $bytes, public array $entries ) {
	}
}
