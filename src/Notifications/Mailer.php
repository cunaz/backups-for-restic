<?php
/**
 * Port for sending notifications.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a notice. WordPress\WpMailer writes the translated e-mail and hands it to
 * wp_mail(); tests record what would have been sent.
 */
interface Mailer {

	/**
	 * Sends one notice.
	 *
	 * @param Notice $notice Notice.
	 * @return bool True if the message was handed over, or if there is nobody to send it to;
	 *              false to try again later.
	 */
	public function send( Notice $notice ): bool;
}
