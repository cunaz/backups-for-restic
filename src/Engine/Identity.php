<?php
/**
 * Who writes to the repository.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Host and user names recorded in snapshots, locks and key files.
 *
 * The host name is the site's host (for example example.com), not the machine name, so that a
 * site keeps one series of snapshots when it moves between servers or containers.
 */
final readonly class Identity {

	/**
	 * Creates the identity.
	 *
	 * @param string $hostname Host name.
	 * @param string $username User name.
	 * @param int    $uid      User ID.
	 * @param int    $gid      Group ID.
	 * @param string $program  Program version written into snapshots.
	 */
	public function __construct(
		public string $hostname,
		public string $username = '',
		public int $uid = 0,
		public int $gid = 0,
		public string $program = 'backups-for-restic'
	) {
	}
}
