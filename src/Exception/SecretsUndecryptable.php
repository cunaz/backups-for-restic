<?php
/**
 * Stored secrets cannot be decrypted.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The secret store holds ciphertext that the current key cannot open. Usually the WordPress
 * salts in wp-config.php changed; the administrator has to enter the passwords again.
 */
final class SecretsUndecryptable extends PluginException {
}
