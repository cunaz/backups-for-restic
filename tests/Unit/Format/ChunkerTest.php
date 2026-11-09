<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Format\Chunker\ChunkReader;
use BackupsForRestic\Format\Chunker\Polynomial;
use BackupsForRestic\Format\Chunker\RabinChunker;
use BackupsForRestic\Support\StringByteSource;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Chunk boundaries must equal restic's so that data deduplicates across the plugin and the CLI.
 * The vectors come from restic/chunker itself (tests/Fixtures/vectors/generate-go-vectors.go.txt).
 */
final class ChunkerTest extends TestCase {

	public function test_chunk_boundaries_match_restic(): void {
		$vector = Vectors::json( 'vectors/go-strings-and-chunker.json' )['chunker'];
		$data   = Vectors::hash_stream( $vector['seed'], $vector['length'] );
		$reader = new ChunkReader( new StringByteSource( $data ), new RabinChunker( (int) hexdec( $vector['polynomial'] ) ) );

		$actual = [];
		$start  = 0;
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			$actual[] = [ $start, strlen( $chunk ), hash( 'sha256', $chunk ) ];
			$start   += strlen( $chunk );
		}

		$expected = array_map( static fn ( array $chunk ): array => [ $chunk['start'], $chunk['length'], $chunk['sha256'] ], $vector['chunks'] );
		self::assertSame( $expected, $actual );
	}

	public function test_boundaries_do_not_depend_on_read_sizes(): void {
		$data    = Vectors::hash_stream( 'read-sizes', 3 * 1048576 );
		$chunker = new RabinChunker( 0x3DA3358B4DC173 );

		$whole  = [];
		$reader = new ChunkReader( new StringByteSource( $data ), $chunker );
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			$whole[] = strlen( $chunk );
		}

		// Feed the same data in odd-sized pieces straight into the chunker.
		$chunker->reset();
		$pieces = [];
		$length = 0;
		$total  = strlen( $data );
		for ( $offset = 0; $offset < $total; $offset += 77777 ) {
			$piece  = substr( $data, $offset, 77777 );
			$size   = strlen( $piece );
			$cursor = 0;
			while ( $cursor < $size ) {
				$split = $chunker->next_split_point( $piece, $cursor, strlen( $piece ) - $cursor );
				if ( -1 === $split ) {
					$length += strlen( $piece ) - $cursor;
					break;
				}
				$pieces[] = $length + $split;
				$length   = 0;
				$cursor  += $split;
			}
		}
		$pieces[] = $length;

		self::assertSame( $whole, $pieces );
	}

	public function test_small_inputs_are_one_chunk_and_empty_input_none(): void {
		$reader = new ChunkReader( new StringByteSource( 'tiny' ), new RabinChunker( 0x3DA3358B4DC173 ) );
		self::assertSame( 'tiny', $reader->next() );
		self::assertNull( $reader->next() );

		$empty = new ChunkReader( new StringByteSource( '' ), new RabinChunker( 0x3DA3358B4DC173 ) );
		self::assertNull( $empty->next() );
	}

	public function test_chunks_stay_within_restic_bounds(): void {
		$reader = new ChunkReader( new StringByteSource( str_repeat( "\0", 20 * 1048576 ) ), new RabinChunker( 0x3DA3358B4DC173 ) );
		$total  = 0;
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			self::assertLessThanOrEqual( RabinChunker::MAX_SIZE, strlen( $chunk ) );
			$total += strlen( $chunk );
		}
		self::assertSame( 20 * 1048576, $total );
	}

	public function test_irreducibility_matches_restic(): void {
		foreach ( Vectors::json( 'vectors/go-strings-and-chunker.json' )['irreducible'] as $hex => $expected ) {
			self::assertSame( $expected, Polynomial::is_irreducible( (int) hexdec( (string) $hex ) ), 'Polynomial ' . $hex );
		}
	}

	public function test_random_polynomials_match_restic_for_the_same_input(): void {
		foreach ( Vectors::json( 'vectors/go-strings-and-chunker.json' )['derived_polynomials'] as $case ) {
			$polynomial = Polynomial::random( new FixedRandom( '', Vectors::hash_stream( $case['seed'], 8 * 4000 ) ) );
			self::assertSame( $case['polynomial'], dechex( $polynomial ) );
			self::assertSame( 53, Polynomial::degree( $polynomial ) );
		}
	}

	public function test_polynomial_arithmetic_basics(): void {
		self::assertSame( -1, Polynomial::degree( 0 ) );
		self::assertSame( 0, Polynomial::degree( 1 ) );
		self::assertSame( 53, Polynomial::degree( 0x3DA3358B4DC173 ) );
		self::assertSame( 0b1, Polynomial::mod( 0b111, 0b11 ) );
		self::assertSame( 0b11, Polynomial::gcd( 0b110, 0b11 ) );
		self::assertSame( 0b101, Polynomial::mul_mod( 0b11, 0b11, 0b1011 ) );
	}

	public function test_the_gcd_with_zero_is_the_other_polynomial(): void {
		self::assertSame( 0b1011, Polynomial::gcd( 0, 0b1011 ) );
		self::assertSame( 0b1011, Polynomial::gcd( 0b1011, 0 ) );
	}

	public function test_irreducibility_of_small_polynomials(): void {
		// Over GF(2), x^2 + x + 1 is the only irreducible polynomial of degree 2, and
		// x^3 + x + 1 and x^3 + x^2 + 1 are the only ones of degree 3.
		$irreducible = [ 0b111, 0b1011, 0b1101 ];
		foreach ( [ 0b100, 0b101, 0b110, 0b111, 0b1000, 0b1001, 0b1010, 0b1011, 0b1100, 0b1101, 0b1110, 0b1111 ] as $polynomial ) {
			self::assertSame( in_array( $polynomial, $irreducible, true ), Polynomial::is_irreducible( $polynomial ), 'Polynomial ' . decbin( $polynomial ) );
		}
	}

	/**
	 * Data whose Rabin fingerprint has its low 20 bits zero where a cut is wanted: the last three
	 * bytes of each 64-byte window are chosen to cancel the rest of the window, the fingerprint
	 * being linear. restic/chunker cuts it into 524288, 524298 and 1000 bytes: the first chunk
	 * ends exactly at the minimum size, the second ten bytes into the third 512 KiB read. The
	 * window that ends at 523288 matches too, but restic skips it without hashing.
	 */
	private static function crafted_data(): string {
		$data = Vectors::hash_stream( 'chunk-boundaries', 1049586 );
		foreach ( [
			523285  => '7390c8',
			524285  => 'a7f582',
			1048583 => 'ad154f',
		] as $position => $bytes ) {
			$data = substr_replace( $data, (string) hex2bin( $bytes ), $position, 3 );
		}
		return $data;
	}

	/**
	 * @return list<int> Chunk lengths.
	 */
	private static function chunk_lengths( ChunkReader $reader ): array {
		$lengths = [];
		for ( $chunk = $reader->next(); null !== $chunk; $chunk = $reader->next() ) {
			$lengths[] = strlen( $chunk );
		}
		return $lengths;
	}

	public function test_a_chunk_can_end_exactly_at_the_minimum_size(): void {
		$reader = new ChunkReader( new StringByteSource( self::crafted_data() ), new RabinChunker( 0x3DA3358B4DC173 ) );
		self::assertSame( [ RabinChunker::MIN_SIZE, 524298, 1000 ], self::chunk_lengths( $reader ) );
	}

	public function test_boundaries_do_not_depend_on_how_the_skipped_part_is_fed(): void {
		$data    = self::crafted_data();
		$chunker = new RabinChunker( 0x3DA3358B4DC173 );
		$lengths = [];
		$length  = 0;
		foreach ( str_split( $data, 77777 ) as $piece ) {
			$cursor = 0;
			$size   = strlen( $piece );
			while ( $cursor < $size ) {
				$split = $chunker->next_split_point( $piece, $cursor, $size - $cursor );
				if ( -1 === $split ) {
					$length += $size - $cursor;
					break;
				}
				$lengths[] = $length + $split;
				$length    = 0;
				$cursor   += $split;
			}
		}
		$lengths[] = $length;

		self::assertSame( [ RabinChunker::MIN_SIZE, 524298, 1000 ], $lengths );
	}

	public function test_a_reused_chunker_starts_afresh_for_each_input(): void {
		$chunker = new RabinChunker( 0x3DA3358B4DC173 );
		$first   = new ChunkReader( new StringByteSource( str_repeat( 'x', 2000 ) ), $chunker );
		self::assertSame( [ 2000 ], self::chunk_lengths( $first ) );

		$second = new ChunkReader( new StringByteSource( self::crafted_data() ), $chunker );
		self::assertSame( [ RabinChunker::MIN_SIZE, 524298, 1000 ], self::chunk_lengths( $second ) );
	}

	public function test_split_points_are_counted_from_the_offset(): void {
		$chunker = new RabinChunker( 0x3DA3358B4DC173 );
		$data    = self::crafted_data();
		self::assertSame( RabinChunker::MIN_SIZE, $chunker->next_split_point( 'abc' . $data, 3, strlen( $data ) ) );
	}
}
