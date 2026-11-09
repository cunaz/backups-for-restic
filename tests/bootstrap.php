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
