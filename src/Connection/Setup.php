<?php
/**
 * The repository steps of the setup wizard.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Secrets\SecretStore;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a tested server, creates a new repository with a generated password, or stores the
 * password of an existing repository whose key is then derived by an unlock job.
 */
final class Setup {

	/**
	 * Creates the setup.
	 *
	 * @param SettingsStore                $settings Settings.
	 * @param SecretStore                  $secrets  Secrets.
	 * @param \Closure(): RepositoryAccess $access   Builds access from the current settings.
	 * @param RandomSource                 $random   Randomness.
	 * @param Identity                     $identity Recorded in the key file.
	 */
	public function __construct(
		private readonly SettingsStore $settings,
		private readonly SecretStore $secrets,
		private readonly \Closure $access,
		private readonly RandomSource $random,
		private readonly Identity $identity
	) {
	}

	/**
	 * A new repository password: 32 characters from a CSPRNG, 192 bits.
	 *
	 * @param RandomSource $random Randomness.
	 * @return Secret Password.
	 */
	public static function generate_password( RandomSource $random ): Secret {
		return new Secret( Bytes::base64url_encode( $random->bytes( 24 ) ) );
	}

	/**
	 * The REST password that may be sent to a server named in a request: the one that came with
	 * the request, or the stored one if the request names the host, port and user name it is
	 * stored for. Without this rule a request that only names another host would get the stored
	 * password sent there.
	 *
	 * A password defined in wp-config.php cannot be replaced by a request. It stays in force
	 * for whatever server is saved, so for another server or user name the request has to
	 * carry exactly that password.
	 *
	 * @param string      $server   borgbase or rest-server.
	 * @param string      $url      Repository URL.
	 * @param string      $username REST username; empty for a server without authentication.
	 * @param Secret|null $given    Password from the request, if any.
	 * @return Secret|null The password to use; null without a user name, when none is sent.
	 * @throws InvalidArgument With the code password_required if the request has to carry the password, or with the code of a rejected URL.
	 */
	public function rest_password( string $server, string $url, string $username, ?Secret $given ): ?Secret {
		if ( '' === $username ) {
			// RestBackend sends no credentials without a user name.
			return $given;
		}
		$current = $this->settings->load();
		$same    = ServerProfile::same_server( ServerProfile::normalize( $server, $url ), $current->string( 'repository.url' ) ) && $username === $current->string( 'repository.rest_username' );
		try {
			$stored = $this->secrets->get( SecretName::RestPassword );
		} catch ( SecretsUndecryptable ) {
			$stored = null;
		}
		if ( null !== $stored && $this->secrets->is_overridden( SecretName::RestPassword ) ) {
			$allowed = ( $same || ( null !== $given && $stored->equals( $given ) ) ) ? $stored : null;
		} else {
			$allowed = $given ?? ( $same ? $stored : null );
		}
		if ( null === $allowed ) {
			InvalidArgument::raise( 'The request names a server or user name that the stored REST password is not for; it has to carry the password.', [ 'code' => 'password_required' ] );
		}
		return $allowed;
	}

	/**
	 * Saves a server after a successful connection test.
	 *
	 * @param string           $server   borgbase or rest-server.
	 * @param ConnectionReport $report   Test result with the normalized URL.
	 * @param string           $username REST username.
	 * @param Secret|null      $password REST password; null keeps the stored one, which must be
	 *                                   stored for this server and user name.
	 * @throws ValidationFailed If a setting is rejected.
	 * @throws InvalidArgument With the code password_required if the stored REST password would end up with another server or user name.
	 */
	public function save_server( string $server, ConnectionReport $report, string $username, ?Secret $password ): void {
		$append_only = match ( $report->append_only ) {
			AppendOnlyStatus::AppendOnly       => 'yes',
			AppendOnlyStatus::DeletionsAllowed => 'no',
			AppendOnlyStatus::Unknown          => 'unknown',
		};
		$changes = [
			'repository.server'        => $server,
			'repository.url'           => $report->url,
			'repository.rest_username' => $username,
			'repository.append_only'   => $append_only,
		];
		if ( 'yes' === $append_only ) {
			$changes['retention.mode'] = 'external';
		}
		$current = $this->settings->load();
		foreach ( array_keys( $changes ) as $path ) {
			if ( $current->is_overridden( $path ) ) {
				unset( $changes[ $path ] );
			}
		}
		// What the jobs will use after the save; a value from wp-config.php stays as it is.
		$next     = $current->with( $changes );
		$url      = $next->string( 'repository.url' );
		$username = $next->string( 'repository.rest_username' );
		$this->rest_password( ServerProfile::detect( $url ), $url, $username, $password );
		// The password first: should storing it fail, the settings still name the server that
		// the stored password is for.
		if ( ! $this->secrets->is_overridden( SecretName::RestPassword ) ) {
			if ( null !== $password ) {
				$this->secrets->set( SecretName::RestPassword, $password );
			} elseif ( '' === $username ) {
				// The server takes no credentials: a password kept from before belongs to none.
				$this->secrets->delete( SecretName::RestPassword );
			}
		}
		$this->settings->update( $changes );
		$this->secrets->delete( SecretName::MasterKey );
	}

	/**
	 * Creates a new repository on the saved server.
	 *
	 * @param Secret|null $password Password to use; a new one is generated if null.
	 * @return Secret The repository password, for the recovery kit.
	 * @throws AlreadyExists If the server already holds a repository.
	 */
	public function create( ?Secret $password ): Secret {
		if ( $this->secrets->is_overridden( SecretName::RepositoryPassword ) ) {
			$password = $this->secrets->get( SecretName::RepositoryPassword );
		}
		$password ??= self::generate_password( $this->random );
		$access     = ( $this->access )();
		$repository = Repository::initialize( $access->backend(), $password->reveal(), $this->identity, $this->random );
		if ( ! $this->secrets->is_overridden( SecretName::RepositoryPassword ) ) {
			$this->secrets->set( SecretName::RepositoryPassword, $password );
		}
		$access->cache_master_key( $repository->config(), $repository->master_key() );
		$this->settings->update(
			[
				'repository.format_version' => $repository->config()->version,
				'repository.id'             => $repository->config()->id,
			]
		);
		return $password;
	}

	/**
	 * Stores the password of an existing repository and queues the unlock job that derives its
	 * key, because the derivation can take longer than one request.
	 *
	 * Nothing is stored while another job is active: that job would lose the cached key in the
	 * middle of its work, and no unlock job could tell whether the new password is right.
	 *
	 * @param Secret|null $password Repository password; null for the one defined in wp-config.php.
	 * @param JobStore    $jobs     Jobs.
	 * @param int         $now      Unix time.
	 * @return Job The unlock job.
	 * @throws InvalidArgument If there is no password: none given and none in wp-config.php.
	 * @throws JobConflict If another job is active.
	 */
	public function connect( ?Secret $password, JobStore $jobs, int $now ): Job {
		$overridden = $this->secrets->is_overridden( SecretName::RepositoryPassword );
		if ( null === $password && ! $overridden ) {
			InvalidArgument::raise( 'No repository password was given.', [ 'code' => 'no_password' ] );
		}
		$active = $jobs->active();
		if ( null !== $active ) {
			JobConflict::raise( 'Another job is still running.', [ 'job' => $active->id ] );
		}
		if ( null !== $password ) {
			if ( ! $overridden ) {
				$this->secrets->set( SecretName::RepositoryPassword, $password );
			}
			$this->secrets->delete( SecretName::MasterKey );
		}
		return $jobs->create( JobType::Unlock, 'setup', $now );
	}
}
