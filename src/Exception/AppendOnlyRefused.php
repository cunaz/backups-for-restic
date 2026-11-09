<?php
/**
 * Exception: The server refused a deletion because the repository is append-only.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The server refused a deletion because the repository is append-only.
 */
final class AppendOnlyRefused extends BackendException {
}
