<?php
/**
 * Exception: The object already exists on the server and cannot be overwritten.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The object already exists on the server and cannot be overwritten.
 */
final class AlreadyExists extends BackendException {
}
