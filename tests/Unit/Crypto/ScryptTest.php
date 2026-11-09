<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\Scrypt;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Tests\Support\Vectors;

final class ScryptTest extends TestCase {

	/**
	 * RFC 7914 §12 plus one restic-shaped case (64-byte salt, p = 2), cross-checked with Python's hashlib.scrypt.
	 *
	 * @return iterable<string, array{string, string, int, int, int, string}>
	 */
	public static function vectors(): iterable {
		yield 'rfc7914 #1' => [ '', '', 16, 1, 1, '77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906' ];
		yield 'rfc7914 #2' => [ 'password', 'NaCl', 1024, 8, 16, 'fdbabe1c9d3472007856e7190d01e9fe7c6ad7cbc8237830e77376634b3731622eaf30d92e22a3886ff109279d9830dac727afb94a83ee6d8360cbdfa2cc0640' ];
		yield 'restic-shaped' => [ 'restic', implode( '', array_map( 'chr', range( 0, 63 ) ) ), 1024, 8, 2, 'e1060e95f6baf81502da1355300c55f2ac5204a6e3b5dde475b4181189fd00f106234e18e366b5721645680f2186bb7ff42c402e53a3832837bdb130b0fd860a' ];
	}

	#[DataProvider( 'vectors' )]
	public function test_matches_reference_vectors( string $password, string $salt, int $n, int $r, int $p, string $expected ): void {
		self::assertSame( $expected, bin2hex( Scrypt::derive( $password, $salt, new ScryptParameters( $n, $r, $p ) ) ) );
	}

	public function test_resuming_from_checkpoints_gives_the_same_key(): void {
		$parameters = new ScryptParameters( 1024, 8, 2 );
		$salt       = implode( '', array_map( 'chr', range( 0, 63 ) ) );
		$table      = tempnam( sys_get_temp_dir(), 'scrypt' );
		self::assertIsString( $table );

		$checkpoint = [];
		$slices     = 0;
		do {
			// Each run stops at the first deadline check, as a request would on a busy host.
			$scrypt   = new Scrypt( 'restic', $salt, $parameters, 64, $table, $checkpoint );
			$finished = $scrypt->run( 0.0 );
			if ( ! $finished ) {
				$checkpoint = json_decode( (string) json_encode( $scrypt->checkpoint() ), true );
				self::assertIsArray( $checkpoint );
			}
			++$slices;
		} while ( ! $finished );

		self::assertGreaterThan( 10, $slices );
		self::assertSame( 'e1060e95f6baf81502da1355300c55f2ac5204a6e3b5dde475b4181189fd00f106234e18e366b5721645680f2186bb7ff42c402e53a3832837bdb130b0fd860a', bin2hex( $scrypt->result() ) );
		self::assertFileDoesNotExist( $table, 'The lookup table must be deleted after the derivation.' );
	}

	/**
	 * @return iterable<string, array{int, int, int}>
	 */
	public static function invalid_parameters(): iterable {
		yield 'N not a power of two' => [ 1000, 8, 1 ];
		yield 'N too small' => [ 1, 8, 1 ];
		yield 'N beyond the cap' => [ 1 << 21, 8, 1 ];
		yield 'r zero' => [ 1024, 0, 1 ];
		yield 'p beyond the cap' => [ 1024, 8, 64 ];
	}

	#[DataProvider( 'invalid_parameters' )]
	public function test_rejects_invalid_or_too_expensive_parameters( int $n, int $r, int $p ): void {
		$this->expectException( UnsupportedRepository::class );
		new ScryptParameters( $n, $r, $p );
	}

	/**
	 * Opens the key restic created for the vector repository (N = 32768, r = 8, p = 6): about
	 * 20 seconds without JIT, so it runs with the slow group.
	 */
	#[Group( 'slow' )]
	public function test_opens_a_key_created_by_restic(): void {
		$files = Vectors::restic_files( 'keys/*' );
		self::assertCount( 1, $files );
		$key      = KeyFile::from_json( (string) file_get_contents( $files[0] ) );
		$password = trim( Vectors::restic( 'password.txt' ) );

		$user   = MasterKey::from_kdf_output( Scrypt::derive( $password, $key->salt, $key->scrypt_parameters() ) );
		$master = $key->open( $user, new SystemRandom() );

		self::assertSame( json_decode( Vectors::restic( 'masterkey.json' ), true ), json_decode( $master->to_json(), true ) );
	}
}
