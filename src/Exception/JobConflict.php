<?php
/**
 * Another job is active.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Only one job may be active at a time (docs/02-architecture.md §4.4).
 */
final class JobConflict extends PluginException {
}
