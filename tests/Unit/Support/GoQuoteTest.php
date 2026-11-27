<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Node names and JSON strings must match Go byte for byte (vectors from strconv.Quote and encoding/json).
 */
final class GoQuoteTest extends TestCase {

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function go_vectors(): iterable {
		foreach ( Vectors::json( 'vectors/go-strings-and-chunker.json' )['strings'] as $case ) {
			yield $case['input_hex'] => [ (string) hex2bin( $case['input_hex'] ), $case['quoted'], $case['json'] ];
		}
	}

	#[DataProvider( 'go_vectors' )]
	public function test_quote_matches_go( string $input, string $quoted, string $json ): void {
		self::assertSame( $quoted, GoQuote::quote( $input ) );
	}

	#[DataProvider( 'go_vectors' )]
	public function test_unquote_reverses_quote( string $input, string $quoted, string $json ): void {
		self::assertSame( $input, GoQuote::unquote( $quoted ) );
	}

	#[DataProvider( 'go_vectors' )]
	public function test_json_string_matches_go( string $input, string $quoted, string $json ): void {
		self::assertSame( $json, GoJson::encode_string( $input ) );
	}

	/**
	 * Runes that Go prints can be escaped as well, the way Go escapes a rune it does not print;
	 * the others keep Go's form, and without them the quoting is restic's.
	 */
	public function test_quote_escapes_the_runes_asked_for_as_go_escapes_a_rune_it_does_not_print(): void {
		$name = "a\u{034F}b\u{FE0F}\t\u{1F600}\u{00E9}";
		self::assertSame( 'a\\u034fb\\ufe0f\\t' . "\u{1F600}\u{00E9}", GoQuote::quote( $name, '\x{034F}\x{FE00}-\x{FE0F}' ) );
		self::assertSame( "a\u{034F}b\u{FE0F}\\t\u{1F600}\u{00E9}", GoQuote::quote( $name ) );
		self::assertSame( 'plain', GoQuote::quote( 'plain', '\x{034F}' ) );
	}

	public function test_quote_escapes_a_line_break_at_the_end_of_a_name(): void {
		self::assertSame( 'abc\n', GoQuote::quote( "abc\n" ) );
		self::assertSame( "abc\n", GoQuote::unquote( 'abc\n' ) );
	}

	public function test_unquote_accepts_octal_and_long_unicode_escapes(): void {
		self::assertSame( "A\u{1F600}", GoQuote::unquote( '\\101\\U0001f600' ) );
	}

	public function test_unquote_maps_stray_bytes_to_replacement_character_like_go(): void {
		self::assertSame( "a\u{FFFD}b", GoQuote::unquote( "a\xffb" ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_literals(): iterable {
		yield 'unknown escape' => [ '\\q' ];
		yield 'short hex' => [ '\\x4' ];
		yield 'short unicode' => [ '\\u12' ];
		yield 'raw quote' => [ 'a"b' ];
		yield 'raw newline' => [ "a\nb" ];
		yield 'surrogate' => [ '\\ud800' ];
		yield 'out of range' => [ '\\U00110000' ];
		yield 'trailing backslash' => [ 'abc\\' ];
		yield 'octal too large' => [ '\\400' ];
	}

	#[DataProvider( 'invalid_literals' )]
	public function test_unquote_rejects_invalid_literals( string $literal ): void {
		$this->expectException( FormatException::class );
		GoQuote::unquote( $literal );
	}
}
