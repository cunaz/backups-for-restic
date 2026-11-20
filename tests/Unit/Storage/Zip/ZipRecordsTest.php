<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage\Zip;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Storage\Zip\ZipRecords;

final class ZipRecordsTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_names(): array {
		return [
			'empty'                 => [ '' ],
			'absolute'              => [ '/etc/passwd' ],
			'parent at the start'   => [ '../outside.txt' ],
			'parent in the middle'  => [ 'a/../../outside.txt' ],
			'current directory'     => [ 'a/./b' ],
			'empty component'       => [ 'a//b' ],
			'trailing slash'        => [ 'a/' ],
			'NUL'                   => [ "a\0b" ],
			'backslash'             => [ '..\\outside.txt' ],
			'drive letter'          => [ 'C:/Windows/system.ini' ],
			'not UTF-8'             => [ "latin1-\xe9.txt" ],
			'longer than the field' => [ str_repeat( 'a/', 32767 ) . 'ab' ],
		];
	}

	#[DataProvider( 'refused_names' )]
	public function test_a_name_that_could_leave_the_unpacking_directory_or_is_not_utf8_is_refused( string $name ): void {
		$this->expectException( InvalidArgument::class );
		ZipRecords::name( $name, false );
	}

	public function test_a_refused_name_is_reported_in_a_form_that_is_safe_to_log(): void {
		try {
			ZipRecords::name( "bad\xe9\n/../name", false );
			self::fail( 'The name must be refused.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( [ 'name' => 'bad%E9%0A%2F..%2Fname' ], $refused->context() );
			self::assertStringNotContainsString( 'bad', $refused->getMessage() );
		}
	}

	public function test_names_keep_their_bytes_and_directories_end_with_a_slash(): void {
		self::assertSame( 'wp-content/ümlaut ördner/日本語.txt', ZipRecords::name( 'wp-content/ümlaut ördner/日本語.txt', false ) );
		self::assertSame( 'wp-content/uploads/', ZipRecords::name( 'wp-content/uploads', true ) );
		self::assertSame( "tab\tand \"quote\".txt", ZipRecords::name( "tab\tand \"quote\".txt", false ) );
	}

	public function test_small_archives_have_no_zip64_field(): void {
		$records = new ZipRecords();
		$local   = $records->local( 'a.txt', 5, 1767323045, 0x12345678 );
		$central = $records->central( 'a.txt', 5, 1767323045, 0x12345678, 0xFFFFFFFE, false );

		self::assertSame( 30 + 5 + 9, strlen( $local ) );
		self::assertSame( 46 + 5 + 9, strlen( $central ) );
		self::assertSame( 22, strlen( $records->end( 0xFFFE, 0xFFFFFFFE, 0xFFFFFFFE ) ) );
		self::assertSame( 20, self::word( $local, 4 ), 'A reader of version 2.0 can extract the entry.' );
		self::assertSame( 0x0800, self::word( $local, 6 ), 'The name is declared as UTF-8.' );
		self::assertSame( 0, self::word( $local, 8 ), 'The content is stored.' );
		self::assertSame( pack( 'V', 0x12345678 ), substr( $local, ZipRecords::CRC_OFFSET, 4 ) );
	}

	public function test_a_size_an_offset_or_a_count_at_the_limit_gets_a_zip64_field_each(): void {
		$records = new ZipRecords();

		$local = $records->local( 'a', 0xFFFFFFFF, 0, 0 );
		self::assertSame( 45, self::word( $local, 4 ) );
		self::assertSame( pack( 'VV', 0xFFFFFFFF, 0xFFFFFFFF ), substr( $local, 18, 8 ) );
		self::assertSame( pack( 'vvPP', 1, 16, 0xFFFFFFFF, 0xFFFFFFFF ), substr( $local, 31, 20 ) );

		$far = $records->central( 'a', 7, 0, 0, 0x100000000, false );
		self::assertSame( pack( 'VV', 7, 7 ), substr( $far, 20, 8 ), 'A size that fits stays where it is.' );
		self::assertSame( pack( 'V', 0xFFFFFFFF ), substr( $far, 42, 4 ) );
		self::assertSame( pack( 'vvP', 1, 8, 0x100000000 ), substr( $far, 47, 12 ) );

		$both = $records->central( 'a', 0x100000001, 0, 0, 0x100000002, false );
		self::assertSame( pack( 'vvPPP', 1, 24, 0x100000001, 0x100000001, 0x100000002 ), substr( $both, 47, 28 ) );

		$end = $records->end( 0xFFFF, 100, 200 );
		self::assertSame( 56 + 20 + 22, strlen( $end ) );
		self::assertSame( pack( 'VP', 0x06064b50, 44 ), substr( $end, 0, 12 ) );
		self::assertSame( pack( 'PPPP', 0xFFFF, 0xFFFF, 100, 200 ), substr( $end, 24, 32 ) );
		self::assertSame( pack( 'VVPV', 0x07064b50, 0, 300, 1 ), substr( $end, 56, 20 ) );
		self::assertSame( pack( 'vvVV', 0xFFFF, 0xFFFF, 100, 200 ), substr( $end, 84, 12 ), 'Only the count is replaced by its marker.' );
	}

	public function test_times_are_stored_as_dos_time_in_utc_and_exactly_in_an_extended_field(): void {
		$records = new ZipRecords();
		// 2026-01-02 03:04:05 UTC.
		$local = $records->local( 'a', 0, 1767323045, 0 );
		self::assertSame( ( 3 << 11 ) | ( 4 << 5 ) | 2, self::word( $local, 10 ) );
		self::assertSame( ( 46 << 9 ) | ( 1 << 5 ) | 2, self::word( $local, 12 ) );
		self::assertSame( pack( 'vvCV', 0x5455, 5, 1, 1767323045 ), substr( $local, 31 ) );

		$early = $records->local( 'a', 0, 86400, 0 );
		self::assertSame( ( 1 << 5 ) | 1, self::word( $early, 12 ), 'A time before 1980 becomes the first day the format knows.' );

		$late = $records->local( 'a', 0, 4500000000, 0 );
		self::assertSame( 31, strlen( $late ), 'A time beyond 2038 has no 32-bit field.' );
	}

	private static function word( string $bytes, int $offset ): int {
		$word = unpack( 'v', $bytes, $offset );
		self::assertIsArray( $word );
		self::assertIsInt( $word[1] );
		return $word[1];
	}
}
