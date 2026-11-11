<?php
/**
 * The secrets the plugin keeps.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Names of stored secrets (docs/03-security-and-threat-model.md §5.1).
 */
enum SecretName: string {

	case RepositoryPassword = 'repository_password';
	case RestPassword       = 'rest_password';
	case MasterKey          = 'master_key';

	/**
	 * A repository password that somebody entered and no unlock has confirmed yet. It replaces
	 * the repository password once it opened the repository, and is dropped if it did not.
	 */
	case RepositoryPasswordCandidate = 'repository_password_candidate';

	/**
	 * The wp-config.php constant that overrides this secret, if any. The cached master key has
	 * none: it is derived from the password. Neither has a password that is still to be
	 * confirmed: somebody entered it.
	 *
	 * @return string|null Constant name.
	 */
	public function constant(): ?string {
		return match ( $this ) {
			self::RepositoryPassword => 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD',
			self::RestPassword       => 'BACKUPS_FOR_RESTIC_REST_PASSWORD',
			self::MasterKey,
			self::RepositoryPasswordCandidate => null,
		};
	}
}
