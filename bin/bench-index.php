<?php
/**
 * Measures what the index of a large repository costs in memory and time (ADR 0031, NFR-03).
 *
 * Usage: php -d opcache.enable_cli=0 -d memory_limit=-1 bin/bench-index.php [blobs ...]
 * Without arguments it measures 250000, 500000, 1000000 and 2000000 blobs.
 *
 * For each size the script stores index files of 50000 blobs, as restic writes them, in a
 * repository in memory and then loads the index three ways: straight from the index files,
 * through an empty cache (which writes the cache file), and from the cache file. It prints
 * the memory the index holds, the peak above the starting point and the time of each. The
 * index files themselves live in the test backend and are not counted: on a host they are
 * on the server.
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
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

$sizes = array_map( 'intval', array_slice( $argv, 1 ) );
$sizes = [] === $sizes ? [ 250000, 500000, 1000000, 2000000 ] : $sizes;
$mb    = static fn ( int $bytes ): string => number_format( $bytes / 1048576, 1 );

/**
 * Runs a closure and returns its result, the peak above the memory in use before, and seconds.
 *
 * @return array{mixed, int, float}
 */
$measure = static function ( callable $work ): array {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$before = memory_get_usage();
	$start  = hrtime( true );
	$result = $work();
	return [ $result, memory_get_peak_usage() - $before, ( hrtime( true ) - $start ) / 1e9 ];
};

printf( "PHP %s, %s\n\n", PHP_VERSION, php_uname( 'm' ) );
printf( "%9s | %21s | %25s | %25s | %25s\n", 'Blobs', 'Index in memory', 'From the index files', 'First load through cache', 'Load from the cache file' );
printf( "%9s | %21s | %25s | %25s | %25s\n", '', 'MB (bytes per blob)', 'peak MB, seconds', 'peak MB, seconds', 'peak MB, seconds' );

foreach ( $sizes as $blobs ) {
	$backend    = new InMemoryBackend();
	$repository = Repository::initialize( $backend, 'bench', new Identity( 'bench.example' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	$directory  = TempDir::create( 'bench-index' );

	// Packs of 40 blobs: 16 MiB packs of files of a few hundred kilobytes.
	for ( $done = 0; $done < $blobs; ) {
		$packs = [];
		for ( $in_file = 0; $in_file < IndexFile::MAX_BLOBS && $done < $blobs; ) {
			$entries = [];
			for ( $i = 0; $i < 40 && $done < $blobs; $i++, $done++, $in_file++ ) {
				$entries[] = new PackEntry( 0 === $done % 12 ? BlobType::Tree : BlobType::Data, Id::from_bytes( random_bytes( 32 ) ), $i * 400000, 400000 );
			}
			$packs[ bin2hex( random_bytes( 32 ) ) ] = $entries;
		}
		$repository->save_unpacked( ObjectType::Index, IndexFile::encode( $packs ) );
	}
	unset( $packs, $entries );

	[ $index, $direct_peak, $direct_seconds ] = $measure( static fn () => $repository->load_index() );
	if ( $index->count() !== $blobs ) {
		fwrite( STDERR, "The index holds {$index->count()} blobs instead of {$blobs}.\n" );
		exit( 1 );
	}
	unset( $index );
	gc_collect_cycles();
	$before = memory_get_usage();
	$index  = $repository->load_index();
	$held   = memory_get_usage() - $before;
	unset( $index );

	$cache                                 = new IndexCache( $directory );
	[ , $first_peak, $first_seconds ]      = $measure( static fn () => $cache->load( $repository )->count() );
	[ $count, $cached_peak, $cached_time ] = $measure( static fn () => $cache->load( $repository )->count() );
	if ( $count !== $blobs ) {
		fwrite( STDERR, "The cache gave {$count} blobs instead of {$blobs}.\n" );
		exit( 1 );
	}
	printf(
		"%9d | %10s (%3d)      | %12s, %6.1f s    | %12s, %6.1f s    | %12s, %6.1f s\n",
		$blobs,
		$mb( $held ),
		intdiv( $held, $blobs ),
		$mb( $direct_peak ),
		$direct_seconds,
		$mb( $first_peak ),
		$first_seconds,
		$mb( $cached_peak ),
		$cached_time
	);
	TempDir::remove( $directory );
	unset( $backend, $repository, $cache );
}
