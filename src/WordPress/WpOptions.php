<?php
/**
 * Options table adapter.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Storage\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The Options port on top of get_option() and friends.
 */
final class WpOptions implements Options {

	/**
	 * Reads an option.
	 *
	 * @param string $name Option name.
	 * @return mixed Value, or null if missing.
	 */
	public function get( string $name ): mixed {
		return get_option( $name, null );
	}

	/**
	 * Writes an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 */
	public function set( string $name, mixed $value, bool $autoload ): void {
		update_option( $name, $value, $autoload );
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): void {
		delete_option( $name );
	}
}
