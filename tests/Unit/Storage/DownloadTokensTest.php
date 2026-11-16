<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\DownloadTokens;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;

final class DownloadTokensTest extends TestCase {

	private string $directory;

	private FrozenClock $clock;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'tokens' );
		$this->clock     = new FrozenClock();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_a_token_is_redeemed_once(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'job-1/wp-content/uploads/photo.jpg' );

		self::assertTrue( $tokens->redeem( $token, 'job-1/wp-content/uploads/photo.jpg' ) );
		self::assertFalse( $tokens->redeem( $token, 'job-1/wp-content/uploads/photo.jpg' ) );
		self::assertFalse( $this->tokens()->redeem( $token, 'job-1/wp-content/uploads/photo.jpg' ), 'Another request reads the same store.' );
	}

	public function test_a_token_is_256_random_bits_as_text_that_is_safe_in_a_url(): void {
		$token = ( new DownloadTokens( $this->directory, new FixedRandom( 'tokens', str_repeat( "\xff", 32 ) ), $this->clock ) )->issue( 'zip' );

		self::assertSame( str_repeat( '_', 42 ) . '8', $token );
		self::assertNotSame( $this->tokens()->issue( 'zip' ), $this->tokens()->issue( 'zip' ) );
	}

	public function test_a_wrong_token_is_refused_and_uses_nothing_up(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'job-1/zip' );
		$other  = substr( $token, 0, 42 ) . ( 'A' === $token[42] ? 'B' : 'A' );

		foreach ( [ $other, '', 'short', $token . 'A', substr( $token, 0, 42 ) . '=', substr( $token, 0, 42 ) . "\n", str_repeat( 'a', 100000 ) ] as $wrong ) {
			self::assertFalse( $tokens->redeem( $wrong, 'job-1/zip' ) );
		}
		self::assertTrue( $tokens->redeem( $token, 'job-1/zip' ) );
	}

	public function test_a_token_for_another_subject_is_refused_and_stays_valid_for_its_own(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'job-1/a.txt' );
		$second = $tokens->issue( 'job-1/b.txt' );

		self::assertFalse( $tokens->redeem( $token, 'job-1/b.txt' ) );
		self::assertFalse( $tokens->redeem( $token, 'job-2/a.txt' ) );
		self::assertFalse( $tokens->redeem( $token, '' ) );
		self::assertTrue( $tokens->redeem( $token, 'job-1/a.txt' ) );
		self::assertTrue( $tokens->redeem( $second, 'job-1/b.txt' ) );
	}

	public function test_a_token_expires_after_an_hour(): void {
		$tokens = $this->tokens();
		$early  = $tokens->issue( 'early' );
		$late   = $tokens->issue( 'late' );

		$this->clock->advance( DownloadTokens::LIFETIME - 1 );
		self::assertTrue( $tokens->redeem( $early, 'early' ) );
		$this->clock->advance( 1 );
		self::assertFalse( $tokens->redeem( $late, 'late' ) );
		self::assertSame( [], $this->stored(), 'An expired token leaves the store when a token is presented.' );
	}

	public function test_expired_tokens_leave_the_store_when_a_token_is_issued(): void {
		$tokens = $this->tokens();
		$tokens->issue( 'first' );
		$tokens->issue( 'second' );
		$this->clock->advance( DownloadTokens::LIFETIME );

		$tokens->issue( 'third' );

		self::assertSame( [ hash( 'sha256', 'third' ) ], array_column( $this->stored(), 'subject' ) );
	}

	public function test_the_store_is_private_and_holds_no_token_and_no_subject(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'job-1/wp-config.php' );

		$file    = $this->directory . '/download-tokens.json';
		$content = (string) file_get_contents( $file );
		self::assertSame( 0600, fileperms( $file ) & 0777 );
		self::assertStringNotContainsString( $token, $content );
		self::assertStringNotContainsString( 'wp-config', $content );
		self::assertSame(
			[
				[
					'hash'    => hash( 'sha256', $token ),
					'subject' => hash( 'sha256', 'job-1/wp-config.php' ),
					'expires' => $this->clock->seconds + 3600,
				],
			],
			$this->stored()
		);
		self::assertSame( [], glob( $this->directory . '/*.tmp' ) );
	}

	public function test_a_subject_may_hold_any_bytes(): void {
		$tokens  = $this->tokens();
		$subject = "job-1/latin1-\xe9\0.txt";
		$token   = $tokens->issue( $subject );

		self::assertFalse( $tokens->redeem( $token, 'job-1/latin1-?.txt' ) );
		self::assertTrue( $tokens->redeem( $token, $subject ) );
	}

	public function test_many_tokens_are_each_redeemed_once_in_any_order(): void {
		$tokens = $this->tokens();
		$issued = [];
		for ( $number = 0; $number < 100; $number++ ) {
			$issued[ 'file-' . $number ] = $tokens->issue( 'file-' . $number );
		}
		self::assertCount( 100, array_unique( $issued ) );

		foreach ( array_reverse( $issued, true ) as $subject => $token ) {
			self::assertTrue( $tokens->redeem( $token, $subject ), $subject );
			self::assertFalse( $tokens->redeem( $token, $subject ), $subject );
		}
		self::assertSame( [], $this->stored() );
	}

	public function test_the_oldest_token_gives_way_when_the_store_is_full(): void {
		$tokens = new DownloadTokens( $this->directory, new SystemRandom(), $this->clock, 3 );
		$issued = [];
		for ( $number = 0; $number < 5; $number++ ) {
			$issued[] = $tokens->issue( 'file' );
			$this->clock->advance( 1 );
		}

		self::assertCount( 3, $this->stored() );
		self::assertFalse( $tokens->redeem( $issued[0], 'file' ) );
		self::assertFalse( $tokens->redeem( $issued[1], 'file' ) );
		self::assertTrue( $tokens->redeem( $issued[2], 'file' ) );
		self::assertTrue( $tokens->redeem( $issued[4], 'file' ) );
	}

	public function test_a_damaged_store_is_treated_as_empty(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'file' );
		$file   = $this->directory . '/download-tokens.json';
		$sound  = (string) file_get_contents( $file );
		$record = $this->stored()[0];

		$damaged = [
			'',
			'not json',
			substr( $sound, 0, -5 ),
			'[]',
			'{"version":2,"tokens":' . json_encode( [ $record ] ) . '}',
			'{"version":1,"tokens":"none"}',
			'{"version":1,"tokens":[' . str_repeat( '[', 600 ) . str_repeat( ']', 600 ) . ']}',
			json_encode(
				[
					'version' => 1,
					'tokens'  => [ [ 'expires' => PHP_INT_MAX ] + $record, [ 'hash' => strtoupper( $record['hash'] ) ] + $record, [ 'subject' => 7 ] + $record, 'text', null ],
				]
			),
			str_repeat( ' ', 1 << 20 ) . $sound,
		];
		foreach ( $damaged as $number => $content ) {
			file_put_contents( $file, $content );
			self::assertFalse( $tokens->redeem( $token, 'file' ), 'damage ' . $number );
		}

		$again = $tokens->issue( 'file' );
		self::assertCount( 1, $this->stored(), 'Issuing replaces what could not be read.' );
		self::assertTrue( $tokens->redeem( $again, 'file' ) );
	}

	public function test_a_token_is_not_accepted_when_it_cannot_be_used_up(): void {
		$tokens = $this->tokens();
		$token  = $tokens->issue( 'file' );
		$moved  = $this->directory . '-moved';
		rename( $this->directory, $moved );
		try {
			self::assertFalse( $tokens->redeem( $token, 'file' ), 'Without its store, no token is known.' );
			try {
				$tokens->issue( 'file' );
				self::fail( 'A token that is stored nowhere must not be handed out.' );
			} catch ( StorageFailed $failed ) {
				self::assertSame( [], $failed->context() );
			}
		} finally {
			rename( $moved, $this->directory );
		}
		self::assertTrue( $tokens->redeem( $token, 'file' ) );
	}

	public function test_the_store_is_read_and_written_under_a_lock_that_other_requests_wait_for(): void {
		// The clock is asked while the store is read, so it sees what another request would see.
		$clock  = new class( $this->directory . '/download-tokens.lock' ) implements Clock {

			public int $asked = 0;

			public int $locked = 0;

			public function __construct( private readonly string $file ) {
			}

			public function now(): array {
				$handle = fopen( $this->file, 'c' );
				TestCase::assertIsResource( $handle );
				++$this->asked;
				$this->locked += flock( $handle, LOCK_EX | LOCK_NB ) ? 0 : 1;
				fclose( $handle );
				return [ 1767225600, 0 ];
			}
		};
		$tokens = new DownloadTokens( $this->directory, new SystemRandom(), $clock );

		$token = $tokens->issue( 'file' );
		self::assertTrue( $tokens->redeem( $token, 'file' ) );
		self::assertFalse( $tokens->redeem( $token, 'file' ) );

		self::assertGreaterThanOrEqual( 3, $clock->asked );
		self::assertSame( $clock->asked, $clock->locked, 'Every look at the store happens while the lock is held.' );
	}

	private function tokens(): DownloadTokens {
		return new DownloadTokens( $this->directory, new SystemRandom(), $this->clock );
	}

	/**
	 * @return list<array{hash: string, subject: string, expires: int}>
	 */
	private function stored(): array {
		$data = json_decode( (string) file_get_contents( $this->directory . '/download-tokens.json' ), true );
		self::assertIsArray( $data );
		self::assertSame( 1, $data['version'] );
		self::assertIsArray( $data['tokens'] );
		$records = [];
		foreach ( $data['tokens'] as $record ) {
			self::assertIsArray( $record );
			self::assertIsString( $record['hash'] );
			self::assertIsString( $record['subject'] );
			self::assertIsInt( $record['expires'] );
			$records[] = [
				'hash'    => $record['hash'],
				'subject' => $record['subject'],
				'expires' => $record['expires'],
			];
		}
		return $records;
	}
}
