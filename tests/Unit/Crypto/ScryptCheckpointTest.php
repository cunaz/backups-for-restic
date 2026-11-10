<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Scrypt;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * A derivation with a file-backed table runs across requests (ADR 0020): it stops at deadlines,
 * resumes from checkpoints that come back from storage, and must never turn damaged state into a
 * wrong key. A small cost (N = 256, r = 1, p = 2) keeps each test fast.
 */
final class ScryptCheckpointTest extends TestCase {

	/** scrypt("restic", bytes 0..63, N = 256, r = 1, p = 2, 64 bytes), from Python's hashlib.scrypt. */
	private const string KEY = 'cdec07e384ebc83b1e9063675f7fa945e13e69baef5d7b9d53e8324f3c8f167777261cbb3375f27faa36b7806e772940075edf2c06e2fa355a554d79188b3413';

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'scrypt' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function table(): string {
		return $this->directory . '/table.bin';
	}

	/**
	 * @param array<string, mixed> $checkpoint State from checkpoint().
	 */
	private function scrypt( array $checkpoint = [] ): Scrypt {
		return new Scrypt( 'restic', implode( '', array_map( 'chr', range( 0, 63 ) ) ), new ScryptParameters( 256, 1, 2 ), 64, $this->table(), $checkpoint );
	}

	public function test_a_file_backed_table_gives_the_reference_key_in_one_run(): void {
		$scrypt = $this->scrypt();
		self::assertTrue( $scrypt->run() );
		self::assertSame( self::KEY, bin2hex( $scrypt->result() ) );
		self::assertSame( self::KEY, bin2hex( Scrypt::derive( 'restic', implode( '', array_map( 'chr', range( 0, 63 ) ) ), new ScryptParameters( 256, 1, 2 ) ) ) );
		self::assertFileDoesNotExist( $this->table() );
	}

	public function test_the_table_file_is_readable_by_its_owner_only(): void {
		$umask = umask( 0022 );
		try {
			self::assertFalse( $this->scrypt()->run( 0.0 ) );
		} finally {
			umask( $umask );
		}
		clearstatcache();
		self::assertSame( 0600, fileperms( $this->table() ) & 0777 );
	}

	public function test_an_expired_deadline_still_lets_each_run_do_one_batch_of_64_iterations(): void {
		$progress   = [];
		$checkpoint = [];
		$runs       = 0;
		do {
			$scrypt   = $this->scrypt( $checkpoint );
			$finished = $scrypt->run( 0.0 );
			if ( ! $finished ) {
				$progress[] = $scrypt->progress();
				$checkpoint = $scrypt->checkpoint();
			}
		} while ( ! $finished && ++$runs < 100 );

		// Filling and mixing take N iterations each, for each of the p blocks: 1024 in all, so
		// every run that stops at its first deadline check adds one sixteenth.
		self::assertSame( array_map( static fn ( int $batch ): float => $batch / 16.0, range( 1, 16 ) ), $progress );
		self::assertSame( self::KEY, bin2hex( $scrypt->result() ) );
	}

	public function test_a_checkpoint_taken_before_the_first_run_resumes(): void {
		$scrypt = $this->scrypt( $this->scrypt()->checkpoint() );
		self::assertTrue( $scrypt->run() );
		self::assertSame( self::KEY, bin2hex( $scrypt->result() ) );
	}

	/**
	 * Changes that make a checkpoint (phase 1, block 0, iteration 64) inconsistent; null removes
	 * the field.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function corrupted_checkpoints(): iterable {
		yield 'phase that does not exist' => [ [ 'phase' => 0 ] ];
		yield 'block that is not an integer' => [ [ 'block' => '0' ] ];
		yield 'negative block' => [ [ 'block' => -1 ] ];
		yield 'block p, all blocks mixed' => [
			[
				'block' => 2,
				'mixed' => str_repeat( 'ab', 2 * 128 ),
			],
		];
		yield 'iteration that is not an integer' => [ [ 'iteration' => '64' ] ];
		yield 'negative iteration' => [ [ 'iteration' => -1 ] ];
		yield 'iteration beyond N' => [ [ 'iteration' => 257 ] ];
		yield 'state missing' => [ [ 'state' => null ] ];
		yield 'state of the wrong length' => [ [ 'state' => str_repeat( 'ab', 127 ) ] ];
		yield 'mixed blocks missing' => [ [ 'mixed' => null ] ];
		yield 'mixed blocks that do not match the block number' => [ [ 'mixed' => str_repeat( 'ab', 128 ) ] ];
	}

	/**
	 * @param array<string, mixed> $changes Fields to replace.
	 */
	#[DataProvider( 'corrupted_checkpoints' )]
	public function test_inconsistent_checkpoints_are_refused( array $changes ): void {
		$scrypt = $this->scrypt();
		self::assertFalse( $scrypt->run( 0.0 ) );
		$checkpoint = array_filter( array_replace( $scrypt->checkpoint(), $changes ), static fn ( mixed $value ): bool => null !== $value );

		$this->expectException( CryptoException::class );
		$this->expectExceptionMessage( 'Key derivation checkpoint is inconsistent.' );
		$this->scrypt( $checkpoint );
	}

	public function test_a_lost_table_file_stops_the_derivation_instead_of_giving_a_wrong_key(): void {
		$checkpoint = [];
		do {
			$scrypt = $this->scrypt( $checkpoint );
			self::assertFalse( $scrypt->run( 0.0 ) );
			$checkpoint = $scrypt->checkpoint();
		} while ( $scrypt->progress() <= 0.25 );
		// The first block is being mixed now, which reads the table back.
		unset( $scrypt );
		unlink( $this->table() );

		$this->expectException( CryptoException::class );
		$this->expectExceptionMessage( 'Key derivation table cannot be read.' );
		$this->scrypt( $checkpoint )->run();
	}
}
