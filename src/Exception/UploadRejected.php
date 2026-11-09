<?php
/**
 * Exception: The server rejected an upload because its content did not match its name.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The server rejected an upload because its content did not match its name.
 */
final class UploadRejected extends BackendException {
}
