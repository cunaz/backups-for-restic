<?php
/**
 * Exception: The repository's index, or what a job reads beside it, does not fit into the memory the host allows.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The repository's index does not fit into the memory the host allows, or the next blob of a
 * restore does not fit beside it (code restore_blob_memory) (ADR 0031, ADR 0036).
 */
class IndexTooLarge extends PluginException {
}
