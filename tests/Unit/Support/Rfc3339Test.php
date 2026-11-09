<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Rfc3339;

final class Rfc3339Test extends TestCase {

	public function test_formats_like_go_rfc3339nano(): void {
		self::assertSame( '1970-01-01T00:00:00Z', Rfc3339::format( 0 ) );
		self::assertSame( '2026-01-02T03:04:05.123456789Z', Rfc3339::format( 1767323045, 123456789 ) );
		self::assertSame( '2026-01-02T03:04:05.5Z', Rfc3339::format( 1767323045, 500000000 ) );
	}

	public function test_parses_offsets_and_nanoseconds(): void {
		self::assertSame( [ 1767323045, 123456789 ], Rfc3339::parse( '2026-01-02T03:04:05.123456789Z' ) );
		self::assertSame( [ 1767323045, 0 ], Rfc3339::parse( '2026-01-02T04:04:05+01:00' ) );
		self::assertSame( [ 1767323045, 100000000 ], Rfc3339::parse( '2026-01-01T22:04:05.1-05:00' ) );
	}

	public function test_rejects_other_formats(): void {
		$this->expectException( FormatException::class );
		Rfc3339::parse( '2026-01-02 03:04:05' );
	}

	public function test_bytes_helpers_round_trip_and_validate(): void {
		self::assertSame( 4294967295, Bytes::read_uint32_le( Bytes::uint32_le( 4294967295 ) ) );
		self::assertSame( "\x01\x00\x00\x00", Bytes::uint32_le( 1 ) );
		self::assertSame( 'hello', Bytes::base64_decode( Bytes::base64_encode( 'hello' ) ) );

		$this->expectException( FormatException::class );
		Bytes::read_uint32_le( "\x01\x02\x03" );
	}

	public function test_base64_rejects_foreign_characters(): void {
		$this->expectException( FormatException::class );
		Bytes::base64_decode( 'aGVsbG8*' );
	}

	public function test_parses_years_before_1970_and_gos_zero_time(): void {
		self::assertSame( [ -62135596800, 0 ], Rfc3339::parse( '0001-01-01T00:00:00Z' ) );
		self::assertSame( '0001-01-01T00:00:00Z', Rfc3339::format( -62135596800 ) );
		self::assertSame( [ -2208988800, 0 ], Rfc3339::parse( '1900-01-01T00:00:00Z' ) );
		self::assertSame( [ -1, 999999999 ], Rfc3339::parse( '1969-12-31T23:59:59.999999999Z' ) );
		self::assertSame( [ 951782400, 0 ], Rfc3339::parse( '2000-02-29T00:00:00Z' ) );
		self::assertSame( [ 1709164800, 0 ], Rfc3339::parse( '2024-02-29T01:00:00+01:00' ) );
		self::assertSame( [ 253402300799, 0 ], Rfc3339::parse( '9999-12-31T23:59:59Z' ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function impossible_times(): iterable {
		yield 'month 13' => [ '2026-13-01T00:00:00Z' ];
		yield 'month 0' => [ '2026-00-10T00:00:00Z' ];
		yield 'day 0' => [ '2026-01-00T00:00:00Z' ];
		yield 'February 29 outside a leap year' => [ '2026-02-29T00:00:00Z' ];
		yield 'February 29 in 1900' => [ '1900-02-29T00:00:00Z' ];
		yield 'April 31' => [ '2026-04-31T00:00:00Z' ];
		yield 'hour 24' => [ '2026-01-01T24:00:00Z' ];
		yield 'minute 60' => [ '2026-01-01T00:60:00Z' ];
		yield 'second 60' => [ '2026-01-01T00:00:60Z' ];
		yield 'offset hour 24' => [ '2026-01-01T00:00:00+24:00' ];
		yield 'offset minute 60' => [ '2026-01-01T00:00:00-01:60' ];
	}

	#[DataProvider( 'impossible_times' )]
	public function test_rejects_times_that_do_not_exist( string $text ): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'out of range' );
		Rfc3339::parse( $text );
	}
}
