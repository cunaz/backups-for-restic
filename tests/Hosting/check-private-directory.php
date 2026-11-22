<?php
/**
 * Exercise the packaged WordPress HTTP probe against a temporary static document root.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Storage\BrowserProbe;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\WordPress\WpExposureCheck;

[ , $site, $package, $content, $url, $expected ] = $argv;
define( 'WP_CONTENT_DIR', $content );
define( 'WP_CONTENT_URL', $url );
define( 'DISABLE_WP_CRON', true );
require $site . '/wp-load.php';
require $package . '/src/autoload.php';
require dirname( __DIR__ ) . '/Support/InMemoryOptions.php';

$directory = new PrivateDirectory( WP_CONTENT_DIR . '/backups-for-restic' );
$directory->ensure();
// The plugin's stylesheet at its place in the document root: a refusal of the probe counts
// only when the same check gets it back from the same server.
$plugin  = WP_PLUGIN_DIR . '/backups-for-restic';
$control = $plugin . '/' . WpExposureCheck::CONTROL;
if ( ! is_dir( dirname( $control ) ) && ! mkdir( dirname( $control ), 0755, true ) ) {
	throw new RuntimeException( 'The control file could not be placed in the document root.' );
}
copy( $package . '/' . WpExposureCheck::CONTROL, $control );
$state   = new SiteState( new InMemoryOptions() );
$random  = new SystemRandom();
$browser = new BrowserProbe( $directory, $state, $random, new SystemClock() );
$probe   = new WpExposureCheck( $directory, $state, $random, $browser, $plugin . '/backups-for-restic.php' );
$exposed = $probe->exposed( true );
$staging = $probe->permits_staging();

if ( $exposed !== ( 'exposed' === $expected ) || $staging !== ( 'protected' === $expected ) ) {
	throw new RuntimeException( sprintf( 'Expected %s, got exposed=%s, permits_staging=%s.', $expected, var_export( $exposed, true ), var_export( $staging, true ) ) );
}
if ( [] !== glob( $directory->subdirectory( 'probe' ) . '/*' ) ) {
	throw new RuntimeException( 'The exposure check left a probe file in public storage.' );
}
