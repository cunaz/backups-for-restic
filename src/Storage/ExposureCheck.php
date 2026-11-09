<?php
/**
 * Whether the private directory is reachable from the web.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Port for the HTTP probe of ADR 0007. The WordPress layer writes a probe file, requests
 * its URL and caches the answer for a day.
 */
interface ExposureCheck {

	/**
	 * Checks the private directory.
	 *
	 * @return bool|null True if exposed, false if protected, null if it cannot be checked
	 *                   (for example because the directory lies outside the web root).
	 */
	public function exposed(): ?bool;
}
