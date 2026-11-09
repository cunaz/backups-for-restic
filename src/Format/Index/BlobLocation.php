<?php
/**
 * Where a blob is stored.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Index;

use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Pack\PackEntry;

defined( 'ABSPATH' ) || exit;

/**
 * A blob's pack and its entry in that pack.
 */
final readonly class BlobLocation {

	/**
	 * Creates the value.
	 *
	 * @param Id        $pack  Pack ID.
	 * @param PackEntry $entry Entry inside the pack.
	 */
	public function __construct( public Id $pack, public PackEntry $entry ) {
	}
}
