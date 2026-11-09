<?php
/**
 * Builds tree nodes from the file system.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Backup;

use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Turns lstat() results into nodes with the fields restic records on Linux.
 *
 * PHP's stat() has whole-second timestamps, so nodes carry no nanoseconds. As restic does by
 * default, the access time is set to the modification time so that reading a file does not
 * change its node.
 */
final class NodeFactory {

	/**
	 * User names by ID.
	 *
	 * @var array<int, string>
	 */
	private array $users = [];

	/**
	 * Group names by ID.
	 *
	 * @var array<int, string>
	 */
	private array $groups = [];

	/**
	 * Builds a node.
	 *
	 * @param string                 $name        Entry name.
	 * @param array<int|string, int> $stat   Result of lstat().
	 * @param list<Id>|null          $content     Data blobs of a file.
	 * @param Id|null                $subtree     Tree of a directory.
	 * @param string                 $link_target Target of a symlink.
	 * @return Node Node.
	 */
	public function from_stat( string $name, array $stat, ?array $content = null, ?Id $subtree = null, string $link_target = '' ): Node {
		$st_mode = $stat['mode'] ?? 0;
		$type    = FileMode::type_from_posix( $st_mode );
		$mtime   = Rfc3339::format( $stat['mtime'] ?? 0 );
		$uid     = $stat['uid'] ?? 0;
		$gid     = $stat['gid'] ?? 0;
		$links   = in_array( $type, [ NodeType::File, NodeType::Symlink, NodeType::Dev, NodeType::CharDev ], true ) ? ( $stat['nlink'] ?? 0 ) : 0;

		return new Node(
			$name,
			$type,
			FileMode::from_posix( $st_mode ),
			$mtime,
			$mtime,
			Rfc3339::format( $stat['ctime'] ?? 0 ),
			$uid,
			$gid,
			$this->user( $uid ),
			$this->group( $gid ),
			$stat['ino'] ?? 0,
			$stat['dev'] ?? 0,
			NodeType::File === $type ? ( $stat['size'] ?? 0 ) : 0,
			$links,
			$link_target,
			0,
			NodeType::File === $type ? ( $content ?? [] ) : null,
			$subtree
		);
	}

	/**
	 * Builds the node of a virtual file.
	 *
	 * @param VirtualFile $file    Virtual file.
	 * @param int         $size    Size of the produced content.
	 * @param list<Id>    $content Data blobs.
	 * @return Node Node.
	 */
	public function virtual( VirtualFile $file, int $size, array $content ): Node {
		$time = Rfc3339::format( $file->mtime );
		return new Node( $file->name, NodeType::File, FileMode::from_posix( 0100000 | ( $file->mode & 0777 ) ), $time, $time, $time, size: $size, links: 1, content: $content );
	}

	/**
	 * Resolves a user name if the posix extension is available.
	 *
	 * @param int $uid User ID.
	 * @return string Name or empty string.
	 */
	private function user( int $uid ): string {
		if ( ! isset( $this->users[ $uid ] ) ) {
			$entry               = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
			$this->users[ $uid ] = is_array( $entry ) ? $entry['name'] : '';
		}
		return $this->users[ $uid ];
	}

	/**
	 * Resolves a group name if the posix extension is available.
	 *
	 * @param int $gid Group ID.
	 * @return string Name or empty string.
	 */
	private function group( int $gid ): string {
		if ( ! isset( $this->groups[ $gid ] ) ) {
			$entry                = function_exists( 'posix_getgrgid' ) ? posix_getgrgid( $gid ) : false;
			$this->groups[ $gid ] = is_array( $entry ) ? $entry['name'] : '';
		}
		return $this->groups[ $gid ];
	}
}
