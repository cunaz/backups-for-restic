<?php
/**
 * Repository object types.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level object kinds of a restic repository; the values are the REST path segments.
 */
enum ObjectType: string {
	case Config    = 'config';
	case Data      = 'data';
	case Index     = 'index';
	case Keys      = 'keys';
	case Locks     = 'locks';
	case Snapshots = 'snapshots';
}
