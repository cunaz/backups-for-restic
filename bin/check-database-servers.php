<?php
/**
 * Checks the database servers of the database mutation gate, one per Infection thread
 * (tests/Support/DatabaseServers.php, docs/04 §5.6). bin/database-servers.sh runs it before the
 * gate and after it.
 *
 * Usage: php bin/check-database-servers.php before [--answers FILE]
 *        php bin/check-database-servers.php after STARTED [--answers FILE]
 *
 * before: every server that BFR_TEST_MYSQL_HOSTS names answers with the account of
 * BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD, no two of them are one server (server_uuid),
 * all run the version of the first, and their Performance Schema is on. Then the first server,
 * on which Infection's initial test run runs, is cleared of what an earlier gate left: other
 * sessions and a lowered max_allowed_packet (DatabaseServers::first()).
 * after: every server still answers, has run since STARTED, the Unix time at which the gate
 * started, so that no restart cut off the tests on it or reset its counters, and its Performance
 * Schema could instrument every thread (tests/Support/PerformanceSchemaCheck.php). Each server's
 * uptime is held to the time of its own answer.
 *
 * --answers FILE reads what each server answers from a JSON object of hosts and answers, null
 * or a missing host for one that does not answer, instead of asking the servers, for
 * tests/Unit/Tooling/DatabaseServersTest.php.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

// The source files end a request without ABSPATH, and that silently, with status 0: loading
// MysqliConnection reaches them. So define it, and fail unless the check reaches its verdict.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Tests\Support\DatabaseServers;

$verdict = false;
register_shutdown_function(
	static function () use ( &$verdict ): void {
		if ( ! $verdict ) {
			echo "::error::The check of the database servers ended before its verdict, so the database scores cannot be trusted.\n";
			exit( 1 );
		}
	}
);

$arguments = array_slice( $argv, 1 );
$mode      = $arguments[0] ?? '';
$started   = $arguments[1] ?? '';
if ( 'before' !== $mode && ( 'after' !== $mode || 1 !== preg_match( '/\A[0-9]{1,18}\z/', $started ) ) ) {
	$verdict = true;
	echo "::error::Usage: php bin/check-database-servers.php before, or after with the Unix time at which the gate started.\n";
	exit( 1 );
}
$hosts    = DatabaseServers::hosts( getenv( DatabaseServers::VARIABLE ) );
$position = array_search( '--answers', $arguments, true );
$answers  = [];
$asked    = [];
if ( false !== $position ) {
	$file    = $arguments[ $position + 1 ] ?? '';
	$decoded = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	foreach ( $hosts as $host ) {
		$answers[ $host ] = null;
		if ( is_array( $decoded ) && is_array( $decoded[ $host ] ?? null ) ) {
			$answers[ $host ] = [];
			foreach ( $decoded[ $host ] as $name => $value ) {
				$answers[ $host ][ (string) $name ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
	}
} else {
	// Each server is held to the time at which its own question began; the servers after it
	// may take their time.
	[ $answers, $asked ] = DatabaseServers::asked( $hosts, (int) $started );
}

$seconds  = 'after' === $mode ? max( 0, time() - (int) $started ) : 0;
$problems = 'before' === $mode ? DatabaseServers::before( $hosts, $answers ) : DatabaseServers::after( $hosts, $answers, $seconds, $asked );
if ( 'before' === $mode && [] === $problems && false === $position ) {
	// The initial test run uses the first server and has no mutant process to clear it.
	$problem = DatabaseServers::first( $hosts[0] );
	if ( null !== $problem ) {
		$problems[] = $problem;
	}
}
$verdict = true;
foreach ( $problems as $problem ) {
	echo '::error::', $problem, "\n";
}
if ( [] !== $problems ) {
	exit( 1 );
}
$servers = array_map( static fn ( string $host ): string => $host . ' (' . ( $answers[ $host ]['version'] ?? '' ) . ', server_uuid ' . ( $answers[ $host ]['server_uuid'] ?? '' ) . ')', $hosts );
if ( 'before' === $mode ) {
	echo 'Database servers of the gate, one per Infection thread: ', count( $hosts ), ', each a server of its own: ', implode( ', ', $servers ), ".\n";
} else {
	echo 'Every database server of the gate ran through it without a restart (', $seconds, ' seconds) and its Performance Schema lost no thread: ', implode( ', ', $servers ), ".\n";
}
