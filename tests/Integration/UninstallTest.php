<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;
use BackupsForRestic\Tests\Support\TempDir;

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

	public function test_deleting_the_plugin_is_refused_with_the_name_of_a_stored_job_that_cannot_be_read(): void {
		// Newer than every other job of the site, so that the uninstall meets them first.
		$inaccessible = '20361126T150000Z-0c3001';
		$unreadable   = '20361126T150000Z-0c3002';
		TestSite::php(
			'
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
			$container->private_dir()->ensure();
			$backup              = new BackupsForRestic\Jobs\Job( $arguments[0], BackupsForRestic\Jobs\JobType::Backup, "cli", time() - 120 );
			$backup->state       = BackupsForRestic\Jobs\JobState::Succeeded;
			$backup->finished_at = time() - 60;
			$container->jobs()->save( $backup );
			',
			[ $inaccessible ]
		);
		$folder = TestSite::private_dir() . '/jobs/' . $inaccessible;
		chmod( $folder, 0600 );
		$stored = self::stored();
		$delete = static fn (): string => TestSite::last_line(
			TestSite::php(
				'
				require_once ABSPATH . "wp-admin/includes/plugin.php";
				add_filter( "wp_die_handler", static fn () => static function ( $message ) {
					throw new RuntimeException( "refused: " . ( is_string( $message ) ? $message : "" ) );
				} );
				try {
					uninstall_plugin( $arguments[0] );
					echo "\nreturned";
				} catch ( RuntimeException $e ) {
					echo "\n", $e->getMessage();
				}
				',
				[ TestSite::PLUGIN ]
			)
		);
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			// Before: "Restore cleanup could not finish. Check the job history, database connection
			// and DROP permission.", which names neither the job nor what keeps it from being read.
			$refusal = $delete();
			self::assertStringStartsWith( 'refused: Backups for restic cannot be deleted yet: the PHP user cannot open the stored job ' . $inaccessible . '.', $refusal );
			self::assertStringContainsString( 'Give the PHP user back ownership of that folder', $refusal );
			self::assertStringContainsString( 'Where they cannot be put back, move what is in their place out of jobs/ once the job cannot be running any more, and keep that copy.', $refusal );
			self::assertStringEndsWith( 'Then retry deletion.', $refusal );
			chmod( $folder, 0700 );
			mkdir( TestSite::private_dir() . '/jobs/' . $unreadable, 0700 );
			file_put_contents( TestSite::private_dir() . '/jobs/' . $unreadable . '/job.json', '{' );
			self::assertSame( 'refused: Backups for restic cannot be deleted yet: this version cannot read the stored job ' . $unreadable . '. A newer version of the plugin may have written it: install that version, let it finish the job&#039;s cleanup, then retry deletion. If no version reads it, its document is damaged: once the job cannot be running any more, move its folder out of jobs/ in the private working directory, keep that copy, then retry deletion.', $delete() );
			self::assertSame( $stored, self::stored(), 'Options, transients and cron events stay.' );
		} finally {
			chmod( $folder, 0700 );
			foreach ( [ $inaccessible, $unreadable ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	#[DependsExternal( WordPressSiteTest::class, 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_deleting_the_plugin_on_an_older_php_is_refused_and_removes_nothing(): void {
		$copy = TempDir::create( 'uninstall-older-php' );
		try {
			// The branch for a PHP before 8.3, as such a PHP runs it: the main file has kept the
			// plugin inert, and WordPress deletes the plugin once this file returns.
			$source = str_replace( 'PHP_VERSION', "'8.2.29'", (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ) );
			file_put_contents( $copy . '/uninstall.php', $source );
			$stored = self::stored();
			$output = TestSite::php(
				'
				define( "WP_UNINSTALL_PLUGIN", "backups-for-restic/backups-for-restic.php" );
				add_filter( "wp_die_handler", static fn () => static function ( $message ) {
					throw new RuntimeException( "refused: " . ( is_string( $message ) ? $message : "" ) );
				} );
				try {
					include $arguments[0];
					echo "\nreturned";
				} catch ( RuntimeException $e ) {
					echo "\n", $e->getMessage();
				}
				',
				[ $copy . '/uninstall.php' ]
			);
			self::assertStringStartsWith( 'refused: Switch back to PHP 8.3 or later before deleting', TestSite::last_line( $output ), $output );
			self::assertSame( $stored, self::stored(), 'Options, transients and cron events stay.' );
			self::assertDirectoryExists( TestSite::private_dir() . '/jobs' );
		} finally {
			TempDir::remove( $copy );
		}
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
		// A staged restore that nobody removed: uninstalling takes it along.
		$staged = TestSite::private_dir() . '/restores/20270115T030000Z-abcdef/files/var/www';
		self::assertTrue( is_dir( $staged ) || mkdir( $staged, 0700, true ) );
		file_put_contents( $staged . '/index.php', '<?php // A restored file.' );

		$foreign = TempDir::create( 'uninstall-foreign' );
		mkdir( $foreign . '/jobs' );
		file_put_contents( $foreign . '/jobs/owner.txt', 'another application' );
		$unknown = TestSite::private_dir() . '/jobs/20370115T030000Z-ffffff';
		mkdir( $unknown );
		file_put_contents( $unknown . '/job.json', '{"schema":9999}' );
		try {
			// The previous directory still holds job ownership, even after a filter moves new jobs.
			$blocked = TestSite::php(
				'
				require_once ABSPATH . "wp-admin/includes/plugin.php";
				add_filter( "backups_for_restic_private_dir", static fn () => $arguments[1], PHP_INT_MAX );
				add_filter( "wp_die_handler", static fn () => static function () { throw new RuntimeException( "cleanup-deferred" ); } );
				try { uninstall_plugin( $arguments[0] ); } catch ( RuntimeException $e ) { echo $e->getMessage(); }
				',
				[ TestSite::PLUGIN, $foreign ]
			);
			self::assertStringContainsString( 'cleanup-deferred', $blocked );
			self::assertFileExists( $unknown . '/job.json' );
			self::assertContains( 'backups_for_restic_secrets', self::stored() );
			unlink( $unknown . '/job.json' );
			rmdir( $unknown );

			// The previous directory owns a real private InnoDB table from an interrupted rehearsal.
			$ledger = TestSite::last_line(
				TestSite::php(
					'
					global $wpdb;
					$store = (new BackupsForRestic\WordPress\Container(WP_PLUGIN_DIR . "/" . $arguments[0]))->jobs();
					$sql = new BackupsForRestic\Database\Restore\MysqliConnection($wpdb->dbh);
					$token = bin2hex(random_bytes(16));
					$digest = hash("sha256", $token);
					$owned = new BackupsForRestic\Database\Restore\OwnedTables($sql, $wpdb->prefix, $digest, $token);
					$binding = ["site" => home_url(), "path" => "/database", "prefix" => $wpdb->prefix,
						"fingerprint" => $digest, "count" => 0, "token" => $token];
					$job = $store->create(BackupsForRestic\Jobs\JobType::Restore, "cli", time(), ["database_binding" => $binding]);
					$job->state = BackupsForRestic\Jobs\JobState::Failed;
					$store->save($job);
					$sql->execute("CREATE TABLE " . $owned->progress() . " (id INT) ENGINE=InnoDB COMMENT=" .
						BackupsForRestic\Database\Dump\SqlFormatter::value($owned->owner, BackupsForRestic\Database\Dump\SqlFormatter::TEXT));
					echo "\n", $owned->progress();
					',
					[ TestSite::PLUGIN ]
				)
			);
			self::assertMatchesRegularExpression( '/\A_bfr_r_[0-9a-f]{32}_p\z/', $ledger );

			// What the Plugins screen does on "Delete", without removing the plugin's own files.
			TestSite::php(
				'
				require_once ABSPATH . "wp-admin/includes/plugin.php";
				deactivate_plugins( $arguments[0] );
				add_filter( "backups_for_restic_private_dir", static fn () => $arguments[1], PHP_INT_MAX );
				uninstall_plugin( $arguments[0] );
				',
				[ TestSite::PLUGIN, $foreign ]
			);

			self::assertSame( [], self::stored(), 'No option, transient or cron event of the plugin is left.' );
			self::assertDirectoryDoesNotExist( TestSite::private_dir(), 'The private directory held nothing but the plugin\'s files.' );
			self::assertSame( [ '.', '..', 'jobs' ], scandir( $foreign ) );
			self::assertSame( 'another application', file_get_contents( $foreign . '/jobs/owner.txt' ) );
			self::assertSame(
				'0',
				TestSite::last_line( TestSite::php( 'global $wpdb; echo "\n", $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $arguments[0]));', [ $ledger ] ) )
			);
			self::assertSame( $snapshots, TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] ), 'The repository is not touched.' );
			self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
		} finally {
			TempDir::remove( $foreign );
			TempDir::remove( $unknown );
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
