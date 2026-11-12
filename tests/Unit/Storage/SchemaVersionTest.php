<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\SchemaVersion;
use BackupsForRestic\Tests\Support\InMemoryOptions;

final class SchemaVersionTest extends TestCase {

	public function test_the_first_run_records_the_current_number_once(): void {
		$options = new InMemoryOptions();
		$version = new SchemaVersion( $options );
		self::assertSame( 0, $version->stored(), 'Nothing is recorded on a fresh installation.' );
		self::assertFalse( $version->is_newer() );

		self::assertTrue( $version->upgrade() );

		self::assertSame( SchemaVersion::CURRENT, $options->values[ SchemaVersion::OPTION ] );
		self::assertTrue( $options->autoload[ SchemaVersion::OPTION ], 'Every request reads the number, so it is loaded with the other options.' );
		self::assertSame( SchemaVersion::CURRENT, $version->stored() );

		// A second write would set the flag again.
		$options->autoload[ SchemaVersion::OPTION ] = false;
		self::assertTrue( $version->upgrade() );
		self::assertFalse( $options->autoload[ SchemaVersion::OPTION ], 'The number is written only when it changes.' );
	}

	public function test_the_number_is_read_as_wordpress_returns_it(): void {
		$options                                  = new InMemoryOptions();
		$options->values[ SchemaVersion::OPTION ] = (string) SchemaVersion::CURRENT;
		$version                                  = new SchemaVersion( $options );
		self::assertSame( SchemaVersion::CURRENT, $version->stored() );
		self::assertFalse( $version->is_newer() );
	}

	public function test_options_of_a_newer_version_are_recognised_and_left_alone(): void {
		$options                                  = new InMemoryOptions();
		$options->values[ SchemaVersion::OPTION ] = (string) ( SchemaVersion::CURRENT + 1 );
		$version                                  = new SchemaVersion( $options );
		self::assertTrue( $version->is_newer() );

		self::assertFalse( $version->upgrade() );

		self::assertSame( (string) ( SchemaVersion::CURRENT + 1 ), $options->values[ SchemaVersion::OPTION ], 'An older version never lowers the number.' );
	}

	public function test_a_refused_write_does_not_count_as_an_upgrade(): void {
		$options                  = new InMemoryOptions();
		$options->refuses         = static fn ( string $name ): bool => SchemaVersion::OPTION === $name;
		$options->refuses_quietly = true;
		$version                  = new SchemaVersion( $options );

		self::assertFalse( $version->upgrade() );
		self::assertSame( 0, $version->stored() );
		self::assertSame( [], $options->values );

		$options->refuses = null;
		self::assertTrue( $version->upgrade() );
		self::assertSame( SchemaVersion::CURRENT, $version->stored() );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function not_numbers(): iterable {
		yield 'a list' => [ [ 2 ] ];
		yield 'a negative number' => [ -3 ];
		yield 'a negative numeral' => [ '-3' ];
		yield 'text after the digits' => [ '2x' ];
		yield 'a fraction' => [ '2.5' ];
		yield 'an empty string' => [ '' ];
		yield 'a boolean' => [ true ];
		yield 'more digits than a version has' => [ '12345678901234567890' ];
	}

	#[DataProvider( 'not_numbers' )]
	public function test_anything_but_a_number_counts_as_not_recorded( mixed $value ): void {
		$options                                  = new InMemoryOptions();
		$options->values[ SchemaVersion::OPTION ] = $value;
		$version                                  = new SchemaVersion( $options );
		self::assertSame( 0, $version->stored() );
		self::assertFalse( $version->is_newer() );
	}
}
