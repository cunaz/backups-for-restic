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
$check  = new BackupsForRestic\WordPress\WpExposureCheck( $directory, $state, $random, $probe );
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
}
