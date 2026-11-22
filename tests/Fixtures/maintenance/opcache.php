<?php
/**
 * For FileMaintenanceTest: runs with OPcache on and without timestamp checks, as some hosts
 * do. One job's marker is compiled and then removed by another process, a second job
 * publishes its own marker under the same name, and after its cleanup core's upgrader
 * writes a marker of its own there. Every marker is required as WordPress requires it.
 *
 * The scenario "refused" runs under the OPcache settings that the test passes, such as an
 * opcache.restrict_api that leaves this script out: it enters one job's maintenance twice,
 * reports the refusal code and the duration of each attempt, and releases the marker.
 *
 * Usage: php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0
 *        -d opcache.file_update_protection=0 opcache.php <root> [refused]. Prints the
 *        outcome as JSON.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\FileMaintenance;

$root = $argv[1];
define( 'ABSPATH', $root . '/' );
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

if ( 'refused' === ( $argv[2] ?? null ) ) {
	if ( ! function_exists( 'opcache_invalidate' ) ) {
		echo json_encode( [ 'opcache' => false ] );
		return;
	}
	$id      = '20261120T000000Z-333333';
	$dir     = $root . '/jobs/' . $id;
	$service = new FileMaintenance( $root );
	mkdir( $dir, 0700, true );
	$attempt = static function () use ( $service, $id, $dir ): array {
		$started = microtime( true );
		try {
			$service->enter( $id, $dir, '' );
			$code = null;
		} catch ( StorageFailed $refused ) {
			$code = $refused->context()['code'] ?? 'storage';
		}
		return [
			'code'    => $code,
			'seconds' => microtime( true ) - $started,
		];
	};
	$result = [
		'opcache' => true,
		'first'   => $attempt(),
		'again'   => $attempt(),
	];
	try {
		$service->release( $id, $dir );
		$result['released'] = true;
	} catch ( StorageFailed ) {
		$result['released'] = false;
	}
	$result['left'] = file_exists( $root . '/.maintenance' );
	echo json_encode( $result, JSON_PRESERVE_ZERO_FRACTION );
	return;
}

if ( ! function_exists( 'opcache_invalidate' ) || ! function_exists( 'opcache_is_script_cached' ) || false === opcache_get_status( false ) ) {
	echo json_encode( [ 'opcache' => false ] );
	return;
}

/**
 * The gate adds its filters to WordPress, which this process does not load.
 */
function add_filter(): bool {
	return true;
}

$marker  = $root . '/.maintenance';
$first   = '20261120T000000Z-111111';
$second  = '20261120T000000Z-222222';
$token   = bin2hex( random_bytes( 32 ) );
$service = new FileMaintenance( $root, 'backups-for-restic/backups-for-restic.php', 'https://example.com/wp-json/backups-for-restic/v1', 'https://example.com/wp-admin/admin.php' );
foreach ( [ $first, $second ] as $id ) {
	mkdir( $root . '/jobs/' . $id, 0700, true );
}
// Every request is the second job's controller polling its status.
$_SERVER['REQUEST_URI']      = '/wp-json/backups-for-restic/v1/jobs/' . $second;
$_SERVER['REQUEST_METHOD']   = 'GET';
$_COOKIE['bfr_file_control'] = $token;

$service->enter( $first, $root . '/jobs/' . $first, hash( 'sha256', bin2hex( random_bytes( 32 ) ) ) );
$upgrading = null;
require $marker;
$result = [
	'opcache' => true,
	'cached'  => opcache_is_script_cached( $marker ),
	'first'   => $upgrading,
];
// Another process clears the first job's marker without this process's cache.
unlink( $marker );
unlink( $root . '/jobs/' . $first . '/maintenance.php' );

$service->enter( $second, $root . '/jobs/' . $second, hash( 'sha256', $token ) );
require $marker;
$result['second'] = $upgrading;
$service->release( $second, $root . '/jobs/' . $second );

// WP_Upgrader::maintenance_mode() writes its marker through WP_Filesystem, without OPcache.
file_put_contents( $marker, '<?php $upgrading = 1763600000; ?>' );
require $marker;
$result['core'] = $upgrading;

echo json_encode( $result, JSON_PRESERVE_ZERO_FRACTION );
