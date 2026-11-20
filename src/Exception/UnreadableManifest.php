<?php
/**
 * Exception: The optional site manifest cannot be read from an otherwise accessible snapshot.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The optional site manifest cannot be read from an otherwise accessible snapshot.
 */
final class UnreadableManifest extends FormatException {
}
