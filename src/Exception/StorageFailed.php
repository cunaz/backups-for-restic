<?php
/**
 * A local file operation failed.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The private directory or a file in it could not be created, written or read.
 */
final class StorageFailed extends PluginException {
}
