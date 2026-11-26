<?php
/**
 * Runs one restore or one check of a repository on files in this process, for
 * ReadMemoryTest. PHP's memory limit is the one this process was started with, and the job
 * gets the budget that a job gets from it (MemoryBudget::from_ini()). Reads go through
 * WordPressResponses, which holds every response three times as WordPress does.
 *
 * Before the job, the process fills its memory with small strings up to a number of MiB
 * reserved, as WordPress and a large index fill it. A restore can take more once its
 * planning is done, as memory taken after the index loaded.
 *
 * Usage: php read.php <request.json>. Prints the outcome as JSON: what ended the job, the
 * memory reserved before the first blob was read and at the peak, the data requests while
 * the files were read, and the SHA-256 and size of the restored file.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

$request = json_decode( (string) file_get_contents( $argv[1] ), true, 32, JSON_THROW_ON_ERROR );
define( 'ABSPATH', $request['root'] . '/' );
require $request['root'] . '/vendor/autoload.php';

use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\WordPressResponses;

/**
 * Fills memory with small strings until PHP has reserved the given number of MiB.
 *
 * @param list<string> $ballast Strings kept alive.
 * @param float        $mib     Reserved MiB to reach.
 */
function fill( array &$ballast, float $mib ): void {
	while ( memory_get_usage( true ) < $mib * 1048576 ) {
		$ballast[] = str_repeat( 'w', 200 ) . count( $ballast );
	}
}

$ballast = [];
fill( $ballast, $request['before'] );
$counted    = new CountingBackend( new WordPressResponses( new FileBackend( $request['repository'] ) ) );
$repository = Repository::open( $counted, MasterKey::from_json( (string) file_get_contents( $request['key'] ) ), new SystemRandom() );
$repository->limit_memory( MemoryBudget::from_ini( (string) ini_get( 'memory_limit' ) ) );
$result = [
	'outcome'  => 'complete',
	'reserved' => null,
	'peak'     => null,
	'loads'    => 0,
	'sha256'   => null,
	'size'     => null,
];
// A fatal error ends the process with the status 255 and this line.
register_shutdown_function(
	static function () use ( &$result ): void {
		$error = error_get_last();
		if ( null !== $error && E_ERROR === $error['type'] ) {
			$result['outcome'] = 'fatal: ' . $error['message'];
			echo json_encode( $result ), "\n";
		}
	}
);
try {
	if ( 'check' === $request['job'] ) {
		$run = CheckRun::start( $repository, $request['work'], new Identity( 'example.test' ), new FrozenClock(), new SystemRandom(), 100 );
		gc_mem_caches();
		$result['reserved'] = memory_get_usage( true );
		memory_reset_peak_usage();
		while ( ! $run->advance( INF ) ) {
			// One call checks the repository completely; another one only finishes it.
		}
		$result['findings'] = array_map( static fn ( array $finding ): string => $finding['severity'] . ':' . $finding['code'], $run->result()['findings'] );
	} else {
		mkdir( $request['target'], 0700 );
		$run = RestoreRun::start( $repository, Id::from_hex( $request['snapshot'] ), $request['target'], $request['work'], [ '/' ], new Identity( 'example.test' ), new FrozenClock() );
		while ( 'restore' !== $run->progress()['phase'] ) {
			$run->advance( 0.0 );
		}
		fill( $ballast, $request['after'] );
		gc_mem_caches();
		$result['reserved'] = memory_get_usage( true );
		$loads              = $counted->loads['data'] ?? 0;
		memory_reset_peak_usage();
		try {
			while ( ! $run->advance( 0.0 ) ) {
				// Each call reads one group of a file, as a tick does until its deadline.
			}
		} finally {
			$result['loads'] = ( $counted->loads['data'] ?? 0 ) - $loads;
			$file            = $request['target'] . '/file.bin';
			if ( is_file( $file ) ) {
				$result['sha256'] = hash_file( 'sha256', $file );
				$result['size']   = filesize( $file );
			}
		}
	}
} catch ( PluginException $error ) {
	$result['outcome'] = JobError::from_throwable( $error )->code;
}
$result['peak'] = memory_get_peak_usage( true );
echo json_encode( $result ), "\n";
