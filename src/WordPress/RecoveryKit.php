<?php
/**
 * The recovery kit text.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Plugin;
use BackupsForRestic\Secrets\SecretName;

defined( 'ABSPATH' ) || exit;

/**
 * Everything needed to restore without this site (docs/03-security-and-threat-model.md §5.5):
 * repository address, credentials, password and a short restic recipe. It is shown, never
 * stored, and only to an administrator who just entered their WordPress password.
 */
final class RecoveryKit {

	/**
	 * The CA certificate the kit has to carry: a self-signed server needs it for restic too. It
	 * is public, not a secret.
	 *
	 * @param Container $container Services.
	 * @return string PEM text, or an empty string if no certificate was uploaded.
	 * @throws StorageFailed With the code ca_certificate_missing if the settings name an uploaded certificate that is not there; a kit without it would be useless.
	 */
	public static function ca_certificate( Container $container ): string {
		return 'uploaded' === $container->settings()->string( 'repository.ca_certificate' ) ? $container->private_dir()->ca_certificate() : '';
	}

	/**
	 * Builds the kit.
	 *
	 * @param Container $container Services.
	 * @param bool      $entered   Whether the kit is for a repository password that was just entered and
	 *                             that the unlock job is still to confirm: the kit then carries that one.
	 * @return string Plain text.
	 * @throws SecretsUndecryptable If the stored secrets cannot be decrypted.
	 * @throws StorageFailed If the uploaded CA certificate is missing.
	 */
	public static function text( Container $container, bool $entered = false ): string {
		$settings = $container->settings();
		$secrets  = $container->secrets();
		$password = $entered ? $secrets->get( SecretName::RepositoryPasswordCandidate ) : null;
		$password = ( $password ?? $secrets->get( SecretName::RepositoryPassword ) )?->reveal() ?? '';
		$rest     = $secrets->get( SecretName::RestPassword )?->reveal() ?? '';
		$url      = $settings->string( 'repository.url' );
		$username = $settings->string( 'repository.rest_username' );
		$hostname = $container->site()->hostname();
		$ca       = self::ca_certificate( $container );
		$cacert   = '' === $ca ? '' : ' --cacert ca.pem';
		$lines    = [
			__( 'Backup recovery kit', 'backups-for-restic' ),
			str_repeat( '=', 40 ),
			/* translators: %s: site address. */
			sprintf( __( 'Site: %s', 'backups-for-restic' ), home_url() ),
			/* translators: %s: date and time in UTC. */
			sprintf( __( 'Created: %s UTC', 'backups-for-restic' ), gmdate( 'Y-m-d H:i' ) ),
			/* translators: %s: plugin version. */
			sprintf( __( 'Plugin version: %s', 'backups-for-restic' ), Plugin::VERSION ),
			'',
			/* translators: %s: repository address. */
			sprintf( __( 'Repository: rest:%s', 'backups-for-restic' ), $url ),
			/* translators: %s: user name. */
			sprintf( __( 'REST username: %s', 'backups-for-restic' ), $username ),
			/* translators: %s: password. */
			sprintf( __( 'REST password: %s', 'backups-for-restic' ), $rest ),
			/* translators: %s: password. */
			sprintf( __( 'Repository password: %s', 'backups-for-restic' ), $password ),
			/* translators: %d: format version number. */
			sprintf( __( 'Repository format: %d', 'backups-for-restic' ), $settings->int( 'repository.format_version' ) ),
			'',
			__( 'Restore without WordPress, on any computer with restic 0.16.1 or newer:', 'backups-for-restic' ),
			"  export RESTIC_REPOSITORY='rest:" . $url . "'",
			"  export RESTIC_REST_USERNAME='" . $username . "'",
			"  export RESTIC_REST_PASSWORD='" . $rest . "'",
			"  export RESTIC_PASSWORD='" . $password . "'",
			'  restic snapshots --host ' . $hostname . $cacert,
			'  restic restore latest --host ' . $hostname . $cacert . ' --target ./restore',
			'',
			__( 'The database is in the snapshot under the plugin\'s spool/database folder. Import its .sql files in name order, for example: cat *.sql | mariadb <database>', 'backups-for-restic' ),
			__( 'Keep this kit away from the website, for example in a password manager.', 'backups-for-restic' ),
		];
		if ( '' !== $ca ) {
			$lines[] = '';
			$lines[] = __( 'The server uses its own certificate authority. Save the certificate below as ca.pem in the folder where you run restic:', 'backups-for-restic' );
			$lines[] = $ca;
		}
		return implode( "\n", $lines ) . "\n";
	}
}
