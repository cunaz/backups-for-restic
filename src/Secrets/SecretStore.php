<?php
/**
 * Where secrets are kept.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Secrets;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\SecretsUndecryptable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes secrets (ADR 0006).
 */
interface SecretStore {

	/**
	 * Reads a secret.
	 *
	 * @param SecretName $name Secret.
	 * @return Secret|null The secret, or null if none is stored.
	 * @throws SecretsUndecryptable If a stored secret cannot be decrypted, for example after the salts changed.
	 */
	public function get( SecretName $name ): ?Secret;

	/**
	 * Stores a secret.
	 *
	 * @param SecretName $name   Secret.
	 * @param Secret     $secret Value.
	 * @throws InvalidArgument If a wp-config.php constant defines this secret.
	 * @throws SecretsUndecryptable If the secret cannot be read back after it was stored.
	 */
	public function set( SecretName $name, Secret $secret ): void;

	/**
	 * Deletes a stored secret.
	 *
	 * @param SecretName $name Secret.
	 */
	public function delete( SecretName $name ): void;

	/**
	 * Whether a wp-config.php constant defines the secret, which makes it read-only.
	 *
	 * @param SecretName $name Secret.
	 * @return bool True if overridden.
	 */
	public function is_overridden( SecretName $name ): bool;
}
