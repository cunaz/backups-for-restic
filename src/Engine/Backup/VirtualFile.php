<?php
/**
 * A file that exists only in the backup.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Backup;

use BackupsForRestic\Support\ByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * Content produced while backing up, such as one table of the database dump (ADR 0021).
 *
 * The source closure opens the content from the beginning, or, for a ResumableByteSource
 * that stopped early, after the cursor it handed out. Sources that are not resumable are
 * restarted from the beginning when a backup is interrupted inside them.
 */
final readonly class VirtualFile {

	/**
	 * Creates the entry.
	 *
	 * @param string                        $name   File name.
	 * @param \Closure(?string): ByteSource $source Opens the content at a cursor, or from the beginning with null.
	 * @param int                           $mtime  Modification time.
	 * @param int                           $mode   POSIX permission bits.
	 */
	public function __construct(
		public string $name,
		public \Closure $source,
		public int $mtime,
		public int $mode = 0600
	) {
	}
}
