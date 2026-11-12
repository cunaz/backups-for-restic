<?php
/**
 * Exception: The repository's index does not fit into the memory the host allows.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The repository's index does not fit into the memory the host allows (ADR 0031).
 */
class IndexTooLarge extends PluginException {
}
