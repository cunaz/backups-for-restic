<?php
/**
 * Writes a repository on files for ReadMemoryTest, in a process of its own so that building
 * it takes nothing from the limit of the test or of a measured process.
 *
 * Every file is a pack of its own, with its blobs back to back in file order and a header,
 * so that a check can read it as well as a restore. Format 1 has full chunks, two chunks just
 * short of the 8 MiB limit, which make the largest group that holds two, and many small blobs.
 * Format 2 has full chunks of data that does not compress, stored in raw zstd blocks as restic
 * stores them, full chunks of text that compresses about three times, and many small blobs of
 * text.
 *
 * Usage: php repository.php <root> <directory> <format>. Prints the key file and, for each
 * file, its snapshot, SHA-256 and size as JSON.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', $argv[1] . '/' );
require $argv[1] . '/vendor/autoload.php';

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\ResticFrame;

$directory = $argv[2];
$format    = (int) $argv[3];
$backend   = new FileBackend( $directory );
$repository = Repository::initialize( $backend, 'memory password', new Identity( 'example.test' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
if ( 2 === $format ) {
	$config = $repository->config();
	unlink( $directory . '/config' );
	$backend->save( ObjectType::Config, 'config', $repository->cipher()->seal( ( new Config( Config::VERSION_2, $config->id, $config->chunker_polynomial ) )->to_json() ) );
	$repository = Repository::open( $backend, $repository->master_key(), new SystemRandom() );
}
$key = $directory . '-key.json';
file_put_contents( $key, $repository->master_key()->to_json() );

/**
 * Words of a small vocabulary.
 */
$text = static function ( int $size, int $seed ): string {
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
};

/**
 * One file of chunks of the given sizes in a pack with a header, and a snapshot of it.
 *
 * @param list<int> $sizes Chunk sizes.
 * @return array{snapshot: string, sha256: string, size: int}
 */
$file = static function ( array $sizes, bool $compressible ) use ( $repository, $format, $text ): array {
	$cipher  = $repository->cipher();
	$body    = '';
	$header  = '';
	$entries = [];
	$digest  = hash_init( 'sha256' );
	foreach ( $sizes as $number => $size ) {
		$chunk = $compressible ? $text( $size, $number ) : random_bytes( $size );
		hash_update( $digest, $chunk );
		$id = Id::of( $chunk );
		if ( 2 === $format ) {
			$sealed    = $cipher->seal( $compressible ? zstd_compress( $chunk, 3 ) : ResticFrame::raw( $chunk ) );
			$header   .= chr( 2 ) . pack( 'VV', strlen( $sealed ), $size ) . $id->bytes();
			$entries[] = new PackEntry( BlobType::Data, $id, strlen( $body ), strlen( $sealed ), $size );
		} else {
			$sealed    = $cipher->seal( $chunk );
			$header   .= chr( 0 ) . pack( 'V', strlen( $sealed ) ) . $id->bytes();
			$entries[] = new PackEntry( BlobType::Data, $id, strlen( $body ), strlen( $sealed ) );
		}
		$body .= $sealed;
		unset( $chunk, $sealed );
	}
	$header = $cipher->seal( $header );
	$body  .= $header . pack( 'V', strlen( $header ) );
	$pack   = Id::of( $body );
	$repository->backend()->save( ObjectType::Data, $pack->hex(), $body );
	unset( $body );
	$repository->save_unpacked( ObjectType::Index, IndexFile::encode( [ $pack->hex() => $entries ] ) );
	$trees = new BlobStore( $repository, new IndexSet() );
	$tree  = $trees->save( BlobType::Tree, TreeCodec::encode( [ new Node( 'file.bin', NodeType::File, 0644, '2026-01-01T00:00:00Z', size: array_sum( $sizes ), content: array_map( static fn ( PackEntry $entry ): Id => $entry->id, $entries ) ) ] ) );
	$trees->flush();
	$trees->write_index();
	return [
		'snapshot' => $repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $tree, [ '/' ] ) )->to_json() )->hex(),
		'sha256'   => hash_final( $digest ),
		'size'     => array_sum( $sizes ),
	];
};

$files           = [];
$files['chunks'] = $file( array_fill( 0, 2, RabinChunker::MAX_SIZE ), false );
if ( 1 === $format ) {
	$files['pairs'] = $file( array_fill( 0, 2, RabinChunker::MAX_SIZE - 64 ), false );
	$files['small'] = $file( array_fill( 0, 200, 40960 ), false );
} else {
	$files['text']  = $file( array_fill( 0, 3, RabinChunker::MAX_SIZE ), true );
	$files['small'] = $file( array_fill( 0, 200, 40960 ), true );
}
echo json_encode(
	[
		'directory' => $directory,
		'key'       => $key,
		'files'     => $files,
	],
	JSON_THROW_ON_ERROR
), "\n";
