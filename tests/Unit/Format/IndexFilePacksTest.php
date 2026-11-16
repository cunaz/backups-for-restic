<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Tests\Support\Vectors;
use BackupsForRestic\Tests\Support\WholeIndexDecoder;

/**
 * Index files are decoded pack by pack (ADR 0031). For every text that must give the same
 * packs as decoding the whole document at once, or be refused like it.
 */
final class IndexFilePacksTest extends TestCase {

	private static function pack( string $seed, string $blobs = '' ): string {
		return '{"id":"' . Id::of( $seed )->hex() . '","blobs":[' . $blobs . ']}';
	}

	private static function blob( string $seed, int $offset = 0, int $length = 40 ): string {
		return '{"id":"' . Id::of( $seed )->hex() . '","type":"data","offset":' . $offset . ',"length":' . $length . '}';
	}

	/**
	 * Texts that are valid index files, with the number of packs they hold.
	 *
	 * @return iterable<string, array{string, int}>
	 */
	public static function accepted(): iterable {
		$a = self::pack( 'a', self::blob( 'a1' ) . ',' . self::blob( 'a2', 40 ) );
		$b = self::pack( 'b' );
		yield 'what restic 0.19.1 wrote' => [ Vectors::restic( 'index.json' ), 2 ];
		yield 'an empty list' => [ '{"packs":[]}', 0 ];
		yield 'one pack' => [ '{"packs":[' . $a . ']}', 1 ];
		yield 'white space of every kind between the tokens' => [ " \t\r\n{ \"packs\" \t:\r\n[ " . $a . " \n,\t" . $b . " ]\n}\r\n", 2 ];
		yield 'a list of superseded index files before the packs' => [ '{"supersedes":["' . Id::of( 'old' )->hex() . '"],"packs":[' . $a . ']}', 1 ];
		yield 'members after the packs' => [ '{"packs":[' . $a . '],"later":{"packs":[' . $b . ']},"more":[[{}]]}', 1 ];
		yield 'the key inside another member is not the list' => [ '{"other":{"packs":[' . $b . ',' . $b . ']},"packs":[' . $a . ']}', 1 ];
		yield 'a second member with the key replaces the first' => [ '{"packs":[' . $a . ',' . $b . '],"packs":[' . $b . ']}', 1 ];
		yield 'a second member with an empty list replaces the first' => [ '{"packs":[' . $a . '],"packs":[]}', 0 ];
		yield 'the key written with an escape' => [ '{"pack\u0073":[' . $a . ']}', 1 ];
		yield 'an escaped key replaces a plain one' => [ '{"packs":[' . $a . ',' . $b . '],"\u0070acks":[' . $b . ']}', 1 ];
		yield 'a string value that reads like the key' => [ '{"note":"packs","packs":[' . $a . ']}', 1 ];
		yield 'brackets, braces and quotes inside strings' => [ '{"note":"}]{[\\"","packs":[{"id":"' . Id::of( 'p' )->hex() . '","note":"]}\\\\","x":"\\\\\\"}","blobs":[]}]}', 1 ];
		yield 'a pack with members the plugin does not know' => [ '{"packs":[{"extra":[{"deep":[1,{"a":"}"}]}],"id":"' . Id::of( 'p' )->hex() . '","blobs":[' . self::blob( 'x' ) . ']}]}', 1 ];
		yield 'the same pack twice' => [ '{"packs":[' . $a . ',' . self::pack( 'a', self::blob( 'a3', 80 ) ) . ']}', 1 ];
		yield 'a newline at the end, as restic writes it' => [ '{"packs":[' . $a . "]}\n", 1 ];
		yield 'a second list of blobs replaces the first' => [ '{"packs":[{"id":"' . Id::of( 'p' )->hex() . '","blobs":[' . self::blob( 'x' ) . '],"blobs":[]}]}', 1 ];
		yield 'the blobs key inside another member is not the list of blobs' => [ '{"packs":[{"id":"' . Id::of( 'p' )->hex() . '","other":{"blobs":{}},"blobs":[' . self::blob( 'x' ) . '],"later":{"blobs":{}}}]}', 1 ];
		yield 'a string value that reads like the blobs key' => [ '{"packs":[{"id":"' . Id::of( 'p' )->hex() . '","note":"blobs","blobs" : [ ' . self::blob( 'x' ) . ' ]}]}', 1 ];
		// With a repeated name the last member decides for both decoders. restic refuses these
		// three, because it keeps the type error of the first member; no writer repeats a name.
		yield 'an object of packs that a second member replaces by a list' => [ '{"packs":{},"packs":[' . $a . ']}', 1 ];
		yield 'a number of packs that a second member replaces by a list' => [ '{"packs":5,"packs":[]}', 0 ];
		yield 'an object of blobs that a second member replaces by a list' => [ '{"packs":[{"id":"' . Id::of( 'p' )->hex() . '","blobs":{},"blobs":[' . self::blob( 'x' ) . ']}]}', 1 ];
		yield 'members whose names begin with a NUL character' => [ '{"\u0000packs":{},"packs":[{"\u0000":"\u0000","id":"' . Id::of( 'p' )->hex() . '","blobs":[],"note":"\"\u0000"}]}', 1 ];
	}

