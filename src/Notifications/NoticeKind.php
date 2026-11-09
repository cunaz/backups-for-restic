<?php
/**
 * Kinds of notification.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * What an e-mail notification is about (docs/02-architecture.md §14).
 */
enum NoticeKind: string {

	/** A job failed: the first failure of a streak of that job type. */
	case Failure = 'failure';

	/** No backup succeeded for longer than the stale threshold. */
	case Stale = 'stale';

	/** A job succeeded again after a failure or stale notice. */
	case Recovery = 'recovery';

	/** A repository check found damage. */
	case CheckFindings = 'check_findings';

	/** The server refused deletions; retention switched to external (ADR 0010). */
	case RetentionExternal = 'retention_external';
}
