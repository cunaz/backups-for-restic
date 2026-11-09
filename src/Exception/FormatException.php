<?php
/**
 * Exception: Repository data does not follow the restic format.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Repository data does not follow the restic format.
 */
class FormatException extends PluginException {
}
