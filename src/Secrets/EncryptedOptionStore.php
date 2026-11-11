<?php
/**
 * Secrets encrypted in the options table.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Secrets;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\Options;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps secrets in one option that is not autoloaded, each one encrypted with
 * sodium_crypto_secretbox() (ADR 0006, docs/03-security-and-threat-model.md §5.2).
 *
 * The key is HKDF-SHA256 over the salt constants from wp-config.php, with a random
 * per-installation salt stored next to the ciphertexts. A copy of the database alone
 * therefore yields only ciphertext. If the salt constants change, the secrets become
 * undecryptable and get() says so instead of returning nothing.
 *
 * Option layout: ['salt' => base64url(32 bytes), 'secrets' => [name => 'v1.<nonce>.<box>']].
 */
final class EncryptedOptionStore implements SecretStore {

	public const string OPTION = 'backups_for_restic_secrets';

	private const string INFO = 'backups-for-restic/secrets/v1';

	private const string PREFIX = 'v1.';

	/**
	 * Used instead of empty key material when wp-config.php defines no salt constants. The
	 * Doctor reports that case: the secrets are then readable by anyone with the database.
	 */
	private const string NO_KEY_MATERIAL = 'backups-for-restic/no-salt-constants';

	/**
	 * Creates the store.
	 *
	 * @param Options       $options      Options table.
	 * @param Secret        $key_material Concatenated salt constants from wp-config.php.
	 * @param RandomSource  $random       Source of salts and nonces.
	 * @param FileLock|null $lock         Held while the option is read, changed and written back.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly Secret $key_material,
		private readonly RandomSource $random,
		private readonly ?FileLock $lock = null
	) {
	}

	/**
	 * Reads and decrypts a secret.
	 *
	 * @param SecretName $name Secret.
	 * @return Secret|null The secret, or null if none is stored.
	 * @throws SecretsUndecryptable If the ciphertext cannot be opened with the current key.
	 */
	public function get( SecretName $name ): ?Secret {
		$stored = $this->stored();
		$box    = $stored['secrets'][ $name->value ] ?? null;
		return null === $box ? null : new Secret( $this->open( $box, $stored['salt'] ) );
	}

	/**
	 * Encrypts and stores a secret, then reads it back: a secret that the options table did not
	 * keep, or that comes back changed, must not pass for saved
	 * (docs/03-security-and-threat-model.md §13).
	 *
	 * @param SecretName $name   Secret.
	 * @param Secret     $secret Value.
	 * @throws SecretsUndecryptable If the secret that is stored afterwards is not the one given.
	 */
	public function set( SecretName $name, Secret $secret ): void {
		// From the database and under the lock: all secrets are in the one option, and a job's
		// tick that caches the key must not write back the passwords as they were when it
		// started, nor cross a password that is saved at the same moment.
		$this->change(
			function ( array $stored ) use ( $name, $secret ): array {
				$stored['secrets'][ $name->value ] = $this->seal( $secret, $stored['salt'] );
				return $stored;
			}
		);
		$saved = $this->get( $name );
		if ( null === $saved || ! $saved->equals( $secret ) ) {
			SecretsUndecryptable::raise( 'A secret could not be read back after it was saved.', [ 'secret' => $name->value ] );
		}
	}

	/**
	 * Deletes a stored secret.
	 *
	 * @param SecretName $name Secret.
	 */
	public function delete( SecretName $name ): void {
		$this->change(
			static function ( array $stored ) use ( $name ): ?array {
				if ( ! isset( $stored['secrets'][ $name->value ] ) ) {
					return null;
				}
				unset( $stored['secrets'][ $name->value ] );
				return $stored;
			}
		);
	}

