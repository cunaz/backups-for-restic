<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\TestSite;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * Who may use the plugin's REST API: administrators, and nobody else. Every route is asked
 * without credentials and as a user who may edit posts but not manage the site; none of them
 * may answer, whatever the site's state is.
 */
final class AccessTest extends TestCase {

	private const string JOB = '20270115T030000Z-abcdef';

	private const string SNAPSHOT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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
			[ 'GET', '/jobs/' . self::JOB . '/kept-entries' ],
			[ 'GET', '/snapshots' ],
			[ 'GET', '/snapshots/' . self::SNAPSHOT ],
			[ 'GET', '/snapshots/' . self::SNAPSHOT . '/tree' ],
			[ 'GET', '/restores' ],
			[ 'DELETE', '/restores/' . self::JOB ],
			[ 'POST', '/restores/' . self::JOB . '/download' ],
			[ 'POST', '/restores/' . self::JOB . '/apply-plan' ],
			[ 'POST', '/restores/' . self::JOB . '/apply' ],
			[ 'POST', '/restores/' . self::JOB . '/database/plan' ],
			[ 'POST', '/restores/' . self::JOB . '/database/apply' ],
			[ 'GET', '/database-changes' ],
			[ 'POST', '/database-changes/' . self::JOB . '/undo-plan' ],
			[ 'POST', '/database-changes/' . self::JOB . '/undo' ],
			[ 'GET', '/undo' ],
			[ 'POST', '/undo/' . self::JOB . '/plan' ],
			[ 'POST', '/undo/' . self::JOB ],
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

