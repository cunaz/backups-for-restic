<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Backend;

use BackupsForRestic\Backend\Http\StreamedBody;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamedBodyTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'http-stream' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_audit_observes_the_transport_cap_and_ignores_discarded_bytes(): void {
		$audit = new StreamedBody();
		$audit->observe( 'abc', 0, 5 );
		$audit->observe( 'defg', 3, 5 );
		$audit->observe( 'hijk', 5, 5 );
		file_put_contents( $this->directory . '/body', 'abcde' );
		$audit->verify( $this->directory . '/body' );
		self::assertSame( 'abcde', file_get_contents( $this->directory . '/body' ) );
	}

	#[DataProvider( 'bad_writes' )]
	public function test_short_or_corrupted_writes_are_local_failures( string $persisted ): void {
		$audit = new StreamedBody();
		$audit->observe( '["pack"]', 0, false );
		file_put_contents( $this->directory . '/body', $persisted );
		$this->expectException( StorageFailed::class );
		$audit->verify( $this->directory . '/body' );
	}

	public static function bad_writes(): array {
		return [ [ '["p"]' ], [ '["puck"]' ], [ '' ] ];
	}

	public function test_chunked_streams_are_decoded_with_extensions_and_trailers(): void {
		$body = str_repeat( 'x', 20000 );
		file_put_contents( $this->directory . '/body', "4e20;extension=yes\r\n" . $body . "\r\n2\r\n[]\r\n0\r\nDigest: ignored\r\n\r\n" );
		StreamedBody::decode_chunked( $this->directory . '/body', 30000 );
		self::assertSame( $body . '[]', file_get_contents( $this->directory . '/body' ) );
		self::assertSame( 0600, fileperms( $this->directory . '/body' ) & 0777 );
	}

	public function test_oversized_decoded_body_retains_one_byte_beyond_the_callers_limit(): void {
		file_put_contents( $this->directory . '/body', "10000\r\n" . str_repeat( 'x', 65536 ) );
		StreamedBody::decode_chunked( $this->directory . '/body', 100 );
		self::assertSame( str_repeat( 'x', 101 ), file_get_contents( $this->directory . '/body' ) );
	}

	#[DataProvider( 'bad_chunks' )]
	public function test_invalid_chunk_framing_preserves_the_raw_file( string $body ): void {
		file_put_contents( $this->directory . '/body', $body );
		try {
			StreamedBody::decode_chunked( $this->directory . '/body', 1000 );
			self::fail( 'An incomplete listing must not be published.' );
		} catch ( BackendException ) {
			self::assertSame( $body, file_get_contents( $this->directory . '/body' ) );
			self::assertFileDoesNotExist( $this->directory . '/body.decoded' );
		}
	}

	public static function bad_chunks(): array {
		return array_map( static fn ( string $body ): array => [ $body ], [ '', "z\r\n", "ffffffffffffffffffff\r\n", "2\r\n[", "2\r\n[]x", "0\r\n", "0\r\n\r\nx", str_repeat( 'a', 8193 ) ] );
	}
}
