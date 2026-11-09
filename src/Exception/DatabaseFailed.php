<?php
/**
 * A database query failed.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The database refused a query while dumping or importing.
 */
final class DatabaseFailed extends PluginException {
}
