<?php
/**
 * Pre-encoded JSON fragment.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Embeds JSON that must be reproduced byte for byte, for example node attributes written by
 * restic that the plugin does not interpret.
 */
final readonly class RawJson {

	/**
	 * Creates the fragment.
	 *
	 * @param string $json Valid JSON text.
	 */
	public function __construct( public string $json ) {
	}
}
