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
	 * @return array{RestBackend, \ArrayObject<int, array{string, string, array<string, string>, string}>}
	 */
	private function backend( array $responses, ?string $username = 'user' ): array {
		$log    = new \ArrayObject();
		$client = new class( $responses, $log ) implements HttpClient {
			/**
			 * @param list<HttpResponse|\Throwable> $responses
			 * @param \ArrayObject<int, array{string, string, array<string, string>, string}> $log
			 */
			public function __construct( private array $responses, private \ArrayObject $log ) {
			}

			public function request( string $method, string $url, array $headers = [], string $body = '' ): HttpResponse {
				$this->log->append( [ $method, $url, $headers, $body ] );
				$next = array_shift( $this->responses ) ?? new HttpResponse( 500, [], '' );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}
				return $next;
			}
		};
		return [ new RestBackend( $client, 'https://repo.example.com/site/', $username, 'secret', 'test-agent', 3, static function ( int $ms ): void {} ), $log ];
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
