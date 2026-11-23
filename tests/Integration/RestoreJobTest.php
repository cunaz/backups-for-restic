<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\StagedTree;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * The restore job on a real site (ADR 0037): started through the REST API, driven by cron,
 * it puts a directory of a snapshot into its staging directory. Runs on the site and
 * repository that WordPressSiteTest set up.
 */
final class RestoreJobTest extends TestCase {

	private const string SETUP = 'test_setup_and_backup_through_wp_cron_restore_with_restic';

	private const string FILE = 'restore-job.bin';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	public static function tearDownAfterClass(): void {
		if ( TestSite::available() ) {
			TestSite::remove_upload( self::FILE );
			// Staging expires after a day; the tests remove at once what they staged.
			foreach ( (array) glob( TestSite::private_dir() . '/restores/*', GLOB_ONLYDIR ) as $staging ) {
				TempDir::remove( (string) $staging );
			}
		}
	}

	/**
	 * The directory of the uploads fixture, as snapshots name it.
	 */
	private static function fixture(): string {
		return TestSite::root() . '/wp-content/uploads/fixture';
	}

	/**
	 * IDs of the jobs that the list shows.
	 *
	 * @return list<string>
	 */
	private static function known(): array {
		[ $status, $list, $raw ] = TestSite::api( 'GET', '/jobs' );
		self::assertSame( 200, $status, $raw );
		return array_values( array_map( 'strval', array_column( $list['jobs'] ?? [], 'id' ) ) );
	}

	/**
	 * Backs up the site with one more file in the uploads fixture.
	 *
	 * @return string ID of the snapshot.
	 */
	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_backup_for_the_restore(): string {
		TestSite::idle( 600 );
		TestSite::add_upload( self::FILE, 8 );
		$job = TestSite::wait_for( TestSite::start(), 1800 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		TestSite::idle( 600 );
		$snapshot = (string) ( $job['result']['snapshot'] ?? '' );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $snapshot );
		return $snapshot;
	}

	#[Depends( 'test_a_backup_for_the_restore' )]
	public function test_invalid_restore_requests_are_refused_with_a_code( string $snapshot ): void {
		$requests = [
			'backups_for_restic_invalid_snapshot'      => [
				[ 'type' => 'restore' ],
				[
					'type'     => 'restore',
					'snapshot' => substr( $snapshot, 0, 8 ),
				],
				[
					'type'     => 'restore',
					'snapshot' => '../' . substr( $snapshot, 3 ),
				],
			],
			'backups_for_restic_invalid_fresh_install' => [
				[
					'type'          => 'restore',
					'snapshot'      => $snapshot,
					'fresh_install' => true,
				],
				[
					'type'             => 'restore',
					'snapshot'         => $snapshot,
					'fresh_install'    => true,
					'database_preview' => true,
					'paths'            => [ '/' ],
				],
			],
			'backups_for_restic_invalid_paths'         => [
				[
					'type'     => 'restore',
					'snapshot' => $snapshot,
					'paths'    => 'wp-content',
				],
				[
					'type'     => 'restore',
					'snapshot' => $snapshot,
					'paths'    => [],
				],
				[
					'type'     => 'restore',
					'snapshot' => $snapshot,
					'paths'    => [ self::fixture() . '/../../../wp-config.php' ],
				],
			],
		];
		$known    = self::known();
		foreach ( $requests as $code => $bodies ) {
			foreach ( $bodies as $body ) {
				[ $status, $error, $raw ] = TestSite::api( 'POST', '/jobs', $body );
				self::assertSame( 400, $status, $raw );
				self::assertSame( $code, $error['code'] ?? null, $raw );
			}
		}
		self::assertSame( $known, self::known(), 'A refused request creates no job.' );
	}

