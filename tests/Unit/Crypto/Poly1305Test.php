<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Poly1305;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Poly1305 is hand-written (no PHP extension exposes it), so it is pinned to RFC 8439 and to
 * 400 vectors produced by libsodium's crypto_onetimeauth.
 */
final class Poly1305Test extends TestCase {

	public function test_rfc8439_section_2_5_2(): void {
		$key = (string) hex2bin( '85d6be7857556d337f4452fe42d506a80103808afb0db2fd4abff6af4149f51b' );
		self::assertSame( 'a8061dc1305136c6c22b8baf0c0127a9', bin2hex( Poly1305::mac( 'Cryptographic Forum Research Group', $key ) ) );
	}

	/**
	 * @return iterable<int, array{string, string, string}>
	 */
	public static function libsodium_vectors(): iterable {
		foreach ( Vectors::json( 'vectors/poly1305-libsodium.json' )['cases'] as $index => $case ) {
			yield $index => [ $case['key'], $case['message'], $case['tag'] ];
		}
	}

	#[DataProvider( 'libsodium_vectors' )]
	public function test_matches_libsodium( string $key, string $message, string $tag ): void {
		self::assertSame( $tag, bin2hex( Poly1305::mac( (string) hex2bin( $message ), (string) hex2bin( $key ) ) ) );
	}

	public function test_verify_rejects_modified_tags(): void {
		$key = str_repeat( "\x42", 32 );
		$tag = Poly1305::mac( 'message', $key );
		self::assertTrue( Poly1305::verify( $tag, 'message', $key ) );
		self::assertFalse( Poly1305::verify( $tag ^ "\x01" . str_repeat( "\0", 15 ), 'message', $key ) );
		self::assertFalse( Poly1305::verify( substr( $tag, 0, 15 ), 'message', $key ) );
	}

	public function test_rejects_wrong_key_length(): void {
		$this->expectException( CryptoException::class );
		Poly1305::mac( 'message', 'short' );
	}
}
