<?php
/**
 * Port to the WordPress options table.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Small named values that live in the database. WordPress\WpOptions adapts get_option()
 * and friends; tests use an in-memory map. Keeping this a port lets settings and secrets be
 * tested without WordPress.
 */
interface Options {

	/**
	 * Reads an option.
	 *
	 * @param string $name Option name.
	 * @return mixed Stored value, or null if the option does not exist.
	 */
	public function get( string $name ): mixed;

	/**
	 * Writes an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value; arrays and scalars only.
	 * @param bool   $autoload Whether WordPress loads the option on every request.
	 */
	public function set( string $name, mixed $value, bool $autoload ): void;

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): void;
}
