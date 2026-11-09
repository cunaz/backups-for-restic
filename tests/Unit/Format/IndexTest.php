<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;

/**
 * Index files are untrusted JSON from the server; the in-memory index keeps data and tree blobs
 * apart because restic does (a blob ID is only unique per type).
 */
final class IndexTest extends TestCase {

	private static function blob( string $id, string $type, int $offset, int $length, ?int $uncompressed = null ): string {
		return '{"id":"' . $id . '","type":"' . $type . '","offset":' . $offset . ',"length":' . $length
			. ( null === $uncompressed ? '' : ',"uncompressed_length":' . $uncompressed ) . '}';
	}

	public function test_compressed_blobs_keep_their_uncompressed_length(): void {
		$pack = Id::of( 'pack' );
		$blob = Id::of( 'blob' );
		$json = '{"packs":[{"id":"' . $pack->hex() . '","blobs":[' . self::blob( $blob->hex(), 'data', 0, 100, 250 ) . ']}]}' . "\n";

		$packs = IndexFile::decode( $json );
		self::assertEquals( [ $pack->hex() => [ new PackEntry( BlobType::Data, $blob, 0, 100, 250 ) ] ], $packs );
		self::assertSame( $json, IndexFile::encode( $packs ) );
	}

	public function test_a_pack_listed_twice_keeps_the_blobs_of_both_lists(): void {
		$pack = Id::of( 'pack' )->hex();
		$a    = Id::of( 'a' );
		$b    = Id::of( 'b' );
		$json = '{"packs":[{"id":"' . $pack . '","blobs":[' . self::blob( $a->hex(), 'data', 0, 40 ) . ']},'
			. '{"id":"' . $pack . '","blobs":[' . self::blob( $b->hex(), 'tree', 40, 50 ) . ']}]}';

		self::assertEquals(
			[
				$pack => [
					new PackEntry( BlobType::Data, $a, 0, 40 ),
					new PackEntry( BlobType::Tree, $b, 40, 50 ),
				],
			],
			IndexFile::decode( $json )
		);
	}

	/**
	 * @return iterable<string, array{int, int, int|string}>
	 */
	public static function invalid_blobs(): iterable {
		yield 'negative offset' => [ -1, 40, 0 ];
		yield 'zero length' => [ 0, 0, 0 ];
		yield 'negative uncompressed length' => [ 0, 40, -1 ];
		yield 'uncompressed length that is not a number' => [ 0, 40, '"40"' ];
	}

	#[DataProvider( 'invalid_blobs' )]
	public function test_invalid_blob_entries_are_rejected( int $offset, int $length, int|string $uncompressed ): void {
		$json = '{"packs":[{"id":"' . Id::of( 'pack' )->hex() . '","blobs":[{"id":"' . Id::of( 'blob' )->hex()
			. '","type":"data","offset":' . $offset . ',"length":' . $length . ',"uncompressed_length":' . $uncompressed . '}]}]}';

		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Index file contains an invalid blob.' );
		IndexFile::decode( $json );
	}

	public function test_data_and_tree_blobs_with_the_same_id_are_separate_entries(): void {
		$id   = Id::of( 'same content' );
		$data = Id::of( 'data pack' );
		$tree = Id::of( 'tree pack' );
		$set  = new IndexSet();
		$set->add_pack( $data, [ new PackEntry( BlobType::Data, $id, 0, 40 ) ] );
		$set->add_pack( $tree, [ new PackEntry( BlobType::Tree, $id, 10, 50 ) ] );

		self::assertSame( 2, $set->count() );
		self::assertSame( $data->hex(), $set->find( $id, BlobType::Data )?->pack->hex() );
		self::assertSame( $tree->hex(), $set->find( $id, BlobType::Tree )?->pack->hex() );
		self::assertEquals( new PackEntry( BlobType::Tree, $id, 10, 50 ), $set->find( $id, BlobType::Tree )?->entry );
	}

	public function test_the_cache_layout_stores_the_blob_type_as_t_or_d(): void {
		$pack = Id::of( 'pack' );
		$tree = Id::of( 'tree' );
		$data = Id::of( 'data' );
		$set  = new IndexSet();
		$set->add_pack( $pack, [ new PackEntry( BlobType::Tree, $tree, 0, 40 ), new PackEntry( BlobType::Data, $data, 40, 100, 200 ) ] );

		$expected = "backups-for-restic index 1\n" . pack( 'V', 1 ) . $pack->bytes() . pack( 'V', 2 )
			. 't' . $tree->bytes() . pack( 'VJVV', 0, 0, 40, 0 )
			. 'd' . $data->bytes() . pack( 'VJVV', 0, 40, 100, 200 );
		self::assertSame( $expected, $set->to_binary() );
		self::assertEquals( $set, IndexSet::from_binary( $expected ) );
	}
}
