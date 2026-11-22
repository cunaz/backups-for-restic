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

	public function test_downloads_of_restored_content_need_the_wordpress_password_and_reports_do_not(): void {
		$route = '/restores/' . self::JOB . '/download';
		// A restored file or archive can be wp-config.php or the database; an unknown kind is treated as such.
		foreach ( [ 'file', 'archive', 'database', '' ] as $kind ) {
			foreach ( [ [], [ 'user_password' => '' ], [ 'user_password' => [ TestSite::ADMIN_PASSWORD ] ] ] as $proof ) {
				[ $status, $error, $raw ] = TestSite::api( 'POST', $route, [ 'kind' => $kind ] + $proof );
				self::assertSame( 403, $status, $kind . ': ' . $raw );
				self::assertSame( 'backups_for_restic_wrong_user_password', $error['code'] ?? null, $raw );
			}
			// The password counts only in the JSON body, never in the address.
			[ $status, $error, $raw ] = TestSite::api( 'POST', $route . '&kind=' . $kind . '&user_password=' . rawurlencode( TestSite::ADMIN_PASSWORD ), [] );
			self::assertSame( 403, $status, $raw );
			self::assertSame( 'backups_for_restic_wrong_user_password', $error['code'] ?? null, $raw );

			[ $status, $error, $raw ] = TestSite::api(
				'POST',
				$route,
				[
					'kind'          => $kind,
					'user_password' => TestSite::ADMIN_PASSWORD,
				]
			);
			self::assertSame( 404, $status, $raw );
			self::assertSame( 'backups_for_restic_download_not_found', $error['code'] ?? null, $raw );
		}
		// The reports hold names, counts and table metadata, which the page fetches by itself.
		foreach ( [ 'report', 'database_report' ] as $kind ) {
			[ $status, $error, $raw ] = TestSite::api( 'POST', $route, [ 'kind' => $kind ] );
			self::assertSame( 404, $status, $raw );
			self::assertSame( 'backups_for_restic_download_not_found', $error['code'] ?? null, $raw );
		}
	}

	public function test_wrong_passwords_are_counted_per_user_slowed_down_and_reported_to_login_limiters(): void {
		$output  = TestSite::php(
			'
			require_once ABSPATH . "wp-admin/includes/user.php";
			$existing = get_user_by( "login", "bfr-confirm" );
			if ( $existing instanceof WP_User ) {
				wp_delete_user( $existing->ID );
			}
			$user = wp_insert_user( [ "user_login" => "bfr-confirm", "user_pass" => "right-password-test-only", "user_email" => "bfr-confirm@example.com", "role" => "administrator" ] );
			$failed = [];
			add_action( "wp_login_failed", static function ( $login, $error ) use ( &$failed ) {
				$failed[] = [ $login, $error instanceof WP_Error ? $error->get_error_code() : null ];
			}, 10, 2 );
			// The download of a restored file asks for the password; the job does not exist, so a right one gets 404.
			$ask = static function ( int $as, string $password ) use ( $arguments ): array {
				wp_set_current_user( $as );
				$request = new WP_REST_Request( "POST", "/backups-for-restic/v1/restores/" . $arguments[0] . "/download" );
				$request->set_header( "content-type", "application/json" );
				$request->set_body( wp_json_encode( [ "kind" => "file", "path" => "/wp-config.php", "user_password" => $password ] ) );
				$response = rest_do_request( $request );
				$data     = $response->get_data();
				return [ $response->get_status(), $data["code"] ?? null, $response->get_headers()["Retry-After"] ?? null ];
			};
			$record = static fn (): mixed => ( get_option( "backups_for_restic_state" )["password_confirmation"] ?? [] )[ "user_" . $user ] ?? null;
			// As if the minute had passed.
			$wait = static function ( int $until ) use ( $user ): void {
				$state = get_option( "backups_for_restic_state" );
				$state["password_confirmation"][ "user_" . $user ]["until"] = $until;
				update_option( "backups_for_restic_state", $state, false );
			};
			$results = [];
			foreach ( [ "wrong 1", "wrong 2", "wrong 3", "wrong 4" ] as $guess ) {
				$results["wrong"][] = $ask( $user, $guess );
			}
			$results["right while waiting"] = $ask( $user, "right-password-test-only" );
			$results["empty while waiting"] = $ask( $user, "" );
			$results["other user"]          = $ask( 1, "admin-password-test-only" );
			$results["failures"]            = $record()["failures"] ?? null;
			$wait( time() - 1 );
			$results["right after the wait"] = $ask( $user, "right-password-test-only" );
			$results["forgotten"]            = null === $record();
			// The tenth wrong password in a row locks the confirmation for an hour.
			$results["wrong"][] = $ask( $user, "wrong 5" );
			$state = get_option( "backups_for_restic_state" );
			$state["password_confirmation"][ "user_" . $user ] = [ "failures" => 9, "last" => time(), "until" => time() - 1 ];
			update_option( "backups_for_restic_state", $state, false );
			$results["tenth"]  = $ask( $user, "wrong 10" );
			$results["locked"] = $ask( $user, "right-password-test-only" );
			$results["admin"]  = ( get_option( "backups_for_restic_state" )["password_confirmation"] ?? [] )["user_1"] ?? null;
			$results["other user while locked"] = $ask( 1, "admin-password-test-only" );
			$results["reported"] = $failed;
			$state = get_option( "backups_for_restic_state" );
			unset( $state["password_confirmation"][ "user_" . $user ] );
			update_option( "backups_for_restic_state", $state, false );
			wp_delete_user( $user );
			echo "\n", wp_json_encode( $results );
			',
			[ self::JOB ]
		);
		$results = json_decode( TestSite::last_line( $output ), true );
		self::assertIsArray( $results, $output );
		$wrong     = [ 403, 'backups_for_restic_wrong_user_password', null ];
		$throttled = 'backups_for_restic_user_password_throttled';
		$not_found = [ 404, 'backups_for_restic_download_not_found', null ];

		self::assertSame( [ $wrong, $wrong, $wrong, $wrong, $wrong ], $results['wrong'], $output );
		foreach ( [ 'right while waiting', 'empty while waiting' ] as $attempt ) {
			self::assertSame( [ 429, $throttled ], array_slice( $results[ $attempt ], 0, 2 ), $attempt . ': ' . $output );
			self::assertContains( $results[ $attempt ][2], [ '59', '60' ], 'After the fourth wrong password the next attempt waits a minute.' );
		}
		self::assertSame( $not_found, $results['other user'], 'Another user is not slowed down.' );
		self::assertSame( 4, $results['failures'], 'An attempt during the wait is not counted.' );
		self::assertSame( $not_found, $results['right after the wait'] );
		self::assertTrue( $results['forgotten'], 'The right password forgets the count.' );
		self::assertSame( $wrong, $results['tenth'] );
		self::assertSame( [ 429, $throttled ], array_slice( $results['locked'], 0, 2 ), $output );
		self::assertGreaterThan( 3500, (int) $results['locked'][2], 'From the tenth on each wrong password locks for an hour.' );
		self::assertNull( $results['admin'], 'Nothing was counted for the other user.' );
		self::assertSame( $not_found, $results['other user while locked'] );
		// Login limiters count each wrong password as core's login reports it, and nothing else.
		self::assertSame( array_fill( 0, 6, [ 'bfr-confirm', 'incorrect_password' ] ), $results['reported'], $output );
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

		foreach ( [ '/', '/index.php', '/.htaccess', '/web.config', '/jobs/', '/cache/', '/restores/', '/restores/index.php', '/restores/.htaccess', '/restores/web.config' ] as $path ) {
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
