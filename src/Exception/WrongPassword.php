<?php
/**
 * Exception: No key in the repository accepts the password.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * No key in the repository accepts the password.
 */
class WrongPassword extends PluginException {
}
