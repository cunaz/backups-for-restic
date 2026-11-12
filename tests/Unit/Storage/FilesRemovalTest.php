<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Tests\Support\TempDir;

/** Recursive cleanup reports refused operations and never follows a symbolic link. */
final class FilesRemovalTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = TempDir::create( 'files-removal' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	private function require_permissions(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to refuse deletion.' );
		}
	}

	private function assert_refused( string $path ): void {
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			Files::remove_tree( $path );
			self::fail( 'A refused cleanup must report the local storage failure.' );
		} catch ( StorageFailed $error ) {
			self::assertSame( 'environment', JobError::from_throwable( $error )->code );
			self::assertSame( basename( $path ), $error->context()['file'] ?? null );
		} finally {
			restore_error_handler();
		}
	}

	public function test_removes_nested_files_and_links_but_preserves_the_link_target(): void {
		mkdir( $this->root . '/outside' );
		file_put_contents( $this->root . '/outside/keep', 'keep' );
		mkdir( $this->root . '/remove/nested', 0700, true );
		file_put_contents( $this->root . '/remove/nested/drop', 'drop' );
		symlink( $this->root . '/outside', $this->root . '/remove/link' );
		symlink( $this->root . '/absent', $this->root . '/remove/dangling' );

		Files::remove_tree( $this->root . '/remove' );
		Files::remove_tree( $this->root . '/remove' );

		self::assertDirectoryDoesNotExist( $this->root . '/remove' );
		self::assertSame( 'keep', file_get_contents( $this->root . '/outside/keep' ) );
	}

	public function test_a_refused_unlink_is_reported(): void {
		$this->require_permissions();
		mkdir( $this->root . '/locked' );
		$file = $this->root . '/locked/keep';
		file_put_contents( $file, 'keep' );
		chmod( $this->root . '/locked', 0500 );
		try {
			$this->assert_refused( $file );
			self::assertSame( 'keep', file_get_contents( $file ) );
		} finally {
			chmod( $this->root . '/locked', 0700 );
		}
	}

	public function test_an_unreadable_directory_is_not_treated_as_empty(): void {
		$this->require_permissions();
		$directory = $this->root . '/unreadable';
		mkdir( $directory );
		file_put_contents( $directory . '/keep', 'keep' );
		chmod( $directory, 0300 );
		try {
			$this->assert_refused( $directory );
			self::assertFileExists( $directory . '/keep' );
		} finally {
			chmod( $directory, 0700 );
		}
	}

	public function test_a_refused_rmdir_is_reported(): void {
		$this->require_permissions();
		mkdir( $this->root . '/locked/empty', 0700, true );
		chmod( $this->root . '/locked', 0500 );
		try {
			$this->assert_refused( $this->root . '/locked/empty' );
			self::assertDirectoryExists( $this->root . '/locked/empty' );
		} finally {
			chmod( $this->root . '/locked', 0700 );
		}
	}
}
