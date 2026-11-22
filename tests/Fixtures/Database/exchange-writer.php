<?php
// A request that started before maintenance: it writes a post, keeps its session while the
// job enters maintenance, then writes the metadata that belongs to that post. With a sixth
// argument it first closes its session and opens a new one after that many seconds.
declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use BackupsForRestic\Tests\Support\MysqliConnection as TestConnection;

[ , $database, $maintenance, $started, $delay ] = $argv;
$reconnect                                      = $argv[5] ?? null;
if ( 1 !== preg_match( '/\Abfr_exchange_[a-f0-9]{12}\z/', $database ) || ! is_numeric( $delay ) || ( null !== $reconnect && ! is_numeric( $reconnect ) ) ) {
	throw new RuntimeException( 'Invalid disposable exchange fixture.' );
}
$db = TestConnection::connect( $database );
if ( null === $db ) {
	throw new RuntimeException( 'The writer database is unavailable.' );
}
$db->execute( "INSERT INTO wp_posts VALUES (9001,'in-flight-post')" );
file_put_contents( $started, 'started' );
$until = microtime( true ) + 60;
while ( ! is_file( $maintenance ) && microtime( true ) < $until ) {
	usleep( 5000 );
	clearstatcache();
}
if ( ! is_file( $maintenance ) ) {
	throw new RuntimeException( 'The job never entered maintenance.' );
}
if ( null !== $reconnect ) {
	// The request loses its session, for example to a proxy or a server timeout, and comes
	// back under a new connection ID that no earlier session list contained.
	$db->mysqli->close();
	usleep( (int) ( (float) $reconnect * 1000000 ) );
	$db = TestConnection::connect( $database );
	if ( null === $db ) {
		throw new RuntimeException( 'The writer could not reconnect.' );
	}
}
usleep( (int) ( (float) $delay * 1000000 ) );
$db->execute( "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (2,'in_flight_post','9001')" );
echo 'written';
