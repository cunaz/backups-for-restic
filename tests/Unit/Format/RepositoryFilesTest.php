<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Unpacked;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Config, key, snapshot and lock files: parsed from restic's raw files and re-encoded byte for
 * byte, because file names are the SHA-256 of their content.
 */
final class RepositoryFilesTest extends TestCase {

	public function test_config_round_trips_byte_for_byte(): void {
		$plaintext = Vectors::cipher()->open( Vectors::restic( 'raw/config' ) );
		$config    = Config::from_json( $plaintext );

		self::assertSame( 1, $config->version );
		self::assertSame( json_decode( Vectors::restic( 'config.json' ), true )['id'], $config->id );
		self::assertSame( $plaintext, $config->to_json() );
	}

	public function test_new_configs_are_version_1_with_an_irreducible_polynomial(): void {
		$config = Config::create( new FixedRandom( 'config' ) );
		self::assertSame( 1, $config->version );
		self::assertSame( 64, strlen( $config->id ) );
		self::assertEquals( $config, Config::from_json( $config->to_json() ) );
	}

	public function test_config_rejects_unknown_versions_and_reducible_polynomials(): void {
		try {
			Config::from_json( '{"version":3,"id":"x","chunker_polynomial":"3da3358b4dc173"}' );
			self::fail( 'Version 3 was accepted.' );
		} catch ( UnsupportedRepository ) {
			self::assertTrue( true );
		}
		$this->expectException( FormatException::class );
		Config::from_json( '{"version":1,"id":"x","chunker_polynomial":"3da3358b4dc172"}' );
	}

	public function test_key_file_round_trips_and_its_name_is_its_hash(): void {
		$files = Vectors::restic_files( 'keys/*' );
		self::assertCount( 1, $files );
		$raw = (string) file_get_contents( $files[0] );
		$key = KeyFile::from_json( $raw );

		self::assertSame( $raw, $key->to_json() );
		self::assertSame( basename( $files[0] ), Id::of( $raw )->hex() );
		self::assertSame( 64, strlen( $key->salt ) );
	}

	public function test_created_key_files_open_with_the_right_key_only(): void {
		$random = new SystemRandom();
		$master = MasterKey::generate( $random );
		$user   = MasterKey::generate( $random );
		$key    = KeyFile::create( $master, $user, $random->bytes( 64 ), ScryptParameters::for_new_keys(), $random, '2026-11-09T12:00:00Z', 'www-data', 'example.com' );
		$parsed = KeyFile::from_json( $key->to_json() );

		self::assertSame( $master->to_json(), $parsed->open( $user, $random )->to_json() );
		$this->expectException( WrongPassword::class );
		$parsed->open( MasterKey::generate( $random ), $random );
	}

	public function test_key_files_with_other_kdfs_or_short_salts_are_rejected(): void {
		try {
			KeyFile::from_json( '{"kdf":"argon2","N":1,"r":1,"p":1,"salt":"","data":""}' );
			self::fail( 'A non-scrypt key was accepted.' );
		} catch ( UnsupportedRepository ) {
			self::assertTrue( true );
		}
		$this->expectException( FormatException::class );
		KeyFile::from_json( '{"kdf":"scrypt","N":16384,"r":8,"p":1,"salt":"c2FsdA==","data":"' . base64_encode( str_repeat( 'x', 40 ) ) . '"}' );
	}

	public function test_snapshot_round_trips_byte_for_byte(): void {
		$files = Vectors::restic_files( 'snapshots/*' );
		self::assertCount( 1, $files );
		$plaintext = Unpacked::decode( Vectors::cipher()->open( (string) file_get_contents( $files[0] ) ), 1 );
		$snapshot  = Snapshot::from_json( $plaintext );

		self::assertSame( $plaintext, $snapshot->to_json() );
		self::assertSame( trim( Vectors::restic( 'root-tree.id' ) ), $snapshot->tree->hex() );
		self::assertSame( [ 'vectors' ], $snapshot->tags );
		self::assertTrue( $snapshot->has_tags( [ 'vectors' ] ) );
		self::assertSame( 5, $snapshot->summary?->files_new );
	}

	public function test_lock_round_trip_and_staleness(): void {
		$lock = new Lock( '2026-11-09T12:00:00Z', true, 'example.com', 'www-data', 4242, 33, 33 );
		self::assertSame( '{"time":"2026-11-09T12:00:00Z","exclusive":true,"hostname":"example.com","username":"www-data","pid":4242,"uid":33,"gid":33}', $lock->to_json() );
		self::assertEquals( $lock, Lock::from_json( $lock->to_json() ) );
		self::assertFalse( $lock->is_stale( 1794225600 + 1799 ) );
		self::assertTrue( $lock->is_stale( 1794225600 + 1801 ) );
	}

