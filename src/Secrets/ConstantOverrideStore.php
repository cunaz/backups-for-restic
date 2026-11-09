<?php
/**
 * Secrets defined in wp-config.php.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Secrets;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Lets wp-config.php constants win over stored secrets (ADR 0006). Operators who define
 * BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD keep their backups readable after a salt rotation,
 * and the password never touches the database.
 */
final class ConstantOverrideStore implements SecretStore {

	/**
	 * Values of the defined constants, by constant name.
	 *
	 * @var array<string, Secret>
	 */
	private readonly array $constants;

	/**
	 * Creates the store.
	 *
	 * @param SecretStore           $inner     Store for everything not defined as a constant.
	 * @param array<string, string> $constants Values of the defined constants, by constant name.
	 */
	public function __construct( private readonly SecretStore $inner, #[\SensitiveParameter] array $constants ) {
		$wrapped = [];
		foreach ( $constants as $constant => $value ) {
			if ( '' !== $value ) {
				$wrapped[ $constant ] = new Secret( $value );
			}
		}
		$this->constants = $wrapped;
	}

	/**
	 * Reads a secret, preferring the constant.
	 *
	 * @param SecretName $name Secret.
	 * @return Secret|null The secret, or null if none is defined or stored.
	 */
	public function get( SecretName $name ): ?Secret {
		$constant = $name->constant();
		return null !== $constant && isset( $this->constants[ $constant ] ) ? $this->constants[ $constant ] : $this->inner->get( $name );
	}

	/**
	 * Stores a secret unless a constant defines it.
	 *
	 * @param SecretName $name   Secret.
	 * @param Secret     $secret Value.
	 * @throws InvalidArgument If a constant defines the secret.
	 */
	public function set( SecretName $name, Secret $secret ): void {
		if ( $this->is_overridden( $name ) ) {
			InvalidArgument::raise( 'This secret is defined in wp-config.php and cannot be changed here.', [ 'secret' => $name->value ] );
		}
		$this->inner->set( $name, $secret );
	}

	/**
	 * Deletes a stored secret; constants stay.
	 *
	 * @param SecretName $name Secret.
	 */
	public function delete( SecretName $name ): void {
		$this->inner->delete( $name );
	}

	/**
	 * Whether a constant defines the secret.
	 *
	 * @param SecretName $name Secret.
	 * @return bool True if overridden.
	 */
	public function is_overridden( SecretName $name ): bool {
		$constant = $name->constant();
		return null !== $constant && isset( $this->constants[ $constant ] );
	}
}
