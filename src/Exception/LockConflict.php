<?php
/**
 * Exception: The repository is locked by another process.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The repository is locked by another process.
 */
class LockConflict extends PluginException {
}
