<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\Vectors;

final class CipherTest extends TestCase {

	public function test_opens_a_file_encrypted_by_restic(): void {
		$plaintext = Vectors::cipher()->open( Vectors::restic( 'raw/config' ) );
		self::assertSame( json_decode( Vectors::restic( 'config.json' ), true ), json_decode( $plaintext, true ) );
	}

	public function test_round_trip_and_overhead(): void {
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
		foreach ( [ '', 'x', str_repeat( 'abc', 10000 ) ] as $plaintext ) {
			$sealed = $cipher->seal( $plaintext );
			self::assertSame( Cipher::ciphertext_length( strlen( $plaintext ) ), strlen( $sealed ) );
			self::assertSame( $plaintext, $cipher->open( $sealed ) );
		}
	}

	public function test_nonces_are_fresh_for_every_object(): void {
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
		$nonces = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$nonces[ substr( $cipher->seal( 'same plaintext' ), 0, 16 ) ] = true;
		}
		self::assertCount( 200, $nonces );
	}

	public function test_every_modified_byte_is_detected(): void {
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), new SystemRandom() );
		$sealed = $cipher->seal( 'authenticated data' );
		$length = strlen( $sealed );
		for ( $position = 0; $position < $length; $position++ ) {
			$tampered              = $sealed;
			$tampered[ $position ] = chr( ord( $tampered[ $position ] ) ^ 0x01 );
			try {
				$cipher->open( $tampered );
				self::fail( 'Modification at byte ' . $position . ' was not detected.' );
			} catch ( AuthenticationFailed ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_rejects_short_input_and_zero_nonce(): void {
		$key    = MasterKey::generate( new SystemRandom() );
		$cipher = new Cipher( $key, new SystemRandom() );
		foreach ( [ str_repeat( "\0", 31 ), str_repeat( "\0", 16 ) . str_repeat( 'x', 16 ) ] as $data ) {
			try {
				$cipher->open( $data );
				self::fail( 'Invalid input was accepted.' );
			} catch ( AuthenticationFailed ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_rejections_name_the_reason(): void {
		$cases = [
			'Encrypted object is too short.'          => str_repeat( 'x', Cipher::OVERHEAD - 1 ),
			'Encrypted object has an invalid nonce.'  => str_repeat( "\0", Cipher::NONCE_LENGTH ) . str_repeat( 'x', Cipher::TAG_LENGTH ),
			'Encrypted object failed authentication.' => str_repeat( 'x', Cipher::OVERHEAD ),
		];
		foreach ( $cases as $message => $data ) {
			try {
				Vectors::cipher()->open( $data );
				self::fail( 'Invalid input was accepted: ' . $message );
			} catch ( AuthenticationFailed $exception ) {
				self::assertSame( $message, $exception->getMessage() );
			}
		}
	}

	public function test_system_random_returns_exactly_the_requested_length(): void {
		$random = new SystemRandom();
		foreach ( [ 1, 2, 16, 64 ] as $length ) {
			self::assertSame( $length, strlen( $random->bytes( $length ) ) );
		}
	}

	public function test_seal_never_emits_an_all_zero_nonce(): void {
		$random = new FixedRandom( 'zero-nonce', str_repeat( "\0", 16 ) );
		$cipher = new Cipher( MasterKey::generate( new SystemRandom() ), $random );
		self::assertNotSame( str_repeat( "\0", 16 ), substr( $cipher->seal( 'x' ), 0, 16 ) );
	}

	public function test_master_key_json_matches_restic_and_hides_material(): void {
		$key = Vectors::master_key();
		self::assertSame( json_decode( Vectors::restic( 'masterkey.json' ), true ), json_decode( $key->to_json(), true ) );
		self::assertStringNotContainsString( base64_encode( $key->encryption_key() ), print_r( $key, true ) );

		$this->expectException( \LogicException::class );
		serialize( $key );
	}

	public function test_master_key_rejects_zero_or_short_material(): void {
		$this->expectException( FormatException::class );
		MasterKey::from_kdf_output( str_repeat( "\0", 64 ) );
	}
}
