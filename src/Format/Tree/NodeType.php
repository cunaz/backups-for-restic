<?php
/**
 * Node types.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * File system object types as restic names them.
 */
enum NodeType: string {
	case File      = 'file';
	case Dir       = 'dir';
	case Symlink   = 'symlink';
	case Dev       = 'dev';
	case CharDev   = 'chardev';
	case Fifo      = 'fifo';
	case Socket    = 'socket';
	case Irregular = 'irregular';
}
