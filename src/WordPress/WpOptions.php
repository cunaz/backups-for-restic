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
	 * Reads an option from the database, past what this request has cached.
	 *
	 * @param string $name Option name.
	 * @return mixed Value, or null if missing.
	 */
	public function fresh( string $name ): mixed {
		// Where get_option() keeps an option that is not autoloaded, and where it remembers
		// that an option did not exist. With a persistent object cache other requests read the
		// database once more; nothing is lost.
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, null );
	}

	/**
	 * Writes an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 * @return bool False if the database refused the write.
	 */
	public function set( string $name, mixed $value, bool $autoload ): bool {
		if ( update_option( $name, $value, $autoload ) ) {
			return true;
		}
		// update_option() answers false for a refused write and for a value that is stored
		// already, and it compares in this way to tell that the value is there. What the
		// database holds now decides, not what this request has cached: another request may
		// have stored the same value a moment ago, and then the database changed no row for
		// this one, which WordPress answers with false as well.
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return maybe_serialize( get_option( $name, null ) ) === maybe_serialize( $value );
	}

	/**
	 * Inserts a missing option without ever updating one another request inserted.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Initial value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 * @return bool True if this value is stored after the insert, or was there already.
	 */
	public function initialize( string $name, mixed $value, bool $autoload ): bool {
		$encoded = maybe_serialize( $value );
		if ( ! is_scalar( $encoded ) ) {
			return false;
		}
		$wpdb       = Container::wpdb();
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// add_option() also updates on a duplicate key after its cached existence check.
			// This duplicate-key clause leaves every column of the existing row unchanged.
			$query = $wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = option_name',
				$wpdb->options,
				$name,
				$encoded,
				$autoload ? 'on' : 'off'
			);
			if ( null === $query ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; atomic insertion needs a database constraint and the caches are invalidated below.
			$result = $wpdb->query( $query );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		// Database scalars are strings; a missing option is not an empty stored value.
		$stored = maybe_serialize( get_option( $name, null ) );
		return false !== $result && is_scalar( $stored ) && (string) $stored === (string) $encoded;
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
