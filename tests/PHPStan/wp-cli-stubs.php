<?php
/**
 * The few WP-CLI methods the plugin calls, for static analysis only.
 *
 * Declared here for the CLI entry points.
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

	/**
	 * @param string $message
	 * @param array<string,string> $assoc_args
	 */
	public static function confirm( $message, $assoc_args = [] ): void {}

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
