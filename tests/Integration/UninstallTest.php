<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * Deleting the plugin from the Plugins screen: everything it stored on the site goes, and
 * the repository stays as it is (FR-15). Runs last, because nothing of the setup is left
 * afterwards.
 */
final class UninstallTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	/**
	 * What the site's database holds of the plugin: its options, its transients and its cron events.
	 *
	 * @return list<string> Names.
	 */
	private static function stored(): array {
		$printed = TestSite::php(
			'
			global $wpdb;
			$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \x27%backups\\\\_for\\\\_restic%\x27 ORDER BY option_name" );
			foreach ( [ "backups_for_restic_heartbeat", "backups_for_restic_tick" ] as $hook ) {
				if ( false !== wp_next_scheduled( $hook ) ) {
					$names[] = "cron:" . $hook;
				}
			}
			echo "\n", json_encode( $names );
			'
		);
		$names   = json_decode( TestSite::last_line( $printed ), true );
		self::assertIsArray( $names, $printed );
		return array_values( array_map( 'strval', $names ) );
	}

	#[DependsExternal( WordPressSiteTest::class, 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_uninstalling_removes_what_the_plugin_stored_and_leaves_the_repository(): void {
		TestSite::idle( 600 );
		$snapshots = TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] );
		$stored    = self::stored();
		foreach ( [ 'backups_for_restic_settings', 'backups_for_restic_secrets', 'backups_for_restic_state', 'cron:backups_for_restic_heartbeat' ] as $name ) {
			self::assertContains( $name, $stored );
		}
		self::assertDirectoryExists( TestSite::private_dir() . '/jobs' );

		try {
			// What the Plugins screen does on "Delete", without removing the plugin's own files.
			TestSite::php(
				'
				require_once ABSPATH . "wp-admin/includes/plugin.php";
				deactivate_plugins( $arguments[0] );
				uninstall_plugin( $arguments[0] );
				',
				[ TestSite::PLUGIN ]
			);

			self::assertSame( [], self::stored(), 'No option, transient or cron event of the plugin is left.' );
			self::assertDirectoryDoesNotExist( TestSite::private_dir(), 'The private directory held nothing but the plugin\'s files.' );
			self::assertSame( $snapshots, TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] ), 'The repository is not touched.' );
			self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
		} finally {
			TestSite::plugin( true );
		}

		// Activated again, the plugin starts from nothing.
		[ $status, $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
		self::assertFalse( $dashboard['setup_completed'] ?? null, $raw );
		self::assertSame( '', $dashboard['repository']['host'] ?? null, $raw );
		$stored = self::stored();
		self::assertNotContains( 'backups_for_restic_secrets', $stored, 'No secret comes back by itself.' );
		self::assertNotContains( 'backups_for_restic_settings', $stored, 'Settings are stored when something is saved.' );
	}
}
