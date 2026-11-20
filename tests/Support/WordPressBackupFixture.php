<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use PHPUnit\Framework\Assert;

/** The synthetic uploads and SQL data shared by the local and opt-in hosted WordPress restores. */
final class WordPressBackupFixture {

	/**
	 * Writes deterministic 8-MiB files. A completed local run can supply the same data
	 * through hard links, avoiding a second allocated copy on the acceptance host.
	 */
	public static function write_uploads( string $uploads, int $megabytes, ?string $reuse = null ): int {
		TempDir::remove( $uploads );
		mkdir( $uploads, 0755, true );
		$bytes = 0;
		for ( $i = 0; $i < $megabytes; $i += 8 ) {
			$name = '/file-' . $i . '.bin';
			$data = Vectors::hash_stream( 'site-' . $i, 8 * 1048576 );
			if ( null === $reuse ) {
				Assert::assertSame( strlen( $data ), file_put_contents( $uploads . $name, $data ) );
			} else {
				$file   = $reuse . $name;
				$digest = hash( 'sha256', $data );
				Assert::assertFalse( is_link( $file ) );
				Assert::assertSame( $digest, hash_file( 'sha256', $file ), 'Only the matching synthetic fixture may be reused.' );
				Assert::assertTrue( link( $file, $uploads . $name ) );
				Assert::assertSame( $digest, hash_file( 'sha256', $uploads . $name ) );
			}
			$bytes += strlen( $data );
		}
		return $bytes;
	}

	public static function write_database( MysqliConnection $db, int $megabytes ): void {
		$db->execute( 'DELETE FROM wp_postmeta WHERE meta_key = "_fixture"' );
		$rows = $megabytes * 1024;
		for ( $offset = 0; $offset < $rows; $offset += 1000 ) {
			$values = [];
			$end    = min( $offset + 1000, $rows );
			for ( $i = $offset; $i < $end; $i++ ) {
				$values[] = sprintf( "(1, '_fixture', '%s')", str_repeat( hash( 'sha256', (string) $i ), 16 ) );
			}
			$db->execute( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ' . implode( ',', $values ) );
		}
	}

	/**
	 * @return array<string,string> Matching checksums from the source and independently imported tables.
	 */
	public static function assert_restored_database( MysqliConnection $original, string $dump_directory ): array {
		$files = array_map( 'strval', (array) glob( $dump_directory . '/*.sql' ) );
		Assert::assertNotSame( [], $files, 'The snapshot contains the database dump.' );
		$admin = MysqliConnection::connect();
		Assert::assertNotNull( $admin );
		$restored = 'bfr_site_restored_' . bin2hex( random_bytes( 3 ) );
		$admin->execute( 'CREATE DATABASE `' . $restored . '` CHARACTER SET utf8mb4' );
		try {
			// Use the recovery guide's client so the full dump can exceed max_allowed_packet.
			MysqliConnection::import( $restored, $files );
			$copy = MysqliConnection::connect( $restored );
			Assert::assertNotNull( $copy );
			$checksums = [];
			foreach ( [ 'wp_postmeta', 'wp_posts', 'wp_users' ] as $table ) {
				$expected = $original->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'];
				Assert::assertNotNull( $expected, $table );
				Assert::assertSame( $expected, $copy->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $table );
				$checksums[ $table ] = (string) $expected;
			}
			return $checksums;
		} finally {
			$admin->execute( 'DROP DATABASE `' . $restored . '`' );
		}
	}
}
