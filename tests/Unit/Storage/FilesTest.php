<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Tests\Support\TempDir;

final class FilesTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'file-pieces' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_pieces_are_private_and_replace_the_target_only_when_complete(): void {
		$file = $this->directory . '/index.bin';
		file_put_contents( $file, 'previous' );
		$pieces = static function () use ( $file ): \Generator {
			yield 'first';
			self::assertSame( 'previous', file_get_contents( $file ) );
			$temporary = glob( $file . '.*.tmp' );
			self::assertIsArray( $temporary );
			self::assertCount( 1, $temporary );
			self::assertSame( 0600, fileperms( $temporary[0] ) & 0777 );
			yield '';
			yield 'second';
		};

		self::assertTrue( Files::write_atomic_pieces( $file, $pieces() ) );
		self::assertSame( 'firstsecond', file_get_contents( $file ) );
		self::assertSame( [], glob( $file . '.*.tmp' ) );
	}

	public function test_an_exception_in_the_pieces_preserves_the_target_and_removes_the_temporary_file(): void {
		$file = $this->directory . '/index.bin';
		file_put_contents( $file, 'previous' );
		$failure = new \RuntimeException( 'The next piece could not be produced.' );
		$pieces  = static function () use ( $failure ): \Generator {
			yield 'first';
			throw $failure;
		};

		try {
			Files::write_atomic_pieces( $file, $pieces() );
			self::fail( 'The failure of the producer must be reported.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( $failure, $caught );
		}
		self::assertSame( 'previous', file_get_contents( $file ) );
		self::assertSame( [], glob( $file . '.*.tmp' ), 'A failed cache write must not accumulate partial files.' );
	}

	public function test_a_durable_write_replaces_the_target_privately_without_a_temporary_file(): void {
		$file = $this->directory . '/entry.json';
		file_put_contents( $file, 'previous' );
		chmod( $file, 0644 );
		Files::write_durable( $file, '{"phase":"ready"}' );
		self::assertSame( '{"phase":"ready"}', file_get_contents( $file ) );
		self::assertSame( 0600, fileperms( $file ) & 0777 );
		self::assertSame( [], glob( $file . '.*.tmp' ) );
	}

	public function test_a_durable_write_synchronises_the_file_before_its_rename_and_the_directory_after(): void {
		$file  = $this->directory . '/job.json';
		$steps = [];
		Files::write_durable(
			$file,
			'{"step":"applying"}',
			static function ( string $operation, string $path ) use ( &$steps ): void {
				// The temporary name is random.
				$steps[] = $operation . ' ' . preg_replace( '/\.[0-9a-f]{8}\.tmp\z/', '.tmp', $path );
			}
		);
		self::assertSame( [ 'sync ' . $file . '.tmp', 'rename ' . $file, 'sync ' . $this->directory ], $steps );
		self::assertSame( '{"step":"applying"}', file_get_contents( $file ) );
	}

	public function test_synchronisation_accepts_read_only_copies_and_skips_what_cannot_be_opened(): void {
		$copy = $this->directory . '/candidate';
		file_put_contents( $copy, 'bytes' );
		chmod( $copy, 0400 );
		Files::sync_file( $copy );
		Files::sync_directory( $this->directory );
		// A directory that cannot be opened as a stream is skipped, never an error.
		Files::sync_directory( $this->directory . '/missing' );
		$this->expectException( StorageFailed::class );
		Files::sync_file( $this->directory . '/missing' );
	}

	public function test_a_file_beyond_the_limit_leaves_the_target_unchanged(): void {
		$file = $this->directory . '/index.bin';
		file_put_contents( $file, 'previous' );

		self::assertFalse( Files::write_atomic_pieces( $file, [ 'first', 'second' ], 5 ) );
		self::assertSame( 'previous', file_get_contents( $file ) );
		self::assertSame( [], glob( $file . '.*.tmp' ) );
		self::assertTrue( Files::write_atomic_pieces( $file, [ 'first' ], 5 ) );
		self::assertSame( 'first', file_get_contents( $file ) );
	}

	public function test_a_marker_is_there_or_missing_only_where_its_folder_establishes_it(): void {
		$folder = $this->directory . '/records';
		mkdir( $folder, 0700 );
		file_put_contents( $folder . '/marker', '1' );
		self::assertTrue( Files::marker( $folder . '/marker' ) );
		self::assertFalse( Files::marker( $folder . '/missing' ) );
		// Nothing is below a folder that is missing, also several levels down, nor below a file.
		self::assertFalse( Files::marker( $this->directory . '/gone/deeper/marker' ) );
		self::assertFalse( Files::marker( $folder . '/marker/below' ) );
		// What is no regular file is no marker that the plugin wrote.
		mkdir( $folder . '/directory', 0700 );
		self::assertNull( Files::marker( $folder . '/directory' ) );
		symlink( $folder . '/marker', $folder . '/link' );
		self::assertNull( Files::marker( $folder . '/link' ) );
		// Below a link, the folder that holds the name may be anywhere: neither answer holds.
		symlink( $folder, $this->directory . '/linked' );
		self::assertNull( Files::marker( $this->directory . '/linked/marker' ) );
		self::assertNull( Files::marker( $this->directory . '/linked/missing' ) );
		self::assertNull( Files::marker( $this->directory . '/linked/gone/marker' ) );
	}

	public function test_a_marker_below_a_folder_that_cannot_be_searched_is_neither_there_nor_missing(): void {
		$folder = $this->directory . '/records';
		mkdir( $folder, 0700 );
		file_put_contents( $folder . '/marker', '1' );
		chmod( $folder, 0600 );
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// PHP's is_file() answers false for both, as for a name that is missing.
			self::assertNull( Files::marker( $folder . '/marker' ) );
			self::assertNull( Files::marker( $folder . '/missing' ) );
			self::assertNull( Files::marker( $folder . '/missing/below' ) );
			self::assertFalse( Files::missing( $folder . '/missing' ) );
		} finally {
			chmod( $folder, 0700 );
		}
		self::assertTrue( Files::missing( $folder . '/missing' ) );
		self::assertFalse( Files::missing( $folder . '/marker' ) );
	}
}
