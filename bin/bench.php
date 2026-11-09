<?php
/**
 * Throughput benchmarks with the Milestone 0 gates (docs/05-roadmap.md, docs/02 §16).
 *
 * Usage: php -d opcache.enable_cli=0 bin/bench.php [--size=256] [--slow]
 * The baseline is PHP 8.3 without JIT. Exit code 1 if a gate fails.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\Poly1305;
use BackupsForRestic\Crypto\Scrypt;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\Chunker\ChunkReader;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Support\StringByteSource;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\Vectors;

$options = getopt( '', [ 'size::', 'slow' ] );
$size_mb = isset( $options['size'] ) ? max( 16, (int) $options['size'] ) : 256;
$slow    = isset( $options['slow'] );
$results = [];
$failed  = false;

/**
 * Times a closure and returns seconds.
 */
$measure = static function ( callable $work ): float {
	$start = microtime( true );
	$work();
	return microtime( true ) - $start;
};

$data = Vectors::hash_stream( 'bench', 64 * 1048576 );

$seconds   = $measure(
	static function () use ( $data ): void {
		$reader = new ChunkReader( new StringByteSource( $data ), new RabinChunker( 0x3DA3358B4DC173 ) );
		while ( null !== $reader->next() ) {
			continue;
		}
	}
);
$results[] = [ 'Rabin chunker', 64 / $seconds, 'MB/s', '>= 5 MB/s', 64 / $seconds >= 5 ];

$key       = random_bytes( 32 );
$seconds   = $measure( static fn () => Poly1305::mac( $data, $key ) );
$results[] = [ 'Poly1305 (pure PHP)', 64 / $seconds, 'MB/s', '', true ];

$cipher    = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
$seconds   = $measure(
	static function () use ( $cipher, $data ): void {
		$length = strlen( $data );
		for ( $offset = 0; $offset < $length; $offset += 1048576 ) {
			$cipher->seal( substr( $data, $offset, 1048576 ) );
		}
	}
);
$results[] = [ 'Seal (AES-256-CTR + Poly1305)', 64 / $seconds, 'MB/s', '', true ];

$seconds   = $measure( static fn () => hash( 'sha256', $data ) );
$results[] = [ 'SHA-256', 64 / $seconds, 'MB/s', '', true ];

$seconds   = $measure( static fn () => Scrypt::derive( 'password', random_bytes( 64 ), ScryptParameters::for_new_keys() ) );
$results[] = [ 'scrypt N=16384 r=8 p=1 (new keys)', $seconds, 's', '<= 30 s', $seconds <= 30 ];

if ( $slow ) {
	$seconds   = $measure( static fn () => Scrypt::derive( 'password', random_bytes( 64 ), new ScryptParameters( 32768, 8, 6 ) ) );
	$results[] = [ 'scrypt N=32768 r=8 p=6 (restic-calibrated)', $seconds, 's', '', true ];
}

// End to end: backup of a generated tree into an in-memory repository.
$site = TempDir::create( 'bench-site' );
$work = TempDir::create( 'bench-work' );
for ( $i = 0; $i < $size_mb; $i += 8 ) {
	file_put_contents( $site . '/file-' . $i . '.bin', Vectors::hash_stream( 'bench-file-' . $i, 8 * 1048576 ) );
}
for ( $i = 0; $i < 2000; $i++ ) {
	file_put_contents( $site . '/small-' . $i . '.php', str_repeat( "<?php // file $i\n", 20 ) );
}
$repository = Repository::initialize( new InMemoryBackend(), 'password', new Identity( 'bench.example' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
$cpu_before = getrusage();
$seconds    = $measure(
	static function () use ( $repository, $site, $work ): void {
		$run = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $work, new Identity( 'bench.example' ), new SystemClock() );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
	}
);
$cpu_after  = getrusage();
$cpu        = ( $cpu_after['ru_utime.tv_sec'] - $cpu_before['ru_utime.tv_sec'] ) + ( $cpu_after['ru_utime.tv_usec'] - $cpu_before['ru_utime.tv_usec'] ) / 1e6;
$per_gb     = $cpu / ( $size_mb / 1024 ) / 60;
$results[]  = [ sprintf( 'Backup of %d MB + 2000 small files', $size_mb ), $size_mb / $seconds, 'MB/s', '', true ];
$results[]  = [ 'Backup CPU time, extrapolated to 1 GB', $per_gb, 'min', '<= 15 min', $per_gb <= 15 ];
TempDir::remove( $site );
TempDir::remove( $work );

$opcache = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
$jit     = is_array( $opcache ) && is_array( $opcache['jit'] ?? null ) && true === ( $opcache['jit']['on'] ?? false );
printf( "PHP %s, OPcache %s, JIT %s\n\n", PHP_VERSION, is_array( $opcache ) ? 'on' : 'off', $jit ? (string) ini_get( 'opcache.jit' ) : 'off' );
printf( "%-45s %10s %-5s %-10s %s\n", 'Measurement', 'Value', 'Unit', 'Gate', 'Result' );
foreach ( $results as [ $name, $value, $unit, $gate, $passed ] ) {
	printf( "%-45s %10.1f %-5s %-10s %s\n", $name, $value, $unit, $gate, '' === $gate ? '' : ( $passed ? 'pass' : 'FAIL' ) );
	$failed = $failed || ! $passed;
}
exit( $failed ? 1 : 0 );
