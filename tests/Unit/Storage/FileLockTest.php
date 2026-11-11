<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The lock for short changes to values that several requests change.
 */
final class FileLockTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'lock' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * Takes the lock file as another request would, and holds it until the handle is closed.
	 *
	 * @return resource Handle.
	 */
	private function other_request() {
		$handle = fopen( $this->directory . '/state.lock', 'c' );
		self::assertNotFalse( $handle );
		self::assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		return $handle;
	}

	public function test_the_work_runs_under_the_lock_and_its_result_comes_back(): void {
		$lock = new FileLock( $this->directory . '/state.lock' );

		$result = $lock->hold(
			function (): string {
				$handle = fopen( $this->directory . '/state.lock', 'c' );
				self::assertNotFalse( $handle );
				self::assertFalse( flock( $handle, LOCK_EX | LOCK_NB ), 'Another request would wait.' );
				fclose( $handle );
				return 'done';
			}
		);

		self::assertSame( 'done', $result );
		fclose( $this->other_request() );
	}

	public function test_the_lock_is_released_when_the_work_fails(): void {
		$lock = new FileLock( $this->directory . '/state.lock' );

		try {
			$lock->hold(
				static function (): never {
					throw new \RuntimeException( 'The database went away.' );
				}
			);
		} catch ( \RuntimeException ) {
			fclose( $this->other_request() );
		}
	}

	public function test_the_request_that_holds_the_lock_may_take_it_again(): void {
		$lock = new FileLock( $this->directory . '/state.lock' );

		$result = $lock->hold( static fn (): string => $lock->hold( static fn (): string => 'inner' ) );
		$ran    = false;
		$lock->hold(
			static function () use ( $lock, &$ran ): void {
				$ran = $lock->hold_if_free( static fn () => null );
			}
		);

		self::assertSame( 'inner', $result );
		self::assertTrue( $ran );
	}

	public function test_work_that_only_one_request_should_do_is_skipped_while_another_does_it(): void {
		$lock  = new FileLock( $this->directory . '/state.lock' );
		$other = $this->other_request();
		$runs  = 0;
		$work  = static function () use ( &$runs ): void {
			++$runs;
		};

		self::assertFalse( $lock->hold_if_free( $work ) );
		self::assertSame( 0, $runs );

		fclose( $other );
		self::assertTrue( $lock->hold_if_free( $work ) );
		self::assertSame( 1, $runs );
		fclose( $this->other_request() );
	}

	public function test_without_a_directory_the_work_runs_all_the_same(): void {
		$lock = new FileLock( $this->directory . '/not-there-yet/state.lock' );
		$runs = 0;

		self::assertSame( 'done', $lock->hold( static fn (): string => 'done' ) );
		self::assertTrue(
			$lock->hold_if_free(
				static function () use ( &$runs ): void {
					++$runs;
				}
			)
		);

		self::assertSame( 1, $runs );
		self::assertDirectoryDoesNotExist( $this->directory . '/not-there-yet', 'The lock creates nothing outside what exists.' );
	}
}
