<?php
/**
 * The recovery kit text.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Plugin;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Everything needed to restore without this site (docs/03-security-and-threat-model.md §5.5):
 * repository address, credentials, password and a short restic recipe. It is shown, never
 * stored. The web routes confirm authorization before delivery. WP-CLI requires an
 * interactive terminal or an explicit request for redirected output.
 */
final class RecoveryKit {

	/** Number of kit deliveries retained for the administrator's audit history. */
	private const int KIT_VIEWS = 20;

	/**
	 * Records the delivery before returning the kit. Shell access without a selected
	 * WordPress user is recorded as WP-CLI with user ID zero.
	 *
	 * @param Container $container Site services.
	 * @param string    $via create, connect, settings or cli.
	 * @return string Recovery kit.
	 * @throws PluginException If the kit or the audit entry cannot be produced.
	 */
	public static function deliver( Container $container, string $via ): string {
		$kit  = self::text( $container, 'connect' === $via );
		$user = wp_get_current_user();
		$view = [
			'at'    => time(),
			'user'  => $user->ID,
			'login' => 'cli' === $via && 0 === $user->ID ? 'WP-CLI' : $user->user_login,
			'via'   => $via,
		];
		$container->state()->update( 'recovery_kit', static fn ( array $entry ): array => [ 'views' => array_slice( [ $view, ...Decoded::arrays( $entry['views'] ?? null ) ], 0, self::KIT_VIEWS ) ], true );
		/**
		 * Fires when the recovery kit has been prepared for an authorized recipient.
		 *
		 * @param int    $user_id WordPress user ID, or zero for a local shell operator.
		 * @param string $via     create, connect, settings or cli.
		 */
		do_action( 'backups_for_restic_recovery_kit_shown', $user->ID, $via );
		return $kit;
	}

	/**
	 * Quotes one argument for the POSIX shell used in the recovery instructions. The
	 * destination shell may differ from the server's OS and locale.
	 *
	 * @param string $value Text to reproduce literally.
	 * @return string Quoted argument.
	 */
	private static function shell_word( string $value ): string {
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}

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
		$site     = $container->site();
		$filter   = ' --host ' . self::shell_word( $site->hostname() ) . ' --tag ' . self::shell_word( PlanFactory::TAG . ',' . $site->site_tag() );
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
			'  export RESTIC_REPOSITORY=' . self::shell_word( 'rest:' . $url ),
			'  export RESTIC_REST_USERNAME=' . self::shell_word( $username ),
			'  export RESTIC_REST_PASSWORD=' . self::shell_word( $rest ),
			'  export RESTIC_PASSWORD=' . self::shell_word( $password ),
			'  restic snapshots' . $filter . $cacert,
			'  restic restore latest' . $filter . $cacert . ' --target ./restore',
			'',
			/* translators: %s: restic command listing all snapshots in the repository. */
			sprintf( __( 'To recover a site with another address, use %s to list the repository. Verify the source host, tags and date, then restore its full snapshot ID without the host and tag filters.', 'backups-for-restic' ), 'restic snapshots' . $cacert ),
			'',
			__( 'The database is in the snapshot under the plugin\'s spool/database folder. In that directory, import the .sql files into an empty database in name order. Replace recovered_site with its name: cat ./*.sql | mariadb recovered_site', 'backups-for-restic' ),
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
