<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Keys follow restic's crypto.Key: 32 bytes for AES-256, then the MAC key parts k and r of 16
 * bytes each, none of them all zeros.
 */
final class MasterKeyTest extends TestCase {

	/**
	 * scrypt output for the vector repository's password and key file (N = 32768, r = 8, p = 6),
	 * computed with Python's hashlib.scrypt so that this test skips restic's cost.
	 */
	private const string RESTIC_KDF_OUTPUT = '3f42e9ac0ead6c0d7fd7a3978c9dfd9c284e74e4680d49d742c3e51621fdb12e739523431683b51b84f24e91ba5032c98c8e3b0dd0876b902ceafef0f984838f';

	public function test_kdf_output_opens_the_key_file_restic_wrote(): void {
		$files = Vectors::restic_files( 'keys/*' );
		self::assertCount( 1, $files );
		$key  = KeyFile::from_json( (string) file_get_contents( $files[0] ) );
		$user = MasterKey::from_kdf_output( (string) hex2bin( self::RESTIC_KDF_OUTPUT ) );

		self::assertSame( json_decode( Vectors::restic( 'masterkey.json' ), true ), json_decode( $key->open( $user, new SystemRandom() )->to_json(), true ) );
	}

	public function test_kdf_output_is_split_into_encryption_key_k_and_r_like_restic(): void {
		$bytes = implode( '', array_map( 'chr', range( 1, 64 ) ) );
		$json  = json_decode( MasterKey::from_kdf_output( $bytes )->to_json(), true );

		self::assertSame(
			[
				'mac'     => [
					'k' => base64_encode( substr( $bytes, 32, 16 ) ),
					'r' => base64_encode( substr( $bytes, 48, 16 ) ),
				],
				'encrypt' => base64_encode( substr( $bytes, 0, 32 ) ),
			],
			$json
		);
	}

	/**
	 * @return iterable<string, array{int, int, int, string}>
	 */
	public static function malformed_parts(): iterable {
		yield 'encryption key too short' => [ 31, 16, 16, 'Key material has the wrong length.' ];
		yield 'k too long' => [ 32, 17, 16, 'Key material has the wrong length.' ];
		yield 'r too short' => [ 32, 16, 15, 'Key material has the wrong length.' ];
		yield 'encryption key all zeros' => [ 0, 16, 16, 'Key material must not be all zeros.' ];
		yield 'r all zeros' => [ 32, 16, 0, 'Key material must not be all zeros.' ];
	}

	/**
	 * @param int    $encrypt Length of the encryption key; 0 for 32 zero bytes.
	 * @param int    $k       Length of k.
	 * @param int    $r       Length of r; 0 for 16 zero bytes.
	 * @param string $message Expected message.
	 */
	#[DataProvider( 'malformed_parts' )]
	public function test_stored_master_keys_with_malformed_parts_are_rejected( int $encrypt, int $k, int $r, string $message ): void {
		$part = static fn ( int $length, int $zero_length ): string => base64_encode( 0 === $length ? str_repeat( "\0", $zero_length ) : str_repeat( "\x01", $length ) );
		$json = '{"mac":{"k":"' . $part( $k, 16 ) . '","r":"' . $part( $r, 16 ) . '"},"encrypt":"' . $part( $encrypt, 32 ) . '"}';

		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( $message );
		MasterKey::from_json( $json );
	}

	public function test_kdf_output_must_be_64_bytes(): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Key derivation output has the wrong length.' );
		MasterKey::from_kdf_output( str_repeat( "\x01", 63 ) );
	}

	public function test_debug_output_shows_a_redaction_marker_instead_of_key_material(): void {
		$output = print_r( Vectors::master_key(), true );
		self::assertStringContainsString( '[redacted]', $output );
		self::assertStringNotContainsString( base64_encode( Vectors::master_key()->mac_r() ), $output );
	}
}