	#[DataProvider( 'accepted' )]
	public function test_valid_index_files_give_the_same_packs_as_decoding_them_whole( string $json, int $packs ): void {
		self::assertCount( $packs, WholeIndexDecoder::compare( $json ) );
	}

	/**
	 * Texts that both decoders must refuse.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function refused(): iterable {
		$a = self::pack( 'a', self::blob( 'a1' ) );
		yield 'nothing' => [ '' ];
		yield 'only white space' => [ " \n" ];
		yield 'a list at the top' => [ '[{"packs":[' . $a . ']}]' ];
		yield 'a string at the top' => [ '"packs"' ];
		yield 'no packs member' => [ '{"supersedes":[]}' ];
		yield 'packs that is a number' => [ '{"packs":5}' ];
		yield 'packs that is null' => [ '{"packs":null}' ];
		yield 'a list replaced by a number in a second member' => [ '{"packs":[' . $a . '],"packs":7}' ];
		yield 'an object with packs as its members' => [ '{"packs":{"first":' . $a . '}}' ];
		// restic refuses an object where its format has a list. Decoded into arrays, the next
		// three are what a list would be, so the decoders must not judge by the decoded value.
		yield 'an empty object where the list should be' => [ '{"packs":{}}' ];
		yield 'an object with numbered packs' => [ '{"packs":{"0":' . $a . '}}' ];
		yield 'an object with two numbered packs' => [ '{"packs":{"0":' . $a . ',"1":' . self::pack( 'b' ) . '}}' ];
		yield 'a list replaced by an empty object in a second member' => [ '{"packs":[' . $a . '],"packs":{}}' ];
		yield 'a list replaced by an object under an escaped key' => [ '{"packs":[' . $a . '],"pack\u0073":{}}' ];
		yield 'an empty object where the blobs should be' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":{}}]}' ];
		yield 'an object with numbered blobs' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":{"0":' . self::blob( 'a1' ) . '}}]}' ];
		yield 'an object with named blobs' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":{"first":' . self::blob( 'a1' ) . '}}]}' ];
		yield 'a list of blobs replaced by an empty object in a second member' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[' . self::blob( 'a1' ) . '],"blobs":{}}]}' ];
		yield 'a list of blobs replaced by an object under an escaped key' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[],"blob\u0073" : {}}]}' ];
		yield 'a pack whose ID is a number' => [ '{"packs":[{"id":5,"blobs":[]}]}' ];
		yield 'a number among the packs' => [ '{"packs":[' . $a . ',5]}' ];
		yield 'the literal true among the packs' => [ '{"packs":[true,' . $a . ']}' ];
		yield 'only the literal true' => [ '{"packs":[true]}' ];
		yield 'a list among the packs' => [ '{"packs":[[' . $a . ']]}' ];
		yield 'a string among the packs' => [ '{"packs":["' . Id::of( 'a' )->hex() . '"]}' ];
		yield 'an empty object among the packs' => [ '{"packs":[{}]}' ];
		yield 'a pack without blobs' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '"}]}' ];
		yield 'a pack whose ID is not hexadecimal' => [ '{"packs":[{"id":"' . str_repeat( 'zz', 32 ) . '","blobs":[]}]}' ];
		yield 'a blob without a type' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[{"id":"' . Id::of( 'b' )->hex() . '","offset":0,"length":4}]}]}' ];
		yield 'two packs without a comma' => [ '{"packs":[' . $a . ' ' . $a . ']}' ];
		yield 'a comma after the last pack' => [ '{"packs":[' . $a . ',]}' ];
		yield 'a pack that is cut off' => [ '{"packs":[' . substr( $a, 0, -5 ) ];
		yield 'a string that never ends' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . ',"blobs":[]}]}' ];
		yield 'a list that never ends' => [ '{"packs":[' . $a ];
		yield 'an object closed with the wrong bracket' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[]]]}' ];
		yield 'text after the document' => [ '{"packs":[' . $a . ']} {"packs":[]}' ];
		yield 'text before the document' => [ 'x{"packs":[' . $a . ']}' ];
		yield 'a pack with broken text inside' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[tru]}]}' ];
		yield 'a key that is not a string' => [ '{packs:[' . $a . ']}' ];
		yield 'invalid UTF-8 inside a pack' => [ '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","note":"' . "\xC3\x28" . '","blobs":[]}]}' ];
		yield 'invalid UTF-8 outside the packs' => [ '{"note":"' . "\xFF" . '","packs":[' . $a . ']}' ];
	}

	#[DataProvider( 'refused' )]
	public function test_invalid_index_files_are_refused_as_when_decoded_whole( string $json ): void {
		$this->expectException( FormatException::class );
		WholeIndexDecoder::compare( $json );
	}

	/**
	 * What the scheduled fuzzing found: the decoder for the whole file took an object with
	 * the member "0" for a list of one pack, because both decode into the same array.
	 */
	public function test_an_object_with_a_numbered_member_is_no_list_of_packs_for_either_decoder(): void {
		$finding = (string) hex2bin(
			'7b227061636b73223a7b2230223a7b226964223a226364636463646364636463646364636463646364636463646364636463'
			. '6463646364636463646364636463646364636463646364636463646364636463646364222c22626c6f6273223a5b5d7d7d2c'
			. '22756e6b6e6f776e5f6669656c64223a2d327d'
		);
		self::assertSame( '{"packs":{"0":{"id":"' . str_repeat( 'cd', 32 ) . '","blobs":[]}},"unknown_field":-2}', $finding );

		$refusals = [];
		foreach ( [ IndexFile::decode( ... ), WholeIndexDecoder::decode( ... ), WholeIndexDecoder::compare( ... ) ] as $decode ) {
			try {
				$decode( $finding );
			} catch ( FormatException $refused ) {
				$refusals[] = $refused->getMessage();
			}
		}

		self::assertSame( array_fill( 0, 3, 'Index file has no pack list.' ), $refusals );
	}

