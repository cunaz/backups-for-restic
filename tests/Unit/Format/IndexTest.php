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

	private const string MAGIC = "backups-for-restic index 2\n";

	/**
	 * Every blob of an index as one line, sorted: what two indexes with the same content share
	 * whatever their buckets are.
	 *
	 * @return list<string>
	 */
	private static function blobs( IndexSet $index ): array {
		$lines = [];
		foreach ( $index->locations() as $location ) {
			$entry   = $location->entry;
			$lines[] = implode( ' ', [ $entry->type->value, $entry->id->hex(), $location->pack->hex(), $entry->offset, $entry->length, $entry->uncompressed_length ] );
		}
		sort( $lines );
		return $lines;
	}

	/**
	 * A blob ID whose bytes at two positions are given; the rest depends on the seed.
	 */
	private static function id_with( int $high_position, int $high, int $low_position, int $low, string $seed ): Id {
		$raw                   = Id::of( $seed )->bytes();
		$raw[ $high_position ] = chr( $high );
		$raw[ $low_position ]  = chr( $low );
		return Id::from_bytes( $raw );
	}

	public function test_the_first_location_of_a_blob_wins_and_is_counted_once(): void {
		$blob  = Id::of( 'blob' );
		$first = Id::of( 'first pack' );
		$index = new IndexSet();
		$index->add_pack( $first, [ new PackEntry( BlobType::Data, $blob, 10, 40 ) ] );
		$index->add_pack( Id::of( 'second pack' ), [ new PackEntry( BlobType::Data, $blob, 99, 41 ), new PackEntry( BlobType::Tree, Id::of( 'tree' ), 0, 7 ) ] );
		$index->add_pack( $first, [ new PackEntry( BlobType::Data, $blob, 500, 42 ) ] );

		self::assertSame( 2, $index->count() );
		self::assertTrue( $index->has( $blob, BlobType::Data ) );
		self::assertFalse( $index->has( $blob, BlobType::Tree ) );
		self::assertFalse( $index->has( Id::of( 'unknown' ), BlobType::Data ) );
		self::assertNull( $index->find( Id::of( 'unknown' ), BlobType::Data ) );
		self::assertEquals( new PackEntry( BlobType::Data, $blob, 10, 40 ), $index->find( $blob, BlobType::Data )?->entry );
		self::assertSame( $first->hex(), $index->find( $blob, BlobType::Data )?->pack->hex() );
		self::assertTrue( $index->has_pack( Id::of( 'second pack' ) ) );
		self::assertFalse( $index->has_pack( Id::of( 'third pack' ) ) );
		self::assertSame( [ $first->hex(), Id::of( 'second pack' )->hex() ], array_map( static fn ( Id $id ): string => $id->hex(), $index->pack_ids() ) );
	}

	public function test_blobs_that_share_a_bucket_are_all_found(): void {
		$index = new IndexSet( 3, 17 );
		$pack  = Id::of( 'pack' );
		$ids   = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$ids[] = self::id_with( 3, 0xAB, 17, 0xCD, 'blob ' . $i );
		}
		$index->add_pack( $pack, array_map( static fn ( Id $id, int $i ): PackEntry => new PackEntry( BlobType::Data, $id, 100 * $i, 100, $i ), $ids, array_keys( $ids ) ) );

		self::assertSame( 300, $index->count() );
		foreach ( $ids as $i => $id ) {
			self::assertEquals( new PackEntry( BlobType::Data, $id, 100 * $i, 100, $i ), $index->find( $id, BlobType::Data )?->entry );
		}
		self::assertFalse( $index->has( self::id_with( 3, 0xAB, 17, 0xCD, 'not added' ), BlobType::Data ) );
		$pieces = iterator_to_array( $index->export(), false );
		self::assertSame( 'D' . pack( 'vV', 0xABCD, 300 * 48 ), substr( $pieces[2], 0, 7 ), 'All of them lie in the bucket their two bytes name, high byte first.' );
	}

	public function test_an_id_that_only_appears_across_two_records_is_not_found(): void {
		// The bytes of this ID are the three numbers of one record followed by the start of the
		// next record's ID: a search that ignored where records begin would find it.
		$numbers = pack( 'V3', 0x00001234, 77, 5 );
		$second  = self::id_with( 0, 0x34, 1, 0x12, 'second' );
		$ghost   = Id::from_bytes( $numbers . substr( $second->bytes(), 0, 20 ) );
		$first   = self::id_with( 0, 0x34, 1, 0x12, 'first' );
		$index   = new IndexSet( 1, 0 );
		$index->add_pack( Id::of( 'pack' ), [ new PackEntry( BlobType::Data, $first, 0x1234, 77, 5 ), new PackEntry( BlobType::Data, $second, 9000, 10 ) ] );
		self::assertSame( "\x34\x12", substr( $ghost->bytes(), 0, 2 ), 'The ghost belongs to the same bucket.' );

		self::assertFalse( $index->has( $ghost, BlobType::Data ) );
		self::assertNull( $index->find( $ghost, BlobType::Data ) );

		// Once it really is in the index, it is found behind the place where it only seemed to be.
		$index->add_pack( Id::of( 'other pack' ), [ new PackEntry( BlobType::Data, $ghost, 1, 2 ) ] );
		self::assertSame( 3, $index->count() );
		self::assertEquals( new PackEntry( BlobType::Data, $ghost, 1, 2 ), $index->find( $ghost, BlobType::Data )?->entry );
		self::assertSame( Id::of( 'other pack' )->hex(), $index->find( $ghost, BlobType::Data )?->pack->hex() );
	}

	/**
	 * @return iterable<string, array{int, int, int}>
	 */
	public static function entries_beyond_any_pack(): iterable {
		yield 'offset of 4 GiB' => [ 4294967296, 40, 0 ];
		yield 'length of 4 GiB' => [ 0, 4294967296, 0 ];
		yield 'uncompressed length of 4 GiB' => [ 0, 40, 4294967296 ];
		yield 'negative offset' => [ -1, 40, 0 ];
		yield 'negative length' => [ 0, -1, 0 ];
		yield 'negative uncompressed length' => [ 0, 40, -1 ];
	}

	#[DataProvider( 'entries_beyond_any_pack' )]
	public function test_an_entry_beyond_any_pack_is_refused_with_its_whole_pack( int $offset, int $length, int $uncompressed ): void {
		$index = new IndexSet();
		$pack  = Id::of( 'pack' );
		try {
			$index->add_pack( $pack, [ new PackEntry( BlobType::Data, Id::of( 'fine' ), 0, 40 ), new PackEntry( BlobType::Data, Id::of( 'beyond' ), $offset, $length, $uncompressed ) ] );
			self::fail( 'A record holds 32-bit numbers only.' );
		} catch ( FormatException $e ) {
			self::assertSame( 'The index describes a blob beyond the size of any pack.', $e->getMessage() );
		}
		self::assertSame( 0, $index->count(), 'Nothing of the pack was added.' );
		self::assertFalse( $index->has_pack( $pack ) );
	}

	public function test_the_largest_numbers_a_record_holds_come_back_unchanged(): void {
		$index = new IndexSet();
		$blob  = Id::of( 'blob' );
		$index->add_pack( Id::of( 'pack' ), [ new PackEntry( BlobType::Tree, $blob, 4294967295, 4294967295, 4294967295 ) ] );

		self::assertEquals( new PackEntry( BlobType::Tree, $blob, 4294967295, 4294967295, 4294967295 ), $index->find( $blob, BlobType::Tree )?->entry );
	}

	public function test_the_bucket_bytes_are_two_different_positions_drawn_at_random(): void {
		$seen = [];
		for ( $i = 0; $i < 400; $i++ ) {
			$head   = iterator_to_array( ( new IndexSet() )->export(), false )[0];
			$fields = unpack( 'Chigh/Clow', $head, strlen( self::MAGIC ) );
			self::assertIsArray( $fields );
			self::assertNotSame( $fields['high'], $fields['low'] );
			self::assertLessThan( 32, max( $fields['high'], $fields['low'] ) );
			$seen[ $fields['high'] . ':' . $fields['low'] ] = true;
		}
		self::assertGreaterThan( 200, count( $seen ), 'Many of the 992 pairs come up, so nobody can aim at one bucket.' );
		self::assertGreaterThan( 20, count( array_unique( array_map( static fn ( string $pair ): string => explode( ':', $pair )[0], array_keys( $seen ) ) ) ) );
		self::assertGreaterThan( 20, count( array_unique( array_map( static fn ( string $pair ): string => explode( ':', $pair )[1], array_keys( $seen ) ) ) ) );
	}

	/**
	 * @return iterable<string, array{int, int}>
	 */
	public static function positions_an_id_does_not_have(): iterable {
		yield 'the same byte twice' => [ 4, 4 ];
		yield 'a high position after the last byte' => [ 32, 0 ];
		yield 'a low position after the last byte' => [ 0, 32 ];
		yield 'a negative high position' => [ -1, 0 ];
		yield 'a negative low position' => [ 0, -1 ];
	}

	#[DataProvider( 'positions_an_id_does_not_have' )]
	public function test_bucket_positions_must_be_two_bytes_of_an_id( int $high, int $low ): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'The index names bucket bytes that an ID does not have.' );
		new IndexSet( $high, $low );
	}

	public function test_the_last_byte_of_an_id_can_choose_the_bucket(): void {
		$index = new IndexSet( 31, 0 );
		$blob  = Id::of( 'blob' );
		$index->add_pack( Id::of( 'pack' ), [ new PackEntry( BlobType::Data, $blob, 0, 40 ) ] );

		self::assertTrue( $index->has( $blob, BlobType::Data ) );
		$raw = $blob->bytes();
		self::assertSame( 'D' . pack( 'vV', ( ord( $raw[31] ) << 8 ) | ord( $raw[0] ), 48 ), substr( iterator_to_array( $index->export(), false )[2], 0, 7 ) );
	}

	public function test_blobs_whose_first_bytes_agree_do_not_meet_in_one_bucket(): void {
		// What someone gets who can choose the first two bytes of every blob ID: nothing, unless
		// those happen to be the two positions of this index.
		$index = new IndexSet( 9, 21 );
		$ids   = [];
		for ( $i = 0; $i < 500; $i++ ) {
			$ids[] = self::id_with( 0, 0xAA, 1, 0xBB, 'chosen ' . $i );
		}
		$index->add_pack( Id::of( 'pack' ), array_map( static fn ( Id $id ): PackEntry => new PackEntry( BlobType::Data, $id, 0, 40 ), $ids ) );

		$buckets = 0;
		$longest = 0;
		$piece   = iterator_to_array( $index->export(), false )[2];
		$length  = strlen( $piece );
		$offset  = 1;
		while ( $offset < $length ) {
			$fields = unpack( 'vbucket/Vbytes', $piece, $offset );
			self::assertIsArray( $fields );
			++$buckets;
			$longest = max( $longest, $fields['bytes'] / 48 );
			$offset += 6 + $fields['bytes'];
		}
		self::assertGreaterThan( 450, $buckets );
		self::assertLessThan( 5, $longest );
	}

	public function test_the_exported_pieces_have_the_documented_layout(): void {
		$pack  = Id::of( 'pack' );
		$other = Id::of( 'other pack' );
		$tree  = self::id_with( 0, 0x01, 1, 0x02, 'tree' );
		$data  = self::id_with( 0, 0xFE, 1, 0xFF, 'data' );
		$late  = self::id_with( 0, 0xFE, 1, 0xFF, 'late' );
		$set   = new IndexSet( 0, 1 );
		$set->add_pack( $pack, [ new PackEntry( BlobType::Tree, $tree, 0, 40 ), new PackEntry( BlobType::Data, $data, 40, 100, 200 ) ] );
		$set->add_pack( $other, [ new PackEntry( BlobType::Data, $late, 7, 8, 9 ) ] );

		self::assertSame(
			[
				self::MAGIC . pack( 'C2V2', 0, 1, 2, 3 ),
				'P' . $pack->bytes() . $other->bytes(),
				'T' . pack( 'vV', 0x0102, 48 ) . $tree->bytes() . pack( 'V4', 0, 0, 40, 0 ),
				'D' . pack( 'vV', 0xFEFF, 96 ) . $data->bytes() . pack( 'V4', 0, 40, 100, 200 ) . $late->bytes() . pack( 'V4', 1, 7, 8, 9 ),
			],
			iterator_to_array( $set->export(), false )
		);
	}

	public function test_an_empty_index_is_one_piece_and_comes_back_empty(): void {
		$pieces = iterator_to_array( ( new IndexSet( 5, 6 ) )->export(), false );
		self::assertSame( [ self::MAGIC . pack( 'C2V2', 5, 6, 0, 0 ) ], $pieces );

		$copy = IndexSet::import( $pieces );
		self::assertSame( 0, $copy->count() );
		self::assertSame( [], $copy->pack_ids() );
		self::assertSame( $pieces, iterator_to_array( $copy->export(), false ), 'The copy keeps the bucket positions.' );
	}

	public function test_a_large_index_is_exported_in_pieces_of_about_a_megabyte_and_comes_back_complete(): void {
		$index = new IndexSet();
		for ( $pack = 0; $pack < 70; $pack++ ) {
			$entries = [];
			for ( $i = 0; $i < 1000; $i++ ) {
				$entries[] = new PackEntry( 0 === $i % 10 ? BlobType::Tree : BlobType::Data, Id::of( $pack . ' ' . $i ), $i * 1000, 1000, $pack );
			}
			$index->add_pack( Id::of( 'pack ' . $pack ), $entries );
		}
		$pieces = iterator_to_array( $index->export(), false );
		$tags   = implode( '', array_map( static fn ( string $piece ): string => $piece[0], array_slice( $pieces, 1 ) ) );

		self::assertMatchesRegularExpression( '/^PT+D{3,}$/', $tags, 'Packs first, then trees, then data in several pieces.' );
		self::assertLessThan( 1048576 + 70000, max( array_map( 'strlen', $pieces ) ), 'A piece ends with the bucket that fills it.' );
		self::assertGreaterThan( 1048575, strlen( $pieces[ count( $pieces ) - 2 ] ), 'Only the last piece of a type may be short.' );

		$copy = IndexSet::import( $pieces );
		self::assertSame( 70000, $copy->count() );
		self::assertSame( self::blobs( $index ), self::blobs( $copy ) );
		self::assertSame( $pieces, iterator_to_array( $copy->export(), false ) );
		self::assertCount( 70000, array_unique( self::blobs( $copy ) ), 'locations() gives every blob once.' );
	}

	public function test_an_index_of_many_blobs_stays_compact(): void {
		$entries = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$entries[] = new PackEntry( BlobType::Data, Id::of( 'template ' . $i ), $i, 40 );
		}
		$before = memory_get_usage();
		$index  = new IndexSet();
		for ( $pack = 0; $pack < 4000; $pack++ ) {
			$batch = [];
			foreach ( $entries as $i => $entry ) {
				$batch[] = new PackEntry( BlobType::Data, Id::from_bytes( hash( 'sha256', $pack . ':' . $i, true ) ), $entry->offset, $entry->length );
			}
			$index->add_pack( Id::from_bytes( hash( 'sha256', 'pack' . $pack, true ) ), $batch );
		}
		unset( $batch );
		$bytes = ( memory_get_usage() - $before ) / $index->count();

		self::assertSame( 200000, $index->count() );
		self::assertLessThan( 80, $bytes, 'About 160 bytes per blob was the cost of one array element each (ADR 0031).' );
	}

	/**
	 * Pieces that import() must refuse, each with the reason it gives.
	 *
	 * @return iterable<string, array{list<string>, string}>
	 */
	public static function damaged_caches(): iterable {
		$pack   = Id::of( 'pack' );
		$blob   = self::id_with( 0, 0x00, 1, 0x07, 'blob' );
		$head   = self::MAGIC . pack( 'C2V2', 0, 1, 1, 1 );
		$packs  = 'P' . $pack->bytes();
		$record = $blob->bytes() . pack( 'V4', 0, 0, 40, 0 );
		$bucket = 'D' . pack( 'vV', 7, 48 ) . $record;
		yield 'no piece at all' => [ [], 'is empty' ];
		yield 'the format before this one' => [ [ "backups-for-restic index 1\n" . pack( 'C2V2', 0, 1, 1, 1 ), $packs, $bucket ], 'not recognised' ];
		yield 'a head that is a byte short' => [ [ substr( $head, 0, -1 ), $packs, $bucket ], 'not recognised' ];
		yield 'a head that is a byte long' => [ [ $head . "\0", $packs, $bucket ], 'not recognised' ];
		yield 'bucket positions that are the same byte' => [ [ self::MAGIC . pack( 'C2V2', 3, 3, 1, 1 ), $packs, $bucket ], 'bucket bytes' ];
		yield 'a bucket position beyond an ID' => [ [ self::MAGIC . pack( 'C2V2', 0, 32, 1, 1 ), $packs, $bucket ], 'bucket bytes' ];
		yield 'an unknown piece' => [ [ $head, $packs, 'X' . substr( $bucket, 1 ) ], 'unknown piece' ];
		yield 'an empty piece' => [ [ $head, $packs, '', $bucket ], 'unknown piece' ];
		yield 'a pack list with a byte missing' => [ [ $head, substr( $packs, 0, -1 ), $bucket ], 'truncated pack list' ];
		yield 'a pack list with a byte too many' => [ [ $head, $packs . "\0", $bucket ], 'truncated pack list' ];
		yield 'a pack listed twice in one piece' => [ [ $head, $packs . $pack->bytes(), $bucket ], 'lists a pack twice' ];
		yield 'a pack listed twice in two pieces' => [ [ $head, $packs, $packs, $bucket ], 'lists a pack twice' ];
		yield 'a bucket whose length is cut off' => [ [ $head, $packs, 'D' . pack( 'v', 7 ) . "\x30\0\0" ], 'damaged bucket' ];
		yield 'a bucket without records' => [ [ $head, $packs, 'D' . pack( 'vV', 7, 0 ) ], 'damaged bucket' ];
		yield 'a bucket that is not whole records' => [ [ $head, $packs, 'D' . pack( 'vV', 7, 47 ) . substr( $record, 0, 47 ) ], 'damaged bucket' ];
		yield 'a bucket longer than its piece' => [ [ $head, $packs, 'D' . pack( 'vV', 7, 96 ) . $record ], 'damaged bucket' ];
		yield 'a bucket twice in one piece' => [ [ $head, $packs, $bucket . substr( $bucket, 1 ) ], 'bucket twice' ];
		yield 'a bucket twice in two pieces' => [ [ $head, $packs, $bucket, $bucket ], 'bucket twice' ];
		yield 'a pack fewer than counted' => [ [ $head, 'P', $bucket ], 'incomplete' ];
		yield 'a pack more than counted' => [ [ $head, $packs . Id::of( 'more' )->bytes(), $bucket ], 'incomplete' ];
		yield 'a blob fewer than counted' => [ [ $head, $packs ], 'incomplete' ];
		yield 'a blob more than counted' => [ [ $head, $packs, 'D' . pack( 'vV', 7, 96 ) . $record . Id::of( 'more' )->bytes() . pack( 'V4', 0, 0, 1, 0 ) ], 'incomplete' ];
	}

	/**
	 * @param list<string> $pieces
	 */
	#[DataProvider( 'damaged_caches' )]
	public function test_a_damaged_cache_is_refused( array $pieces, string $reason ): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( $reason );
		IndexSet::import( $pieces );
	}

	public function test_the_same_bucket_number_may_hold_trees_and_data(): void {
		$pack = Id::of( 'pack' );
		$tree = self::id_with( 0, 0, 1, 7, 'tree' );
		$data = self::id_with( 0, 0, 1, 7, 'data' );
		$copy = IndexSet::import(
			[
				self::MAGIC . pack( 'C2V2', 0, 1, 1, 2 ),
				'P' . $pack->bytes(),
				'T' . pack( 'vV', 7, 48 ) . $tree->bytes() . pack( 'V4', 0, 5, 6, 0 ),
				'D' . pack( 'vV', 7, 48 ) . $data->bytes() . pack( 'V4', 0, 11, 40, 90 ),
			]
		);

		self::assertSame( 2, $copy->count() );
		self::assertEquals( new PackEntry( BlobType::Tree, $tree, 5, 6 ), $copy->find( $tree, BlobType::Tree )?->entry );
		self::assertEquals( new PackEntry( BlobType::Data, $data, 11, 40, 90 ), $copy->find( $data, BlobType::Data )?->entry );
		self::assertNull( $copy->find( $tree, BlobType::Data ) );
		self::assertTrue( $copy->has_pack( $pack ) );
	}

	public function test_a_record_that_names_a_pack_the_index_does_not_list_is_refused_when_it_is_used(): void {
		$blob = self::id_with( 0, 0, 1, 7, 'blob' );
		$copy = IndexSet::import(
			[
				self::MAGIC . pack( 'C2V2', 0, 1, 1, 1 ),
				'P' . Id::of( 'pack' )->bytes(),
				'D' . pack( 'vV', 7, 48 ) . $blob->bytes() . pack( 'V4', 1, 0, 40, 0 ),
			]
		);

		self::assertTrue( $copy->has( $blob, BlobType::Data ) );
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'The index names a pack it does not list.' );
		$copy->find( $blob, BlobType::Data );
	}
}
