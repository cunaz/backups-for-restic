<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\FileLock;
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
		self::assertSame( 0600, fileperms( $probe['file'] ) & 0777 );
		self::assertSame( $probe['token'], file_get_contents( $probe['file'] ) );
		self::assertStringStartsWith( $directory->subdirectory( 'probe' ) . '/', $probe['file'] );
		self::assertTrue( $directory->is_inside( $this->root . '/site/' ) );
		self::assertFalse( $directory->is_inside( $this->root . '/si' ) );
		self::assertNotNull( $directory->free_space() );
	}

	public function test_uninstalling_leaves_nothing_of_what_the_plugin_put_into_the_directory(): void {
		$directory = new PrivateDirectory( $this->root . '/backups-for-restic' );
		$directory->ensure();
		$directory->write_probe( new SystemRandom() );
		foreach ( [ 'runner.lock', 'state.lock', 'notify.lock' ] as $name ) {
			self::assertTrue( ( new FileLock( $directory->path . '/' . $name ) )->hold( static fn (): bool => true ) );
			self::assertFileExists( $directory->path . '/' . $name );
		}

		$directory->remove_contents();

		self::assertDirectoryDoesNotExist( $directory->path );
	}

	public function test_unclaimed_directory_with_a_foreign_cache_is_left_alone(): void {
		$directory = new PrivateDirectory( $this->root . '/shared' );
		mkdir( $directory->path . '/cache', 0755, true );
		chmod( $directory->path, 0755 );
		file_put_contents( $directory->path . '/cache/keep', 'foreign cache' );

		try {
			$directory->ensure();
			self::fail( 'A nonempty location must not be claimed.' );
		} catch ( StorageFailed $error ) {
			self::assertStringContainsString( 'empty directory', $error->getMessage() );
		}
		$directory->remove_contents();
		self::assertSame( 'foreign cache', file_get_contents( $directory->path . '/cache/keep' ) );
		self::assertSame( 0755, fileperms( $directory->path ) & 0777 );
		self::assertFileDoesNotExist( $directory->path . '/.htaccess' );
	}

	public function test_a_legacy_directory_is_recognized_and_custom_protection_survives_uninstall(): void {
		$directory = new PrivateDirectory( $this->root . '/legacy' );
		$directory->ensure();
		unlink( $directory->path . '/.backups-for-restic' );
		file_put_contents( $directory->path . '/.htaccess', 'host-specific deny rule' );
		file_put_contents( $directory->subdirectory( 'jobs' ) . '/keep', 'job data' );
		$directory->ensure();
		self::assertSame( 'job data', file_get_contents( $directory->subdirectory( 'jobs' ) . '/keep' ) );

		$directory->remove_contents();
		self::assertSame( 'host-specific deny rule', file_get_contents( $directory->path . '/.htaccess' ) );
		self::assertDirectoryDoesNotExist( $directory->subdirectory( 'jobs' ) );
	}

	public function test_uninstalling_keeps_files_that_are_not_the_plugins(): void {
		$directory = new PrivateDirectory( $this->root . '/shared' );
		$directory->ensure();
		file_put_contents( $directory->path . '/notes.txt', 'Somebody else put this here.' );

		$directory->remove_contents();

		self::assertSame( [ 'notes.txt' ], array_values( array_diff( (array) scandir( $directory->path ), [ '.', '..' ] ) ) );
	}
}
