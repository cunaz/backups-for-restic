<?php
/**
 * Fails when the Performance Schema of the database server that the database mutation gate used
 * could not instrument every thread during the run (tests/Support/PerformanceSchemaCheck.php,
 * docs/04 §5.6). The counters start at zero with the server, so this must ask the server that the
 * gate just used: the one named by BFR_TEST_MYSQL_HOST, _USER and _PASSWORD, as the database
 * suite reads them. composer mutation:database runs it after the gate.
 *
 * Usage: php bin/check-performance-schema.php [--status FILE]
 *
 * --status FILE reads the answer from a JSON object of variable names and values instead of the
 * server, for tests/Unit/Tooling/PerformanceSchemaCheckTest.php. Other arguments are ignored:
 * Composer passes the gate's arguments on to every command of the script.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

// The source files end a request without ABSPATH, and that silently, with status 0: loading
// MysqliConnection reaches them. So define it, and fail unless the check reaches its verdict.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\PerformanceSchemaCheck;

$verdict = false;
register_shutdown_function(
	static function () use ( &$verdict ): void {
		if ( ! $verdict ) {
			echo "::error::The check of the database server's Performance Schema ended before its verdict, so the database scores cannot be trusted.\n";
			exit( 1 );
		}
	}
);

$arguments = array_slice( $argv, 1 );
$position  = array_search( '--status', $arguments, true );
if ( false !== $position ) {
	$file    = (string) ( $arguments[ $position + 1 ] ?? '' );
	$decoded = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	$status  = [];
	foreach ( is_array( $decoded ) ? $decoded : [] as $name => $value ) {
		$status[ (string) $name ] = is_scalar( $value ) ? (string) $value : '';
	}
} else {
	$status = PerformanceSchemaCheck::status( MysqliConnection::connect() );
}

$problems = PerformanceSchemaCheck::problems( $status );
$verdict  = true;
foreach ( $problems as $problem ) {
	echo '::error::', $problem, "\n";
}
if ( [] !== $problems ) {
	exit( 1 );
}
echo 'The Performance Schema of the database server lost no thread during the run: ', implode( ', ', array_map( static fn ( string $counter ): string => $counter . ' ' . $status[ $counter ], PerformanceSchemaCheck::COUNTERS ) ), ".\n";
