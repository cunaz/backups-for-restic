<?php
/**
 * The recovery kit text.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\SecretsUndecryptable;
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
	 * Builds the kit.
	 *
	 * @param Container $container Services.
	 * @return string Plain text.
	 * @throws SecretsUndecryptable If the stored secrets cannot be decrypted.
	 */
	public static function text( Container $container ): string {
		$settings = $container->settings();
		$secrets  = $container->secrets();
		$password = $secrets->get( SecretName::RepositoryPassword )?->reveal() ?? '';
		$rest     = $secrets->get( SecretName::RestPassword )?->reveal() ?? '';
		$url      = $settings->string( 'repository.url' );
		$username = $settings->string( 'repository.rest_username' );
		$hostname = $container->site()->hostname();
		// A self-signed server needs its CA certificate for restic too; it is public, not a secret.
		$ca_file = $container->private_dir()->path . '/ca.pem';
		$ca      = 'uploaded' === $settings->string( 'repository.ca_certificate' ) && is_readable( $ca_file ) ? trim( (string) file_get_contents( $ca_file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the private directory.
		$cacert  = '' === $ca ? '' : ' --cacert ca.pem';
		$lines   = [
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
