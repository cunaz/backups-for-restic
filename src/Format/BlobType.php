<?php
/**
 * Blob types.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

defined( 'ABSPATH' ) || exit;

/**
 * File contents are stored as data blobs, directory listings as tree blobs.
 */
enum BlobType: string {
	case Data = 'data';
	case Tree = 'tree';
}
