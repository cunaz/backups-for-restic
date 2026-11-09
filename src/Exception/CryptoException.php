<?php
/**
 * Exception: A cryptographic operation could not be performed.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A cryptographic operation could not be performed.
 */
class CryptoException extends PluginException {
}
