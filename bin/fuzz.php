<?php
/**
 * Fuzzes every parser that reads server data (ADR 0024).
 *
 * Usage: php bin/fuzz.php [--seconds=10] [--seed=N] [--replay=FILE] [target ...]
 *
 * Without targets, all run one after another for --seconds each. Findings are saved under
 * .tools/fuzz-findings/ together with the command that reproduces them. Exit code 1 if
 * anything was found. Fixed findings belong in tests/Fixtures/fuzz/<target>/ so that the
 * unit suite replays them forever.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use BackupsForRestic\Tests\Fuzz\Fuzzer;
use BackupsForRestic\Tests\Fuzz\Targets;

$options = getopt( '', [ 'seconds:', 'seed:', 'replay:' ], $rest );
$seconds = isset( $options['seconds'] ) && is_string( $options['seconds'] ) ? max( 0.1, (float) $options['seconds'] ) : 10.0;
$seed    = isset( $options['seed'] ) && is_string( $options['seed'] ) ? (int) $options['seed'] : random_int( 1, PHP_INT_MAX );
$replay  = isset( $options['replay'] ) && is_string( $options['replay'] ) ? $options['replay'] : null;
$targets = Targets::all();
$names   = array_slice( $argv, $rest );
$names   = [] === $names ? array_keys( $targets ) : $names;

foreach ( $names as $name ) {
	if ( ! isset( $targets[ $name ] ) ) {
		fwrite( STDERR, "Unknown target {$name}. Targets: " . implode( ', ', array_keys( $targets ) ) . "\n" );
		exit( 2 );
	}
}

if ( null !== $replay ) {
	$input   = (string) file_get_contents( $replay );
	$fuzzer  = new Fuzzer( $targets[ $names[0] ], new Randomizer( new Xoshiro256StarStar( $seed ) ) );
	$outcome = $fuzzer->execute( $input );
	foreach ( $fuzzer->findings() as [ , $error ] ) {
		echo $error, "\n";
	}
	echo $outcome, "\n";
	exit( 'finding' === $outcome ? 1 : 0 );
}

printf( "Seed %d, %.1f s per target\n\n%-20s %10s %10s %8s\n", $seed, $seconds, 'Target', 'Inputs', 'Behaviours', 'Findings' );
$found     = 0;
$directory = dirname( __DIR__ ) . '/.tools/fuzz-findings';
foreach ( $names as $name ) {
	$fuzzer = new Fuzzer( $targets[ $name ], new Randomizer( new Xoshiro256StarStar( $seed ) ) );
	$fuzzer->run( $seconds );
	printf( "%-20s %10d %10d %8d\n", $name, $fuzzer->executions(), $fuzzer->behaviours(), count( $fuzzer->findings() ) );
	foreach ( $fuzzer->findings() as [ $input, $error ] ) {
		++$found;
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0700, true );
		}
		$file = $directory . '/' . $name . '-' . sha1( $input ) . '.bin';
		file_put_contents( $file, $input );
		printf( "  %s: %s in %s:%d\n  Replay: php bin/fuzz.php --replay=%s %s\n", $error::class, $error->getMessage(), $error->getFile(), $error->getLine(), $file, $name );
	}
}
exit( $found > 0 ? 1 : 0 );
