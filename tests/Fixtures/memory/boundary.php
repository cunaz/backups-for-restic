<?php
/**
 * Reads one blob at the exact boundary of the job's memory budget, for ReadMemoryTest: PHP's
 * memory limit leaves, beside the memory reserved before the read and the two MiB that the
 * budget keeps free, exactly what the budget says that reading the blob takes. The budget lets
 * the blob through; what happens then is printed.
 *
 * The request names the job (a restore through BlobBatch, or a check that verifies the blob's
 * pack), the blob and a state of PHP's allocator, produced before or during the read:
 *
 * - quiet: nothing more;
 * - churn: while a range arrives, small strings are made and let go again, as a request may do
 *   with its headers and hooks; PHP keeps their pages until it gives them back;
 * - fragmented: before the read, strings of 1 MiB fill PHP's blocks of 2 MiB so that none has
 *   room left for a string of more than 1 MiB, and each such string needs a block of its own.
 *
 * Usage: php boundary.php <request.json>. Prints JSON: what ended the read ("read" when the
 * blob was read and matched), and for a check the findings.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

$request = json_decode( (string) file_get_contents( $argv[1] ), true, 32, JSON_THROW_ON_ERROR );
define( 'ABSPATH', $request['root'] . '/' );
require $request['root'] . '/vendor/autoload.php';

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\BlobBatch;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\HookedBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\ResticFrame;

$result = [ 'outcome' => 'read' ];
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

/**
 * Words of a small vocabulary, which zstd compresses about three times.
 */
function text( int $size ): string {
	mt_srand( $size );
	$words = [];
	for ( $i = 0; $i < 4096; ++$i ) {
		$words[] = bin2hex( random_bytes( 1 + $i % 6 ) );
	}
	$text = '';
	while ( strlen( $text ) < $size ) {
		$text .= $words[ mt_rand( 0, 4095 ) ] . ' ';
	}
	return substr( $text, 0, $size );
}

// The blob, its stored form and its pack entry.
[ $content, $frame ] = match ( $request['blob'] ) {
	// A full chunk that does not compress, in raw zstd blocks as restic stores it.
	'chunk' => ( static function (): array {
		$chunk = random_bytes( 8388608 );
		return [ $chunk, ResticFrame::raw( $chunk ) ];
	} )(),
	// A full chunk that compresses about three times.
	'text'  => ( static function (): array {
		$chunk = text( 8388608 );
		return [ $chunk, (string) zstd_compress( $chunk, 3 ) ];
	} )(),
	// A chunk of 1.1 MiB that does not compress, uncompressed as format 1 stores it.
	'plain' => [ random_bytes( 1153434 ), null ],
	// A chunk of 1.5 MiB that compresses, smaller than a block of PHP's.
	'part'  => ( static function (): array {
		$chunk = text( 1572864 );
		return [ $chunk, (string) zstd_compress( $chunk, 3 ) ];
	} )(),
	// A blob of 200 bytes in the frame restic 0.20.0 writes for it: no declared size, a window
	// of 1 KiB and one raw block.
	'tiny'  => ( static function (): array {
		$blob = random_bytes( 200 );
		return [ $blob, "\x28\xb5\x2f\xfd\x00\x00" . substr( pack( 'V', 200 << 3 | 1 ), 0, 3 ) . $blob ];
	} )(),
};
$backend    = new InMemoryBackend();
$repository = Repository::initialize( $backend, 'boundary password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
$cipher     = $repository->cipher();
$sealed     = $cipher->seal( $frame ?? $content );
$id         = Id::of( $content );
$entry      = new PackEntry( BlobType::Data, $id, 0, strlen( $sealed ), null === $frame ? 0 : strlen( $content ) );
$digest     = hash( 'sha256', $content );
$header     = $cipher->seal( ( null === $frame ? chr( 0 ) . pack( 'V', $entry->length ) : chr( 2 ) . pack( 'VV', $entry->length, $entry->uncompressed_length ) ) . $id->bytes() );
$pack_bytes = $sealed . $header . pack( 'V', strlen( $header ) );
$pack       = Id::of( $pack_bytes );
$backend->save( ObjectType::Data, $pack->hex(), $pack_bytes );
$repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->hex() => [ $entry ] ] ) );
// A snapshot of the blob as a file: a check walks its tree and so loads its index before it
// reads any pack, as it does in a job.
$trees = new BlobStore( $repository, new IndexSet() );
$tree  = $trees->save( BlobType::Tree, TreeCodec::encode( [ new Node( 'file.bin', NodeType::File, 0644, '2026-01-01T00:00:00Z', size: strlen( $content ), content: [ $id ] ) ] ) );
$trees->flush();
$trees->write_index();
$repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $tree, [ '/' ] ) )->to_json() );
unset( $content, $frame, $sealed, $header, $pack_bytes, $trees );

