<?php
/**
 * Exception: The server has no space left for this repository.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The server has no space left for this repository.
 */
final class QuotaExceeded extends BackendException {
}
