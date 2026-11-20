<?php
/**
 * Exception: The site manifest cannot be located within the snapshot search limits.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The site manifest cannot be located within the snapshot search limits.
 */
final class ManifestNotLocated extends InvalidArgument {
}
