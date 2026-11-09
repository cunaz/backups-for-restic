<?php
/**
 * The few WP-CLI methods the plugin calls, for static analysis only.
 *
 * Declared here instead of adding a stubs package: the plugin uses five methods.
 *
 * @package BackupsForRestic\Tests
 */

// phpcs:disable

class WP_CLI {
	/**
	 * @param string $name
	 * @param object $command
	 */
	public static function add_command( $name, $command ): bool {}

	/** @param string $message */
	public static function line( $message = '' ): void {}

	/** @param string $message */
	public static function success( $message ): void {}

	/** @param string $message */
	public static function warning( $message ): void {}

	/**
	 * @param string $message
	 * @return never
	 */
	public static function error( $message ) {}
}
