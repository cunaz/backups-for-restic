<?php
/**
 * Runs one real WordPress job tick in a fresh, memory-limited PHP process (CI only).
 *
 * The coordinator supplies the job ID and starts PHP with 128M, max_execution_time=30
 * and set_time_limit disabled. The absolute peaks include WordPress bootstrap; they are
 * never reset and no baseline is subtracted. This is not a WP-CLI process.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

use BackupsForRestic\WordPress\Container;
use BackupsForRestic\WordPress\Cron;

define( 'DISABLE_WP_CRON', true );
define( 'DOING_CRON', true );
$started = microtime( true );
require dirname( __DIR__ ) . '/.tools/site/wp-load.php';
$baseline  = memory_get_usage( true );
$container = new Container( dirname( __DIR__ ) . '/backups-for-restic.php' );
Cron::tick( $container, chain: false );
$job = $container->jobs()->get( $argv[1] ?? '' );
if ( null === $job ) {
	throw new RuntimeException( 'The measured job is missing.' );
}
echo json_encode(
	[
		'state'              => $job->state->value,
		'step'               => $job->step,
		'error'              => $job->error?->to_array(),
		'progress'           => $job->progress,
		'result'             => $job->result,
		'ticks'              => $job->ticks,
		'memory_limit'       => ini_get( 'memory_limit' ),
		'max_execution_time' => ini_get( 'max_execution_time' ),
		'set_time_limit'     => function_exists( 'set_time_limit' ),
		'baseline_reserved'  => $baseline,
		'peak_reserved'      => memory_get_peak_usage( true ),
		'peak_used'          => memory_get_peak_usage(),
		'seconds'            => microtime( true ) - $started,
	],
	JSON_THROW_ON_ERROR
), "\n";
