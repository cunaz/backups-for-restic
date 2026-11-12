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
			$output = TestSite::php(
				'
				$selected = [];
				$chunked = false;
				add_action( "requests-requests.before_request", static function ( &$url, &$headers, &$data, &$method, &$options ) use ( $arguments, &$selected, &$chunked ) {
					$options["transport"] = "Curl" === $arguments[2] ? WpOrg\Requests\Transport\Curl::class : WpOrg\Requests\Transport\Fsockopen::class;
					$options["hooks"]->register( "curl.before_request", static function () use ( &$selected ) { $selected[] = "Curl"; } );
					$options["hooks"]->register( "fsockopen.before_request", static function () use ( &$selected ) { $selected[] = "Fsockopen"; } );
					$options["hooks"]->register( "fsockopen.after_request", static function ( $headers ) use ( &$chunked ) {
						$chunked = 1 === preg_match( "/^transfer-encoding:\\s*chunked\\s*$/mi", $headers );
					} );
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
				$warnings = [];
				set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
					if ( 0 !== ( error_reporting() & $severity ) ) { $warnings[] = $message; }
					return false;
				}, E_WARNING );
				try {
					$plain = $backend->list( BackupsForRestic\Backend\ObjectType::Data );
					$backend->list_to_file( BackupsForRestic\Backend\ObjectType::Data, $directory . "/listing" );
					$result = [];
					$offset = 0;
					do {
						$batch = BackupsForRestic\Backend\ListingFile::batch( $directory . "/listing", $offset, 31 );
						foreach ( $batch["entries"] as [ $name, $size ] ) { $result[$name] = $size; }
						$offset = $batch["offset"];
					} while ( ! $batch["done"] );
					ksort( $result );
					ksort( $plain );
					echo "\n", json_encode( [ "entries" => $result, "plain" => $plain, "mode" => fileperms( $directory . "/listing" ) & 0777, "transports" => $selected, "chunked" => $chunked, "warnings" => $warnings ] );
				} catch ( Throwable $error ) {
					echo "\n", json_encode( [ "error" => get_class( $error ), "message" => $error->getMessage(), "context" => $error instanceof BackupsForRestic\Exception\PluginException ? $error->context() : [], "transports" => $selected ] );
				} finally {
					restore_error_handler();
					BackupsForRestic\Storage\Files::remove_tree( $directory );
				}
				',
				// Requests' Fsockopen transport checks DNS SANs only, and its CN fallback
				// rejects the zero components of 127.0.0.1. Use the fixture's DNS SAN with
				// full peer/name verification, through the actual transport class.
				[ $server->url( 'streaming', 'localhost' ), $server->certificate, $transport ]
			);
			$result   = json_decode( TestSite::last_line( $output ), true );
			$expected = $backend->list( ObjectType::Data );
			ksort( $expected );
			self::assertIsArray( $result, $output );
			self::assertArrayNotHasKey( 'error', $result, $output );
			self::assertSame( [ $transport, $transport ], $result['transports'] ?? null, $output );
			self::assertSame( [], $result['warnings'] ?? null, $output );
			self::assertSame( 'Fsockopen' === $transport, $result['chunked'] ?? null, $output );
			self::assertSame( $expected, $result['entries'] ?? null, $output );
			self::assertSame( $expected, $result['plain'] ?? null, $output );
			self::assertSame( 0600, $result['mode'] ?? null );
		} finally {
			$server->stop();
		}
	}

	public static function transports(): array {
		return [ [ 'Curl' ], [ 'Fsockopen' ] ];
	}
}