	public function test_setup_requires_an_affirmative_recovery_kit_acknowledgment(): void {
		foreach ( [ false, 'no', 'off', 'unknown' ] as $value ) {
			[ $status, $error, $raw ] = TestSite::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => $value ] );
			self::assertSame( 400, $status, $raw );
			self::assertSame( 'backups_for_restic_recovery_kit_not_acknowledged', $error['code'] ?? null, $raw );
		}
	}

	public function test_a_delegated_backup_capability_does_not_grant_live_file_writes(): void {
		$statuses = TestSite::php(
			'
			add_filter( "backups_for_restic_capability", static fn () => "read" );
			wp_set_current_user( get_user_by( "login", "bfr-editor" )->ID );
			foreach ( [ "/restores/" . $arguments[0] . "/apply", "/undo/" . $arguments[0] ] as $route ) {
				echo rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1" . $route ) )->get_status(), "\n";
			}
			',
			[ self::JOB ]
		);
		self::assertSame( [ '403', '403' ], explode( "\n", trim( $statuses ) ) );
	}

	public function test_database_writes_require_every_capability_including_core_updates(): void {
		$output = TestSite::php(
			'
			add_filter( "backups_for_restic_capability", static fn () => "bfr_manage_backups" );
			$editor = get_user_by( "login", "bfr-editor" )->ID;
			wp_set_current_user( $editor );
			$required = [ "bfr_manage_backups", "manage_options", "edit_users", "promote_users", "update_core" ];
			$omitted = "";
			add_filter( "user_has_cap", static function ( $caps ) use ( $required, &$omitted ) {
				foreach ( $required as $cap ) { $caps[ $cap ] = $omitted !== $cap; }
				return $caps;
			} );
			$results = [ BackupsForRestic\WordPress\RestRoutes::database_writes_allowed() ];
			foreach ( $required as $omitted ) {
				$results[] = BackupsForRestic\WordPress\RestRoutes::database_writes_allowed();
			}
			wp_set_current_user( 0 );
			$omitted = "";
			$results[] = BackupsForRestic\WordPress\RestRoutes::database_user_allowed( $editor );
			$results[] = BackupsForRestic\WordPress\RestRoutes::database_writes_allowed();
			echo wp_json_encode( $results );
			'
		);
		self::assertSame( [ true, false, false, false, false, false, true, false ], json_decode( $output, true ), $output );
	}

	public function test_database_routes_refuse_a_delegated_user_who_cannot_update_core(): void {
		$output = TestSite::php(
			'
			wp_set_current_user( get_user_by( "login", "bfr-editor" )->ID );
			add_filter( "backups_for_restic_capability", static fn () => "read" );
			add_filter( "user_has_cap", static function ( $caps ) {
				$caps["manage_options"] = $caps["edit_users"] = $caps["promote_users"] = true;
				$caps["update_core"] = false;
				return $caps;
			} );
			foreach ( [ "/restores/" . $arguments[0] . "/database/apply", "/database-changes/" . $arguments[0] . "/undo" ] as $route ) {
				echo rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1" . $route ) )->get_status(), "\n";
			}
			',
			[ self::JOB ]
		);
		self::assertSame( [ '403', '403' ], explode( "\n", trim( $output ) ) );
	}

	public function test_database_writes_require_explicit_confirmation_in_the_json_body(): void {
		foreach ( [ '/restores/' . self::JOB . '/database/apply', '/database-changes/' . self::JOB . '/undo' ] as $route ) {
			[ $status, $error, $raw ] = TestSite::api( 'POST', $route . '&reviewed=true&site_url=' . rawurlencode( TestSite::url() ), [] );
			self::assertSame( 400, $status, $raw );
			self::assertSame( 'backups_for_restic_live_confirmation', $error['code'] ?? null, $raw );
		}
	}

	public function test_live_writes_respect_the_hosts_file_modification_policy(): void {
		$statuses = TestSite::php(
			'
			wp_set_current_user( 1 );
			add_filter( "file_mod_allowed", "__return_false" );
			foreach ( [ "/restores/" . $arguments[0] . "/apply", "/undo/" . $arguments[0] ] as $route ) {
				echo rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1" . $route ) )->get_status(), "\n";
			}
			',
			[ self::JOB ]
		);
		self::assertSame( [ '403', '403' ], explode( "\n", trim( $statuses ) ) );
	}

	public function test_invalid_preview_page_identifiers_are_rejected_before_planning(): void {
		foreach ( [ [ 'offset' => -1 ], [ 'offset' => 1 ], [ 'plan_job' => 'invalid' ] ] as $body ) {
			[ $status, $error, $raw ] = TestSite::api( 'POST', '/restores/' . self::JOB . '/apply-plan', $body );
			self::assertSame( 400, $status, $raw );
			self::assertSame( 'backups_for_restic_invalid_plan_page', $error['code'] ?? null, $raw );
		}
	}

	public function test_live_changes_cannot_be_started_through_the_generic_job_endpoint(): void {
		foreach ( [ 'apply', 'undo', 'file_plan', 'database_apply', 'database_undo' ] as $type ) {
			[ $status, $error ] = TestSite::api( 'POST', '/jobs', [ 'type' => $type ] );
			self::assertSame( 400, $status );
			self::assertSame( 'backups_for_restic_invalid_job_type', $error['code'] ?? null );
		}
	}

	public function test_the_list_of_routes_is_complete(): void {
		[ $status, $index, $raw ] = TestSite::api( 'GET', '' );
		self::assertSame( 200, $status, $raw );

		$registered = [];
		foreach ( $index['routes'] ?? [] as $route => $description ) {
			$route = (string) preg_replace( [ '~\(\?P<id>[^)]*\)~', '~\(\?P<snapshot>[^)]*\)~' ], [ self::JOB, self::SNAPSHOT ], substr( (string) $route, strlen( '/backups-for-restic/v1' ) ) );
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

	public function test_configured_private_directory_is_claimed_on_write_and_invalid_locations_reach_doctor(): void {
		$path = TempDir::create( 'wp-state-lock' );
		try {
			$output = TestSite::php(
				'
				add_filter( "backups_for_restic_private_dir", static fn () => $arguments[0] );
				$c = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
				$c->settings();
				$c->state()->get( "last_backup" );
				$untouched = scandir( $arguments[0] );
				file_put_contents( $arguments[0] . "/unrelated", "keep" );
				$routes = new BackupsForRestic\WordPress\RestRoutes( $c );
				$report = $routes->environment( new WP_REST_Request( "GET" ) )->get_data();
				$check = array_column( $report["checks"], null, "id" )["private_directory"];
				try { $c->settings_store()->update( [] ); $refused = false; }
				catch ( BackupsForRestic\Exception\StorageFailed ) { $refused = true; }
				$foreign = scandir( $arguments[0] );
				unlink( $arguments[0] . "/unrelated" );
				// Invalid input stops before writing any option, after ownership and the lock are established.
				try { $c->settings_store()->update( [ "unknown.setting" => true ] ); }
				catch ( BackupsForRestic\Exception\ValidationFailed ) {}
				$c->private_dir()->ensure();
				echo wp_json_encode( [ $untouched, $check["status"], $check["code"], $report["browser_probe"], $refused, $foreign, is_file( $arguments[0] . "/state.lock" ) ] );
				',
				[ $path ]
			);
			self::assertSame( [ [ '.', '..' ], 'critical', 'unavailable', false, true, [ '.', '..', 'unrelated' ], true ], json_decode( $output, true ), $output );
		} finally {
			TempDir::remove( $path );
		}
	}

	public function test_the_private_directory_is_not_served(): void {
		// Any request of an administrator creates the directory with its deny files.
		[ $status, , $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );

		foreach ( [ '/', '/index.php', '/.htaccess', '/web.config', '/jobs/', '/cache/', '/restores/' ] as $path ) {
			self::assertFileExists( TestSite::private_dir() . $path );
			$handle = curl_init( TestSite::url() . '/wp-content/' . PrivateDirectory::NAME . $path );
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