	public function test_lock_json_omits_zero_ids_like_restic(): void {
		$lock = new Lock( '2026-11-09T12:00:00Z', false, 'example.com', 'www-data', 4242 );
		self::assertSame( '{"time":"2026-11-09T12:00:00Z","exclusive":false,"hostname":"example.com","username":"www-data","pid":4242}', $lock->to_json() );
	}

	public function test_lock_parsing_reads_missing_fields_as_empty(): void {
		self::assertEquals( new Lock( '2026-11-09T12:00:00Z', true, '', '', 0, 0, 0 ), Lock::from_json( '{"time":"2026-11-09T12:00:00Z","exclusive":true}' ) );
		self::assertEquals( new Lock( '2026-11-09T12:00:00Z', false, '', '', 0, 0, 0 ), Lock::from_json( '{"time":"2026-11-09T12:00:00Z","exclusive":false,"hostname":7,"pid":"1","uid":-1.5,"gid":null}' ) );
	}

	public function test_a_lock_becomes_stale_only_after_thirty_minutes(): void {
		$lock = new Lock( '2026-11-09T12:00:00Z', true, 'example.com', 'www-data', 4242 );
		self::assertFalse( $lock->is_stale( 1794225600 + Lock::STALE_AFTER_SECONDS ), 'restic treats a lock as stale only when it is older than 30 minutes.' );
		self::assertTrue( $lock->is_stale( 1794225600 + Lock::STALE_AFTER_SECONDS + 1 ) );
	}

	public function test_key_file_data_must_hold_at_least_nonce_and_tag(): void {
		$salt = str_repeat( 's', KeyFile::SALT_LENGTH );
		$key  = new KeyFile( '', '', '', 16384, 8, 1, $salt, str_repeat( 'd', Cipher::OVERHEAD ) );
		self::assertSame( Cipher::OVERHEAD, strlen( $key->data ) );

		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Key file data is too short.' );
		new KeyFile( '', '', '', 16384, 8, 1, $salt, str_repeat( 'd', Cipher::OVERHEAD - 1 ) );
	}

	public function test_key_file_that_does_not_hold_a_master_key_counts_as_a_wrong_password(): void {
		$random = new FixedRandom( 'key file' );
		$user   = MasterKey::generate( $random );
		$data   = ( new Cipher( $user, $random ) )->seal( 'not a master key' );
		$key    = new KeyFile( '', '', '', 16384, 8, 1, str_repeat( 's', KeyFile::SALT_LENGTH ), $data );

		$this->expectException( WrongPassword::class );
		$key->open( $user, $random );
	}

	public function test_config_reports_the_unsupported_version(): void {
		try {
			Config::from_json( '{"version":3,"id":"x","chunker_polynomial":"3da3358b4dc173"}' );
			self::fail( 'Version 3 was accepted.' );
		} catch ( UnsupportedRepository $exception ) {
			self::assertSame( [ 'version' => 3 ], $exception->context() );
		}
	}

	/**
	 * @return iterable<string, array{int, bool}>
	 */
	public static function polynomial_degrees(): iterable {
		yield 'degree 8' => [ 0x1ff, false ];
		yield 'degree 9' => [ 0x201, true ];
		yield 'degree 53' => [ 0x3DA3358B4DC173, true ];
		yield 'degree 54' => [ 0x7DA3358B4DC173, false ];
	}

	#[DataProvider( 'polynomial_degrees' )]
	public function test_config_accepts_chunker_polynomials_of_degree_9_to_53( int $polynomial, bool $valid ): void {
		if ( ! $valid ) {
			$this->expectException( FormatException::class );
			$this->expectExceptionMessage( 'Chunker polynomial has an unsupported degree.' );
		}
		self::assertSame( $polynomial, ( new Config( 1, 'id', $polynomial ) )->chunker_polynomial );
	}

	public function test_compression_support_follows_the_zstd_extension(): void {
		self::assertSame( function_exists( 'zstd_uncompress' ), Compression::available() );
	}

	public function test_unpacked_files_reject_unknown_encodings(): void {
		self::assertSame( '{"a":1}', Unpacked::decode( '{"a":1}', 2 ) );
		self::assertSame( "\x05", Unpacked::decode( "\x05", 1 ) );
		$this->expectException( FormatException::class );
		Unpacked::decode( "\x05garbage", 2 );
	}

	public function test_compressed_unpacked_files_need_zstd(): void {
		if ( function_exists( 'zstd_uncompress' ) ) {
			self::markTestSkipped( 'The zstd extension is installed.' );
		}
		$this->expectException( UnsupportedRepository::class );
		Unpacked::decode( "\x02compressed", 2 );
	}

	public function test_cipher_from_vectors_has_the_expected_overhead(): void {
		self::assertSame( 32, Cipher::OVERHEAD );
	}
}
