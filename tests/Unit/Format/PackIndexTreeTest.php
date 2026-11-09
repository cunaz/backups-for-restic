<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Format\Unpacked;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Packs, index files and trees written by restic are read, verified and re-encoded.
 */
final class PackIndexTreeTest extends TestCase {

	/**
	 * @return array<string, list<\BackupsForRestic\Format\Pack\PackEntry>>
	 */
	private static function restic_index(): array {
		$files = Vectors::restic_files( 'index/*' );
		self::assertCount( 1, $files );
		return IndexFile::decode( Unpacked::decode( Vectors::cipher()->open( (string) file_get_contents( $files[0] ) ), 1 ) );
	}

	public function test_index_round_trips_byte_for_byte(): void {
		$files     = Vectors::restic_files( 'index/*' );
		$plaintext = Vectors::cipher()->open( (string) file_get_contents( $files[0] ) );
		self::assertSame( $plaintext, IndexFile::encode( IndexFile::decode( $plaintext ) ) );
		self::assertStringEndsWith( "\n", $plaintext, 'restic writes index files with a trailing newline.' );
		self::assertSame( trim( Vectors::restic( 'index.json' ) ), rtrim( $plaintext, "\n" ) );
	}

	public function test_pack_headers_match_the_index_and_blobs_verify(): void {
		$cipher = Vectors::cipher();
		foreach ( self::restic_index() as $pack_id => $indexed ) {
			$path  = Vectors::restic_files( 'data/' . substr( (string) $pack_id, 0, 2 ) . '/' . $pack_id )[0];
			$bytes = (string) file_get_contents( $path );
			self::assertSame( $pack_id, Id::of( $bytes )->hex(), 'Pack name is the hash of its content.' );

			$length  = PackReader::header_length( substr( $bytes, -4 ), strlen( $bytes ) );
			$entries = PackReader::read_header( $cipher, substr( $bytes, -4 - $length, $length ), strlen( $bytes ) );
			self::assertEquals( $indexed, $entries );

			foreach ( $entries as $entry ) {
				$plaintext = PackReader::open_blob( $cipher, $entry, substr( $bytes, $entry->offset, $entry->length ) );
				self::assertSame( $entry->id->hex(), Id::of( $plaintext )->hex() );
			}
		}
	}

	public function test_every_tree_in_the_repository_re_encodes_byte_for_byte(): void {
		$cipher = Vectors::cipher();
		$trees  = 0;
		foreach ( self::restic_index() as $pack_id => $entries ) {
			$bytes = (string) file_get_contents( Vectors::restic_files( 'data/' . substr( (string) $pack_id, 0, 2 ) . '/' . $pack_id )[0] );
			foreach ( $entries as $entry ) {
				if ( BlobType::Tree !== $entry->type ) {
					continue;
				}
				$blob = PackReader::open_blob( $cipher, $entry, substr( $bytes, $entry->offset, $entry->length ) );
				self::assertSame( $blob, TreeCodec::encode( TreeCodec::decode( $blob ) ) );
				++$trees;
			}
		}
		self::assertSame( 4, $trees );
	}

	public function test_root_tree_contents(): void {
		$nodes = TreeCodec::decode( Vectors::restic( 'root-tree.bin' ) );
		$names = array_map( static fn ( Node $node ): string => $node->name, $nodes );
		self::assertSame( [ 'dir', 'empty.txt', 'hello.txt', 'ümlaut' ], $names );
		self::assertSame( [], $nodes[1]->content, 'Empty files have an empty content list.' );
		self::assertNull( $nodes[0]->content, 'Directories have no content.' );
		self::assertSame( '2026-01-02T03:04:05.123456789Z', $nodes[2]->mtime );
		self::assertSame( FileMode::DIR | 0755, $nodes[0]->mode );
	}

