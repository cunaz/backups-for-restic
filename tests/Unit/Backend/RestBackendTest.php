<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Backend;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\QuotaExceeded;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\UploadRejected;

/**
 * Request shapes, status mapping and retry policy of the REST client.
 */
final class RestBackendTest extends TestCase {

	private const string NAME = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * @param list<HttpResponse|\Throwable> $responses
	 * @param (\Closure(string): void)|null $after_write Observes a streamed attempt before its response returns.
	 * @return array{RestBackend, \ArrayObject<int, array{string, string, array<string, string>, string, int}>}
	 */
	private function backend( array $responses, ?string $username = 'user', ?\Closure $after_write = null ): array {
		$log    = new \ArrayObject();
		$client = new class( $responses, $log, $after_write ) implements HttpClient {
			/**
			 * @param list<HttpResponse|\Throwable> $responses
			 * @param \ArrayObject<int, array{string, string, array<string, string>, string, int}> $log
			 * @param (\Closure(string): void)|null $after_write Stream observation.
			 */
			public function __construct( private array $responses, private \ArrayObject $log, private ?\Closure $after_write ) {
			}

			public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0, ?string $file = null ): HttpResponse {
				$this->log->append( [ $method, $url, $headers, $body, $limit ] );
				$next = array_shift( $this->responses ) ?? new HttpResponse( 500, [], '' );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}
				if ( null !== $file ) {
					TestCase::assertSame( 0600, fileperms( $file ) & 0777, 'Private before response bytes are written.' );
					file_put_contents( $file, $next->body );
					if ( null !== $this->after_write ) {
						( $this->after_write )( $file );
					}
					return new HttpResponse( $next->status, $next->headers, '' );
				}
				return $next;
			}
		};
		return [ new RestBackend( $client, 'https://repo.example.com/site/', $username, 'secret', 'test-agent', 3, static function ( int $ms ): void {} ), $log ];
	}

	public function test_streamed_listing_retries_truncate_partial_responses(): void {
		$directory = \BackupsForRestic\Tests\Support\TempDir::create( 'listing-download' );
		try {
			file_put_contents( $directory . '/list', 'previous' );
			$json              = '["' . self::NAME . '"]';
			[ $backend, $log ] = $this->backend( [ new HttpResponse( 503, [], str_repeat( 'failed', 100 ) ), new HttpResponse( 200, [], $json ) ] );
			$backend->list_to_file( ObjectType::Data, $directory . '/list' );
			self::assertSame( $json, file_get_contents( $directory . '/list' ) );
			self::assertSame( [ [ self::NAME, -1 ] ], \BackupsForRestic\Backend\ListingFile::batch( $directory . '/list', 0 )['entries'] );
			self::assertSame( [ 134217728, 134217728 ], array_column( $log->getArrayCopy(), 4 ) );
			self::assertSame( [], glob( $directory . '/*.tmp' ) );
		} finally {
			\BackupsForRestic\Tests\Support\TempDir::remove( $directory );
		}
	}

	public function test_streamed_listing_is_published_only_after_a_successful_attempt(): void {
		$directory = \BackupsForRestic\Tests\Support\TempDir::create( 'listing-publication' );
		try {
			$file = $directory . '/list';
			file_put_contents( $file, 'previous' );
			$attempts    = 0;
			[ $backend ] = $this->backend(
				[ new HttpResponse( 503, [], 'failed' ), new HttpResponse( 200, [], '[]' ) ],
				'user',
				static function ( string $temporary ) use ( $file, &$attempts ): void {
					++$attempts;
					self::assertNotSame( $file, $temporary );
					self::assertSame( 'previous', file_get_contents( $file ), 'Neither the failed response nor the next attempt may replace the published listing before success.' );
				}
			);
			$backend->list_to_file( ObjectType::Data, $file );
			self::assertSame( 2, $attempts );
			self::assertSame( '[]', file_get_contents( $file ) );
		} finally {
			\BackupsForRestic\Tests\Support\TempDir::remove( $directory );
		}
	}

	public function test_failed_streamed_listing_keeps_the_previous_file_and_removes_its_temporary(): void {
		$directory = \BackupsForRestic\Tests\Support\TempDir::create( 'listing-download' );
		try {
			file_put_contents( $directory . '/list', 'previous' );
			[ $backend ] = $this->backend( [ new HttpResponse( 401, [], 'no' ) ] );
			try {
				$backend->list_to_file( ObjectType::Data, $directory . '/list' );
				self::fail( 'An unsuccessful download must not replace a working listing.' );
			} catch ( Unauthorized ) {
				self::assertSame( 'previous', file_get_contents( $directory . '/list' ) );
				self::assertSame( [], glob( $directory . '/*.tmp' ) );
			}
		} finally {
			\BackupsForRestic\Tests\Support\TempDir::remove( $directory );
		}
	}

	public function test_streamed_response_cap_is_checked_without_loading_the_file(): void {
		$directory = \BackupsForRestic\Tests\Support\TempDir::create( 'listing-download' );
		$client    = new class() implements HttpClient {
			public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0, ?string $file = null ): HttpResponse {
				TestCase::assertNotNull( $file );
				TestCase::assertSame( 134217728, $limit );
				$output = new \BackupsForRestic\Support\WorkFile( $file );
				$output->truncate( $limit + 1 );
				$output->close();
				return new HttpResponse( 200, [], '' );
			}
		};
		try {
			file_put_contents( $directory . '/list', 'previous' );
			$backend = new RestBackend( $client, 'https://example.com/' );
			try {
				$backend->list_to_file( ObjectType::Data, $directory . '/list' );
				self::fail( 'The oversized stream must be refused before parsing.' );
			} catch ( BackendException $error ) {
				self::assertStringContainsString( 'listing is larger', $error->getMessage() );
				self::assertSame( 'previous', file_get_contents( $directory . '/list' ) );
				self::assertSame( [], glob( $directory . '/*.tmp' ) );
			}
		} finally {
			\BackupsForRestic\Tests\Support\TempDir::remove( $directory );
		}
	}

	public function test_a_whole_file_is_read_up_to_what_its_kind_can_hold(): void {
		$kinds = [
			[ ObjectType::Config, 65536 ],
			[ ObjectType::Keys, 65536 ],
			[ ObjectType::Locks, 1048576 ],
			[ ObjectType::Snapshots, 16777216 ],
			[ ObjectType::Index, 134217728 ],
		];
		foreach ( $kinds as [ $type, $limit ] ) {
			[ $backend, $log ] = $this->backend( [ new HttpResponse( 200, [], 'content' ) ] );
			self::assertSame( 'content', $backend->load( $type, self::NAME ) );
			self::assertSame( $limit, $log[0][4], 'The client is told where to stop reading a file of the kind ' . $type->value . '.' );
		}

		// A file of exactly that size is one; a longer answer is not a repository file, whatever it holds.
		[ $backend ] = $this->backend( [ new HttpResponse( 200, [], str_repeat( 'x', 65536 ) ) ] );
		self::assertSame( 65536, strlen( $backend->load( ObjectType::Keys, self::NAME ) ) );
		[ $backend ] = $this->backend( [ new HttpResponse( 200, [], str_repeat( 'x', 65537 ) ) ] );
		try {
			$backend->load( ObjectType::Keys, self::NAME );
			self::fail( 'A key file of restic has a few hundred bytes.' );
		} catch ( FormatException $e ) {
			self::assertSame(
				[
					'type'  => 'keys',
					'limit' => 65536,
				],
				$e->context()
			);
		}
	}

	public function test_a_range_is_read_no_further_than_it_was_asked_for(): void {
		[ $backend, $log ] = $this->backend( [ new HttpResponse( 206, [], str_repeat( 'x', 100 ) ) ] );
		self::assertSame( 100, strlen( $backend->load( ObjectType::Data, self::NAME, 4096, 100 ) ) );
		self::assertSame( 'bytes=4096-4195', $log[0][2]['Range'] );
		self::assertSame( 100, $log[0][4] );

		// A server that ignores the range and sends the whole pack.
		[ $backend ] = $this->backend( [ new HttpResponse( 200, [], str_repeat( 'x', 101 ) ) ] );
		$this->expectException( BackendException::class );
		$backend->load( ObjectType::Data, self::NAME, 4096, 100 );
	}

	public function test_answers_whose_body_nothing_uses_are_cut_short_and_listings_have_a_limit_too(): void {
		[ $backend, $log ] = $this->backend(
			[
				new HttpResponse( 200, [ 'content-length' => '7' ], '' ),
				new HttpResponse( 200, [], '' ),
				new HttpResponse( 200, [], '' ),
				new HttpResponse( 200, [], '[]' ),
			]
		);
		$backend->size( ObjectType::Snapshots, self::NAME );
		$backend->save( ObjectType::Snapshots, self::NAME, 'content' );
		$backend->delete( ObjectType::Snapshots, self::NAME );
		$backend->list( ObjectType::Data );

		self::assertSame( [ 'HEAD', 'POST', 'DELETE', 'GET' ], array_column( $log->getArrayCopy(), 0 ) );
		self::assertSame( [ 65536, 65536, 65536, 134217728 ], array_column( $log->getArrayCopy(), 4 ) );
	}

	public function test_requests_carry_basic_auth_and_never_put_credentials_into_urls(): void {
		[ $backend, $log ] = $this->backend( [ new HttpResponse( 200, [], 'data' ) ] );
		self::assertSame( 'data', $backend->load( ObjectType::Snapshots, self::NAME ) );
		[ $method, $url, $headers ] = $log[0];
		self::assertSame( 'GET', $method );
		self::assertSame( 'https://repo.example.com/site/snapshots/' . self::NAME, $url );
		self::assertSame( 'Basic ' . base64_encode( 'user:secret' ), $headers['Authorization'] );
		self::assertSame( 'test-agent', $headers['User-Agent'] );
		self::assertStringNotContainsString( 'secret', $url );
	}

	public function test_ranged_reads_and_v2_listing(): void {
		[ $backend, $log ] = $this->backend(
			[
				new HttpResponse( 206, [], 'abc' ),
				new HttpResponse( 200, [ 'content-type' => 'application/vnd.x.restic.rest.v2' ], '[{"name":"' . self::NAME . '","size":42}]' ),
				new HttpResponse( 200, [], '["' . self::NAME . '"]' ),
			]
		);
		self::assertSame( 'abc', $backend->load( ObjectType::Data, self::NAME, 10, 3 ) );
		self::assertSame( 'bytes=10-12', $log[0][2]['Range'] );
		self::assertSame( [ self::NAME => 42 ], $backend->list( ObjectType::Index ) );
		self::assertSame( 'application/vnd.x.restic.rest.v2', $log[1][2]['Accept'] );
		self::assertSame( [ self::NAME => -1 ], $backend->list( ObjectType::Index ), 'v1 listings are accepted.' );
	}

	public function test_listing_skips_names_that_are_not_object_ids(): void {
		[ $backend ] = $this->backend(
			[
				new HttpResponse( 200, [], '[{"name":"12","size":1},{"name":".DS_Store","size":2},{"name":"' . strtoupper( self::NAME ) . '","size":3},{"name":"' . self::NAME . '","size":4}]' ),
				new HttpResponse( 200, [], '["12","' . self::NAME . '"]' ),
			]
		);
		self::assertSame( [ self::NAME => 4 ], $backend->list( ObjectType::Snapshots ) );
		self::assertSame( [ self::NAME => -1 ], $backend->list( ObjectType::Snapshots ) );
	}

	public function test_listing_rejects_negative_sizes(): void {
		[ $backend ] = $this->backend( [ new HttpResponse( 200, [], '[{"name":"' . self::NAME . '","size":-1}]' ) ] );
		$this->expectException( BackendException::class );
		$backend->list( ObjectType::Snapshots );
	}

	public function test_transient_failures_are_retried(): void {
		[ $backend, $log ] = $this->backend( [ new BackendException( 'reset' ), new HttpResponse( 503, [], '' ), new HttpResponse( 200, [], 'ok' ) ] );
		self::assertSame( 'ok', $backend->load( ObjectType::Snapshots, self::NAME ) );
		self::assertCount( 3, $log );
	}

	public function test_existing_upload_of_the_same_size_counts_as_success(): void {
		[ $backend ] = $this->backend( [ new HttpResponse( 403, [], '' ), new HttpResponse( 200, [ 'content-length' => '4' ], '' ) ] );
		$backend->save( ObjectType::Data, self::NAME, 'body' );
		$this->expectNotToPerformAssertions();
	}

	public function test_existing_config_is_an_error(): void {
		[ $backend ] = $this->backend( [ new HttpResponse( 403, [], '' ) ] );
		$this->expectException( AlreadyExists::class );
		$backend->save( ObjectType::Config, 'config', 'body' );
	}

	/**
	 * @return iterable<string, array{int, class-string<\Throwable>}>
	 */
	public static function failures(): iterable {
		yield '404' => [ 404, NotFound::class ];
		yield '401' => [ 401, Unauthorized::class ];
		yield '403' => [ 403, Unauthorized::class ];
		yield '507' => [ 507, QuotaExceeded::class ];
		yield '418' => [ 418, BackendException::class ];
	}

	/**
	 * @param class-string<\Throwable> $exception
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_status_codes_map_to_typed_exceptions( int $status, string $exception ): void {
		[ $backend ] = $this->backend( [ new HttpResponse( $status, [], '' ) ] );
		$this->expectException( $exception );
		$backend->load( ObjectType::Snapshots, self::NAME );
	}

	public function test_hash_mismatch_on_upload(): void {
		[ $backend ] = $this->backend( [ new HttpResponse( 400, [], '' ) ] );
		$this->expectException( UploadRejected::class );
		$backend->save( ObjectType::Data, self::NAME, 'body' );
	}

	public function test_append_only_refusal_and_idempotent_deletes(): void {
		[ $backend ] = $this->backend( [ new HttpResponse( 404, [], '' ), new HttpResponse( 403, [], '' ) ] );
		$backend->delete( ObjectType::Snapshots, self::NAME );
		$this->expectException( AppendOnlyRefused::class );
		$backend->delete( ObjectType::Snapshots, self::NAME );
	}

	public function test_only_https_urls_without_credentials_are_accepted(): void {
		$client = $this->createStub( HttpClient::class );
		foreach ( [ 'http://repo.example.com/', 'https://user:pass@repo.example.com/', 'https://repo.example.com/?x=1', 'repo.example.com' ] as $url ) {
			try {
				new RestBackend( $client, $url );
				self::fail( 'Accepted ' . $url );
			} catch ( InvalidArgument ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_object_names_are_validated(): void {
		[ $backend ] = $this->backend( [] );
		$this->expectException( InvalidArgument::class );
		$backend->load( ObjectType::Snapshots, '../config' );
	}
}
