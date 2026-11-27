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
			[ 'POST', '/database-changes/' . self::JOB . '/discard' ],
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
			foreach ( [ "/restores/" . $arguments[0] . "/database/apply", "/database-changes/" . $arguments[0] . "/undo", "/database-changes/" . $arguments[0] . "/discard" ] as $route ) {
				echo rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1" . $route ) )->get_status(), "\n";
			}
			',
			[ self::JOB ]
		);
		self::assertSame( [ '403', '403', '403' ], explode( "\n", trim( $output ) ) );
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

	public function test_discarding_a_database_binding_counts_a_wrong_password_like_every_confirmation(): void {
		$output = TestSite::php(
			'
			$reported = 0;
			add_action( "wp_login_failed", static function () use ( &$reported ) {
				++$reported;
			} );
			wp_set_current_user( 1 );
			$request = new WP_REST_Request( "POST", "/backups-for-restic/v1/database-changes/" . $arguments[0] . "/discard" );
			$request->set_header( "content-type", "application/json" );
			$request->set_body( wp_json_encode( [ "user_password" => "wrong-password-test-only" ] ) );
			$response = rest_do_request( $request );
			$state    = get_option( "backups_for_restic_state" );
			$failures = ( $state["password_confirmation"] ?? [] )["user_1"]["failures"] ?? null;
			unset( $state["password_confirmation"]["user_1"] );
			update_option( "backups_for_restic_state", $state, false );
			echo "\n", wp_json_encode( [ $response->get_status(), $response->get_data()["code"] ?? null, $failures, $reported ] );
			',
			[ self::JOB ]
		);
		self::assertSame( [ 403, 'backups_for_restic_wrong_user_password', 1, 1 ], json_decode( TestSite::last_line( $output ), true ), $output );
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

	public function test_kept_entries_are_served_only_for_an_ended_undo_and_a_page_of_its_list(): void {
		$undo  = '20261121T120000Z-0c0d01';
		$apply = '20261121T120000Z-0c0d10';
		TestSite::ended_undo(
			$undo,
			'cancelled',
			[
				'restored'             => 0,
				'removed'              => 1,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			[ [ TestSite::root() . '/wp-content/uploads/bfr-kept.txt', 'protected' ] ]
		);
		TestSite::undoable_apply( $apply, [ [ TestSite::root() . '/bfr-mapped.txt', TestSite::root() . '/bfr-elsewhere.txt', 'create' ] ] );
		// An undo that kept an entry and could not seal the list of it.
		$unsealed = '20261121T120000Z-0c0d02';
		TestSite::ended_undo(
			$unsealed,
			'succeeded',
			[
				'restored'             => 1,
				'removed'              => 0,
				'unchanged'            => 0,
				'kept'                 => 1,
				'changed_after_review' => 0,
				'apply_state'          => 'partly_undone',
			],
			[]
		);
		try {
			[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $unsealed . '/kept-entries' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_kept_list_unavailable', $error['code'] ?? null, $raw );
			[ $status, $page, $raw ] = TestSite::api( 'GET', '/jobs/' . $undo . '/kept-entries' );
			self::assertSame( 200, $status, $raw );
			self::assertSame( [ 1, 0, null, $undo ], [ $page['total'], $page['offset'], $page['next_offset'], $page['plan_job'] ] );
			self::assertSame( [ 'keep', 'protected' ], [ $page['entries'][0]['action'] ?? null, $page['entries'][0]['reason'] ?? null ], $raw );
			[ $status, $page, $raw ] = TestSite::api( 'GET', '/jobs/' . $undo . '/kept-entries&offset=1' );
			self::assertSame( 200, $status, $raw );
			self::assertSame( [ [], 1, null ], [ $page['entries'], $page['offset'], $page['next_offset'] ] );
			// An offset that is no number of up to seven digits, or that lies beyond the list.
			foreach ( [ '2', 'x', '-1', '1.5', '12345678' ] as $offset ) {
				[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $undo . '/kept-entries&offset=' . rawurlencode( $offset ) );
				self::assertSame( 400, $status, $offset . ': ' . $raw );
				self::assertSame( 'backups_for_restic_invalid_plan_page', $error['code'] ?? null, $raw );
			}
			// A job that is no undo, and no job at all.
			$others = [
				$apply                    => 'not_an_undo',
				'20261121T120000Z-0c0d99' => 'not_found',
			];
			foreach ( $others as $id => $code ) {
				[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $id . '/kept-entries' );
				self::assertSame( 404, $status, $id . ': ' . $raw );
				self::assertSame( 'backups_for_restic_' . $code, $error['code'] ?? null, $raw );
			}
			// A sealed list whose page was changed since is unavailable too, not an entry that the undo never recorded.
			$page = TestSite::private_dir() . '/jobs/' . $undo . '/kept/rows-0.1';
			self::assertFileExists( $page );
			file_put_contents( $page, str_replace( 'protected', 'changed_since_apply', (string) file_get_contents( $page ) ) );
			[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $undo . '/kept-entries' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_kept_list_unavailable', $error['code'] ?? null, $raw );
		} finally {
			foreach ( [ $undo, $unsealed, $apply, substr( $apply, 0, -1 ) . '1' ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
			TempDir::remove( TestSite::private_dir() . '/restores/' . substr( $apply, 0, -1 ) . '1' );
		}
	}

	public function test_kept_entries_of_a_job_that_exists_are_not_answered_as_those_of_a_job_that_does_not(): void {
		$running    = '20261126T120000Z-0c0e01';
		$backup     = '20261126T120000Z-0c0e02';
		$unreadable = '20261126T120000Z-0c0e03';
		// An undo at work, whose tick holds the lease: no other tick touches it meanwhile. And a
		// backup that has ended.
		TestSite::php(
			'
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
			$container->private_dir()->ensure();
			$jobs = $container->jobs();
			$undo = new BackupsForRestic\Jobs\Job( $arguments[0], BackupsForRestic\Jobs\JobType::Undo, "cli", time() - 60 );
			$undo->data             = [ "apply" => "20261120T000000Z-0a0b01", "restore" => "20261120T000000Z-0a0b02" ];
			$undo->state            = BackupsForRestic\Jobs\JobState::Running;
			$undo->step             = "undoing";
			$undo->started_at       = time() - 30;
			$undo->lease_owner      = "0123456789abcdef";
			$undo->lease_expires_at = time() + 3600;
			$jobs->save( $undo );
			$backup = new BackupsForRestic\Jobs\Job( $arguments[1], BackupsForRestic\Jobs\JobType::Backup, "cli", time() - 120 );
			$backup->state       = BackupsForRestic\Jobs\JobState::Succeeded;
			$backup->finished_at = time() - 60;
			$jobs->save( $backup );
			',
			[ $running, $backup ]
		);
		// A document that this version cannot read.
		mkdir( TestSite::private_dir() . '/jobs/' . $unreadable, 0700 );
		file_put_contents( TestSite::private_dir() . '/jobs/' . $unreadable . '/job.json', '{' );
		try {
			$answers = [];
			foreach ( [ $running, $backup, $unreadable ] as $id ) {
				[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $id . '/kept-entries' );
				$answers[ $id ]           = [ $status, $error['code'] ?? null, $error['message'] ?? null, $error['data']['hint'] ?? null ];
				// Before, each of them was "The job does not exist."
				self::assertStringNotContainsString( 'does not exist', $raw, $id );
			}
			self::assertSame(
				[
					$running    => [ 409, 'backups_for_restic_undo_not_ended', 'This undo has not ended yet.', 'The entries that it keeps are listed once it has ended.' ],
					$backup     => [ 404, 'backups_for_restic_not_an_undo', 'This job is not an undo of live file changes.', 'Only an undo keeps entries that it could not revert, and it lists them once it has ended.' ],
					$unreadable => [ 409, 'backups_for_restic_job_unreadable', 'This version of the plugin cannot read the stored job ' . $unreadable . '.', 'Install a plugin version that can read the job. Its files are kept, and new jobs and repository changes stay blocked until it can be read.' ],
				],
				$answers
			);
		} finally {
			foreach ( [ $running, $backup, $unreadable ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_a_stored_job_that_cannot_be_read_is_named_by_every_route_and_command_that_meets_it(): void {
		// The newest of the two, which the refusal of a repository change names.
		$inaccessible = '20261126T150000Z-0c2002';
		$unreadable   = '20261126T150000Z-0c2001';
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
		mkdir( TestSite::private_dir() . '/jobs/' . $unreadable, 0700 );
		file_put_contents( TestSite::private_dir() . '/jobs/' . $unreadable . '/job.json', '{' );
		// As a WP-CLI command run as another user leaves the folder: the web server's PHP user cannot search it.
		$folder = TestSite::private_dir() . '/jobs/' . $inaccessible;
		chmod( $folder, 0600 );
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$named = 'The PHP user cannot open the stored job ' . $inaccessible . ' in the private working directory.';
			// Before, GET /jobs/{id}, its log and its cancellation answered 404 "The job does not exist.",
			// and the others job_unreadable, whose texts ask for another plugin version and name no job.
			foreach ( [ [ 'GET', '/jobs/' . $inaccessible ], [ 'GET', '/jobs/' . $inaccessible . '/log' ], [ 'POST', '/jobs/' . $inaccessible . '/cancel' ], [ 'GET', '/jobs/' . $inaccessible . '/kept-entries' ], [ 'DELETE', '/restores/' . $inaccessible ], [ 'DELETE', '/repository/ca-certificate' ] ] as [ $method, $route ] ) {
				[ $status, $error, $raw ] = TestSite::api( $method, $route );
				self::assertSame( [ 409, 'backups_for_restic_job_inaccessible', $named, $inaccessible ], [ $status, $error['code'] ?? null, $error['message'] ?? null, $error['data']['job'] ?? null ], $method . ' ' . $route . ': ' . $raw );
				self::assertStringContainsString( 'Give the PHP user back ownership of that folder', (string) ( $error['data']['hint'] ?? '' ), $raw );
			}
			foreach ( [ 'logs', 'cancel' ] as $command ) {
				[ $status, $output ] = TestSite::wp( [ 'backups-for-restic', $command, $inaccessible ] );
				self::assertSame( 1, $status, $output );
				// Before: "The job does not exist."
				self::assertStringContainsString( $named . ' The job\'s folder under jobs/', $output, $command );
			}
			chmod( $folder, 0700 );
			// A document that this version cannot parse keeps its code, and now names its job as well.
			[ $status, $error, $raw ] = TestSite::api( 'GET', '/jobs/' . $unreadable );
			self::assertSame( [ 409, 'backups_for_restic_job_unreadable', $unreadable ], [ $status, $error['code'] ?? null, $error['data']['job'] ?? null ], $raw );
			self::assertSame( 'Install a plugin version that can read the job. Its files are kept, and new jobs and repository changes stay blocked until it can be read.', $error['data']['hint'] ?? null );
			[ $status, , $raw ] = TestSite::api( 'GET', '/jobs/20261126T150000Z-0c2003' );
			self::assertSame( 404, $status, 'An ID that no job has: ' . $raw );
		} finally {
			chmod( $folder, 0700 );
			foreach ( [ $inaccessible, $unreadable ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_stored_jobs_that_cannot_be_read_are_shown_on_the_dashboard_in_the_job_list_and_by_status(): void {
		$unreadable   = '20261127T100000Z-0c6001';
		$inaccessible = '20261127T100000Z-0c6002';
		mkdir( TestSite::private_dir() . '/jobs/' . $unreadable, 0700 );
		file_put_contents( TestSite::private_dir() . '/jobs/' . $unreadable . '/job.json', '{' );
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
		try {
			if ( is_executable( $folder ) ) {
				self::markTestSkipped( 'This user bypasses directory permissions.' );
			}
			$expected = [
				[ $inaccessible, 'job_inaccessible', 'The PHP user cannot open the stored job ' . $inaccessible . ' in the private working directory.' ],
				[ $unreadable, 'job_unreadable', 'This version of the plugin cannot read the stored job ' . $unreadable . '.' ],
			];
			// Before, neither the dashboard nor the job list showed them: only the refusal of a new
			// job named one, and scheduled backups did not start without a word.
			foreach ( [ '/status', '/jobs' ] as $route ) {
				[ $status, $answer, $raw ] = TestSite::api( 'GET', $route );
				self::assertSame( 200, $status, $raw );
				$listed = array_map( static fn ( array $job ): array => [ $job['id'] ?? null, $job['code'] ?? null, $job['message'] ?? null ], (array) ( $answer['unreadable_jobs'] ?? [] ) );
				self::assertSame( $expected, $listed, $route );
				self::assertStringContainsString( 'Give the PHP user back ownership of that folder', (string) ( $answer['unreadable_jobs'][0]['hint'] ?? '' ) );
				self::assertStringContainsString( 'Install a plugin version that can read the job.', (string) ( $answer['unreadable_jobs'][1]['hint'] ?? '' ) );
			}
			[ $status, $output ] = TestSite::wp( [ 'backups-for-restic', 'status' ] );
			self::assertSame( 0, $status, $output );
			foreach ( $expected as [ $id, , $message ] ) {
				self::assertStringContainsString( 'Stored job that cannot be read: ' . $id, $output );
				self::assertStringContainsString( $message, $output );
			}
			self::assertStringContainsString( 'No backup starts while it cannot be read, the scheduled ones included.', $output );
		} finally {
			chmod( $folder, 0700 );
			foreach ( [ $unreadable, $inaccessible ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_a_cancelled_job_whose_cleanup_failed_is_answered_with_its_state_its_counts_and_its_error(): void {
		$undo   = '20261126T160000Z-0c4001';
		$apply  = '20261126T160000Z-0c4002';
		$backup = '20261126T160000Z-0c4003';
		// Cancelled, and then the site could not leave maintenance mode: the runner keeps the
		// state cancelled and records the cleanup's failure as the job's error.
		TestSite::php(
			'
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
			$container->private_dir()->ensure();
			$stuck = new BackupsForRestic\Jobs\JobError( "maintenance_stuck", "The maintenance marker could not be removed.", false );
			$jobs  = [
				[ $arguments[0], BackupsForRestic\Jobs\JobType::Undo, [ "restored" => 1, "removed" => 2, "kept" => 0, "unchanged" => 0, "apply_state" => "partly_undone" ], $stuck ],
				[ $arguments[1], BackupsForRestic\Jobs\JobType::Apply, [ "entries_completed" => 2, "entries_attempted" => 3 ], $stuck ],
				[ $arguments[2], BackupsForRestic\Jobs\JobType::Backup, [], null ],
			];
			foreach ( $jobs as [ $id, $type, $result, $error ] ) {
				$job              = new BackupsForRestic\Jobs\Job( $id, $type, "manual", time() - 120 );
				$job->state       = BackupsForRestic\Jobs\JobState::Cancelled;
				$job->finished_at = time() - 60;
				$job->data        = [ "apply" => "20261120T000000Z-0a0b05", "restore" => "20261120T000000Z-0a0b06" ];
				$job->result      = $result;
				$job->error       = $error;
				$container->jobs()->save( $job );
			}
			',
			[ $undo, $apply, $backup ]
		);
		try {
			$answers = [];
			foreach ( [ $undo, $apply, $backup ] as $id ) {
				[ $status, $answer, $raw ] = TestSite::api( 'GET', '/jobs/' . $id );
				self::assertSame( 200, $status, $raw );
				$answers[ $id ] = [ $answer['job']['state'] ?? null, $answer['job']['error']['code'] ?? null, $answer['job']['error']['message'] ?? null ];
			}
			// What the admin page shows a stopped apply or undo from: the state, the counts and the error.
			self::assertSame(
				[
					$undo   => [ 'cancelled', 'maintenance_stuck', 'The site could not leave maintenance mode.' ],
					$apply  => [ 'cancelled', 'maintenance_stuck', 'The site could not leave maintenance mode.' ],
					$backup => [ 'cancelled', null, null ],
				],
				$answers
			);
			// WP-CLI's report after an undo. Before: "The undo was cancelled.", without the failure.
			$report              = '( new ReflectionMethod( BackupsForRestic\WordPress\CliCommand::class, "report" ) )->invoke( new BackupsForRestic\WordPress\CliCommand( new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ) ), "' . $undo . '" );';
			[ $status, $output ] = TestSite::wp( [ 'eval', $report ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The undo was cancelled. The site could not leave maintenance mode. Before it stopped, it did this: 1 restored, 2 removed, 0 kept, 0 unchanged.', $output );
		} finally {
			foreach ( [ $undo, $apply, $backup ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_an_undo_cancelled_after_it_counted_every_entry_records_its_apply_as_undone(): void {
		$site  = TestSite::root();
		$apply = '20261126T140000Z-0c1010';
		$undo  = '20261126T140000Z-0c1020';
		TestSite::undoable_apply(
			$apply,
			[
				[ $site . '/bfr-counted-1.txt', $site . '/bfr-counted-1.txt', 'create' ],
				[ $site . '/bfr-counted-2.txt', $site . '/bfr-counted-2.txt', 'create' ],
			]
		);
		// The worker counted the undo's last entry, and the request ended before the runner saved
		// the step after it; then the administrator cancelled the undo.
		TestSite::php(
			'
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
			$jobs = $container->jobs();
			$undo = new BackupsForRestic\Jobs\Job( $arguments[0], BackupsForRestic\Jobs\JobType::Undo, "cli", time() - 60 );
			$undo->data             = [
				"apply"             => $arguments[1],
				"restore"           => substr( $arguments[1], 0, -1 ) . "1",
				"maintenance_grant" => "",
				"undo_counts"       => [ "restored" => 0, "removed" => 2, "unchanged" => 0, "kept" => 0, "changed_after_review" => 0 ],
			];
			$undo->state            = BackupsForRestic\Jobs\JobState::Running;
			$undo->step             = "undoing";
			$undo->started_at       = time() - 30;
			$undo->progress         = [ "total" => 2, "done" => 2 ];
			$undo->cancel_requested = true;
			$jobs->save( $undo );
			',
			[ $undo, $apply ]
		);
		try {
			[ $status, $output ] = TestSite::wp( [ 'backups-for-restic', 'tick' ] );
			self::assertSame( 0, $status, $output );
			$job = TestSite::job( $undo );
			self::assertSame( [ 'cancelled', 2, 0, 'undone' ], [ $job['state'] ?? null, $job['result']['removed'] ?? null, $job['result']['kept'] ?? null, $job['result']['apply_state'] ?? null ], (string) json_encode( $job ) );
			// Before: listed as partly undone, "the last undo stopped before it finished", until a review retired it.
			[ $status, $listed, $raw ] = TestSite::api( 'GET', '/undo' );
			self::assertSame( 200, $status, $raw );
			self::assertNotContains( $apply, array_column( $listed['jobs'] ?? [], 'job' ), $raw );
			$document = TestSite::job_document( $apply );
			self::assertSame( [ 'undone', 0, $undo ], [ $document['result']['undo_state'] ?? null, $document['result']['undo_kept'] ?? null, $document['result']['undo_job'] ?? null ] );
			self::assertFileExists( TestSite::private_dir() . '/jobs/' . $apply . '/undo/reverted' );
		} finally {
			foreach ( [ $apply, $undo, substr( $apply, 0, -1 ) . '1' ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
			TempDir::remove( TestSite::private_dir() . '/restores/' . substr( $apply, 0, -1 ) . '1' );
		}
	}

	public function test_a_failed_job_is_described_as_the_kind_of_job_it_is(): void {
		// Kind of job, its error code and its data; the review of an undo is a file preview job.
		$failed = [
			'20261126T130000Z-0c0f01' => [ 'restore', 'key_missing', [] ],
			'20261126T130000Z-0c0f02' => [ 'restore', 'environment', [] ],
			'20261126T130000Z-0c0f03' => [ 'prune', 'environment', [] ],
			'20261126T130000Z-0c0f04' => [ 'undo', 'file_writes_refused', [] ],
			'20261126T130000Z-0c0f05' => [ 'file_plan', 'file_staging_gone', [ 'undo' => true ] ],
			'20261126T130000Z-0c0f06' => [ 'database_undo', 'database_exchange_failed', [] ],
			'20261126T130000Z-0c0f07' => [ 'backup', 'environment', [] ],
		];
		TestSite::php(
			'
			$container = new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" );
			$container->private_dir()->ensure();
			foreach ( json_decode( $arguments[0], true ) as $id => [ $type, $code, $data ] ) {
				$job              = new BackupsForRestic\Jobs\Job( $id, BackupsForRestic\Jobs\JobType::from( $type ), "manual", time() - 60 );
				$job->state       = BackupsForRestic\Jobs\JobState::Failed;
				$job->finished_at = time() - 30;
				$job->data        = $data;
				$job->error       = new BackupsForRestic\Jobs\JobError( $code, "The job stopped.", false );
				$container->jobs()->save( $job );
			}
			',
			[ (string) json_encode( $failed ) ]
		);
		try {
			$texts = [];
			foreach ( array_keys( $failed ) as $id ) {
				[ $status, $answer, $raw ] = TestSite::api( 'GET', '/jobs/' . $id );
				self::assertSame( 200, $status, $raw );
				self::assertSame( $failed[ $id ][1], $answer['job']['error']['code'] ?? null, $raw );
				$texts[ $id ] = (string) ( $answer['job']['error']['message'] ?? '' ) . ' ' . (string) ( $answer['job']['error']['hint'] ?? '' );
				if ( 'backup' !== $failed[ $id ][0] ) {
					// Before: "The backup is not configured completely" and "This server cannot run the backup right now".
					self::assertDoesNotMatchRegularExpression( '/the backup|a backup job|next backup/i', $texts[ $id ], $id );
				}
			}
			self::assertStringStartsWith( 'The repository key that this job unlocked is no longer stored.', $texts['20261126T130000Z-0c0f01'] );
			self::assertStringStartsWith( 'This job could not store or read its working data.', $texts['20261126T130000Z-0c0f02'] );
			self::assertStringStartsWith( 'This server cannot run this job right now.', $texts['20261126T130000Z-0c0f03'] );
			// An undo's reverts are not changes that can be undone, and staging again does not bring back its staged files.
			self::assertStringContainsString( 'What the undo reverted stays reverted.', $texts['20261126T130000Z-0c0f04'] );
			self::assertStringNotContainsString( 'Changes already made can be undone', $texts['20261126T130000Z-0c0f04'] );
			self::assertStringContainsString( 'Without them this apply cannot be undone.', $texts['20261126T130000Z-0c0f05'] );
			self::assertStringNotContainsString( 'Restore the snapshot to staging again', $texts['20261126T130000Z-0c0f05'] );
			// The staged SQL files are no way back to the database that an undo brings back.
			self::assertStringNotContainsString( 'staged SQL files', $texts['20261126T130000Z-0c0f06'] );
			self::assertStringStartsWith( 'This server cannot run the backup right now.', $texts['20261126T130000Z-0c0f07'] );
			// The e-mail about a failed prune says what the admin page says.
			$mail = TestSite::php(
				'
				[ , $body ] = BackupsForRestic\WordPress\WpMailer::compose( new BackupsForRestic\Notifications\Notice( BackupsForRestic\Notifications\NoticeKind::Failure, time(), "prune", $arguments[0], "environment" ) );
				echo $body;
				',
				[ '20261126T130000Z-0c0f03' ]
			);
			self::assertStringContainsString( 'What happened: This server cannot run this job right now.', $mail );
			self::assertStringNotContainsString( 'cannot run the backup', $mail );
		} finally {
			foreach ( array_keys( $failed ) as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_the_file_routes_say_what_keeps_or_stops_a_review_and_not_that_a_backup_cannot_run(): void {
		$site    = TestSite::root();
		$rows    = [ [ $site . '/bfr-mapped.txt', $site . '/bfr-mapped.txt', 'create' ] ];
		$apply   = '20261123T120000Z-0e0f10';
		$expired = '20261123T120000Z-0e0f20';
		$damaged = '20261123T120000Z-0e0f30';
		TestSite::undoable_apply( $apply, $rows );
		TestSite::undoable_apply( $expired, $rows, 86401 );
		TestSite::undoable_apply( $damaged, $rows );
		// A manifest that does not match its hash any more.
		file_put_contents( TestSite::private_dir() . '/jobs/' . $damaged . '/undo/manifest.json', '[]' );
		try {
			// The undo of an apply needs its staged files: they are kept, and the answer says until when.
			$ended                    = (int) ( TestSite::job_document( $apply )['finished_at'] ?? 0 );
			[ $status, $error, $raw ] = TestSite::api( 'DELETE', '/restores/' . substr( $apply, 0, -1 ) . '1' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_restore_kept_for_undo', $error['code'] ?? null, $raw );
			self::assertSame( $ended + 86400, $error['data']['until'] ?? null, $raw );
			self::assertStringContainsString( 'These staged files are kept until ', (string) ( $error['message'] ?? '' ) );
			self::assertStringContainsString( 'undone completely', (string) ( $error['data']['hint'] ?? '' ) );
			self::assertDirectoryExists( TestSite::private_dir() . '/restores/' . substr( $apply, 0, -1 ) . '1/files' );
			// A review says when it was made: a confirmation whose answer is lost looks for its job from then on.
			$before                    = time();
			[ $status, $review, $raw ] = TestSite::api( 'POST', '/undo/' . $apply . '/plan', [] );
			self::assertSame( 200, $status, $raw );
			self::assertIsInt( $review['reviewed_at'] ?? null, $raw );
			self::assertGreaterThanOrEqual( $before, $review['reviewed_at'] );
			self::assertLessThanOrEqual( time(), $review['reviewed_at'] );
			// An apply whose recovery window has ended, and a preview that is gone.
			[ $status, $error, $raw ] = TestSite::api( 'POST', '/undo/' . $expired . '/plan', [] );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_undo_unavailable', $error['code'] ?? null, $raw );
			[ $status, $error, $raw ] = TestSite::api(
				'POST',
				'/restores/' . substr( $apply, 0, -1 ) . '1/apply-plan',
				[
					'plan_job' => '20261123T120000Z-0e0f99',
					'offset'   => 0,
				]
			);
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_file_preview_expired', $error['code'] ?? null, $raw );
			// A private record that cannot be read: not "This server cannot run the backup right now".
			[ $status, $error, $raw ] = TestSite::api( 'POST', '/undo/' . $damaged . '/plan', [] );
			self::assertSame( 500, $status, $raw );
			self::assertSame( 'backups_for_restic_live_plan_failed', $error['code'] ?? null, $raw );
			self::assertStringNotContainsString( 'backup', strtolower( (string) ( $error['message'] ?? '' ) ) );
		} finally {
			foreach ( [ $apply, $expired, $damaged ] as $id ) {
				$restore = substr( $id, 0, -1 ) . '1';
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $restore );
				TempDir::remove( TestSite::private_dir() . '/restores/' . $restore );
			}
		}
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
		sort( $registered, SORT_STRING );
		$tested = array_keys( iterator_to_array( self::routes() ) );
		sort( $tested, SORT_STRING );
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
