<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\TempDir;
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

	private const string URL = 'http://127.0.0.1:8890';

	/** The administrator's WordPress password, as bin/test-site.sh install sets it. */
	private const string ADMIN_PASSWORD = 'admin-password-test-only';

	private static ?RestServer $server = null;

	private static string $credentials = '';

	private static string $site = '';

	private static string $repository_password = '';

	public static function setUpBeforeClass(): void {
		$root              = dirname( __DIR__, 2 );
		self::$site        = $root . '/.tools/site';
		$credentials       = is_file( $root . '/.tools/site-app-password' ) ? file_get_contents( $root . '/.tools/site-app-password' ) : false;
		self::$credentials = false === $credentials ? '' : trim( $credentials );
		if ( '' === self::$credentials || ! RestServer::available() || ! self::reachable() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
		self::$server = new RestServer();
	}

	public static function tearDownAfterClass(): void {
		self::$server?->stop();
	}

	private static function reachable(): bool {
		$handle = curl_init( self::URL . '/wp-login.php' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 5,
			]
		);
		curl_exec( $handle );
		return 200 === curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	}

	/**
	 * Calls the plugin's REST API.
	 *
	 * @param array<string, mixed>|null $data JSON body.
	 * @return array{int, array<mixed>, string} Status, decoded body, raw body.
	 */
	private static function api( string $method, string $route, ?array $data = null, bool $authenticated = true ): array {
		$handle  = curl_init( self::URL . '/?rest_route=/backups-for-restic/v1' . $route );
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
		];
		if ( $authenticated ) {
			$options[ CURLOPT_USERPWD ] = self::$credentials;
		}
		if ( null !== $data ) {
			$options[ CURLOPT_POSTFIELDS ] = (string) json_encode( $data );
		}
		curl_setopt_array( $handle, $options );
		$body = curl_exec( $handle );
		if ( ! is_string( $body ) ) {
			// No HTTP response at all: report why, so a failing assertion shows the cause.
			return [ 0, [], $method . ' ' . $route . ': ' . curl_error( $handle ) ];
		}
		$decoded = json_decode( $body, true );
		return [ (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ), is_array( $decoded ) ? $decoded : [], $body ];
	}

	/**
	 * Requests wp-cron.php like an external cron service does.
	 */
	private static function cron(): void {
		$handle = curl_init( self::URL . '/wp-cron.php?doing_wp_cron' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
			]
		);
		curl_exec( $handle );
	}

	/**
	 * Drives a job with cron requests only and returns it once it ended.
	 *
	 * @return array<mixed> Job.
	 */
	private static function wait_for( string $id, int $timeout ): array {
		$deadline   = time() + $timeout;
		$unanswered = 0;
		do {
			self::cron();
			[ $status, $list ] = self::api( 'GET', '/jobs' );
			foreach ( $list['jobs'] ?? [] as $job ) {
				if ( is_array( $job ) && $id === $job['id'] && ! in_array( $job['state'], [ 'queued', 'running' ], true ) ) {
					return $job;
				}
			}
			// A server that is gone never finishes the job: say so now instead of at the deadline.
			$unanswered = 0 === $status ? $unanswered + 1 : 0;
			if ( $unanswered >= 30 ) {
				self::fail( 'The site stopped answering; see .tools/site-server.log.' );
			}
			sleep( 1 );
		} while ( time() < $deadline );
		self::fail( 'The job did not finish in time.' );
	}

	/**
	 * Drives cron until no job is queued or running. After a backup the heartbeat may start a
	 * forget job, which holds an exclusive lock; restic and new jobs wait for it.
	 */
	private static function idle( int $timeout ): void {
		$deadline = time() + $timeout;
		do {
			self::cron();
			[ $status, $list, $raw ] = self::api( 'GET', '/jobs' );
			self::assertSame( 200, $status, $raw );
			$active = array_filter( $list['jobs'] ?? [], static fn ( mixed $job ): bool => is_array( $job ) && in_array( $job['state'] ?? '', [ 'queued', 'running' ], true ) );
			if ( [] === $active ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );
		self::fail( 'Jobs were still active.' );
	}

	private static function site_database(): MysqliConnection {
		$connection = MysqliConnection::connect( MysqliConnection::environment( 'BFR_DB_NAME', 'bfr_site' ) );
		self::assertNotNull( $connection );
		return $connection;
	}

	public function test_rest_api_requires_an_administrator(): void {
		[ $status ] = self::api( 'GET', '/status', null, false );
		self::assertContains( $status, [ 401, 403 ] );
	}

	public function test_setup_and_backup_through_wp_cron_restore_with_restic(): void {
		self::assertNotNull( self::$server );
		$server = self::$server;

		[ $status ] = self::api( 'POST', '/repository/ca-certificate', [ 'pem' => (string) file_get_contents( $server->certificate ) ] );
		self::assertSame( 200, $status );
		$connection        = [
			'server'   => 'rest-server',
			'url'      => $server->url( 'site' ),
			'username' => RestServer::USER,
			'password' => RestServer::PASSWORD,
		];
		[ $status, $test ] = self::api( 'POST', '/repository/test', $connection );
		self::assertSame( 200, $status, (string) json_encode( $test ) );
		self::assertFalse( $test['report']['exists'] );
		[ $status ] = self::api( 'POST', '/repository/save', $connection );
		self::assertSame( 200, $status );
		// The WordPress password only matters when this runs again on a site that is set up already.
		[ $status, $created, $raw ] = self::api( 'POST', '/repository/create', [ 'user_password' => self::ADMIN_PASSWORD ] );
		self::assertSame( 201, $status, $raw );
		self::assertMatchesRegularExpression( "/RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/", $created['recovery_kit'] );
		self::assertStringContainsString( '--cacert ca.pem', $created['recovery_kit'], 'restic needs the self-signed CA too.' );
		self::assertStringContainsString( '-----BEGIN CERTIFICATE-----', $created['recovery_kit'] );
		preg_match( "/RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/", $created['recovery_kit'], $match );
		self::$repository_password = $match[1];
		[ , $dashboard ]           = self::api( 'GET', '/status' );
		if ( false === ( $dashboard['setup_completed'] ?? null ) ) {
			// No backup before the recovery kit is acknowledged. A site this ran on before is set up already.
			[ $status, $refused, $raw ] = self::api( 'POST', '/jobs' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_setup_incomplete', $refused['code'] ?? null, $raw );
		}
		[ $status ] = self::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
		self::assertSame( 200, $status );
		// The wizard showed the recovery kit once; from now on it needs the WordPress password.
		foreach ( [ '/repository/create', '/repository/connect' ] as $route ) {
			[ $status, $refused, $raw ] = self::api( 'POST', $route, [ 'password' => self::$repository_password ] );
			self::assertSame( 403, $status, $raw );
			self::assertSame( 'backups_for_restic_wrong_user_password', $refused['code'] ?? null, $raw );
		}

		// The fixture: files in uploads and a large table.
		$uploads = self::$site . '/wp-content/uploads/fixture';
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

		self::idle( 600 );
		[ $status, $started, $raw ] = self::api( 'POST', '/jobs' );
		self::assertSame( 201, $status, $raw );
		$job = self::wait_for( $started['job']['id'], 3600 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		self::idle( 600 );

		$cli = new ResticCli( $server, 'site', self::$repository_password );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$target = TempDir::create( 'site-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		self::assertSame( TempDir::describe( $uploads ), TempDir::describe( $target . $uploads ) );

		$private  = self::$site . '/wp-content/backups-for-restic';
		$restored = 'bfr_site_restored_' . bin2hex( random_bytes( 3 ) );
		$admin    = MysqliConnection::connect();
		self::assertNotNull( $admin );
		$admin->execute( 'CREATE DATABASE `' . $restored . '` CHARACTER SET utf8mb4' );
		$files = (array) glob( $target . $private . '/spool/database/*.sql' );
		sort( $files );
		$sql = '';
		foreach ( $files as $file ) {
			$sql .= file_get_contents( (string) $file );
		}
		$copy = MysqliConnection::connect( $restored );
		self::assertNotNull( $copy );
		self::assertTrue( $copy->mysqli->multi_query( $sql ), $copy->mysqli->error );
		while ( $copy->mysqli->more_results() && $copy->mysqli->next_result() ) {
			continue;
		}
		foreach ( [ 'wp_postmeta', 'wp_posts', 'wp_users' ] as $table ) {
			self::assertSame( $db->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $copy->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $table );
		}
		$admin->execute( 'DROP DATABASE `' . $restored . '`' );
		TempDir::remove( $target );

		// No secret in job files or REST responses.
		$responses = '';
		foreach ( [ '/status', '/settings', '/jobs', '/jobs/' . $job['id'] . '/log' ] as $route ) {
			$responses .= self::api( 'GET', $route )[2];
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $private . '/jobs', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$responses .= file_get_contents( (string) $file );
		}
		foreach ( [ self::$repository_password, RestServer::PASSWORD ] as $secret ) {
			self::assertStringNotContainsString( $secret, $responses );
		}
	}

	/**
	 * @depends test_setup_and_backup_through_wp_cron_restore_with_restic
	 */
	public function test_a_killed_tick_leaves_a_resumable_job(): void {
		self::assertNotNull( self::$server );
		file_put_contents( self::$site . '/wp-content/uploads/fixture/changed.bin', Vectors::hash_stream( 'changed', 64 * 1048576 ) );
		self::idle( 600 );
		[ $status, $started, $raw ] = self::api( 'POST', '/jobs' );
		self::assertSame( 201, $status, $raw );
		$id = $started['job']['id'];

		$handle = curl_init( self::URL . '/wp-cron.php?doing_wp_cron' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 3,
			]
		);
		curl_exec( $handle );
		$script = dirname( __DIR__, 2 ) . '/bin/test-site.sh';
		exec( escapeshellarg( $script ) . ' stop && ' . escapeshellarg( $script ) . ' start', $output, $code );
		self::assertSame( 0, $code, implode( "\n", $output ) );

		$job = self::wait_for( $id, 1800 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		self::idle( 600 );
		$cli = new ResticCli( self::$server, 'site', self::$repository_password );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check' ] ) );
		self::assertSame( '', trim( $cli->must( [ 'list', 'locks', '--no-lock' ] ) ), 'No lock remains.' );
	}

	public function test_invalid_settings_are_refused_with_an_error_for_each_field(): void {
		[ $status, $error, $raw ] = self::api(
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

	/**
	 * @depends test_setup_and_backup_through_wp_cron_restore_with_restic
	 */
	public function test_maintenance_jobs_and_the_dashboard(): void {
		self::assertNotNull( self::$server );
		self::idle( 600 );
		$ids = [];
		foreach ( [ 'prune', 'check' ] as $type ) {
			[ $status, $started, $raw ] = self::api( 'POST', '/jobs', [ 'type' => $type ] );
			self::assertSame( 201, $status, $raw );
			$ids[ $type ] = $started['job']['id'];
			$job          = self::wait_for( $ids[ $type ], 1800 );
			self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
			self::idle( 600 );
		}

		[ $status, $dashboard, $raw ] = self::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
		self::assertSame( 'plugin', $dashboard['retention']['mode'] ?? null, $raw );
		self::assertGreaterThan( 0, $dashboard['maintenance']['prune']['finished'] ?? 0, $raw );
		self::assertIsInt( $dashboard['maintenance']['prune']['reclaimable_bytes'] ?? null, $raw );
		self::assertSame( 0, $dashboard['maintenance']['check']['errors'] ?? null, $raw );
		self::assertNull( $dashboard['check_alert'] ?? null, $raw );
		// By its ID, not by its place: IDs begin with the second of creation, and two jobs of the
		// same second sort by their random ends.
		$recent = array_column( $dashboard['recent_jobs'] ?? [], 'type_label', 'id' );
		self::assertSame( 'Check', $recent[ $ids['check'] ] ?? null, $raw );
		$cli = new ResticCli( self::$server, 'site', self::$repository_password );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check' ] ) );

		// While a tick holds the runner lock, the cache is not deleted under it.
		$lock = fopen( self::$site . '/wp-content/' . PrivateDirectory::NAME . '/runner.lock', 'c' );
		self::assertNotFalse( $lock );
		try {
			self::assertTrue( flock( $lock, LOCK_EX | LOCK_NB ) );
			[ $status, , $raw ] = self::api( 'DELETE', '/cache' );
		} finally {
			// Whatever happens above, no job of a later test may find the runner lock taken.
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		self::assertSame( 409, $status, $raw );
		// An active job answers 409 as well: wait for it here, so that the loop below only
		// waits for a heartbeat that holds the lock for a moment.
		self::idle( 600 );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			[ $status, $cleared, $raw ] = self::api( 'DELETE', '/cache' );
			if ( 409 !== $status ) {
				break;
			}
			sleep( 1 ); // A heartbeat may hold the lock for a moment.
		}
		self::assertSame( 200, $status, $raw );
		self::assertSame( 0, $cleared['cache_bytes'] ?? null, $raw );
	}

	/**
	 * docs/02-architecture.md §18: options that a newer version of the plugin wrote keep this
	 * version inert, because it could misread them.
	 */
	public function test_options_of_a_newer_version_keep_the_plugin_inert(): void {
		self::idle( 600 );
		$db     = self::site_database();
		$select = "SELECT option_value FROM wp_options WHERE option_name = 'backups_for_restic_schema_version'";
		$stored = $db->rows( $select )[0]['option_value'] ?? null;
		self::assertSame( '1', $stored, 'The plugin recorded the layout of its options on its first request.' );

		try {
			$db->execute( "UPDATE wp_options SET option_value = '2' WHERE option_name = 'backups_for_restic_schema_version'" );
			[ $status, $answer, $raw ] = self::api( 'GET', '/status' );
			self::assertSame( 404, $status, $raw );
			self::assertSame( 'rest_no_route', $answer['code'] ?? null, 'No route of the plugin is registered: ' . $raw );
			self::cron();
			self::assertSame( '2', $db->rows( $select )[0]['option_value'] ?? null, 'An older version never lowers the number.' );
		} finally {
			$db->execute( "UPDATE wp_options SET option_value = '1' WHERE option_name = 'backups_for_restic_schema_version'" );
		}

		[ $status, , $raw ] = self::api( 'GET', '/status' );
		self::assertSame( 200, $status, 'With its own layout number the plugin works again: ' . $raw );
	}
}
