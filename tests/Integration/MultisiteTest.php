<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * The plugin on a multisite network (ADR 0026): it cannot be activated, neither for the
 * network nor for one site, and if it is active all the same it does nothing but say so. A
 * site administrator must never be able to back up or restore the whole network.
 *
 * Runs on the network of bin/test-site.sh install-multisite, through WP-CLI.
 */
final class MultisiteTest extends TestCase {

	private const string ACTIVE = 'update_site_option( "active_sitewide_plugins", [ "backups-for-restic/backups-for-restic.php" => time() ] );';

	private const string INACTIVE = 'update_site_option( "active_sitewide_plugins", [] );';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::has_wp_cli() || ! is_file( TestSite::network_root() . '/wp-config.php' ) ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh wp-cli and bin/test-site.sh install-multisite first.' );
		}
	}

	/**
	 * Runs WP-CLI on the network.
	 *
	 * @param list<string> $arguments Command and arguments.
	 * @return array{int, string} Exit status and output.
	 */
	private static function wp( array $arguments ): array {
		return TestSite::wp( $arguments, TestSite::network_root() );
	}

	private static function plugin_status(): string {
		[ $status, $output ] = self::wp( [ 'plugin', 'list', '--name=backups-for-restic', '--field=status' ] );
		self::assertSame( 0, $status, $output );
		return TestSite::last_line( $output );
	}

	public function test_the_network_is_a_network(): void {
		[ $status, $output ] = self::wp( [ 'eval', 'echo is_multisite() ? "network" : "single site";' ] );
		self::assertSame( 0, $status, $output );
		self::assertSame( 'network', TestSite::last_line( $output ) );
		self::assertSame( 'inactive', self::plugin_status() );
	}

	public function test_activation_is_refused_for_the_network_and_for_one_site(): void {
		foreach ( [ [ '--network' ], [] ] as $scope ) {
			[ $status, $output ] = self::wp( [ 'plugin', 'activate', 'backups-for-restic', ...$scope ] );

			self::assertNotSame( 0, $status, $output );
			self::assertStringContainsString( 'does not support multisite networks yet', $output );
			self::assertSame( 'inactive', self::plugin_status(), 'The refusal comes before WordPress records the activation.' );
		}
		[ $status, $output ] = self::wp( [ 'cron', 'event', 'list', '--fields=hook', '--format=csv' ] );
		self::assertSame( 0, $status, $output );
		self::assertStringNotContainsString( 'backups_for_restic', $output );
	}

	public function test_a_plugin_that_is_active_all_the_same_does_nothing_but_say_so(): void {
		try {
			[ $status, $output ] = self::wp( [ 'eval', self::ACTIVE ] );
			self::assertSame( 0, $status, $output );
			self::assertSame( 'active-network', self::plugin_status() );

			$program             = '
				$routes = array_values( array_filter( array_keys( rest_get_server()->get_routes() ), static fn ( $route ) => str_contains( $route, "backups-for-restic" ) ) );
				ob_start();
				do_action( "network_admin_notices" );
				$notice = ob_get_clean();
				echo "\n", json_encode( [ "routes" => $routes, "heartbeat" => wp_next_scheduled( "backups_for_restic_heartbeat" ), "notice" => $notice, "container" => has_action( "backups_for_restic_tick" ) ] );
			';
			[ $status, $output ] = self::wp( [ '--user=admin', 'eval', $program ] );
			self::assertSame( 0, $status, $output );
			$seen = json_decode( TestSite::last_line( $output ), true );
			self::assertIsArray( $seen, $output );
			self::assertSame( [], $seen['routes'], 'No REST route.' );
			self::assertFalse( $seen['heartbeat'], 'No cron event.' );
			self::assertFalse( $seen['container'], 'No tick handler.' );
			self::assertStringContainsString( 'inactive because multisite networks are not supported yet', $seen['notice'] );

			// Asked after WordPress loaded; a command that exists but fails would answer 0 here.
			[ $status, $output ] = self::wp( [ 'cli', 'has-command', 'backups-for-restic' ] );
			self::assertSame( 1, $status, 'No WP-CLI command: ' . $output );
			[ $status, $output ] = self::wp( [ 'cli', 'has-command', 'plugin' ] );
			self::assertSame( 0, $status, 'WP-CLI itself works here: ' . $output );
		} finally {
			self::wp( [ 'eval', self::INACTIVE ] );
		}
		self::assertSame( 'inactive', self::plugin_status() );
	}
}