	#[Depends( 'test_a_backup_for_the_restore' )]
	public function test_a_restore_job_stages_one_directory_with_identical_bytes( string $snapshot ): void {
		TestSite::idle( 600 );
		[ $status, $started, $raw ] = TestSite::api(
			'POST',
			'/jobs',
			[
				'type'     => 'restore',
				'snapshot' => $snapshot,
				'paths'    => [ self::fixture() ],
			]
		);
		self::assertSame( 201, $status, $raw );
		self::assertSame( 'restore', $started['job']['type'] ?? null, $raw );
		$job = TestSite::wait_for( (string) $started['job']['id'], 1800 );

		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		self::assertSame( 'restores/' . $job['id'], $job['result']['staging'] ?? null );
		self::assertSame( 100, $job['progress']['percent'] ?? null );
		$staging = TestSite::private_dir() . '/restores/' . $job['id'];
		// Staging is owner-only; StagedTree takes the modes from the report and checks that.
		self::assertSame( TempDir::describe( self::fixture() ), StagedTree::describe( $staging . '/files', self::fixture(), $staging ) );
		self::assertFileEquals( self::fixture() . '/' . self::FILE, $staging . '/files' . self::fixture() . '/' . self::FILE );
		self::assertGreaterThan( 0, $job['result']['files'] ?? 0 );
		self::assertFileExists( $staging . '/entries.jsonl' );
		self::assertFileDoesNotExist( $staging . '/files' . TestSite::root() . '/wp-config.php', 'Only the selected directory is staged.' );
		self::assertDirectoryDoesNotExist( TestSite::private_dir() . '/jobs/' . $job['id'] . '/restore' );
		// A heartbeat may have started the forget that follows a backup; it holds a lock of its own.
		TestSite::idle( 600 );
		self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ), 'The restore released its lock.' );

		// What is staged is as private as the rest of the directory.
		$handle = curl_init( TestSite::url() . '/wp-content/' . PrivateDirectory::NAME . '/restores/' . $job['id'] . '/entries.jsonl' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 10,
			]
		);
		curl_exec( $handle );
		self::assertSame( 403, curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) );
	}

	/**
	 * Rehearses the database of the backup.
	 *
	 * @return string ID of the restore job, whose staging the review reads.
	 */
	#[Depends( 'test_a_backup_for_the_restore' )]
	public function test_a_database_preview_verifies_the_dump_and_removes_its_private_tables( string $snapshot ): string {
		TestSite::idle( 600 );
		$db = MysqliConnection::connect( MysqliConnection::environment( 'BFR_DB_NAME', 'bfr_site' ) );
		self::assertNotNull( $db );
		$before                     = $db->rows( 'SELECT ID, post_title FROM wp_posts ORDER BY ID' );
		[ $status, $started, $raw ] = TestSite::api(
			'POST',
			'/jobs',
			[
				'type'             => 'restore',
				'snapshot'         => $snapshot,
				'paths'            => [ TestSite::private_dir() . '/spool/database' ],
				'database_preview' => true,
			]
		);
		self::assertSame( 201, $status, $raw );
		$job = TestSite::wait_for( (string) $started['job']['id'], 1800 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		self::assertGreaterThan( 0, $job['result']['database_tables'] );
		self::assertGreaterThan( 0, $job['result']['database_rows'] );
		$report = json_decode( (string) file_get_contents( TestSite::private_dir() . '/' . $job['result']['database_report'] ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( $snapshot, $report['snapshot'] );
		self::assertContains( 'wp_options', array_column( $report['tables'], 'name' ) );
		self::assertSame( [], $db->rows( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_COMMENT LIKE 'backups-for-restic-restore/1:%'" ) );
		self::assertSame( $before, $db->rows( 'SELECT ID, post_title FROM wp_posts ORDER BY ID' ) );
		// The report holds the backup's role map and active plugins for the review, as stored.
		$roles = TestSite::php( 'echo serialize( get_option( "wp_user_roles" ) );' );
		self::assertSame( TestSite::last_line( $roles ), base64_decode( (string) $report['wordpress_state']['user_roles'], true ) );
		self::assertContains( 'backups-for-restic/backups-for-restic.php', unserialize( (string) base64_decode( (string) $report['wordpress_state']['active_plugins'], true ) ) );
		return (string) $started['job']['id'];
	}

	/**
	 * The review of the rehearsed database says what the exchange keeps from the site and how
	 * the backup differs from it, here as in the finding: a capability added to the subscriber
	 * role and a plugin activated after the backup. Nothing is applied.
	 *
	 * @param string $restore Restore job with the rehearsal.
	 */
	#[Depends( 'test_a_database_preview_verifies_the_dump_and_removes_its_private_tables' )]
	public function test_the_database_review_shows_the_roles_and_plugins_that_the_backup_brings_back( string $restore ): void {
		$directory = TestSite::root() . '/wp-content/plugins/bfr-activated-later';
		$later     = 'bfr-activated-later/bfr-activated-later.php';
		mkdir( $directory );
		file_put_contents( $directory . '/bfr-activated-later.php', "<?php\n/**\n * Plugin Name: Activated after the backup\n */\n" );
		$change = '
			require_once ABSPATH . "wp-admin/includes/plugin.php";
			if ( "1" === $arguments[1] ) {
				$activated = activate_plugin( $arguments[0] );
				if ( is_wp_error( $activated ) ) {
					fwrite( STDERR, $activated->get_error_message() );
					exit( 1 );
				}
				get_role( "subscriber" )->add_cap( "manage_options" );
				get_role( "subscriber" )->add_cap( "edit_users" );
			} else {
				deactivate_plugins( $arguments[0], true );
				get_role( "subscriber" )->remove_cap( "manage_options" );
				get_role( "subscriber" )->remove_cap( "edit_users" );
			}
		';
		try {
			TestSite::php( $change, [ $later, '1' ] );
			[ $status, $review, $raw ] = TestSite::api( 'POST', '/restores/' . $restore . '/database/plan' );
			self::assertSame( 200, $status, $raw );
			self::assertArrayNotHasKey( 'preserve_admin', $review );
			self::assertSame( [ 'site_address', 'database_version', 'cron_lock', 'plugin_data', 'administrator' ], $review['kept'] );
			self::assertSame( [ 'roles', 'active_plugins', 'scheduled_events' ], $review['restored'] );
			self::assertSame(
				[
					'entry' => 'backups-for-restic/backups-for-restic.php',
					'added' => false,
				],
				$review['plugin']
			);
			self::assertContains(
				[
					'plugin' => $later,
					'file'   => true,
				],
				$review['plugins']['deactivated']['entries']
			);
			$subscriber = array_values( array_filter( $review['roles']['entries'], static fn ( array $role ): bool => 'subscriber' === $role['role'] ) );
			self::assertCount( 1, $subscriber, $raw );
			self::assertSame( 'changed', $subscriber[0]['change'] );
			self::assertSame( [ 'edit_users', 'manage_options' ], $subscriber[0]['removed']['entries'] );
			self::assertSame( [], $subscriber[0]['denials_added']['entries'] );
			self::assertTrue( $review['roles']['readable'] );
			self::assertTrue( $review['plugins']['readable'] );
			self::assertSame( [], $review['missing_capabilities'] );
			self::assertNotContains( 'database_admin_capabilities', $review['blockers'] );
			self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $review['digest'] );
			// A role map that WordPress reads, with a field that it never writes: the review does
			// not compare it, lists every role of the backup, and does not refuse for it.
			$saved = TestSite::last_line( TestSite::php( 'echo base64_encode( serialize( get_option( "wp_user_roles" ) ) );' ) );
			try {
				TestSite::php( '$roles = get_option( "wp_user_roles" ); $roles["subscriber"]["note"] = "x"; update_option( "wp_user_roles", $roles );' );
				self::assertSame( 'yes', TestSite::last_line( TestSite::php( 'echo get_role( "subscriber" )->has_cap( "manage_options" ) ? "yes" : "no";' ) ), 'WordPress reads the role map.' );
				[ $status, $unread, $raw ] = TestSite::api( 'POST', '/restores/' . $restore . '/database/plan' );
				self::assertSame( 200, $status, $raw );
				self::assertFalse( $unread['roles']['readable'] );
				self::assertNotContains( 'database_roles_invalid', $unread['blockers'] );
				self::assertContains( 'subscriber', array_column( $unread['roles']['entries'], 'role' ) );
				self::assertSame( [ 'added' ], array_values( array_unique( array_column( $unread['roles']['entries'], 'change' ) ) ) );
				self::assertNotSame( $review['digest'], $unread['digest'] );
			} finally {
				TestSite::php( 'update_option( "wp_user_roles", unserialize( base64_decode( $arguments[0] ) ) );', [ $saved ] );
			}
		} finally {
			TestSite::php( $change, [ $later, '0' ] );
			unlink( $directory . '/bfr-activated-later.php' );
			rmdir( $directory );
		}
	}

	/**
	 * After an exchange the generation that is live keeps its own scheduled events. WordPress
	 * reads them from the database again, and the heartbeat is scheduled if they lack it, as the
	 * events of a backup made before this plugin was installed do. The same holds for roles.
	 */
	public function test_after_an_exchange_wordpress_reads_the_live_events_and_roles_and_schedules_the_heartbeat(): void {
		$printed = TestSite::php(
			'
			global $wpdb;
			$read   = static fn ( string $name ) => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
			$write  = static fn ( string $name, string $value ) => $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s", $value, $name ) );
			$hook   = \BackupsForRestic\WordPress\Cron::HEARTBEAT;
			$events = (string) $read( "cron" );
			$roles  = (string) $read( $wpdb->prefix . "user_roles" );
			$before = false !== wp_next_scheduled( $hook ) && wp_roles()->is_role( "administrator" );
			// RENAME TABLE replaces the tables under WordPress, whose cache keeps the old values:
			// the events of a backup without this plugin, and roles with one more role.
			$write( "cron", serialize( [ time() + 3600 => [ "bfr_backup_event" => [ md5( serialize( [] ) ) => [ "schedule" => false, "args" => [] ] ] ], "version" => 2 ] ) );
			$write( $wpdb->prefix . "user_roles", serialize( get_option( $wpdb->prefix . "user_roles" ) + [ "bfr_restored" => [ "name" => "Restored", "capabilities" => [ "read" => true ] ] ] ) );
			$cached = false !== wp_next_scheduled( $hook ) && ! wp_roles()->is_role( "bfr_restored" );
			\BackupsForRestic\WordPress\Container::after_exchange();
			$hooks = [];
			foreach ( (array) maybe_unserialize( (string) $read( "cron" ) ) as $at ) {
				$hooks = array_merge( $hooks, is_array( $at ) ? array_keys( $at ) : [] );
			}
			$after = [ wp_roles()->is_role( "bfr_restored" ), false !== wp_next_scheduled( $hook ) ];
			$write( "cron", $events );
			$write( $wpdb->prefix . "user_roles", $roles );
			wp_cache_flush();
			echo json_encode( [ "before" => $before, "cached" => $cached, "after" => $after, "stored" => $hooks ] );
			'
		);
		$result  = json_decode( TestSite::last_line( $printed ), true, flags: JSON_THROW_ON_ERROR );
		self::assertTrue( $result['before'], $printed );
		self::assertTrue( $result['cached'], 'Until the refresh, the request still has the replaced values.' );
		self::assertSame( [ true, true ], $result['after'] );
		self::assertEqualsCanonicalizing( [ 'bfr_backup_event', 'backups_for_restic_heartbeat' ], $result['stored'] );
	}

	#[Depends( 'test_a_backup_for_the_restore' )]
	public function test_a_schedule_slot_waits_while_a_restore_is_active( string $snapshot ): void {
		TestSite::idle( 600 );
		[ , $settings ] = TestSite::api( 'GET', '/settings' );
		$before         = [
			'schedule.backup.frequency'  => (string) ( $settings['values']['schedule.backup.frequency'] ?? 'daily' ),
			'advanced.loopback_chaining' => (string) ( $settings['values']['advanced.loopback_chaining'] ?? 'auto' ),
		];
		// Without chaining a new job schedules no tick event and takes no cron lock: nothing but
		// a heartbeat advances it, and a heartbeat looks at the schedule before it does.
		[ $status, , $raw ] = TestSite::api(
			'PUT',
			'/settings',
			[
				'values' => [
					'schedule.backup.frequency'  => 'daily',
					'advanced.loopback_chaining' => 'off',
				],
			]
		);
		self::assertSame( 200, $status, $raw );
		try {
			[ $status, $started, $raw ] = TestSite::api(
				'POST',
				'/jobs',
				[
					'type'     => 'restore',
					'snapshot' => $snapshot,
					'paths'    => [ self::fixture() ],
				]
			);
			self::assertSame( 201, $status, $raw );
			$restore = (string) $started['job']['id'];
			$known   = self::known();
			// As if the last backup that the schedule started was long ago.
			TestSite::schedule_state( [ 'backup_slot' => 1 ] );

			// One heartbeat, made due once: the event is not due again for five minutes after
			// it ran, so what follows describes exactly this heartbeat.
			TestSite::php( 'wp_clear_scheduled_hook( "backups_for_restic_heartbeat" ); wp_schedule_event( time() - 1, "backups_for_restic_five_minutes", "backups_for_restic_heartbeat" );' );
			$deadline = time() + 180;
			do {
				TestSite::cron();
				$job = TestSite::job( $restore );
				sleep( 1 );
			} while ( 'queued' === ( $job['state'] ?? '' ) && time() < $deadline );
			self::assertNotSame( 'queued', $job['state'] ?? '', 'The heartbeat found the restore and advanced it.' );

			// It met the restore as an active job when it looked at the schedule, and started nothing.
			self::assertSame( 1, TestSite::schedule_state()['backup_slot'] ?? null, 'The slot stays unhandled while the restore is active.' );
			self::assertSame( [], array_values( array_diff( self::known(), $known ) ), 'No job started beside the restore.' );

			// Later heartbeats end the restore; the first one that finds no active job starts the
			// backup of the slot that is still due, and the ones after it advance that backup.
			$backup   = null;
			$deadline = time() + 1800;
			do {
				TestSite::heartbeat();
				[ , $list ] = TestSite::api( 'GET', '/jobs' );
				foreach ( $list['jobs'] ?? [] as $candidate ) {
					if ( is_array( $candidate ) && 'backup' === ( $candidate['type'] ?? '' ) && ! in_array( $candidate['id'] ?? '', $known, true ) && ! in_array( $candidate['state'] ?? '', [ 'queued', 'running' ], true ) ) {
						$backup = $candidate;
					}
				}
				sleep( 1 );
			} while ( null === $backup && time() < $deadline );
			self::assertNotNull( $backup, 'The heartbeats started no backup that ended; handled slots: ' . (string) json_encode( TestSite::schedule_state() ) );
			$job = TestSite::job( $restore );
			self::assertSame( 'succeeded', $job['state'] ?? null, (string) json_encode( $job['error'] ?? null ) );
			self::assertSame( [ 'backup', 'schedule', 'succeeded' ], [ $backup['type'], $backup['trigger'], $backup['state'] ], (string) json_encode( $backup ) );
			self::assertGreaterThan( 1, TestSite::schedule_state()['backup_slot'] ?? 0 );
		} finally {
			// Handled before the next test: no later heartbeat starts this slot again.
			TestSite::schedule_state( [ 'backup_slot' => time() ] );
			TestSite::api( 'PUT', '/settings', [ 'values' => $before ] );
			// A job that a heartbeat started without chaining, such as the forget after the
			// backup, has no tick event: one more heartbeat advances it, and it chains again.
			TestSite::heartbeat();
			TestSite::idle( 600 );
		}
	}
}
