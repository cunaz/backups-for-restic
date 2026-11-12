<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\TestSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real WordPress HTTP API with both of its Requests transports. */
final class HttpStreamingTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'The WordPress integration site is not running.' );
		}
	}

	#[DataProvider( 'transports' )]
	public function test_downloads_private_listings_with_both_wordpress_transports( string $transport ): void {
		$server = new RestServer();
		try {
			$backend = $server->backend( 'streaming' );
			$backend->create();
			for ( $i = 0; $i < 300; ++$i ) {
				$content = 'a listing entry ' . $i;
				$backend->save( ObjectType::Data, hash( 'sha256', $content ), $content );
			}
			$output   = TestSite::php(
				'
				add_action( "requests-requests.before_request", static function ( &$url, &$headers, &$data, &$method, &$options ) use ( $arguments ) {
					$options["transport"] = "Curl" === $arguments[2] ? WpOrg\Requests\Transport\Curl::class : WpOrg\Requests\Transport\Fsockopen::class;
				}, 10, 5 );
				// Exercise the socket transport on a chunked answer, not just HTTP/1.0 EOF.
				add_filter( "http_request_args", static function ( $args ) {
					$args["httpversion"] = "1.1";
					return $args;
				} );
				$http = new BackupsForRestic\WordPress\WpHttpClient( 15, $arguments[1] );
				$backend = new BackupsForRestic\Backend\RestBackend( $http, $arguments[0], "plugin", "rest password" );
				$directory = sys_get_temp_dir() . "/bfr-http-test-" . bin2hex( random_bytes( 4 ) );
				mkdir( $directory, 0700 );
				try {
					$backend->list_to_file( BackupsForRestic\Backend\ObjectType::Data, $directory . "/listing" );
					$result = [];
					$offset = 0;
					do {
						$batch = BackupsForRestic\Backend\ListingFile::batch( $directory . "/listing", $offset, 31 );
						foreach ( $batch["entries"] as [ $name, $size ] ) { $result[$name] = $size; }
						$offset = $batch["offset"];
					} while ( ! $batch["done"] );
					ksort( $result );
					echo "\n", json_encode( [ "entries" => $result, "mode" => fileperms( $directory . "/listing" ) & 0777 ] );
				} finally {
					BackupsForRestic\Storage\Files::remove_tree( $directory );
				}
				',
				[ $server->url( 'streaming' ), $server->certificate, $transport ]
			);
			$result   = json_decode( TestSite::last_line( $output ), true );
			$expected = $backend->list( ObjectType::Data );
			ksort( $expected );
			self::assertSame( $expected, $result['entries'] ?? null, $output );
			self::assertSame( 0600, $result['mode'] ?? null );
		} finally {
			$server->stop();
		}
	}

	public static function transports(): array {
		return [ [ 'Curl' ], [ 'Fsockopen' ] ];
	}
}