	/**
	 * Changes the option, starting from what the database holds now.
	 *
	 * @param \Closure(array{salt: string, secrets: array<string, string>}): (array{salt: string, secrets: array<string, string>}|null) $change Returns what to store; null to store nothing.
	 */
	private function change( \Closure $change ): void {
		$apply = function () use ( $change ): void {
			$next = $change( $this->stored( true ) );
			if ( null !== $next ) {
				$this->options->set( self::OPTION, $next, false );
			}
		};
		if ( null === $this->lock ) {
			$apply();
		} else {
			$this->lock->hold( $apply );
		}
	}

	/**
	 * Nothing in this store is overridden; see ConstantOverrideStore.
	 *
	 * @param SecretName $name Secret.
	 * @return bool Always false.
	 */
	public function is_overridden( SecretName $name ): bool {
		return false;
	}

	/**
	 * Loads the option, creating the installation salt on first use.
	 *
	 * @param bool $fresh Whether to read past what this request read before, as a change has to.
	 * @return array{salt: string, secrets: array<string, string>} Stored data.
	 */
	private function stored( bool $fresh = false ): array {
		$value   = $fresh ? $this->options->fresh( self::OPTION ) : $this->options->get( self::OPTION );
		$salt    = is_array( $value ) && is_string( $value['salt'] ?? null ) ? $value['salt'] : '';
		$secrets = [];
		if ( is_array( $value ) && is_array( $value['secrets'] ?? null ) ) {
			foreach ( $value['secrets'] as $name => $box ) {
				if ( is_string( $name ) && is_string( $box ) ) {
					$secrets[ $name ] = $box;
				}
			}
		}
		if ( '' === $salt ) {
			$salt = Bytes::base64url_encode( $this->random->bytes( 32 ) );
		}
		return [
			'salt'    => $salt,
			'secrets' => $secrets,
		];
	}

	/**
	 * Derives the encryption key.
	 *
	 * @param string $salt Installation salt (base64url).
	 * @return string 32-byte key.
	 * @throws SecretsUndecryptable If the stored salt is malformed.
	 */
	private function key( string $salt ): string {
		try {
			$salt_bytes = Bytes::base64url_decode( $salt );
		} catch ( FormatException $e ) {
			SecretsUndecryptable::raise( 'The stored secrets are damaged.', [], $e );
		}
		$material = $this->key_material->is_empty() ? self::NO_KEY_MATERIAL : $this->key_material->reveal();
		return hash_hkdf( 'sha256', $material, 32, self::INFO, $salt_bytes );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param Secret $secret Value.
	 * @param string $salt   Installation salt.
	 * @return string Ciphertext text.
	 */
	private function seal( Secret $secret, string $salt ): string {
		$nonce = $this->random->bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $secret->reveal(), $nonce, $this->key( $salt ) );
		return self::PREFIX . Bytes::base64url_encode( $nonce ) . '.' . Bytes::base64url_encode( $box );
	}

	/**
	 * Decrypts a value.
	 *
	 * @param string $text Ciphertext text.
	 * @param string $salt Installation salt.
	 * @return string Plaintext.
	 * @throws SecretsUndecryptable If the text is malformed or does not decrypt.
	 */
	private function open( string $text, string $salt ): string {
		$parts = explode( '.', $text );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] ) {
			SecretsUndecryptable::raise( 'A stored secret has an unknown format.' );
		}
		try {
			$nonce = Bytes::base64url_decode( $parts[1] );
			$box   = Bytes::base64url_decode( $parts[2] );
		} catch ( FormatException $e ) {
			SecretsUndecryptable::raise( 'A stored secret is damaged.', [], $e );
		}
		if ( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) || strlen( $box ) < SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			SecretsUndecryptable::raise( 'A stored secret is damaged.' );
		}
		$plaintext = sodium_crypto_secretbox_open( $box, $nonce, $this->key( $salt ) );
		if ( false === $plaintext ) {
			SecretsUndecryptable::raise( 'The stored secrets cannot be decrypted; the salts in wp-config.php may have changed.' );
		}
		return $plaintext;
	}
}
