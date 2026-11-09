<?php
/**
 * Exception: The repository server request failed.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The repository server request failed.
 */
class BackendException extends PluginException {
}
