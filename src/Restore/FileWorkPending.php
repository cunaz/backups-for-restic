<?php
/**
 * A resumable private verification reached its worker deadline.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

defined( 'ABSPATH' ) || exit;

/** Internal scheduling signal; the owning job resumes the same record on its next tick. */
final class FileWorkPending extends \RuntimeException {
}
