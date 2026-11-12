<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\CacheDirectory;
use BackupsForRestic\Tests\Support\TempDir;

final class CacheDirectoryTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = TempDir::create( 'cache' );
		foreach ( [
			'current'          => 300,
			'old-repository'   => 200,
			'older-repository' => 100,
		] as $name => $bytes ) {
			mkdir( $this->root . '/cache/' . $name, 0700, true );
			file_put_contents( $this->root . '/cache/' . $name . '/index.bin', str_repeat( 'x', $bytes ) );
		}
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	public function test_measures_every_file(): void {
		self::assertSame( 600, ( new CacheDirectory( $this->root . '/cache' ) )->size() );
		self::assertSame( 0, ( new CacheDirectory( $this->root . '/missing' ) )->size() );
	}

	public function test_removes_the_caches_of_other_repositories(): void {
		$cache = new CacheDirectory( $this->root . '/cache' );
		self::assertSame( 0, $cache->remove_others( '' ), 'Without a current repository nothing is removed.' );
		self::assertSame( 2, $cache->remove_others( 'current' ) );
		self::assertSame( 300, $cache->size() );
		self::assertDirectoryExists( $this->root . '/cache/current' );
	}

	public function test_a_cache_that_could_not_be_removed_is_reported(): void {
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			self::markTestSkipped( 'Needs the posix extension for a named pipe.' );
		}
		// A named pipe is neither a file nor a directory: removing the tree leaves it behind.
		$stuck = $this->root . '/cache/old-repository/stuck';
		self::assertTrue( posix_mkfifo( $stuck, 0600 ) );
		$cache = new CacheDirectory( $this->root . '/cache' );

		// rmdir() warns about the directory that is not empty.
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$cache->remove_others( 'current' );
			self::fail( 'Cleanup must report the directory that could not be removed.' );
		} catch ( StorageFailed $error ) {
			self::assertSame( 'old-repository', $error->context()['file'] ?? null );
		} finally {
			restore_error_handler();
			unlink( $stuck );
		}

		self::assertDirectoryExists( $this->root . '/cache/old-repository' );
		self::assertDirectoryExists( $this->root . '/cache/current' );
	}

	public function test_clears_everything(): void {
		$cache = new CacheDirectory( $this->root . '/cache' );
		$cache->clear();
		self::assertSame( 0, $cache->size() );
		self::assertDirectoryDoesNotExist( $this->root . '/cache' );
	}
}