	public function test_an_object_in_the_place_of_a_list_is_named_as_what_is_wrong(): void {
		$messages = [];
		foreach ( [ '{"packs":{}}', '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":{}}]}' ] as $json ) {
			try {
				IndexFile::decode( $json );
			} catch ( FormatException $refused ) {
				$messages[] = $refused->getMessage();
			}
		}

		self::assertSame( [ 'Index file has no pack list.', 'Index file contains an invalid pack.' ], $messages );
	}

	public function test_nesting_is_limited_as_in_a_whole_document(): void {
		$nested = static fn ( int $levels ): string => '{"packs":[{"id":"' . Id::of( 'a' )->hex() . '","blobs":[],"deep":' . str_repeat( '[', $levels ) . str_repeat( ']', $levels ) . '}]}';

		// The decoder accepts 63 levels. The pack lies at the third, so its member may add 60:
		// decoded on its own, the pack must not be allowed two levels more than inside its file.
		self::assertCount( 1, WholeIndexDecoder::compare( $nested( 60 ) ) );
		$this->expectException( FormatException::class );
		WholeIndexDecoder::compare( $nested( 61 ) );
	}

	public function test_a_pack_listed_twice_comes_twice_and_is_merged_by_decode(): void {
		$json  = '{"packs":[' . self::pack( 'a', self::blob( 'a1' ) ) . ',' . self::pack( 'b' ) . ',' . self::pack( 'a', self::blob( 'a2', 40 ) ) . ']}';
		$packs = [];
		foreach ( IndexFile::packs( $json ) as $pack => $entries ) {
			$packs[] = [ $pack, count( $entries ) ];
		}

		self::assertSame( [ [ Id::of( 'a' )->hex(), 1 ], [ Id::of( 'b' )->hex(), 0 ], [ Id::of( 'a' )->hex(), 1 ] ], $packs );
		self::assertEquals(
			[
				Id::of( 'a' )->hex() => [ new PackEntry( BlobType::Data, Id::of( 'a1' ), 0, 40 ), new PackEntry( BlobType::Data, Id::of( 'a2' ), 40, 40 ) ],
				Id::of( 'b' )->hex() => [],
			],
			IndexFile::decode( $json )
		);
	}

