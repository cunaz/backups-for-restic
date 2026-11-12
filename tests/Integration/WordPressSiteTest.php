<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Milestone 1 acceptance against a real WordPress (docs/05-roadmap.md), as a black box over
 * HTTP: the setup wizard through the REST API, a backup driven only by requests to
 * wp-cron.php on a server with max_execution_time = 30 and memory_limit = 128M, a tick
 * killed in the middle, and checks with the restic CLI and a database import.
 *
 * Needs bin/test-site.sh install and start, restic and rest-server, and the site's MySQL or
 * MariaDB. Sizes: BFR_SITE_FIXTURE_MB (files, default 64) and BFR_DB_FIXTURE_MB (database,
 * default 32); the acceptance run in CI uses 2048 and 300.
 */
final class WordPressSiteTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	private static function site_database(): MysqliConnection {
		$connection = MysqliConnection::connect( MysqliConnection::environment( 'BFR_DB_NAME', 'bfr_site' ) );
		self::assertNotNull( $connection );
		return $connection;
	}

	public function test_setup_and_backup_through_wp_cron_restore_with_restic(): void {
		$server = TestSite::server();

		[ $status ] = TestSite::api( 'POST', '/repository/ca-certificate', [ 'pem' => (string) file_get_contents( $server->certificate ) ] );
		self::assertSame( 200, $status );
		$connection        = TestSite::connection();
		[ $status, $test ] = TestSite::api( 'POST', '/repository/test', $connection );
		self::assertSame( 200, $status, (string) json_encode( $test ) );
		self::assertFalse( $test['report']['exists'] );
		[ $status ] = TestSite::api( 'POST', '/repository/save', $connection );
		self::assertSame( 200, $status );
		// The WordPress password only matters when this runs again on a site that is set up already.
		[ $status, $created, $raw ] = TestSite::api( 'POST', '/repository/create', [ 'user_password' => TestSite::ADMIN_PASSWORD ] );
		self::assertSame( 201, $status, $raw );
		self::assertMatchesRegularExpression( "/RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/", $created['recovery_kit'] );
		self::assertStringContainsString( '--cacert ca.pem', $created['recovery_kit'], 'restic needs the self-signed CA too.' );
		self::assertStringContainsString( '-----BEGIN CERTIFICATE-----', $created['recovery_kit'] );
		preg_match( "/RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/", $created['recovery_kit'], $match );
		TestSite::$repository_password = $match[1];
		[ , $dashboard ]               = TestSite::api( 'GET', '/status' );
		if ( false === ( $dashboard['setup_completed'] ?? null ) ) {
			// No backup before the recovery kit is acknowledged. A site this ran on before is set up already.
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/jobs' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_setup_incomplete', $refused['code'] ?? null, $raw );
		}
		[ $status ] = TestSite::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
		self::assertSame( 200, $status );
		// The wizard showed the recovery kit once; from now on it needs the WordPress password.
		foreach ( [ '/repository/create', '/repository/connect' ] as $route ) {
			[ $status, $refused, $raw ] = TestSite::api( 'POST', $route, [ 'password' => TestSite::$repository_password ] );
			self::assertSame( 403, $status, $raw );
			self::assertSame( 'backups_for_restic_wrong_user_password', $refused['code'] ?? null, $raw );
		}

		// The fixture: files in uploads and a large table.
		$uploads = TestSite::root() . '/wp-content/uploads/fixture';
		TempDir::remove( $uploads );
		mkdir( $uploads, 0755, true );
		$megabytes = (int) MysqliConnection::environment( 'BFR_SITE_FIXTURE_MB', '64' );
		for ( $i = 0; $i < $megabytes; $i += 8 ) {
			file_put_contents( $uploads . '/file-' . $i . '.bin', Vectors::hash_stream( 'site-' . $i, 8 * 1048576 ) );
		}
		$db = self::site_database();
		$db->execute( 'DELETE FROM wp_postmeta WHERE meta_key = "_fixture"' );
		$rows = (int) MysqliConnection::environment( 'BFR_DB_FIXTURE_MB', '32' ) * 1024;
		for ( $offset = 0; $offset < $rows; $offset += 1000 ) {
			$values = [];
			for ( $i = $offset; $i < $offset + 1000; $i++ ) {
				$values[] = sprintf( "(1, '_fixture', '%s')", str_repeat( hash( 'sha256', (string) $i ), 16 ) );
			}
			$db->execute( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ' . implode( ',', $values ) );
		}

		TestSite::idle( 600 );
		[ $status, $started, $raw ] = TestSite::api( 'POST', '/jobs' );
		self::assertSame( 201, $status, $raw );
		$job = TestSite::wait_for( $started['job']['id'], 3600 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		TestSite::idle( 600 );

		$cli = TestSite::restic();
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$target = TempDir::create( 'site-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		self::assertSame( TempDir::describe( $uploads ), TempDir::describe( $target . $uploads ) );

		$private  = TestSite::root() . '/wp-content/backups-for-restic';
		$restored = 'bfr_site_restored_' . bin2hex( random_bytes( 3 ) );
		$admin    = MysqliConnection::connect();
		self::assertNotNull( $admin );
		$admin->execute( 'CREATE DATABASE `' . $restored . '` CHARACTER SET utf8mb4' );
		$files = array_map( 'strval', (array) glob( $target . $private . '/spool/database/*.sql' ) );
		self::assertNotSame( [], $files, 'The snapshot contains the database dump.' );
		// With the client, like the recovery guide: one request with the whole dump exceeds
		// the server's max_allowed_packet at the acceptance size.
		MysqliConnection::import( $restored, $files );
		$copy = MysqliConnection::connect( $restored );
		self::assertNotNull( $copy );
		foreach ( [ 'wp_postmeta', 'wp_posts', 'wp_users' ] as $table ) {
			self::assertSame( $db->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $copy->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $table );
		}
		$admin->execute( 'DROP DATABASE `' . $restored . '`' );
		TempDir::remove( $target );

		// No secret in job files or REST responses.
		$responses = '';
		foreach ( [ '/status', '/settings', '/jobs', '/jobs/' . $job['id'] . '/log' ] as $route ) {
			$responses .= TestSite::api( 'GET', $route )[2];
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $private . '/jobs', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$responses .= file_get_contents( (string) $file );
		}
		foreach ( [ TestSite::$repository_password, RestServer::PASSWORD ] as $secret ) {
			self::assertStringNotContainsString( $secret, $responses );
		}
	}

	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_a_tick_killed_in_the_middle_of_a_backup_leaves_a_resumable_job(): void {
		// Enough new data that the backup step is still at work when the server is stopped.
		TestSite::add_upload( 'changed.bin', 256 );
		try {
			TestSite::idle( 600 );
			$id = TestSite::start();
			// Creating the job chained a tick; this request starts one where loopback requests fail.
			TestSite::cron( 3 );
			$caught = TestSite::wait_for_backup_tick( $id, 120 );

			try {
				TestSite::kill_server();

				$left = TestSite::job_document( $id );
				self::assertSame( 'running', $left['state'] ?? null );
				self::assertSame( $caught['lease_owner'], $left['lease_owner'] ?? null, 'The tick died holding its lease: it never came to save the job again.' );
				self::assertSame( $caught['ticks'], $left['ticks'] ?? null );
				$log = TestSite::job_log( $id );
				self::assertSame( 1, substr_count( $log, 'Backup started.' ), $log );
				self::assertStringNotContainsString( 'Snapshot saved.', $log, 'It was killed in the backup step.' );
			} finally {
				// Whatever the assertions say, the later tests need the site.
				TestSite::start_server();
			}
			$job = TestSite::wait_for( $id, 1800 );
			self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
			self::assertSame( 1, substr_count( TestSite::job_log( $id ), 'Backup started.' ), 'The backup went on from its checkpoint; it did not start over.' );
			TestSite::idle( 600 );
			$cli = TestSite::restic();
			self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check' ] ) );
			self::assertSame( '', trim( $cli->must( [ 'list', 'locks', '--no-lock' ] ) ), 'No lock remains.' );
		} finally {
			// Left behind, the file would be part of every later test's backup.
			TestSite::remove_upload( 'changed.bin' );
		}
	}

	public function test_invalid_settings_are_refused_with_an_error_for_each_field(): void {
		[ $status, $error, $raw ] = TestSite::api(
			'PUT',
			'/settings',
			[
				'values' => [
					'retention.keep.within'          => '0d',
					'notifications.email.recipients' => 'nobody',
				],
			]
		);
		self::assertSame( 400, $status, $raw );
		self::assertArrayHasKey( 'retention.keep.within', $error['data']['fields'] ?? [], $raw );
		self::assertArrayHasKey( 'notifications.email.recipients', $error['data']['fields'] ?? [], $raw );
	}

	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_maintenance_jobs_and_the_dashboard(): void {
		TestSite::idle( 600 );
		$ids = [];
		foreach ( [ 'prune', 'check' ] as $type ) {
			[ $status, $started, $raw ] = TestSite::api( 'POST', '/jobs', [ 'type' => $type ] );
			self::assertSame( 201, $status, $raw );
			$ids[ $type ] = $started['job']['id'];
			$job          = TestSite::wait_for( $ids[ $type ], 1800 );
			self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
			TestSite::idle( 600 );
		}

		[ $status, $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
		self::assertSame( 'plugin', $dashboard['retention']['mode'] ?? null, $raw );
		// Measured after the backups and again after the prune: what restic finds in the repository's data directory.
		$packs = array_filter( explode( "\n", TestSite::restic()->must( [ 'list', 'packs', '--no-lock' ] ) ) );
		self::assertSame( count( $packs ), $dashboard['repository']['usage']['packs'] ?? null, $raw );
		self::assertGreaterThan( 1048576, $dashboard['repository']['usage']['bytes'] ?? 0, $raw );
		self::assertGreaterThan( 0, $dashboard['maintenance']['prune']['finished'] ?? 0, $raw );
		self::assertIsInt( $dashboard['maintenance']['prune']['reclaimable_bytes'] ?? null, $raw );
		self::assertSame( 0, $dashboard['maintenance']['check']['errors'] ?? null, $raw );
		self::assertNull( $dashboard['check_alert'] ?? null, $raw );
		// By its ID, not by its place: IDs begin with the second of creation, and two jobs of the
		// same second sort by their random ends.
		$recent = array_column( $dashboard['recent_jobs'] ?? [], 'type_label', 'id' );
		self::assertSame( 'Check', $recent[ $ids['check'] ] ?? null, $raw );
		$cli = TestSite::restic();
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check' ] ) );

		// While a tick holds the runner lock, the cache is not deleted under it.
		$lock = fopen( TestSite::root() . '/wp-content/' . PrivateDirectory::NAME . '/runner.lock', 'c' );
		self::assertNotFalse( $lock );
		try {
			self::assertTrue( flock( $lock, LOCK_EX | LOCK_NB ) );
			[ $status, , $raw ] = TestSite::api( 'DELETE', '/cache' );
		} finally {
			// Whatever happens above, no job of a later test may find the runner lock taken.
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		self::assertSame( 409, $status, $raw );
		// An active job answers 409 as well: wait for it here, so that the loop below only
		// waits for a heartbeat that holds the lock for a moment.
		TestSite::idle( 600 );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			[ $status, $cleared, $raw ] = TestSite::api( 'DELETE', '/cache' );
			if ( 409 !== $status ) {
				break;
			}
			sleep( 1 ); // A heartbeat may hold the lock for a moment.
		}
		self::assertSame( 200, $status, $raw );
		self::assertSame( 0, $cleared['cache_bytes'] ?? null, $raw );
	}

	/** @return iterable<string, array{string}> */
	public static function unsupported_layouts(): iterable {
		yield 'newer' => [ '2' ];
		yield 'damaged numeral' => [ '2x' ];
		yield 'empty' => [ '' ];
		yield 'zero' => [ '0' ];
		yield 'serialized array' => [ 'a:1:{i:0;i:2;}' ];
	}

	#[DataProvider( 'unsupported_layouts' )]
	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_an_unsupported_options_layout_keeps_the_plugin_inert( string $value ): void {
		TestSite::idle( 600 );
		$db     = self::site_database();
		$select = "SELECT option_value FROM wp_options WHERE option_name = 'backups_for_restic_schema_version'";
		$stored = $db->rows( $select )[0]['option_value'] ?? null;
		self::assertSame( '1', $stored, 'The plugin recorded the layout of its options on its first request.' );

		try {
			$db->execute( "UPDATE wp_options SET option_value = '" . $db->mysqli->real_escape_string( $value ) . "' WHERE option_name = 'backups_for_restic_schema_version'" );
			[ $status, $answer, $raw ] = TestSite::api( 'GET', '/status' );
			self::assertSame( 404, $status, $raw );
			self::assertSame( 'rest_no_route', $answer['code'] ?? null, 'No route of the plugin is registered: ' . $raw );
			TestSite::cron();
			self::assertSame( $value, $db->rows( $select )[0]['option_value'] ?? null, 'An unsupported marker is never overwritten.' );
		} finally {
			$db->execute( "UPDATE wp_options SET option_value = '1' WHERE option_name = 'backups_for_restic_schema_version'" );
		}

		[ $status, , $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, 'With its own layout number the plugin works again: ' . $raw );
	}

	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_a_refused_schema_write_keeps_the_plugin_inert_until_it_can_be_recorded(): void {
		TestSite::idle( 600 );
		$db     = self::site_database();
		$filter = TestSite::root() . '/wp-content/mu-plugins/bfr-refuse-schema.php';
		self::assertFileDoesNotExist( $filter );
		try {
			file_put_contents(
				$filter,
				'<?php add_filter( "query", static fn ( $sql ) => str_starts_with( $sql, "INSERT " ) && str_contains( $sql, "backups_for_restic_schema_version" ) ? "" : $sql );'
			);
			$db->execute( "DELETE FROM wp_options WHERE option_name = 'backups_for_restic_schema_version'" );

			[ $status, $answer, $raw ] = TestSite::api( 'GET', '/status' );
			self::assertSame( 404, $status, $raw );
			self::assertSame( 'rest_no_route', $answer['code'] ?? null, $raw );
			self::assertSame( [], $db->rows( "SELECT option_value FROM wp_options WHERE option_name = 'backups_for_restic_schema_version'" ), 'The refused write did not record the layout.' );
		} finally {
			unlink( $filter );
			TestSite::php( 'update_option( "backups_for_restic_schema_version", 1, true );' );
		}

		[ $status, , $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
	}

	/** @return iterable<string, array{string, bool, bool}> */
	public static function racing_layouts(): iterable {
		yield 'newer, autoloaded' => [ '2', true, false ];
		yield 'newer, not autoloaded' => [ '2', false, false ];
		yield 'same, not autoloaded' => [ '1', false, true ];
		yield 'malformed' => [ '2x', true, false ];
	}

	#[DataProvider( 'racing_layouts' )]
	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_a_layout_written_between_the_read_and_insert_is_preserved( string $value, bool $autoload, bool $supported ): void {
		TestSite::idle( 600 );
		$output = TestSite::php(
			'
			global $wpdb;
			$name = "backups_for_restic_schema_version";
			$version = new BackupsForRestic\Storage\SchemaVersion( new BackupsForRestic\WordPress\WpOptions() );
			$filter = static function ( $sql ) use ( &$filter, $wpdb, $name, $arguments ) {
				if ( str_starts_with( $sql, "INSERT " ) && str_contains( $sql, $name ) ) {
					remove_filter( "query", $filter );
					// Another request wins after the cached missing read, immediately before INSERT.
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						$wpdb->options, $name, $arguments[0], $arguments[1]
					) );
				}
				return $sql;
			};
			try {
				delete_option( $name );
				$before = $version->stored();
				add_filter( "query", $filter );
				$upgraded = $version->upgrade();
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM %i WHERE option_name = %s", $wpdb->options, $name ), ARRAY_A );
				echo "\n", json_encode( [ $before, $upgraded, $version->is_supported(), $row ] );
			} finally {
				remove_filter( "query", $filter );
				update_option( $name, 1, true );
			}
			',
			[ $value, $autoload ? 'on' : 'off' ]
		);
		self::assertSame(
			[
				0,
				$supported,
				$supported,
				[
					'option_value' => $value,
					'autoload'     => $autoload ? 'on' : 'off',
				],
			],
			json_decode( TestSite::last_line( $output ), true ),
			$output
		);
	}

	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_an_unreadable_job_blocks_new_jobs_and_repository_changes_with_an_explanation(): void {
		TestSite::idle( 600 );
		$id = '20990101T000000Z-abcdef';
		try {
			TestSite::php(
				'
				$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/" . $arguments[0] );
				$store = $container->jobs();
				$job = new BackupsForRestic\Jobs\Job( $arguments[1], BackupsForRestic\Jobs\JobType::Backup, "manual", time() );
				$document = $job->to_array();
				$document["schema"] = BackupsForRestic\Jobs\Job::SCHEMA + 1;
				$document["state"] = "succeeded";
				BackupsForRestic\Storage\Files::write_atomic( $store->directory( $job ) . "/job.json", json_encode( $document ) );
				',
				[ TestSite::PLUGIN, $id ]
			);
			$requests = [
				'/jobs'            => [],
				'/repository/save' => TestSite::connection(),
			];
			foreach ( $requests as $route => $body ) {
				[ $status, $answer, $raw ] = TestSite::api( 'POST', $route, $body );
				self::assertSame( 409, $status, $raw );
				self::assertSame( 'backups_for_restic_job_unreadable', $answer['code'] ?? null, $raw );
				self::assertStringContainsString( 'Install a plugin version', $answer['data']['hint'] ?? '', $raw );
			}
		} finally {
			TestSite::php(
				'$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/" . $arguments[0] ); BackupsForRestic\Storage\Files::remove_tree( $container->private_dir()->path . "/jobs/" . $arguments[1] );',
				[ TestSite::PLUGIN, $id ]
			);
		}
	}

	#[DataProvider( 'unsupported_layouts' )]
	#[Depends( 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_deactivation_leaves_jobs_alone_when_the_options_layout_is_unsupported( string $value ): void {
		TestSite::idle( 600 );
		$output = TestSite::php(
			'
			require_once ABSPATH . "wp-admin/includes/plugin.php";
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/" . $arguments[0] );
			$store = $container->jobs();
			$job = $store->create( BackupsForRestic\Jobs\JobType::Backup, "manual", time() );
			$directory = $store->directory( $job );
			$before = file_get_contents( $directory . "/job.json" );
			try {
				update_option( "backups_for_restic_schema_version", $arguments[1], true );
				deactivate_plugins( $arguments[0] );
				echo "\n", json_encode( [
					$before === file_get_contents( $directory . "/job.json" ),
					is_file( $directory . "/cancel-requested" ),
					get_option( "backups_for_restic_schema_version" ),
					wp_next_scheduled( "backups_for_restic_heartbeat" ),
				] );
			} finally {
				BackupsForRestic\Storage\Files::remove_tree( $directory );
				update_option( "backups_for_restic_schema_version", 1, true );
				activate_plugin( $arguments[0] );
			}
			',
			[ TestSite::PLUGIN, $value ]
		);
		self::assertSame( [ true, false, $value, false ], json_decode( TestSite::last_line( $output ), true ), 'Deactivation unschedules events without reading or changing the job: ' . $output );
	}
}
