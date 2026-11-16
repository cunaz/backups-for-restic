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
	 * Reads an option as the database holds it now. get() may answer with what this request
	 * read before; who changes a value that other requests change as well starts from this.
	 *
	 * @param string $name Option name.
	 * @return mixed Stored value, or null if the option does not exist.
	 */
	public function fresh( string $name ): mixed;

	/**
	 * Writes an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value; arrays and scalars only.
	 * @param bool   $autoload Whether WordPress loads the option on every request.
	 * @return bool False if the options table did not take the value: it holds another one
	 *              afterwards. A value that was there already counts as written.
	 */
	public function set( string $name, mixed $value, bool $autoload ): bool;

	/**
	 * Inserts an option only if its name is absent, atomically with respect to other writers.
	 * An existing value and its autoload flag are never changed, even after a stale read.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Initial value; arrays and scalars only.
	 * @param bool   $autoload Whether WordPress loads the option on every request.
	 * @return bool True if this value is stored afterwards, including an identical value
	 *              another writer inserted; false on a different value or a refused write.
	 */
	public function initialize( string $name, mixed $value, bool $autoload ): bool;

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): void;
}