	public function test_encoding_a_pack_generator_preserves_repeated_pack_entries(): void {
		$json = '{"packs":[' . self::pack( 'a', self::blob( 'a1' ) ) . ',' . self::pack( 'b' ) . ',' . self::pack( 'a', self::blob( 'a2', 40 ) ) . "]}\n";
		self::assertSame( $json, IndexFile::encode( IndexFile::packs( $json ) ) );
	}

	#[RunInSeparateProcess]
	public function test_encoding_a_full_index_retains_only_one_packs_decoded_entries(): void {
		$source = static function (): \Generator {
			for ( $pack = 0; $pack < 1250; ++$pack ) {
				$entries = [];
				for ( $blob = 0; $blob < 40; ++$blob ) {
					$entries[] = new PackEntry( BlobType::Data, Id::of( $pack . ':' . $blob ), $blob * 100, 100 );
				}
				yield Id::of( 'pack-' . $pack )->hex() => $entries;
			}
		};
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$json   = IndexFile::encode( $source() );
		$extra  = memory_get_peak_usage() - $before;
		self::assertLessThan( 16 * 1048576, $extra, 'The JSON output fits; a decoded 50,000-blob array does not.' );
		$count = 0;
		foreach ( IndexFile::packs( $json ) as $entries ) {
			$count += count( $entries );
		}
		self::assertSame( IndexFile::MAX_BLOBS, $count );
	}

	public function test_an_error_in_a_later_pack_shows_when_the_iteration_gets_there(): void {
		$json = '{"packs":[' . self::pack( 'a', self::blob( 'a1' ) ) . ',' . self::pack( 'b', self::blob( 'b1' ) ) . ',' . self::pack( 'c', self::blob( 'c1', 0, 0 ) ) . ']}';
		$seen = [];
		try {
			foreach ( IndexFile::packs( $json ) as $pack => $entries ) {
				$seen[] = $pack;
			}
			self::fail( 'The third pack holds a blob without a length.' );
		} catch ( FormatException $e ) {
			self::assertSame( 'Index file contains an invalid blob.', $e->getMessage() );
		}
		self::assertSame( [ Id::of( 'a' )->hex(), Id::of( 'b' )->hex() ], $seen, 'The packs before it were given out: a caller that needs all or nothing collects first.' );
	}

	public function test_what_is_wrong_outside_the_packs_shows_before_the_first_pack(): void {
		$packs = IndexFile::packs( '{"packs":[' . self::pack( 'a' ) . ',7]}' );
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Index file contains an invalid pack.' );
		$packs->current();
	}

	/**
	 * In a process of its own: decoding the file whole, for comparison, takes more memory than
	 * the suite's limit leaves after the tests before it.
	 */
	#[RunInSeparateProcess]
	public function test_a_full_index_file_is_decoded_with_a_fraction_of_the_memory(): void {
		$packs = [];
		for ( $pack = 0; $pack < 250; $pack++ ) {
			$entries = [];
			for ( $i = 0; $i < 200; $i++ ) {
				$entries[] = new PackEntry( BlobType::Data, Id::from_bytes( hash( 'sha256', $pack . ':' . $i, true ) ), $i * 5000, 5000 );
			}
			$packs[ hash( 'sha256', 'pack' . $pack ) ] = $entries;
		}
		$json = IndexFile::encode( $packs );
		unset( $packs, $entries );

		memory_reset_peak_usage();
		$before = memory_get_usage();
		$blobs  = 0;
		foreach ( IndexFile::packs( $json ) as $entries ) {
			$blobs += count( $entries );
		}
		$by_pack = memory_get_peak_usage() - $before;
		unset( $entries );

		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$whole   = WholeIndexDecoder::decode( $json );
		$at_once = memory_get_peak_usage() - $before;

		self::assertSame( IndexFile::MAX_BLOBS, $blobs );
		self::assertCount( 250, $whole );
		self::assertGreaterThan( 5000000, strlen( $json ), 'An index file as large as restic makes them.' );
		self::assertLessThan( 2000000, $by_pack, 'One pack of blobs at a time.' );
		self::assertGreaterThan( 20 * $by_pack, $at_once );
	}
}
