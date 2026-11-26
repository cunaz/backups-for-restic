<?php
/**
 * Fails when the skipped tests of a PHPUnit run are not exactly the expected ones (docs/04 §5).
 *
 * Usage: php bin/check-skipped-tests.php JUNIT.xml tests/Skips/LIST.txt
 *
 * Exit code 0 if they match, 1 if they differ, 2 if a file cannot be read.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Tests\Support\SkippedTests;

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/check-skipped-tests.php JUNIT.xml tests/Skips/LIST.txt\n" );
	exit( 2 );
}
[ , $junit_file, $list_file ] = $argv;
$junit                        = is_file( $junit_file ) ? file_get_contents( $junit_file ) : false;
$list                         = is_file( $list_file ) ? file_get_contents( $list_file ) : false;
if ( false === $junit || false === $list ) {
	fwrite( STDERR, 'Cannot read ' . ( false === $junit ? $junit_file : $list_file ) . ".\n" );
	exit( 2 );
}

try {
	$run         = SkippedTests::read( $junit );
	$differences = SkippedTests::differences( $junit, $list );
} catch ( RuntimeException $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 2 );
}
printf( "%d tests, %d skipped by name, %d classes skipped as a whole; expected skips from %s.\n", $run['count'], count( $run['skipped'] ), count( $run['classes'] ), $list_file );
foreach ( array_merge( $run['skipped'], $run['classes'] ) as $test ) {
	echo '  skipped: ', $test, "\n";
}
if ( [] !== $differences ) {
	foreach ( $differences as $difference ) {
		echo '::error::', $difference, "\n";
	}
	echo "Update {$list_file} only if the skip is intended, and say why in the pull request.\n";
	exit( 1 );
}
echo "The skipped tests are the expected ones.\n";
