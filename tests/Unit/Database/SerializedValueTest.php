<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\SerializedValue;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Tests\Support\WordPressRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reader of the values that WordPress serializes: exactly what serialize() writes for
 * arrays, strings, integers, booleans and null, read as unserialize() reads it, and nothing else.
 */
final class SerializedValueTest extends TestCase {

	/** @return iterable<string, array{mixed}> */
	public static function written(): iterable {
		yield 'null' => [ null ];
		yield 'true' => [ true ];
		yield 'false' => [ false ];
		yield 'zero' => [ 0 ];
		yield 'negative' => [ -42 ];
		yield 'largest integer' => [ PHP_INT_MAX ];
		yield 'smallest integer' => [ PHP_INT_MIN ];
		yield 'empty string' => [ '' ];
		yield 'bytes that look like syntax' => [ "\";}a:9:{s:1:\"x\";\x00\xff" . 'é' ];
		yield 'empty array' => [ [] ];
		yield 'list' => [ [ 'akismet/akismet.php', 'backups-for-restic/backups-for-restic.php' ] ];
		yield 'list with a gap' => [
			[
				0 => 'a.php',
				2 => 'c.php',
			],
		];
		yield 'mixed keys' => [
			[
				5   => 'five',
				'k' => null,
				-1  => true,
			],
		];
		yield 'the roles of a fresh WordPress' => [ WordPressRoles::defaults() ];
		yield 'capabilities of an account' => [
			[
				'administrator' => true,
				'update_core'   => false,
				'custom'        => 1,
				'other'         => '0',
			],
		];
	}

	#[DataProvider( 'written' )]
	public function test_reads_what_serialize_writes_as_unserialize_does( mixed $value ): void {
		$stored = serialize( $value );
		self::assertSame( $value, SerializedValue::decode( $stored, 3 ) );
		self::assertSame( unserialize( $stored, [ 'allowed_classes' => false ] ), SerializedValue::decode( $stored, 3 ) );
	}

	/** @return iterable<string, array{string}> */
	public static function refused(): iterable {
		yield 'an object' => [ serialize( new \stdClass() ) ];
		yield 'an object in an array' => [ 'a:1:{i:0;O:8:"stdClass":0:{}}' ];
		yield 'a custom serialization' => [ 'C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}' ];
		yield 'an enum case' => [ 'E:7:"Foo:Bar";' ];
		yield 'a reference' => [ 'a:2:{i:0;s:1:"a";i:1;R:2;}' ];
		yield 'an object reference' => [ 'a:2:{i:0;s:1:"a";i:1;r:2;}' ];
		yield 'a float' => [ 'd:1.5;' ];
		yield 'an escaped string' => [ 'S:1:"\61";' ];
		yield 'nothing' => [ '' ];
		yield 'a type letter alone' => [ 'N' ];
		yield 'an unknown type' => [ 'x:1;' ];
		yield 'leading whitespace' => [ ' a:0:{}' ];
		yield 'trailing whitespace' => [ "a:0:{}\n" ];
		yield 'a second value' => [ 'i:1;i:2;' ];
		yield 'a plus sign' => [ 'i:+1;' ];
		yield 'a leading zero' => [ 'i:01;' ];
		yield 'minus zero' => [ 'i:-0;' ];
		yield 'an integer beyond the range' => [ 'i:9223372036854775808;' ];
		yield 'an integer of twenty digits' => [ 'i:10000000000000000000;' ];
		yield 'no digits' => [ 'i:;' ];
		yield 'a boolean two' => [ 'b:2;' ];
		yield 'a boolean without its end' => [ 'b:1' ];
		yield 'a string shorter than its length' => [ 's:5:"abc";' ];
		yield 'a string longer than its length' => [ 's:2:"abc";' ];
		yield 'a string without its end' => [ 's:3:"abc"' ];
		yield 'a string with a quote missing' => [ 's:3:"abc;' ];
		yield 'a negative length' => [ 's:-1:"";' ];
		yield 'a length with a leading zero' => [ 's:03:"abc";' ];
		yield 'a length beyond the value' => [ 's:9999999999:"a";' ];
		yield 'an array with fewer elements than its count' => [ 'a:2:{i:0;b:1;}' ];
		yield 'an array with more elements than its count' => [ 'a:1:{i:0;b:1;i:1;b:1;}' ];
		yield 'an array without its end' => [ 'a:1:{i:0;b:1;' ];
		yield 'an array ended by a semicolon' => [ 'a:0:{};' ];
		yield 'a count with a leading zero' => [ 'a:01:{i:0;b:1;}' ];
		yield 'a boolean key' => [ 'a:1:{b:1;b:1;}' ];
		yield 'a null key' => [ 'a:1:{N;b:1;}' ];
		yield 'an array key' => [ 'a:1:{a:0:{}b:1;}' ];
		yield 'a float key' => [ 'a:1:{d:1.5;b:1;}' ];
		yield 'a key at the end' => [ 'a:1:{i:0;}' ];
		yield 'a repeated key' => [ 'a:2:{i:0;b:1;i:0;b:0;}' ];
		yield 'a repeated key, once as a numeric string' => [ 'a:2:{s:1:"5";b:1;i:5;b:0;}' ];
		yield 'a repeated string key' => [ 'a:2:{s:1:"k";b:1;s:1:"k";b:1;}' ];
	}

