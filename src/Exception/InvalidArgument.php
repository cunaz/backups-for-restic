<?php
/**
 * Exception: An argument is outside its valid range.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * An argument is outside its valid range.
 */
class InvalidArgument extends PluginException {
}
