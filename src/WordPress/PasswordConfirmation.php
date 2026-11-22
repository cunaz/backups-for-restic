<?php
/**
 * The WordPress password of the current user, asked for again.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\ConfirmationThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * The one check of every route that asks for the WordPress password of the current user
 * again (docs/03-security-and-threat-model.md §5.5): the recovery kit, creating and
 * connecting a repository after the setup, applying and undoing file and database changes,
 * and the download of restored files. Wrong passwords are counted per user and slow down
 * the next attempts (Storage\ConfirmationThrottle). Each one also fires core's
 * wp_login_failed action with the arguments core's login uses, so that login limiters and
 * fail2ban rules count it as they count a failed login. A two-factor plugin does not take
 * part: only the password is asked for.
 */
final readonly class PasswordConfirmation {

	/**
	 * Uses the counter of wrong passwords.
	 *
	 * @param ConfirmationThrottle $throttle Counts and slows down wrong passwords.
	 */
	public function __construct( private ConfirmationThrottle $throttle ) {
	}

	/**
	 * Checks the password that a request carries for the current user.
	 *
	 * @param mixed $password What the request carries as the password.
	 * @return array{code: string, wait: int}|null Null if the password is right. Otherwise the
	 *                                             refusal: wrong_user_password, or
	 *                                             user_password_throttled with the seconds to wait.
	 * @throws StorageFailed If the attempt cannot be counted; no password is checked then.
	 */
	public function check( #[\SensitiveParameter] mixed $password ): ?array {
		$user  = wp_get_current_user();
		$given = is_string( $password ) && '' !== $password;
		if ( $user->ID < 1 ) {
			return [
				'code' => 'wrong_user_password',
				'wait' => 0,
			];
		}
		$wait = $this->throttle->begin( $user->ID, $given );
		if ( $wait > 0 ) {
			return [
				'code' => 'user_password_throttled',
				'wait' => $wait,
			];
		}
		if ( $given && wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			$this->throttle->succeeded( $user->ID );
			return null;
		}
		if ( $given ) {
			/** This action is documented in wp-includes/pluggable.php */
			do_action( 'wp_login_failed', $user->user_login, new \WP_Error( 'incorrect_password', __( 'The WordPress password entered to confirm a backup action is incorrect.', 'backups-for-restic' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's action for failed logins, so that login limiters count these attempts.
		}
		return [
			'code' => 'wrong_user_password',
			'wait' => 0,
		];
	}
}
