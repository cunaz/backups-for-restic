<?php
/**
 * Reads a group of blobs at the exact boundary of the job's memory budget, for
 * ReadMemoryTest: PHP's memory limit leaves, beside the memory reserved before the read,
 * exactly what the budget says that reading the group takes (MemoryBudget::range()). The
 * budget lets the group through by zero bytes; what happens then is printed.
 *
 * The request names the job (a restore through BlobBatch, or a check that verifies the
 * group's pack), the blobs, whether every response is held three times as WordPress holds it
 * (tests/Support/WordPressResponses.php), and a state of PHP's allocator, produced before or
 * during the read:
 *
 * - quiet: nothing more;
 * - churn: while a range arrives, small strings are made and let go again, as a request may do
 *   with its headers and hooks; PHP keeps their pages until it gives them back;
 * - fragmented: before the read, strings of 1 MiB fill PHP's blocks of 2 MiB so that none has
 *   room left for a string of more than 1 MiB, and each such string needs a block of its own;
 * - taken: once a range has arrived, other code keeps 4 MiB, which the budget could not count
 *   before the download (the reader measures again before it opens a blob);
 * - short (a check only): when the pack is read, the limit leaves less than a new block, which
 *   three copies of the smallest piece may take.
 *
 * The pack holds bytes after the blobs, as a pack's header follows its blobs: a range of the
 * blobs is then a new string, as a server's response is.
 *
 * Blobs are given as kind:bytes: raw (stored uncompressed, random), zraw (random in raw zstd
 * blocks, as restic stores a chunk that does not compress), ztext (text that compresses
 * about three times, zstd level 3), ztiny (no declared size and a window of 1 KiB, as restic
 * 0.20.0 writes a blob below 256 bytes).
 *
 * Usage: php boundary.php <request.json>. Prints JSON: what ended the read ("read" when every
 * blob of the group was read in one range and matched), and for a check the findings and the
 * blobs verified.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

$request = json_decode( (string) file_get_contents( $argv[1] ), true, 32, JSON_THROW_ON_ERROR );
define( 'ABSPATH', $request['root'] . '/' );
require $request['root'] . '/vendor/autoload.php';

use BackupsForRestic\Backend\Backend;
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
use BackupsForRestic\Tests\Support\WordPressResponses;

/**
 * Passes every request on; once the first range of the given pack that holds a blob has
 * arrived, other code keeps memory of the given size, as a plugin that keeps the HTTP API's
 * answers would.
 */
final class Taking implements Backend {

	/** @var list<string> */
	public static array $kept = [];

	public function __construct( private readonly Backend $inner, private readonly string $pack, private readonly int $bytes ) {
	}

	public function create(): void {
		$this->inner->create();
	}

	public function size( ObjectType $type, string $name ): ?int {
		return $this->inner->size( $type, $name );
	}

	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$bytes = $this->inner->load( $type, $name, $offset, $length );
		if ( [] === self::$kept && ObjectType::Data === $type && $this->pack === $name && 0 === $offset ) {
			self::$kept[] = str_repeat( 'k', $this->bytes );
		}
		return $bytes;
	}

	public function save( ObjectType $type, string $name, string $body ): void {
		$this->inner->save( $type, $name, $body );
	}

	public function delete( ObjectType $type, string $name ): void {
		$this->inner->delete( $type, $name );
	}

	public function list( ObjectType $type ): array {
		return $this->inner->list( $type );
	}

	public function list_to_file( ObjectType $type, string $file ): void {
		$this->inner->list_to_file( $type, $file );
	}
}

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
function text( int $size, int $seed ): string {
	mt_srand( $seed );
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

$backend    = new InMemoryBackend();
$repository = Repository::initialize( $backend, 'boundary password', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
$cipher     = $repository->cipher();
$body       = '';
$header     = '';
$entries    = [];
$digests    = [];
foreach ( $request['blobs'] as $number => $blob ) {
	[ $kind, $size ] = explode( ':', $blob );
	$size            = (int) $size;
	$content         = 'ztext' === $kind ? text( $size, $number ) : random_bytes( $size );
	$frame           = match ( $kind ) {
		'raw'   => null,
		'zraw'  => ResticFrame::raw( $content ),
		'ztext' => (string) zstd_compress( $content, 3 ),
		'ztiny' => "\x28\xb5\x2f\xfd\x00\x00" . substr( pack( 'V', $size << 3 | 1 ), 0, 3 ) . $content,
	};
	$sealed    = $cipher->seal( $frame ?? $content );
	$id        = Id::of( $content );
	$entries[] = new PackEntry( BlobType::Data, $id, strlen( $body ), strlen( $sealed ), null === $frame ? 0 : $size );
	$header   .= ( null === $frame ? chr( 0 ) . pack( 'V', strlen( $sealed ) ) : chr( 2 ) . pack( 'VV', strlen( $sealed ), $size ) ) . $id->bytes();
	$digests[] = hash( 'sha256', $content );
	$body     .= $sealed;
	unset( $content, $frame, $sealed );
}
$header = $cipher->seal( $header );
$body  .= $header . pack( 'V', strlen( $header ) );
$pack   = Id::of( $body );
$backend->save( ObjectType::Data, $pack->hex(), $body );
$repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->hex() => $entries ] ) );
// A snapshot of the blobs as a file: a check walks its tree and so loads its index before it
// reads any pack, as it does in a job.
$trees = new BlobStore( $repository, new IndexSet() );
$tree  = $trees->save( BlobType::Tree, TreeCodec::encode( [ new Node( 'file.bin', NodeType::File, 0644, '2026-01-01T00:00:00Z', size: 0, content: array_map( static fn ( PackEntry $entry ): Id => $entry->id, $entries ) ) ] ) );
$trees->flush();
$trees->write_index();
$repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $tree, [ '/' ] ) )->to_json() );
unset( $body, $header, $trees );

