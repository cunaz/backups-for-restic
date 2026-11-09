<?php
/**
 * Exception: The requested object does not exist on the server.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The requested object does not exist on the server.
 */
final class NotFound extends BackendException {
}
