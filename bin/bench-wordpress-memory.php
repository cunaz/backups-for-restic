<?php
/**
 * CI acceptance for complete backup, check and prune jobs against large valid history.
 *
 * Usage: php -d memory_limit=512M bin/bench-wordpress-memory.php 500000|1000000|2000000
 * Requires the disposable WordPress, restic and rest-server installed by the integration
 * workflow. Only this fixture producer and coordinator use 512M; every measured tick is
 * a new PHP process with 128M and a complete WordPress bootstrap.
 * Install the site with BFR_DISABLE_WP_CRON=1 so HTTP requests cannot spawn unmeasured ticks.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\MemoryRepository;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;

$blobs = (int) ( $argv[1] ?? 0 );
if ( ! in_array( $blobs, [ 500000, 1000000, 2000000 ], true ) || ! TestSite::available() ) {
	throw new RuntimeException( 'Select a fixture size and install/start the integration test site first.' );
}
if ( 'disabled' !== TestSite::php( 'echo defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON ? "disabled" : "enabled";' ) ) {
	throw new RuntimeException( 'Install the benchmark site with BFR_DISABLE_WP_CRON=1 before creating jobs.' );
}
$root   = dirname( __DIR__ );
$report = $root . '/.tools/memory-' . $blobs . '.jsonl';
if ( file_exists( $report ) ) {
	throw new RuntimeException( 'A memory report already exists; use a fresh test site.' );
}
$record = static function ( array $row ) use ( $report ): void {
	$line = json_encode( $row, JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $line ) !== file_put_contents( $report, $line, FILE_APPEND ) ) {
		throw new RuntimeException( 'Cannot record a complete memory result.' );
	}
};
$api    = static function ( string $method, string $route, array $body ): array {
	[ $status, $answer, $raw ] = TestSite::api( $method, $route, $body );
	if ( ! in_array( $status, [ 200, 201 ], true ) ) {
		throw new RuntimeException( $route . ': ' . $raw );
	}
	return $answer;
};
$drive  = static function ( string $id, string $operation ) use ( $root, $record, $blobs ): array {
	$peak    = 0;
	$seconds = 0.0;
	for ( $tick = 0; $tick < 600; ++$tick ) {
		$command = [ PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=30', '-d', 'disable_functions=set_time_limit', '-d', 'opcache.enable_cli=0', $root . '/bin/bench-wordpress-memory-tick.php', $id ];
		$process = proc_open( $command, [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'file', $root . '/.tools/memory-worker-errors.log', 'a' ] ], $pipes );
		if ( false === $process ) {
			throw new RuntimeException( 'Cannot start the measured WordPress process.' );
		}
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$status = proc_close( $process );
		if ( 0 !== $status || false === $output ) {
			$record(
				[
					'operation' => $operation,
					'tick'      => $tick,
					'exit'      => $status,
					'output'    => $output,
				]
			);
			throw new RuntimeException( 'A measured WordPress tick crashed; see the memory report and worker error log.' );
		}
		$row = json_decode( $output, true, flags: JSON_THROW_ON_ERROR );
		$record(
			[
				'operation' => $operation,
				'tick'      => $tick,
			] + $row
		);
		if ( '128M' !== $row['memory_limit'] || '30' !== $row['max_execution_time'] || $row['set_time_limit'] || $row['peak_reserved'] > 128 * 1048576 ) {
			throw new RuntimeException( 'A worker did not retain the acceptance limits.' );
		}
		$peak     = max( $peak, $row['peak_reserved'] );
		$seconds += $row['seconds'];
		if ( in_array( $row['state'], [ 'succeeded', 'failed', 'cancelled' ], true ) ) {
			$summary = [
				'operation'     => $operation,
				'blobs'         => $blobs,
				'state'         => $row['state'],
				'error'         => $row['error']['code'] ?? null,
				'ticks'         => $tick + 1,
				'peak_reserved' => $peak,
				'seconds'       => $seconds,
			];
			$record( [ 'summary' => $summary ] );
			echo json_encode( $summary, JSON_THROW_ON_ERROR ), "\n";
			return $row;
		}
		if ( $tick % 10 === 0 ) {
			echo $operation, ' tick ', $tick, ' ', $row['step'], "\n";
		}
	}
	throw new RuntimeException( 'The job exceeded the acceptance tick limit.' );
};

$scratch = TempDir::create( 'wordpress-memory' );
try {
	$server     = TestSite::server();
	$backend    = new FileBackend( $server->directory . '/data/site' );
	$repository = Repository::initialize( $backend, MemoryRepository::PASSWORD, new Identity( 'memory-fixture.example' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	$fixture    = ( new MemoryRepository( $repository ) )->build( $blobs, $scratch );
	$record(
		[
			'fixture' => $fixture,
			'php'     => PHP_VERSION,
		]
	);
	unset( $repository );
	TestSite::$repository_password = MemoryRepository::PASSWORD;
	// The fixture must also be accepted by the independent implementation.
	TestSite::restic()->must( [ 'check', '--read-data' ] );
	$api(
		'PUT',
		'/settings',
		[
			'values' => [
				'advanced.loopback_chaining'              => 'off',
				'advanced.tick_budget_seconds'            => 15,
				'schedule.backup.frequency'               => 'manual',
				'schedule.prune.frequency'                => 'manual',
				'schedule.check.frequency'                => 'manual',
				'schedule.check.read_data_subset_percent' => 10,
				'retention.run_forget_after_backup'       => false,
				'notifications.email.on_failure'          => false,
				'notifications.email.on_stale'            => false,
			],
		]
	);
	$api( 'POST', '/repository/ca-certificate', [ 'pem' => file_get_contents( $server->certificate ) ] );
	$api( 'POST', '/repository/save', TestSite::connection() );
	$connected = $api(
		'POST',
		'/repository/connect',
		[
			'password'      => MemoryRepository::PASSWORD,
			'user_password' => TestSite::ADMIN_PASSWORD,
		]
	);
	if ( isset( $connected['job']['id'] ) ) {
		$unlocked = $drive( $connected['job']['id'], 'unlock' );
		if ( 'succeeded' !== $unlocked['state'] ) {
			throw new RuntimeException( 'The fixture repository did not unlock.' );
		}
	}
	$api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
	TestSite::add_upload( 'memory-payload.bin', 16 );
	foreach ( [ 'backup', 'check', 'prune' ] as $operation ) {
		$job     = $drive( TestSite::start( $operation ), $operation );
		$refused = 'failed' === $job['state'] && 'index_too_large' === ( $job['error']['code'] ?? '' );
		if ( 500000 === $blobs ? 'succeeded' !== $job['state'] : ( 'succeeded' !== $job['state'] && ! $refused ) ) {
			throw new RuntimeException( 'Unexpected job outcome: ' . json_encode( $job, JSON_THROW_ON_ERROR ) );
		}
		if ( 'succeeded' === $job['state'] && 'prune' === $operation && ( $job['result']['packs_deleted'] ?? 0 ) < 1 ) {
			throw new RuntimeException( 'Prune did no work on the unused pack.' );
		}
		if ( 'succeeded' === $job['state'] && 'check' === $operation && ( $job['result']['errors'] ?? -1 ) !== 0 ) {
			throw new RuntimeException( 'The check found unexpected damage.' );
		}
		if ( [] !== $backend->list( ObjectType::Locks ) ) {
			throw new RuntimeException( 'A completed or refused job left a repository lock.' );
		}
	}
	TestSite::restic()->must( [ 'check', '--read-data' ] );
} finally {
	TestSite::remove_upload( 'memory-payload.bin' );
	TempDir::remove( $scratch );
}
