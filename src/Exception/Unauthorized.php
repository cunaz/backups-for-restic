<?php
/**
 * Exception: The server rejected the credentials.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The server rejected the credentials.
 */
final class Unauthorized extends BackendException {
}
