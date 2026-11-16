<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage\Zip;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\Zip\Crc32;
use BackupsForRestic\Tests\Support\Vectors;

final class Crc32Test extends TestCase {

	public function test_two_pieces_combine_to_the_checksum_of_the_whole(): void {
		$data = Vectors::hash_stream( 'crc', 70000 );
		foreach ( [ 0, 1, 2, 255, 256, 4096, 65535, 65536, 69999, 70000 ] as $cut ) {
			$first  = substr( $data, 0, $cut );
			$second = substr( $data, $cut );
			self::assertSame( crc32( $data ), Crc32::combine( crc32( $first ), crc32( $second ), strlen( $second ) ), 'cut at ' . $cut );
		}
	}

	public function test_many_pieces_combine_one_after_the_other_from_an_empty_start(): void {
		$data = Vectors::hash_stream( 'pieces', 10000 );
		$crc  = 0;
		foreach ( str_split( $data, 777 ) as $piece ) {
			$crc = Crc32::combine( $crc, crc32( $piece ), strlen( $piece ) );
		}
		self::assertSame( crc32( $data ), $crc );
	}

	public function test_a_checksum_is_carried_over_pieces_of_a_mebibyte(): void {
		$zeros = str_repeat( "\0", 1 << 20 );
		$crc   = crc32( 'a' );
		$part  = crc32( $zeros );
		for ( $piece = 0; $piece < 8; $piece++ ) {
			$crc = Crc32::combine( $crc, $part, 1 << 20 );
		}
		self::assertSame( crc32( 'a' . str_repeat( $zeros, 8 ) ), $crc );
	}
}
