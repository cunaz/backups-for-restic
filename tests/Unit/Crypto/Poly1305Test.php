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
	 * RFC 8439 Appendix A.3: #5 to #11 aim at the carries and the final reduction modulo
	 * 2^130 - 5, which random inputs practically never reach.
	 *
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function rfc8439_appendix_a3(): iterable {
		$ietf        = 'Any submission to the IETF intended by the Contributor for publication as all or part of an IETF Internet-Draft or RFC '
			. 'and any statement made within the context of an IETF activity is considered an "IETF Contribution". Such statements '
			. 'include oral statements in IETF sessions, as well as written and electronic communications made at any time or place, '
			. 'which are addressed to';
		$jabberwocky = "'Twas brillig, and the slithy toves\nDid gyre and gimble in the wabe:\nAll mimsy were the borogoves,\nAnd the mome raths outgrabe.";

		yield '#1 all zero' => [ str_repeat( '00', 32 ), str_repeat( '00', 64 ), '00000000000000000000000000000000' ];
		yield '#2 r is zero' => [ str_repeat( '00', 16 ) . '36e5f6b5c5e06070f0efca96227a863e', bin2hex( $ietf ), '36e5f6b5c5e06070f0efca96227a863e' ];
		yield '#3 s is zero' => [ '36e5f6b5c5e06070f0efca96227a863e' . str_repeat( '00', 16 ), bin2hex( $ietf ), 'f3477e7cd95417af89a6b8794c310cf0' ];
		yield '#4 jabberwocky' => [ '1c9240a5eb55d38af333888604f6b5f0473917c1402b80099dca5cbc207075c0', bin2hex( $jabberwocky ), '4541669a7eaaee61e708dc7cbcc5eb62' ];
		yield '#5 result not fully reduced' => [ '02' . str_repeat( '00', 31 ), str_repeat( 'ff', 16 ), '03000000000000000000000000000000' ];
		yield '#6 adding s overflows' => [ '02' . str_repeat( '00', 15 ) . str_repeat( 'ff', 16 ), '02' . str_repeat( '00', 15 ), '03000000000000000000000000000000' ];
		yield '#7 limb carry into an all-ones limb' => [ '01' . str_repeat( '00', 31 ), str_repeat( 'ff', 16 ) . 'f0' . str_repeat( 'ff', 15 ) . '11' . str_repeat( '00', 15 ), '05000000000000000000000000000000' ];
		yield '#8 result is exactly 2^130 - 5' => [ '01' . str_repeat( '00', 31 ), str_repeat( 'ff', 16 ) . 'fb' . str_repeat( 'fe', 15 ) . str_repeat( '01', 16 ), '00000000000000000000000000000000' ];
		yield '#9 result is exactly 2^130 - 6' => [ '02' . str_repeat( '00', 31 ), 'fd' . str_repeat( 'ff', 15 ), 'faffffffffffffffffffffffffffffff' ];
		yield '#10 131-bit intermediate result' => [ '0100000000000000040000000000000000000000000000000000000000000000', 'e33594d7505e43b900000000000000003394d7505e4379cd01000000000000000000000000000000000000000000000001000000000000000000000000000000', '14000000000000005500000000000000' ];
		yield '#11 131-bit final result' => [ '0100000000000000040000000000000000000000000000000000000000000000', 'e33594d7505e43b900000000000000003394d7505e4379cd010000000000000000000000000000000000000000000000', '13000000000000000000000000000000' ];
	}

	#[DataProvider( 'rfc8439_appendix_a3' )]
	public function test_rfc8439_appendix_a3( string $key, string $message, string $tag ): void {
		self::assertSame( $tag, bin2hex( Poly1305::mac( (string) hex2bin( $message ), (string) hex2bin( $key ) ) ) );
	}

	/**
	 * One block with r below 2^26, so that the block's product is r * m without folding. m is
	 * chosen to leave the accumulator at 2^130 + 2^26 - 2 and 2^130 + 2^26 - 5, held in the limbs
	 * [h0, 2^26, 2^26 - 1, 2^26 - 1, 2^26 - 1] with h0 >= 2^26 - 5: the final carry wraps the top
	 * limb into the lowest one, which then carries again. Tags from libsodium's crypto_onetimeauth.
	 *
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function final_carry_wraps_twice(): iterable {
		yield 's is zero' => [ '4c084e0300000000000000000000000000000000000000000000000000000000', '577ac96910a81cc94662838af795e22c', '03000004000000000000000000000000' ];
		yield 's is random' => [ '26033d0200000000000000000000000010b9dd1df304c1e9804e421ed4e8763d', '6b8c260f2ce29fc2051da0f3f06af5d5', '10b9dd21f304c1e9804e421ed4e8763d' ];
	}

	#[DataProvider( 'final_carry_wraps_twice' )]
	public function test_the_final_carry_can_wrap_twice( string $key, string $message, string $tag ): void {
		self::assertSame( $tag, bin2hex( Poly1305::mac( (string) hex2bin( $message ), (string) hex2bin( $key ) ) ) );
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
