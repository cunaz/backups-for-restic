<?php
/**
 * Creates disk fixtures and measures individual engine ticks in fresh PHP processes.
 *
 * Usage:
 * php -d memory_limit=512M bin/bench-job-memory.php create DIRECTORY BLOBS
 * php -d memory_limit=128M bin/bench-job-memory.php tick DIRECTORY backup|prune|check [SECONDS]
 *
 * The tick command performs one advance, prints absolute process peaks and exits. Repeat
 * in a fresh process until done. This standalone command is not WordPress acceptance.
 * Repository bytes stay on disk and fixture creation is outside the measured process.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\MemoryRepository;

$command   = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if ( ! in_array( $command, [ 'create', 'tick' ], true ) || '' === $directory ) {
	fwrite( STDERR, "Usage: bench-job-memory.php create DIRECTORY BLOBS | tick DIRECTORY backup|prune|check [SECONDS]\n" );
	exit( 2 );
}
$identity = new Identity( 'memory-fixture.example' );
$random   = new SystemRandom();
$clock    = new SystemClock();
$backend  = new FileBackend( $directory . '/repository' );
if ( 'create' === $command ) {
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
		throw new RuntimeException( 'Cannot create the fixture directory.' );
	}
	$repository = Repository::initialize( $backend, MemoryRepository::PASSWORD, $identity, $random, new ScryptParameters( 1024, 8, 1 ) );
	Files::write_atomic( $directory . '/fixture-key.json', $repository->master_key()->to_json() );
	$fixture = ( new MemoryRepository( $repository ) )->build( (int) ( $argv[3] ?? 500000 ), $directory );
	mkdir( $directory . '/source', 0700 );
	Files::write_atomic( $directory . '/source/new-data.bin', random_bytes( 16 * 1048576 ) );
	Files::write_atomic( $directory . '/fixture.json', json_encode( $fixture, JSON_THROW_ON_ERROR ) );
	echo json_encode( $fixture, JSON_THROW_ON_ERROR ), "\n";
	exit( 0 );
}
$type = $argv[3] ?? '';
if ( ! in_array( $type, [ 'backup', 'prune', 'check' ], true ) ) {
	throw new InvalidArgumentException( 'Unknown benchmark operation.' );
}
$key_json = file_get_contents( $directory . '/fixture-key.json' );
if ( false === $key_json ) {
	throw new RuntimeException( 'The fixture key is missing.' );
}
$repository = Repository::open( $backend, MasterKey::from_json( $key_json ), $random );
$repository->limit_memory( MemoryBudget::from_ini( ini_get( 'memory_limit' ) ) );
$cache    = new IndexCache( $directory . '/cache' );
$work     = $directory . '/run-' . $type;
$resume   = is_file( $work . '/state.json' );
$started  = microtime( true );
$baseline = memory_get_usage( true );
$run      = null;
try {
	if ( 'backup' === $type ) {
		$plan = new BackupPlan( [ $directory . '/source' ] );
		$run  = $resume
			? BackupRun::resume( $repository, $plan, $work, $identity, $clock, cache: $cache )
			: BackupRun::start( $repository, $plan, $work, $identity, $clock, cache: $cache );
	} elseif ( 'prune' === $type ) {
		$run = $resume
			? PruneRun::resume( $repository, $work, $identity, $clock, cache: $cache )
			: PruneRun::start( $repository, $work, $identity, $clock, $random, cache: $cache );
	} else {
		$run = $resume
			? CheckRun::resume( $repository, $work, $identity, $clock, $cache )
			: CheckRun::start( $repository, $work, $identity, $clock, $random, 10, $cache );
	}
	$done   = $run->advance( $started + (float) ( $argv[4] ?? 15 ) );
	$output = [
		'done'     => $done,
		'progress' => $run->progress(),
	];
} catch ( PluginException $error ) {
	$output = [
		'done'    => false,
		'error'   => $error::class,
		'context' => $error->context(),
	];
	$run?->abort();
}
$output += [
	'operation'         => $type,
	'memory_limit'      => ini_get( 'memory_limit' ),
	'baseline_reserved' => $baseline,
	'peak_reserved'     => memory_get_peak_usage( true ),
	'peak_used'         => memory_get_peak_usage(),
	'seconds'           => microtime( true ) - $started,
];
echo json_encode( $output, JSON_THROW_ON_ERROR ), "\n";
exit( isset( $output['error'] ) ? 1 : 0 );
