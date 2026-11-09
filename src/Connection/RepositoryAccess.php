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
		$url = $this->settings->string( 'repository.url' );
		if ( '' === $url ) {
			InvalidArgument::raise( 'No repository is configured.', [ 'code' => 'no_repository' ] );
		}
		$username = $this->settings->string( 'repository.rest_username' );
		$password = $this->secrets->get( SecretName::RestPassword );
		return new RestBackend( $this->http, $url, '' === $username ? null : $username, $password?->reveal(), $this->user_agent );
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
	 * The repository opened with the cached master key, if there is one and it fits. A key
	 * fits when it decrypts the repository's config, so a cached key never serves another
	 * repository; a key that does not fit is forgotten.
	 *
	 * @return Repository|null Repository, or null if it has to be unlocked.
	 * @throws SecretsUndecryptable If the cache cannot be decrypted.
	 */
	public function open_cached(): ?Repository {
		$cached = $this->secrets->get( SecretName::MasterKey );
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
