<?php
/**
 * How the plugin reaches its repository.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Secrets\SecretStore;
use BackupsForRestic\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the REST backend from the settings and secrets, and keeps the master key cache
 * (ADR 0020). The cached key is stored together with the repository ID, so a key for one
 * repository is never used with another.
 */
final class RepositoryAccess {

	/**
	 * Creates the access.
	 *
	 * @param Settings     $settings   Settings.
	 * @param SecretStore  $secrets    Secrets.
	 * @param HttpClient   $http       HTTP client for the repository host.
	 * @param RandomSource $random     Randomness.
	 * @param string       $user_agent User-Agent of repository requests.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SecretStore $secrets,
		private readonly HttpClient $http,
		private readonly RandomSource $random,
		private readonly string $user_agent
	) {
	}

	/**
	 * The REST backend of the configured repository.
	 *
	 * @return RestBackend Backend.
	 * @throws InvalidArgument If no repository URL is configured.
	 * @throws SecretsUndecryptable If the REST password cannot be decrypted.
	 */
	public function backend(): RestBackend {
		return $this->backend_through( $this->http, 5 );
	}

	/**
	 * A backend for one question to the server, as the Doctor asks it: with a client of its
	 * own time limit, and without the repetitions that a job can afford.
	 *
	 * @param HttpClient $http Client.
	 * @return RestBackend Backend that sends every request once.
	 * @throws InvalidArgument If no repository URL is configured.
	 * @throws SecretsUndecryptable If the REST password cannot be decrypted.
	 */
	public function probe_backend( HttpClient $http ): RestBackend {
		return $this->backend_through( $http, 1 );
	}

	/**
	 * The backend of the configured repository.
	 *
	 * @param HttpClient $http     Client.
	 * @param int        $attempts How often a request that may be repeated is sent at most.
	 * @return RestBackend Backend.
	 * @throws InvalidArgument If no repository URL is configured.
	 * @throws SecretsUndecryptable If the REST password cannot be decrypted.
	 */
	private function backend_through( HttpClient $http, int $attempts ): RestBackend {
		$url = $this->settings->string( 'repository.url' );
		if ( '' === $url ) {
			InvalidArgument::raise( 'No repository is configured.', [ 'code' => 'no_repository' ] );
		}
		$username = $this->settings->string( 'repository.rest_username' );
		$password = $this->secrets->get( SecretName::RestPassword );
		return new RestBackend( $http, $url, '' === $username ? null : $username, $password?->reveal(), $this->user_agent, $attempts );
	}

	/**
	 * The repository password.
	 *
	 * @return Secret Password.
	 * @throws InvalidArgument If none is configured.
	 * @throws SecretsUndecryptable If it cannot be decrypted.
	 */
	public function password(): Secret {
		$password = $this->secrets->get( SecretName::RepositoryPassword );
		if ( null === $password || $password->is_empty() ) {
			InvalidArgument::raise( 'No repository password is configured.', [ 'code' => 'no_password' ] );
		}
		return $password;
	}

	/**
	 * Whether the two passwords can be read, for the Doctor: one defined in wp-config.php
	 * always can, a stored one if it decrypts with the current salts. A stored value that a
	 * constant overrides does not count, and neither does the cached key, which open_cached()
	 * replaces on its own.
	 *
	 * @return bool False if a job would fail with secrets_undecryptable.
	 */
	public function passwords_readable(): bool {
		try {
			$this->secrets->get( SecretName::RepositoryPassword );
			$this->secrets->get( SecretName::RestPassword );
		} catch ( SecretsUndecryptable ) {
			return false;
		}
		return true;
	}

	/**
	 * The repository opened with the cached master key, if there is one and it fits. A key
	 * fits when it decrypts the repository's config, so a cached key never serves another
	 * repository; a key that does not fit is forgotten.
	 *
	 * So is a cached key that cannot be decrypted, as after a change of the salts in
	 * wp-config.php: it is a cache, and the password derives the key again. Whether the
	 * password survived the change is the unlock's matter; one defined in wp-config.php did.
	 *
	 * @return Repository|null Repository, or null if it has to be unlocked.
	 */
	public function open_cached(): ?Repository {
		try {
			$cached = $this->secrets->get( SecretName::MasterKey );
		} catch ( SecretsUndecryptable ) {
			$this->forget_master_key();
			return null;
		}
		$fields = null === $cached ? null : json_decode( $cached->reveal(), true );
		if ( ! is_array( $fields ) || ! is_string( $fields['key'] ?? null ) ) {
			return null;
		}
		try {
			return $this->open( MasterKey::from_json( $fields['key'] ) );
		} catch ( AuthenticationFailed | CryptoException | FormatException ) {
			$this->forget_master_key();
			return null;
		}
	}

	/**
	 * ID of the repository whose master key is cached, read without a request to the server.
	 * This is the repository the jobs work with. The setting `repository.id` names it as well
	 * once a job has opened it (RepositoryRecord), but stays empty from the moment another
	 * address is saved until then.
	 *
	 * @return string Repository ID, or '' without a cached key.
	 */
	public function cached_repository_id(): string {
		try {
			$cached = $this->secrets->get( SecretName::MasterKey );
		} catch ( SecretsUndecryptable ) {
			return '';
		}
		$fields = null === $cached ? null : json_decode( $cached->reveal(), true );
		return is_array( $fields ) && is_string( $fields['repository'] ?? null ) ? $fields['repository'] : '';
	}

	/**
	 * Caches the master key.
	 *
	 * @param Config    $config Configuration of the repository the key opened.
	 * @param MasterKey $key    Master key.
	 */
	public function cache_master_key( Config $config, MasterKey $key ): void {
		$this->secrets->set(
			SecretName::MasterKey,
			new Secret(
				(string) json_encode(
					[
						'repository' => $config->id,
						'key'        => $key->to_json(),
					]
				)
			)
		);
	}

	/**
	 * Forgets the cached master key, for example after the password changed.
	 */
	public function forget_master_key(): void {
		$this->secrets->delete( SecretName::MasterKey );
	}

	/**
	 * Opens the repository with a master key.
	 *
	 * @param MasterKey $key Master key.
	 * @return Repository Repository.
	 */
	public function open( MasterKey $key ): Repository {
		return Repository::open( $this->backend(), $key, $this->random );
	}
}
