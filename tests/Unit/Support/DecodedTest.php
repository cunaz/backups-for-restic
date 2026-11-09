<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Support\Decoded;

/**
 * Values read back from JSON files keep their meaning or fall back safely.
 */
final class DecodedTest extends TestCase {

	public function test_a_whole_float_survives_a_json_round_trip_as_a_number(): void {
		$decoded = json_decode( (string) json_encode( [ 'progress' => 1.0 ] ), true );
		self::assertIsArray( $decoded );
		self::assertSame( 1, $decoded['progress'], 'PHP encodes 1.0 as 1.' );
		self::assertSame( 1.0, Decoded::number( $decoded['progress'] ) );
	}

	public function test_fractions_stay_unchanged(): void {
		self::assertSame( 0.25, Decoded::number( 0.25 ) );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function not_numbers(): iterable {
		yield 'numeric string' => [ '1' ];
		yield 'null' => [ null ];
		yield 'boolean' => [ true ];
		yield 'array' => [ [ 1 ] ];
	}

	#[DataProvider( 'not_numbers' )]
	public function test_other_types_give_the_fallback( mixed $value ): void {
		self::assertSame( 0.5, Decoded::number( $value, 0.5 ) );
	}
}
