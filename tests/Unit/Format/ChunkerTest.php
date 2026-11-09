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
}
