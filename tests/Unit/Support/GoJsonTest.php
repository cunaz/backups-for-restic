<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\RawJson;

final class GoJsonTest extends TestCase {

	public function test_encodes_nested_values_in_key_order(): void {
		$value = [
			'b'    => [ 1, true, null, 'x' ],
			'a'    => new \stdClass(),
			'raw'  => new RawJson( '18446744073709551615' ),
			'list' => [],
		];
		self::assertSame( '{"b":[1,true,null,"x"],"a":{},"raw":18446744073709551615,"list":[]}', GoJson::encode( $value ) );
	}

	public function test_escapes_like_go_with_html_safety(): void {
		$escape = chr( 92 ) . 'u00'; // Avoids a literal backslash-u sequence in this file.
		self::assertSame( '"' . $escape . '3ca href=\\"x\\"' . $escape . '3e' . $escape . '26/é"', GoJson::encode_string( '<a href="x">&/é' ) );
	}

	public function test_rejects_unsupported_types(): void {
		$this->expectException( InvalidArgument::class );
		GoJson::encode( 1.5 );
	}

	public function test_decodes_large_integers_as_strings(): void {
		self::assertSame( [ 'inode' => '18446744073709551615' ], GoJson::decode( '{"inode":18446744073709551615}' ) );
	}

	public function test_decode_object_rejects_lists_and_garbage(): void {
		foreach ( [ '[1,2]', 'not json', '"string"' ] as $text ) {
			try {
				GoJson::decode_object( $text );
				self::fail( 'Expected a FormatException for ' . $text );
			} catch ( FormatException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_decode_limits_nesting_depth(): void {
		$this->expectException( FormatException::class );
		GoJson::decode( str_repeat( '[', 100 ) . str_repeat( ']', 100 ) );
	}
}
