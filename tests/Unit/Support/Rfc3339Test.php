<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
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
}
