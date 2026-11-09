<?php
/**
 * Go file mode bits.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between POSIX st_mode values (PHP's stat()) and Go's os.FileMode, which restic stores
 * in the "mode" field of a node.
 */
final class FileMode {

	public const int DIR         = 1 << 31;
	public const int SYMLINK     = 1 << 27;
	public const int DEVICE      = 1 << 26;
	public const int NAMED_PIPE  = 1 << 25;
	public const int SOCKET      = 1 << 24;
	public const int SETUID      = 1 << 23;
	public const int SETGID      = 1 << 22;
	public const int CHAR_DEVICE = 1 << 21;
	public const int STICKY      = 1 << 20;
	public const int PERMISSIONS = 0777;

	/**
	 * Converts a POSIX mode to the value restic stores (Go's FileMode masked like restic does).
	 *
	 * @param int $st_mode Mode from stat().
	 * @return int Go FileMode bits.
	 */
	public static function from_posix( int $st_mode ): int {
		$mode = $st_mode & self::PERMISSIONS;
		if ( 0 !== ( $st_mode & 04000 ) ) {
			$mode |= self::SETUID;
		}
		if ( 0 !== ( $st_mode & 02000 ) ) {
			$mode |= self::SETGID;
		}
		if ( 0 !== ( $st_mode & 01000 ) ) {
			$mode |= self::STICKY;
		}

		return $mode | match ( $st_mode & 0170000 ) {
			0040000 => self::DIR,
			0120000 => self::SYMLINK,
			0060000 => self::DEVICE,
			0020000 => self::DEVICE | self::CHAR_DEVICE,
			0010000 => self::NAMED_PIPE,
			0140000 => self::SOCKET,
			default => 0,
		};
	}

	/**
	 * Returns the node type for a POSIX mode.
	 *
	 * @param int $st_mode Mode from stat().
	 * @return NodeType Type.
	 */
	public static function type_from_posix( int $st_mode ): NodeType {
		return match ( $st_mode & 0170000 ) {
			0040000 => NodeType::Dir,
			0120000 => NodeType::Symlink,
			0060000 => NodeType::Dev,
			0020000 => NodeType::CharDev,
			0010000 => NodeType::Fifo,
			0140000 => NodeType::Socket,
			0100000 => NodeType::File,
			default => NodeType::Irregular,
		};
	}

	/**
	 * Returns the permission bits for chmod() when restoring, including setuid, setgid and sticky.
	 *
	 * @param int $mode Go FileMode bits.
	 * @return int POSIX permission bits.
	 */
	public static function to_posix_permissions( int $mode ): int {
		$permissions = $mode & self::PERMISSIONS;
		if ( 0 !== ( $mode & self::SETUID ) ) {
			$permissions |= 04000;
		}
		if ( 0 !== ( $mode & self::SETGID ) ) {
			$permissions |= 02000;
		}
		if ( 0 !== ( $mode & self::STICKY ) ) {
			$permissions |= 01000;
		}
		return $permissions;
	}
}
