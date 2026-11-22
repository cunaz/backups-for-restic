<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;
use PHPUnit\Framework\TestCase;

final class StorageExposureTest extends TestCase {

	protected function setUp(): void {
		if ( ! is_file( TestSite::root() . '/wp-load.php' ) ) {
			self::markTestSkipped( 'Install the disposable WordPress site first.' );
		}
	}

	private function check( string $directory, string $program ): array {
		$setup = <<<'SETUP'
require $arguments[0];
$directory = new BackupsForRestic\Storage\PrivateDirectory( $arguments[1] );
$directory->ensure();
$state  = new BackupsForRestic\Storage\SiteState( new BackupsForRestic\Tests\Support\InMemoryOptions() );
$random = new BackupsForRestic\Crypto\SystemRandom();
$probe  = new BackupsForRestic\Storage\BrowserProbe( $directory, $state, $random, new BackupsForRestic\Support\SystemClock() );
$check  = new BackupsForRestic\WordPress\WpExposureCheck( $directory, $state, $random, $probe, realpath( WP_PLUGIN_DIR . '/backups-for-restic/backups-for-restic.php' ) );
SETUP;
		return json_decode( TestSite::php( $setup . "\n" . $program, [ dirname( __DIR__, 2 ) . '/vendor/autoload.php', $directory ] ), true, flags: JSON_THROW_ON_ERROR );
	}

	public function test_cli_requires_a_current_site_observation_for_unmapped_private_storage(): void {
		$root = TempDir::create( 'document-root' );
		try {
			$results = $this->check(
				$root . '/private',
				<<<'SCENARIO'
unset( $_SERVER['DOCUMENT_ROOT'] );
$output[] = $check->permits_staging();
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$output[] = $check->outside_web_root();
unset( $_SERVER['DOCUMENT_ROOT'] );
$output[] = $check->permits_staging();
$record = $state->get( 'staging_boundary' );
$state->set( 'staging_boundary', array_merge( $record, [ 'at' => time() - 86401 ] ) );
$output[] = $check->permits_staging();
$_SERVER['DOCUMENT_ROOT'] = dirname( $arguments[1] );
$output[] = $check->permits_staging();
unset( $_SERVER['DOCUMENT_ROOT'] );
$output[] = $check->permits_staging();
echo json_encode( $output );
SCENARIO
			);
			self::assertSame( [ false, true, true, false, false, false ], $results );
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_uploads_denial_does_not_hide_exposure_at_the_content_url(): void {
		$path = TestSite::root() . '/wp-content/bfr-private-test-' . bin2hex( random_bytes( 6 ) );
		try {
			$results = $this->check(
				$path,
				<<<'SCENARIO'
add_filter( 'upload_dir', static function ( $uploads ) {
	$uploads['basedir'] = WP_CONTENT_DIR;
	$uploads['baseurl'] = 'https://static.example.test/content';
	return $uploads;
} );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $directory ) {
	$public = ! str_starts_with( $url, 'https://static.example.test/' );
	return [
		'response' => [ 'code' => $public ? 200 : 403 ],
		'body' => $public ? file_get_contents( $directory->subdirectory( 'probe' ) . '/' . basename( $url ) ) : '',
	];
}, 10, 3 );
echo json_encode( [ $check->outside_web_root(), $check->exposed( true ), $check->permits_staging() ] );
SCENARIO
			);
			self::assertSame( [ false, true, false ], $results );
		} finally {
			TempDir::remove( $path );
		}
	}

	/**
	 * What the probe makes of the site's answers, while a filter stands in for the web
	 * server in front of it for the requests that it decides.
	 *
	 * @param string $answers PHP returning, for a requested URL, null to let the real site
	 *                        answer, or the response that a firewall, CDN or other host gives.
	 * @return array{0: bool|null, 1: bool, 2: list<string>} Doctor's finding, staging decision, requested URLs.
	 */
	private function probe_behind( string $answers ): array {
		$path = TestSite::root() . '/wp-content/bfr-private-test-' . bin2hex( random_bytes( 6 ) );
		try {
			return $this->check(
				$path,
				<<<SCENARIO
\$requested = [];
\$answer    = static function ( string \$url ) { {$answers} };
add_filter( 'pre_http_request', static function ( \$pre, \$args, \$url ) use ( \$answer, &\$requested ) {
	\$requested[] = (string) preg_replace( '~/probe/[0-9a-f]{16}[.]txt\\z~', '/probe/<file>', preg_replace( '~bfr-control=[0-9a-f]{16}~', 'bfr-control=<random>', \$url ) );
	return \$answer( \$url ) ?? \$pre;
}, 10, 3 );
echo json_encode( [ \$check->exposed( true ), \$check->permits_staging(), \$requested ] );
SCENARIO
			);
		} finally {
			TempDir::remove( $path );
		}
	}

	public function test_a_refusal_counts_only_when_the_same_check_reaches_this_sites_own_files(): void {
		$control = TestSite::url() . '/wp-content/plugins/backups-for-restic/assets/css/admin.css?bfr-control=<random>';

		// The real site: its web server refuses the directory and serves the stylesheet.
		[ $exposed, $staging, $requested ] = $this->probe_behind( 'return null;' );
		self::assertFalse( $exposed );
		self::assertTrue( $staging );
		self::assertSame( $control, $requested[1] ?? null, 'The control asks for the stylesheet at the same origin, past any cache.' );
		self::assertCount( 4, $requested, 'Each check, the Doctor\'s and the staging decision\'s, confirms its own refusal.' );

		// A firewall or CDN challenge that refuses every request of the site to itself.
		[ $exposed, $staging ] = $this->probe_behind( 'return [ "response" => [ "code" => 403 ], "body" => "Access denied" ];' );
		self::assertNull( $exposed );
		self::assertFalse( $staging, 'A refusal that the control does not confirm allows no plaintext.' );

		// Another virtual host, which refuses the probe and has no such stylesheet.
		[ $exposed, $staging ] = $this->probe_behind( 'return [ "response" => [ "code" => str_contains( $url, "/probe/" ) ? 404 : 200 ], "body" => "<html>Default page</html>" ];' );
		self::assertNull( $exposed );
		self::assertFalse( $staging );

		// A challenge for the probe while a cache answers the stylesheet with other bytes.
		[ $exposed, $staging ] = $this->probe_behind( 'return str_contains( $url, "/probe/" ) ? [ "response" => [ "code" => 403 ], "body" => "" ] : [ "response" => [ "code" => 200 ], "body" => "/* minified */" ];' );
		self::assertNull( $exposed );
		self::assertFalse( $staging );

		// What the probe file's content proves needs no control.
		[ $exposed, $staging, $requested ] = $this->probe_behind( 'return str_contains( $url, "/probe/" ) ? [ "response" => [ "code" => 403 ], "body" => file_get_contents( WP_CONTENT_DIR . "/" . explode( "/wp-content/", $url )[1] ) ] : null;' );
		self::assertTrue( $exposed );
		self::assertFalse( $staging );
		self::assertSame( [], preg_grep( '~admin[.]css~', $requested ) );
	}
}