// What reading the blob takes, as the budget says it; and the rest of its pack, for a check.
$need = MemoryBudget::range( $entry->length, 0, MemoryBudget::opening( $entry ) );
if ( 'check' === $request['job'] ) {
	$need = max( $need, MemoryBudget::range( (int) $backend->size( ObjectType::Data, $pack->hex() ) - $entry->length, 0, 0 ) );
}

$churn = 'churn' === $request['state'];
$read  = new HookedBackend(
	$backend,
	static function ( string $method, ?ObjectType $type, string $name = '', int $offset = 0, ?int $length = null ) use ( $churn, $pack, $entry ): void {
		// After the hook the backend answers; the small strings go before it does, as a
		// request's own do before its answer is handed on.
		if ( $churn && 'load' === $method && ObjectType::Data === $type && $pack->hex() === $name && 0 === $offset && $entry->length === $length ) {
			$small = [];
			for ( $i = 0; $i < 60000; ++$i ) {
				$small[] = str_repeat( 's', 60 ) . $i;
			}
		}
	}
);
$reader = Repository::open( $read, $repository->master_key(), new SystemRandom() );
unset( $repository );

$blocks = [];
if ( 'fragmented' === $request['state'] ) {
	for ( $grown = 0; $grown < 2; ) {
		$before   = memory_get_usage( true );
		$blocks[] = str_repeat( 'f', 1048576 );
		$grown   += memory_get_usage( true ) - $before >= 2097152 ? 1 : 0;
	}
}

/**
 * Sets PHP's limit, and the job's budget from it, to leave exactly $need beside the memory
 * reserved now.
 */
$tighten = static function ( Repository $repository ) use ( $need ): void {
	gc_mem_caches();
	// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The budget reads PHP's own limit; this process sets it as a host would.
	ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 2097152 + $need ) );
	$repository->limit_memory( MemoryBudget::from_ini( (string) ini_get( 'memory_limit' ) ) );
};

try {
	if ( 'restore' === $request['job'] ) {
		$index = new IndexSet();
		$index->add_pack( $pack, [ $entry ] );
		$read_digest = null;
		$tighten( $reader );
		BlobBatch::read(
			$reader,
			$index,
			[ $id ],
			0,
			static function ( int $number, string $plain ) use ( &$read_digest ): void {
				$read_digest = hash( 'sha256', $plain );
			}
		);
		if ( $digest !== $read_digest ) {
			$result['outcome'] = 'different bytes';
		}
	} else {
		$checker = null;
		$limit   = (string) ini_get( 'memory_limit' );
		$sized   = new HookedBackend(
			$read,
			static function ( string $method, ?ObjectType $type, string $name = '' ) use ( $pack, $tighten, $limit, &$checker ): void {
				if ( 'size' !== $method || ObjectType::Data !== $type || null === $checker ) {
					return;
				}
				if ( $pack->hex() === $name ) {
					// Right before the check reads the pack, as a job that reached it would be.
					$tighten( $checker );
				} else {
					// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The other pack is read with the limit of before.
					ini_set( 'memory_limit', $limit );
					$checker->limit_memory( MemoryBudget::from_ini( $limit ) );
				}
			}
		);
		$checker = Repository::open( $sized, $reader->master_key(), new SystemRandom() );
		$run     = CheckRun::start( $checker, $request['work'], new Identity( 'example.com' ), new FrozenClock(), new SystemRandom(), 100 );
		while ( ! $run->advance( INF ) ) {
			continue;
		}
		$result['findings'] = array_map( static fn ( array $finding ): string => $finding['severity'] . ':' . $finding['code'] . ': ' . $finding['message'], $run->result()['findings'] );
		$result['verified'] = $run->result()['stats']['blobs_verified'] ?? 0;
	}
} catch ( PluginException $error ) {
	$result['outcome'] = JobError::from_throwable( $error )->code . ': ' . $error->getMessage();
}
echo json_encode( $result ), "\n";
