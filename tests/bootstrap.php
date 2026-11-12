<?php
/**
 * PHPUnit bootstrap: the engine is WordPress-free, so unit tests only need the autoloader.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

// PHPUnit reports a test that ends PHP as a "premature end of PHP process" and nothing else.
// Say what ended it, such as the memory limit: that is what one needs to know.
register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			fwrite( STDERR, sprintf( "\nFatal error: %s in %s on line %d\n", $error['message'], $error['file'], $error['line'] ) );
		}
	}
);
