<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
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

	public function test_a_file_beyond_the_limit_leaves_the_target_unchanged(): void {
		$file = $this->directory . '/index.bin';
		file_put_contents( $file, 'previous' );

		self::assertFalse( Files::write_atomic_pieces( $file, [ 'first', 'second' ], 5 ) );
		self::assertSame( 'previous', file_get_contents( $file ) );
		self::assertSame( [], glob( $file . '.*.tmp' ) );
		self::assertTrue( Files::write_atomic_pieces( $file, [ 'first' ], 5 ) );
		self::assertSame( 'first', file_get_contents( $file ) );
	}
}
