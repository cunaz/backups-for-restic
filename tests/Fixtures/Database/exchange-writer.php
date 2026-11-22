<?php
// A request that started before maintenance: it writes a post, keeps its session while the
// job enters maintenance, then writes the metadata that belongs to that post.
declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use BackupsForRestic\Tests\Support\MysqliConnection as TestConnection;

[ , $database, $maintenance, $started, $delay ] = $argv;
if ( 1 !== preg_match( '/\Abfr_exchange_[a-f0-9]{12}\z/', $database ) || ! is_numeric( $delay ) ) {
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
usleep( (int) ( (float) $delay * 1000000 ) );
$db->execute( "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (2,'in_flight_post','9001')" );
echo 'written';
