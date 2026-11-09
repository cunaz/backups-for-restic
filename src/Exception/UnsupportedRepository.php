<?php
/**
 * Exception: The repository uses a format or feature this plugin cannot read.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The repository uses a format or feature this plugin cannot read.
 */
class UnsupportedRepository extends PluginException {
}
