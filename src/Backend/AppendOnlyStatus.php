<?php
/**
 * Result of the append-only probe.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the server lets this client delete repository objects.
 */
enum AppendOnlyStatus: string {
	case AppendOnly       = 'append_only';
	case DeletionsAllowed = 'deletions_allowed';
	case Unknown          = 'unknown';
}