	#[DataProvider( 'refused' )]
	public function test_refuses_what_wordpress_does_not_write_for_these_values( string $stored ): void {
		$this->expectException( FormatException::class );
		SerializedValue::decode( $stored, 3 );
	}

	public function test_a_key_of_decimal_digits_is_an_integer_as_unserialize_makes_it(): void {
		$stored = 'a:2:{s:1:"5";b:1;s:2:"05";b:0;}';
		self::assertSame(
			[
				5    => true,
				'05' => false,
			],
			SerializedValue::decode( $stored, 1 )
		);
		self::assertSame( unserialize( $stored ), SerializedValue::decode( $stored, 1 ) );
	}

	public function test_arrays_nest_only_as_deep_as_allowed(): void {
		self::assertSame( 1, SerializedValue::decode( 'i:1;', 0 ) );
		self::assertSame( [ [ [] ] ], SerializedValue::decode( serialize( [ [ [] ] ] ), 3 ) );
		foreach ( [ [ 'a:0:{}', 0 ], [ serialize( [ [] ] ), 1 ], [ serialize( [ [ [] ] ] ), 2 ], [ serialize( [ 1, [ 2 ] ] ), 1 ] ] as [ $stored, $depth ] ) {
			try {
				SerializedValue::decode( $stored, $depth );
				self::fail( 'An array nested deeper than allowed was read: ' . $stored );
			} catch ( FormatException $refused ) {
				self::assertStringContainsString( 'nests too deep', $refused->getMessage() );
			}
		}
	}

	public function test_a_value_holds_at_most_the_bounded_number_of_elements(): void {
		$largest = array_fill( 0, SerializedValue::MAX_ELEMENTS - 1, true );
		self::assertSame( $largest, SerializedValue::decode( serialize( $largest ), 1 ) );
		try {
			SerializedValue::decode( serialize( [ ...$largest, true ] ), 1 );
			self::fail( 'An array beyond the element bound was read.' );
		} catch ( FormatException $refused ) {
			self::assertStringContainsString( 'too many elements', $refused->getMessage() );
		}
		// The count is checked before any element: a large count with nothing behind it.
		try {
			SerializedValue::decode( 'a:' . SerializedValue::MAX_ELEMENTS . ':{}', 1 );
			self::fail( 'A count beyond the element bound was accepted.' );
		} catch ( FormatException $refused ) {
			self::assertStringContainsString( 'too many elements', $refused->getMessage() );
		}
		// Nested elements count as well: both arrays, every entry of the inner one and the last value.
		$nested = [ array_fill( 0, SerializedValue::MAX_ELEMENTS - 3, true ), true ];
		self::assertSame( $nested, SerializedValue::decode( serialize( $nested ), 2 ) );
		try {
			SerializedValue::decode( serialize( [ [ ...$nested[0], true ], true ] ), 2 );
			self::fail( 'A value beyond the element bound was read.' );
		} catch ( FormatException $refused ) {
			self::assertSame( 'A stored WordPress value holds too many elements.', $refused->getMessage() );
		}
	}

	public function test_whatever_it_reads_unserialize_reads_the_same(): void {
		$seeds = [
			serialize( WordPressRoles::defaults() ),
			serialize(
				[
					0 => 'a/a.php',
					3 => 'b.php',
				]
			),
			serialize( [ 'administrator' => true ] ),
		];
		mt_srand( 20261123 );
		$read = 0;
		for ( $i = 0; $i < 3000; ++$i ) {
			$stored = $seeds[ $i % 3 ];
			for ( $edits = mt_rand( 1, 3 ); $edits > 0; --$edits ) {
				$at     = mt_rand( 0, strlen( $stored ) - 1 );
				$stored = match ( mt_rand( 0, 2 ) ) {
					0       => substr( $stored, 0, $at ) . substr( $stored, $at + 1 ),
					1       => substr( $stored, 0, $at ) . chr( mt_rand( 0, 255 ) ) . substr( $stored, $at + 1 ),
					default => substr( $stored, 0, $at ) . substr( ';:{}"isabN0123456789', mt_rand( 0, 18 ), 1 ) . substr( $stored, $at ),
				};
			}
			try {
				$value = SerializedValue::decode( $stored, 3 );
			} catch ( FormatException ) {
				continue;
			}
			++$read;
			self::assertSame( unserialize( $stored, [ 'allowed_classes' => false ] ), $value, $stored );
		}
		self::assertGreaterThan( 10, $read, 'Some edits keep a value that serialize() could have written.' );
	}
}
