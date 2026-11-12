<?php
/**
 * Exception: Another client changed the repository while a run held no valid lock.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Another client changed the repository while a run held no valid lock, and removed what
 * the run relied on (ADR 0030). The run was given up; a new one may start.
 */
class RepositoryChanged extends PluginException {
}
