<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;

/**
 * Builds valid, small-payload history with many distinct blobs, trees and packs.
 * Run in a separate producer process, never inside the measured WordPress worker.
 */
final class MemoryRepository {

	public const string PASSWORD     = 'memory-fixture-test-only';
	private const int DATA_PER_LEAF  = 11;
	private const int CHILDREN       = 64;
	private const int BLOBS_PER_PACK = 40;

	private PackWriter $data;
	private PackWriter $trees;
	/** @var array<string, list<PackEntry>> */
	private array $packs    = [];
	private int $pending    = 0;
	private int $written    = 0;
	private int $tree_count = 0;

	public function __construct( private readonly Repository $repository ) {
		$this->data  = new PackWriter( $repository->cipher() );
		$this->trees = new PackWriter( $repository->cipher() );
	}

	/** @return array{snapshot: string, blobs: int, trees: int, live_data: int, unused: int} */
	public function build( int $blobs, string $scratch ): array {
		if ( $blobs < 16 ) {
			throw new \InvalidArgumentException( 'A memory fixture needs at least 16 blobs.' );
		}
		// Leave at least one indexed, unused blob so prune must actually rewrite an index.
		$low  = 1;
		$high = $blobs - 1;
		while ( $low < $high ) {
			$middle = intdiv( $low + $high + 1, 2 );
			if ( self::total( $middle ) <= $blobs - 1 ) {
				$low = $middle;
			} else {
				$high = $middle - 1;
			}
		}
		$live  = $low;
		$roots = fopen( $scratch . '/level-0', 'x+b' );
		if ( false === $roots ) {
			throw new \RuntimeException( 'Cannot create the fixture tree list.' );
		}
		$count = 0;
		for ( $number = 0; $number < $live; ) {
			$content = [];
			for ( $i = 0; $i < self::DATA_PER_LEAF && $number < $live; ++$i, ++$number ) {
				$content[] = $this->blob( BlobType::Data, hash( 'sha512', 'memory-data-' . $number, true ) );
			}
			$leaf = $this->blob( BlobType::Tree, TreeCodec::encode( [ new Node( 'data.bin', NodeType::File, 0644, size: count( $content ) * 64, content: $content ) ] ) );
			if ( 32 !== fwrite( $roots, $leaf->bytes() ) ) {
				throw new \RuntimeException( 'Cannot record a fixture tree.' );
			}
			++$count;
		}
		fclose( $roots );
		$level = 0;
		while ( $count > 1 ) {
			$input  = fopen( $scratch . '/level-' . $level, 'rb' );
			$output = fopen( $scratch . '/level-' . ( $level + 1 ), 'x+b' );
			if ( false === $input || false === $output ) {
				throw new \RuntimeException( 'Cannot open a fixture tree level.' );
			}
			$parents = 0;
			for ( $number = 0; $number < $count; ) {
				$nodes = [];
				for ( $i = 0; $i < self::CHILDREN && $number < $count; ++$i, ++$number ) {
					$bytes = fread( $input, 32 );
					if ( false === $bytes || strlen( $bytes ) !== 32 ) {
						throw new \RuntimeException( 'The fixture tree list is incomplete.' );
					}
					$nodes[] = new Node( sprintf( 'directory-%08d', $i ), NodeType::Dir, 0755, subtree: Id::from_bytes( $bytes ) );
				}
				$tree = $this->blob( BlobType::Tree, TreeCodec::encode( $nodes ) );
				if ( 32 !== fwrite( $output, $tree->bytes() ) ) {
					throw new \RuntimeException( 'Cannot record a fixture parent.' );
				}
				++$parents;
			}
			fclose( $input );
			fclose( $output );
			$count = $parents;
			++$level;
		}
		$bytes = file_get_contents( $scratch . '/level-' . $level );
		if ( false === $bytes || strlen( $bytes ) !== 32 ) {
			throw new \RuntimeException( 'The fixture root is incomplete.' );
		}
		$root = Id::from_bytes( $bytes );
		$this->flush( BlobType::Data );
		$this->flush( BlobType::Tree );
		$unused = $blobs - $this->written;
		for ( $number = 0; $number < $unused; ++$number ) {
			$this->blob( BlobType::Data, hash( 'sha512', 'unused-data-' . $number, true ) );
		}
		$this->flush( BlobType::Data );
		$this->index();
		$snapshot = $this->repository->save_unpacked( ObjectType::Snapshots, ( new Snapshot( '2026-01-01T00:00:00Z', $root, [ '/' ], hostname: 'memory-fixture.example' ) )->to_json() );
		return [
			'snapshot'  => $snapshot->hex(),
			'blobs'     => $this->written,
			'trees'     => $this->tree_count,
			'live_data' => $live,
			'unused'    => $unused,
		];
	}

	private static function total( int $data ): int {
		$count = (int) ceil( $data / self::DATA_PER_LEAF );
		$total = $data + $count;
		while ( $count > 1 ) {
			$count  = (int) ceil( $count / self::CHILDREN );
			$total += $count;
		}
		return $total;
	}

	private function blob( BlobType $type, string $plain ): Id {
		$writer = BlobType::Data === $type ? $this->data : $this->trees;
		$id     = Id::of( $plain );
		$writer->add( $type, $id, $plain );
		++$this->written;
		if ( BlobType::Tree === $type ) {
			++$this->tree_count;
		}
		if ( $writer->count() >= self::BLOBS_PER_PACK ) {
			$this->flush( $type );
		}
		return $id;
	}

	private function flush( BlobType $type ): void {
		$writer = BlobType::Data === $type ? $this->data : $this->trees;
		if ( 0 === $writer->count() ) {
			return;
		}
		$pack = $writer->finish();
		$this->repository->backend()->save( ObjectType::Data, $pack->id->hex(), $pack->bytes );
		$this->packs[ $pack->id->hex() ] = $pack->entries;
		$this->pending                  += count( $pack->entries );
		if ( BlobType::Data === $type ) {
			$this->data = new PackWriter( $this->repository->cipher() );
		} else {
			$this->trees = new PackWriter( $this->repository->cipher() );
		}
		if ( $this->pending >= IndexFile::MAX_BLOBS ) {
			$this->index();
		}
	}

	private function index(): void {
		if ( [] !== $this->packs ) {
			$this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( $this->packs ) );
			$this->packs   = [];
			$this->pending = 0;
		}
	}
}
