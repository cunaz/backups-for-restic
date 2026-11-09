<?php
/**
 * Exception: An encrypted object failed authentication: it is damaged or was tampered with.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * An encrypted object failed authentication: it is damaged or was tampered with.
 */
class AuthenticationFailed extends PluginException {
}
