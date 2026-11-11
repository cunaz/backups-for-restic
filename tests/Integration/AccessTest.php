<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * Who may use the plugin's REST API: administrators, and nobody else. Every route is asked
 * without credentials and as a user who may edit posts but not manage the site; none of them
 * may answer, whatever the site's state is.
 */
final class AccessTest extends TestCase {

	private const string JOB = '20270115T030000Z-abcdef';

	private static string $editor = '';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
		self::$editor = TestSite::user( 'bfr-editor', 'editor' );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function routes(): iterable {
		$routes = [
			[ 'GET', '/status' ],
			[ 'GET', '/environment' ],
			[ 'POST', '/environment/probe' ],
			[ 'POST', '/environment/probe/report' ],
			[ 'GET', '/jobs' ],
			[ 'POST', '/jobs' ],
			[ 'GET', '/jobs/' . self::JOB ],
			[ 'POST', '/jobs/' . self::JOB . '/cancel' ],
			[ 'GET', '/jobs/' . self::JOB . '/log' ],
			[ 'GET', '/snapshots' ],
			[ 'GET', '/settings' ],
			[ 'PUT', '/settings' ],
			[ 'DELETE', '/cache' ],
			[ 'POST', '/repository/test' ],
			[ 'POST', '/repository/save' ],
			[ 'POST', '/repository/create' ],
			[ 'POST', '/repository/connect' ],
			[ 'POST', '/repository/ca-certificate' ],
			[ 'DELETE', '/repository/ca-certificate' ],
			[ 'POST', '/setup/complete' ],
			[ 'POST', '/recovery-kit' ],
		];
		foreach ( $routes as [ $method, $route ] ) {
			yield $method . ' ' . $route => [ $method, $route ];
		}
	}

	#[DataProvider( 'routes' )]
	public function test_a_route_answers_nobody_without_credentials( string $method, string $route ): void {
		[ $status, $error, $raw ] = TestSite::api( $method, $route, 'GET' === $method ? null : [], '' );

		self::assertSame( 401, $status, $raw );
		self::assertSame( 'rest_forbidden', $error['code'] ?? null, $raw );
	}

	#[DataProvider( 'routes' )]
	public function test_a_route_answers_no_user_who_cannot_manage_the_site( string $method, string $route ): void {
		[ $status, $error, $raw ] = TestSite::api( $method, $route, 'GET' === $method ? null : [], self::$editor );

		self::assertSame( 403, $status, $raw );
		self::assertSame( 'rest_forbidden', $error['code'] ?? null, $raw );
	}

	public function test_the_list_of_routes_is_complete(): void {
		[ $status, $index, $raw ] = TestSite::api( 'GET', '' );
		self::assertSame( 200, $status, $raw );

		$registered = [];
		foreach ( $index['routes'] ?? [] as $route => $description ) {
			$route = (string) preg_replace( '~\(\?P<id>[^)]*\)~', self::JOB, substr( (string) $route, strlen( '/backups-for-restic/v1' ) ) );
			foreach ( $description['methods'] ?? [] as $method ) {
				if ( '' !== $route ) {
					$registered[] = $method . ' ' . $route;
				}
			}
		}
		sort( $registered );
		$tested = array_keys( iterator_to_array( self::routes() ) );
		sort( $tested );
		self::assertSame( $registered, $tested, 'A route that this test does not know is not tested for who may call it.' );
	}

	public function test_the_private_directory_is_not_served(): void {
		// Any request of an administrator creates the directory with its deny files.
		[ $status, , $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );

		foreach ( [ '/', '/index.php', '/.htaccess', '/web.config', '/jobs/', '/cache/' ] as $path ) {
			self::assertFileExists( TestSite::private_dir() . $path );
			$handle = curl_init( TestSite::URL . '/wp-content/' . PrivateDirectory::NAME . $path );
			curl_setopt_array(
				$handle,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 10,
				]
			);
			curl_exec( $handle );
			self::assertSame( 403, curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ), $path );
		}
	}
}
