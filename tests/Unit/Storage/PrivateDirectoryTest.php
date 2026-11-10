<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\TempDir;

final class PrivateDirectoryTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = TempDir::create( 'private-dir' );
	}

	protected function tearDown(): void {
		chmod( $this->root, 0700 );
		TempDir::remove( $this->root );
	}

	public function test_creates_an_owner_only_tree_with_deny_files(): void {
		$directory = new PrivateDirectory( $this->root . '/wp-content/' . PrivateDirectory::NAME );
		$directory->ensure();
		$directory->ensure();

		self::assertSame( 0700, fileperms( $directory->path ) & 0777 );
		foreach ( PrivateDirectory::SUBDIRECTORIES as $name ) {
			self::assertSame( 0700, fileperms( $directory->subdirectory( $name ) ) & 0777, $name );
		}
		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $directory->path . '/.htaccess' ) );
		self::assertStringContainsString( '<deny users="*" />', (string) file_get_contents( $directory->path . '/web.config' ) );
		self::assertFileExists( $directory->path . '/index.php' );
		self::assertSame( 0600, fileperms( $directory->path . '/.htaccess' ) & 0777 );
	}

	public function test_location_prefers_the_constant_then_wp_content_then_uploads(): void {
		mkdir( $this->root . '/content' );
		mkdir( $this->root . '/uploads' );
		self::assertSame( '/srv/private', PrivateDirectory::locate( '/srv/private/', $this->root . '/content', $this->root . '/uploads' )->path );
		self::assertSame( $this->root . '/content/' . PrivateDirectory::NAME, PrivateDirectory::locate( null, $this->root . '/content', $this->root . '/uploads' )->path );

		chmod( $this->root . '/content', 0500 );
		$fallback = PrivateDirectory::locate( '', $this->root . '/content', $this->root . '/uploads' )->path;
		chmod( $this->root . '/content', 0700 );
		if ( 0 === posix_geteuid() ) {
			self::markTestIncomplete( 'Root can write everywhere; the uploads fallback cannot be observed.' );
		}
		self::assertSame( $this->root . '/uploads/' . PrivateDirectory::NAME, $fallback );
	}

	public function test_the_uploaded_ca_certificate_is_read_or_reported_missing(): void {
		$directory = new PrivateDirectory( $this->root . '/' . PrivateDirectory::NAME );
		$directory->ensure();
		$missing = static function () use ( $directory ): bool {
			try {
				$directory->ca_certificate();
				return false;
			} catch ( StorageFailed $e ) {
				return 'ca_certificate_missing' === ( $e->context()['code'] ?? null );
			}
		};
		self::assertTrue( $missing(), 'No file.' );

		file_put_contents( $directory->path . '/ca.pem', "\n" );
		self::assertTrue( $missing(), 'An empty file is no certificate.' );

		file_put_contents( $directory->path . '/ca.pem', "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n" );
		self::assertSame( "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", $directory->ca_certificate() );
	}

	public function test_probe_file_and_web_root_check(): void {
		$directory = new PrivateDirectory( $this->root . '/site/wp-content/' . PrivateDirectory::NAME );
		$probe     = $directory->write_probe( new SystemRandom() );
		self::assertSame( $probe['token'], file_get_contents( $probe['file'] ) );
		self::assertStringStartsWith( $directory->subdirectory( 'probe' ) . '/', $probe['file'] );
		self::assertTrue( $directory->is_inside( $this->root . '/site/' ) );
		self::assertFalse( $directory->is_inside( $this->root . '/si' ) );
		self::assertNotNull( $directory->free_space() );
	}
}
