<?php
/**
 * PSR-4 autoloader for the BackupsForRestic namespace.
 *
 * The plugin ships without Composer (ADR 0002), so this is the only autoloader at runtime.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BackupsForRestic\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