	public function test_pack_writer_output_is_readable(): void {
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
		$writer = new PackWriter( $cipher );
		$blobs  = [ 'first', str_repeat( 'second', 1000 ), '' ];
		foreach ( $blobs as $blob ) {
			$writer->add( BlobType::Data, Id::of( $blob ), $blob );
		}
		$pack = $writer->finish();

		self::assertSame( $pack->id->hex(), Id::of( $pack->bytes )->hex() );
		$length  = PackReader::header_length( substr( $pack->bytes, -4 ), strlen( $pack->bytes ) );
		$entries = PackReader::read_header( $cipher, substr( $pack->bytes, -4 - $length, $length ), strlen( $pack->bytes ) );
		self::assertEquals( $pack->entries, $entries );
		foreach ( $entries as $index => $entry ) {
			self::assertSame( $blobs[ $index ], PackReader::open_blob( $cipher, $entry, substr( $pack->bytes, $entry->offset, $entry->length ) ) );
		}
	}

	public function test_corrupted_packs_are_rejected(): void {
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
		$writer = new PackWriter( $cipher );
		$writer->add( BlobType::Data, Id::of( 'blob' ), 'blob' );
		$pack = $writer->finish();

		try {
			PackReader::header_length( Bytes::uint32_le( strlen( $pack->bytes ) ), strlen( $pack->bytes ) );
			self::fail( 'An impossible header length was accepted.' );
		} catch ( FormatException ) {
			self::assertTrue( true );
		}

		$entry = $pack->entries[0];
		try {
			PackReader::open_blob( $cipher, new \BackupsForRestic\Format\Pack\PackEntry( BlobType::Data, Id::of( 'other' ), 0, $entry->length ), substr( $pack->bytes, 0, $entry->length ) );
			self::fail( 'A blob with a wrong ID was accepted.' );
		} catch ( FormatException ) {
			self::assertTrue( true );
		}

		$tampered     = $pack->bytes;
		$tampered[20] = chr( ord( $tampered[20] ) ^ 1 );
		$this->expectException( AuthenticationFailed::class );
		PackReader::open_blob( $cipher, $entry, substr( $tampered, 0, $entry->length ) );
	}

	public function test_index_set_lookups(): void {
		$set = new IndexSet();
		foreach ( self::restic_index() as $pack_id => $entries ) {
			$set->add_pack( Id::from_hex( (string) $pack_id ), $entries );
			foreach ( $entries as $entry ) {
				$found = $set->find( $entry->id, $entry->type );
				self::assertNotNull( $found );
				self::assertSame( $pack_id, $found->pack->hex() );
				self::assertEquals( $entry, $found->entry );
			}
		}
		self::assertSame( 8, $set->count() );
		self::assertFalse( $set->has( Id::of( 'unknown' ), BlobType::Data ) );
	}

	public function test_trees_must_be_sorted_and_unique(): void {
		$this->expectException( InvalidArgument::class );
		TreeCodec::encode( [ new Node( 'b', NodeType::File, content: [] ), new Node( 'a', NodeType::File, content: [] ) ] );
	}

	public function test_nodes_preserve_awkward_names_and_link_targets(): void {
		$node    = new Node( "tab\t\"quote\"\xff", NodeType::Symlink, FileMode::SYMLINK | 0777, link_target: "target\xfe" );
		$decoded = TreeCodec::decode( TreeCodec::encode( [ $node ] ) )[0];
		self::assertSame( $node->name, $decoded->name );
		self::assertSame( $node->link_target, $decoded->link_target );
		self::assertStringContainsString( '"linktarget_raw":"', $node->to_json() );
	}

	public function test_file_modes_follow_go(): void {
		self::assertSame( 2147484141, FileMode::from_posix( 040755 ) );
		self::assertSame( 420, FileMode::from_posix( 0100644 ) );
		self::assertSame( 134218239, FileMode::from_posix( 0120777 ) );
		self::assertSame( FileMode::SETUID | FileMode::STICKY | 0700, FileMode::from_posix( 0105700 ) );
		self::assertSame( 05700, FileMode::to_posix_permissions( FileMode::SETUID | FileMode::STICKY | 0700 ) );
		self::assertSame( NodeType::Dir, FileMode::type_from_posix( 040755 ) );
	}
}