$churn = 'churn' === $request['state'];
$read  = new HookedBackend(
	new Taking( $request['wordpress'] ? new WordPressResponses( $backend ) : $backend, 'taken' === $request['state'] ? $pack->hex() : '', 4194304 ),
	static function ( string $method, ?ObjectType $type, string $name = '', int $offset = 0, ?int $length = null ) use ( $churn, $pack, $entries ): void {
		// After the hook the backend answers; the small strings go before it does, as a
		// request's own do before its answer is handed on.
		if ( $churn && 'load' === $method && ObjectType::Data === $type && $pack->hex() === $name && $entries[0]->offset === $offset && null !== $length && $length >= $entries[0]->length ) {
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
 * The most that the budget says that reading takes beside the memory in use (it asks PHP only
 * when that does not fit): for a restore the whole group as BlobBatch plans it, for a check
 * each blob by itself, since the check reads the rest of the pack in pieces that fit.
 */
$need = static function () use ( $request, $entries ): int {
	if ( 'check' === $request['job'] ) {
		return max( array_map( static fn ( PackEntry $entry ): int => MemoryBudget::range( $entry->length, null, $entry ), $entries ) );
	}
	$length = 0;
	$before = null;
	$latest = null;
	foreach ( $entries as $entry ) {
		$length += $entry->length;
		$prior   = MemoryBudget::larger( $before, $latest );
		$before  = $prior;
		$latest  = $entry;
	}
	return MemoryBudget::range( $length, $before, $latest );
};

/**
 * Sets PHP's limit, and the job's budget from it, to leave exactly what reading takes beside
 * the memory reserved now.
 */
$tighten = static function ( Repository $repository ) use ( $need, $request ): void {
	gc_mem_caches();
	$limit = memory_get_usage( true ) + $need();
	if ( 'short' === $request['state'] ) {
		$limit = memory_get_usage( true ) + MemoryBudget::BLOCK - 1;
	}
	// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The budget reads PHP's own limit; this process sets it as a host would.
	ini_set( 'memory_limit', (string) $limit );
	$repository->limit_memory( MemoryBudget::from_ini( (string) ini_get( 'memory_limit' ) ) );
};

try {
	if ( 'restore' === $request['job'] ) {
		$index = new IndexSet();
		$index->add_pack( $pack, $entries );
		$written = [];
		$tighten( $reader );
		BlobBatch::read(
			$reader,
			$index,
			array_map( static fn ( PackEntry $entry ): Id => $entry->id, $entries ),
			0,
			static function ( int $number, string $plain ) use ( &$written, $digests ): void {
				$written[ $number ] = hash( 'sha256', $plain ) === $digests[ $number ];
			}
		);
		if ( count( $written ) !== count( $entries ) ) {
			$result['outcome'] = 'group cut after ' . count( $written ) . ' blobs';
		} elseif ( in_array( false, $written, true ) ) {
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
