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
			[ 'GET', '/database-changes/foreign' ],
			[ 'POST', '/database-changes/' . self::JOB . '/undo-plan' ],
			[ 'POST', '/database-changes/' . self::JOB . '/undo' ],
			[ 'POST', '/database-changes/' . self::JOB . '/discard-review' ],
			[ 'POST', '/database-changes/' . self::JOB . '/discard' ],
			[ 'POST', '/database-changes/' . self::JOB . '/discarded-done' ],
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
			foreach ( [ "/restores/" . $arguments[0] . "/database/apply", "/database-changes/" . $arguments[0] . "/undo", "/database-changes/" . $arguments[0] . "/discard", "/database-changes/" . $arguments[0] . "/discarded-done" ] as $route ) {
				echo rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1" . $route ) )->get_status(), "\n";
			}
			',
			[ self::JOB ]
		);
		self::assertSame( [ '403', '403', '403', '403' ], explode( "\n", trim( $output ) ) );
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
			// A confirmation of a review in all but the password, which is counted as last.
			$digest = str_repeat( "a", 64 );
			$token  = wp_create_nonce( implode( "|", [ "backups_for_restic_live", home_url(), "tables", "discard", $arguments[0], "default", $digest, "" ] ) );
			$request->set_body( wp_json_encode( [ "reviewed" => true, "site_url" => home_url(), "digest" => $digest, "review_token" => $token, "user_password" => "wrong-password-test-only" ] ) );
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

	/**
	 * Sends the confirmation of a discard, or the marking of a discarded binding's tables as
	 * taken care of, again while another request holds the runner lock, at most 40 times in ten
	 * seconds. A request of the test can start WP-Cron, whose heartbeat holds that lock for a
	 * moment, and the route then answers with its busy code before it looks at the job, and
	 * changes nothing. The test checks the first other answer; the busy answer itself is checked
	 * with a lock that the test holds.
	 *
	 * @param \Closure():array{int, array<mixed>, string} $send The request, as TestSite::api() answers it.
	 * @param string                                      $busy The route's busy code: database_discard_busy, or database_discarded_busy for the marking.
	 * @return array{int, array<mixed>, string} The first answer that is not the busy one, or the last one.
	 */
	private static function unless_busy( \Closure $send, string $busy = 'database_discard_busy' ): array {
		for ( $attempt = 1; ; ++$attempt ) {
			$answer = $send();
			if ( 'backups_for_restic_' . $busy !== ( $answer[1]['code'] ?? null ) || $attempt >= 40 ) {
				return $answer;
			}
			usleep( 250000 );
		}
	}

	/**
	 * A confirmation of the discard of a binding: the fields of an undo's confirmation, with
	 * changes.
	 *
	 * @param array<string, mixed> $review  Answer of the review.
	 * @param array<string, mixed> $changes Fields to replace.
	 * @return array<string, mixed> JSON body.
	 */
	private static function discard_confirmation( array $review, array $changes = [] ): array {
		return array_replace(
			[
				'reviewed'      => true,
				'site_url'      => $review['site_url'] ?? null,
				'digest'        => $review['digest'] ?? null,
				'review_token'  => $review['review_token'] ?? null,
				'user_password' => TestSite::ADMIN_PASSWORD,
			],
			$changes
		);
	}

	/**
	 * A finished database apply within its undo window whose binding names another server, and
	 * the review of its discard.
	 *
	 * @param string $job Job ID.
	 * @return array{string, array<string, mixed>} The route of the job and the review.
	 */
	private static function reviewed_binding( string $job ): array {
		TestSite::database_binding(
			$job,
			true,
			[
				'database_generation' => 'applied',
				'retained_until'      => time() + 3600,
			],
			'succeeded'
		);
		$route                     = '/database-changes/' . $job;
		[ $status, $review, $raw ] = TestSite::api( 'POST', $route . '/discard-review', [] );
		self::assertSame( 200, $status, $raw );
		return [ $route, $review ];
	}

	public function test_the_discard_of_a_database_binding_is_confirmed_as_an_undo_is(): void {
		$job = '20261126T120000Z-0d1500';
		try {
			[ $route, $review ]        = self::reviewed_binding( $job );
			[ $status, $listed, $raw ] = TestSite::api( 'GET', '/database-changes/foreign' );
			self::assertSame( 200, $status, $raw );
			self::assertContains( $job, array_column( $listed['jobs'] ?? [], 'job' ), $raw );
			self::assertTrue( $review['write_allowed'] ?? null );
			self::assertSame( [ [], false ], [ $review['write_lacking'] ?? null, $review['file_mods_disallowed'] ?? null ] );
			self::assertIsString( $review['review_token'] ?? null );
			self::assertSame( TestSite::url(), $review['site_url'] ?? null );
			self::assertSame( '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002', $review['server'] ?? null );
			self::assertCount( 7, $review['tables'] ?? [] );
			self::assertIsInt( $review['undo_until'] ?? null );
			$refused = static function ( array $changes ) use ( $route, $review ): array {
				[ $status, $error ] = self::unless_busy( static fn (): array => TestSite::api( 'POST', $route . '/discard', self::discard_confirmation( $review, $changes ) ) );
				return [ $status, $error['code'] ?? null ];
			};
			$token   = static fn ( string $operation, string $digest ): string => TestSite::php(
				'wp_set_current_user( 1 ); echo "\n", wp_create_nonce( implode( "|", [ "backups_for_restic_live", home_url(), "tables", $arguments[0], $arguments[1], "default", $arguments[2], "" ] ) );',
				[ $operation, $job, $digest ]
			);
			$other   = str_repeat( 'e', 64 );
			self::assertSame( [ 400, 'backups_for_restic_live_confirmation' ], $refused( [ 'reviewed' => false ] ) );
			self::assertSame( [ 400, 'backups_for_restic_live_confirmation' ], $refused( [ 'site_url' => TestSite::url() . '/' ] ) );
			self::assertSame( [ 409, 'backups_for_restic_database_discard_changed' ], $refused( [ 'review_token' => null ] ) );
			self::assertSame( [ 409, 'backups_for_restic_database_discard_changed' ], $refused( [ 'digest' => '' ] ) );
			// The token of an undo of the same job and digest, and a token for another digest.
			self::assertSame( [ 409, 'backups_for_restic_database_discard_changed' ], $refused( [ 'review_token' => TestSite::last_line( $token( 'undo', $review['digest'] ) ) ] ) );
			self::assertSame(
				[ 409, 'backups_for_restic_database_discard_changed' ],
				$refused(
					[
						'digest'       => $other,
						'review_token' => TestSite::last_line( $token( 'discard', $other ) ),
					]
				)
			);
			self::assertSame( [ 403, 'backups_for_restic_wrong_user_password' ], $refused( [ 'user_password' => 'wrong-password-test-only' ] ) );
			// The binding changed after the review: its undo window ended.
			$file     = TestSite::private_dir() . '/jobs/' . $job . '/job.json';
			$document = (string) file_get_contents( $file );
			$changed  = json_decode( $document, true );
			self::assertIsArray( $changed );
			$changed['data']['retained_until'] = time() - 1;
			file_put_contents( $file, (string) json_encode( $changed ) );
			self::assertSame( [ 409, 'backups_for_restic_database_discard_changed' ], $refused( [] ) );
			self::assertArrayHasKey( 'database_owner', TestSite::job_document( $job )['data'] ?? [] );
			file_put_contents( $file, $document );
			[ $status, $answer, $raw ] = self::unless_busy( static fn (): array => TestSite::api( 'POST', $route . '/discard', self::discard_confirmation( $review ) ) );
			self::assertSame( 200, $status, $raw );
			self::assertSame( $review['tables'], $answer['discarded']['tables'] ?? null );
			self::assertSame( $review['server'], $answer['discarded']['server'] ?? null );
			$recorded = TestSite::job_document( $job )['data'] ?? [];
			self::assertArrayNotHasKey( 'database_owner', $recorded );
			self::assertSame( [ 1, 'rest', $review['digest'] ], [ $recorded['database_discarded']['user'] ?? null, $recorded['database_discarded']['via'] ?? null, $recorded['database_discarded']['digest'] ?? null ] );
			self::assertStringContainsString( '"digest":"' . $review['digest'] . '"', TestSite::job_log( $job ) );
			self::assertSame( [ 409, 'backups_for_restic_database_binding_discarded' ], $refused( [] ) );
			[ $status, $error ] = TestSite::api( 'POST', $route . '/discard-review', [] );
			self::assertSame( [ 409, 'backups_for_restic_database_binding_discarded' ], [ $status, $error['code'] ?? null ] );
			self::assertStringContainsString( '“Discarded bindings”', $error['data']['hint'] ?? '' );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
		}
	}

	public function test_a_discard_waits_for_another_request_that_holds_the_runner_lock(): void {
		$job = '20261126T120000Z-0d1507';
		try {
			[ $route, $review ] = self::reviewed_binding( $job );
			$lock               = fopen( TestSite::private_dir() . '/runner.lock', 'c' );
			self::assertIsResource( $lock );
			self::assertTrue( flock( $lock, LOCK_EX ) );
			try {
				[ $status, $error ] = TestSite::api( 'POST', $route . '/discard', self::discard_confirmation( $review ) );
				self::assertSame( [ 409, 'backups_for_restic_database_discard_busy' ], [ $status, $error['code'] ?? null ] );
				self::assertArrayHasKey( 'database_owner', TestSite::job_document( $job )['data'] ?? [] );
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
			// The review is still valid once the lock is free.
			[ $status, , $raw ] = self::unless_busy( static fn (): array => TestSite::api( 'POST', $route . '/discard', self::discard_confirmation( $review ) ) );
			self::assertSame( 200, $status, $raw );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
		}
	}

	public function test_the_tables_of_a_discarded_binding_are_listed_until_they_are_taken_care_of(): void {
		$job = '20261126T120000Z-0d1508';
		try {
			[ $route, $review ]        = self::reviewed_binding( $job );
			[ $status, $answer, $raw ] = self::unless_busy( static fn (): array => TestSite::api( 'POST', $route . '/discard', self::discard_confirmation( $review ) ) );
			self::assertSame( 200, $status, $raw );
			// The Restores tab lists the tables, whatever the job history does, until they are taken care of.
			$listed = static function (): array {
				[ $status, $listed, $raw ] = TestSite::api( 'GET', '/database-changes/foreign' );
				self::assertSame( 200, $status, $raw );
				self::assertIsArray( $listed['discarded'] ?? null, $raw );
				self::assertTrue( $listed['write_allowed'] ?? null, $raw );
				return $listed['discarded'];
			};
			$entry  = array_values( array_filter( $listed(), static fn ( array $entry ): bool => $job === ( $entry['job'] ?? null ) ) );
			self::assertCount( 1, $entry );
			self::assertSame( [ $review['tables'], $answer['discarded']['until'] ?? null ], [ $entry[0]['tables'] ?? null, $entry[0]['until'] ?? null ] );
			$mark                    = static fn (): array => TestSite::api( 'POST', $route . '/discarded-done', [] );
			[ $status, $done, $raw ] = self::unless_busy( $mark, 'database_discarded_busy' );
			self::assertSame( 200, $status, $raw );
			self::assertSame( $review['tables'], $done['done']['tables'] ?? null );
			self::assertSame( 1, TestSite::job_document( $job )['data']['database_discarded']['done']['user'] ?? null );
			[ $status, $error ] = self::unless_busy( $mark, 'database_discarded_busy' );
			self::assertSame( [ 409, 'backups_for_restic_database_discarded_missing' ], [ $status, $error['code'] ?? null ] );
			// Before, a failed answer of the list passed here as one without the binding.
			self::assertNotContains( $job, array_column( $listed(), 'job' ) );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
		}
	}

	public function test_the_review_of_a_discard_says_what_is_the_case_and_where_a_database_error_is_logged(): void {
		$verified = '20261126T120000Z-0d1501';
		$damaged  = '20261126T120000Z-0d1502';
		$failing  = '20261126T120000Z-0d1503';
		TestSite::database_binding( $verified, false );
		TestSite::database_binding( $damaged, true, [ 'database_owner' => [ 'version' => 2 ] ] );
		TestSite::database_binding( $failing, true );
		try {
			$reviewed = static function ( string $job ): array {
				[ $status, $error ] = TestSite::api( 'POST', '/database-changes/' . $job . '/discard-review', [] );
				return [ $status, $error['code'] ?? null ];
			};
			self::assertSame( [ 409, 'backups_for_restic_database_binding_verified' ], $reviewed( $verified ) );
			self::assertSame( [ 500, 'backups_for_restic_database_binding_unreadable' ], $reviewed( $damaged ) );
			self::assertSame( [ 409, 'backups_for_restic_database_binding_missing' ], $reviewed( '20991231T235959Z-ffffff' ) );
			self::assertSame( [ 409, 'backups_for_restic_database_binding_missing' ], $reviewed( 'not-a-job' ) );
			[ $status, $listed, $raw ] = TestSite::api( 'GET', '/database-changes/foreign' );
			self::assertSame( 200, $status, $raw );
			self::assertNotContains( $verified, array_column( $listed['jobs'] ?? [], 'job' ) );
			self::assertNotContains( $damaged, array_column( $listed['jobs'] ?? [], 'job' ) );
			// The site's database connection fails when the review asks the server who it is. A first
			// review keeps what WordPress and the plugin read in this request's cache, so that only
			// the plugin's own connection meets the broken one, which cannot be opened again.
			$output                                     = TestSite::php(
				'
				wp_set_current_user( 1 );
				$review = static fn (): WP_REST_Response => rest_do_request( new WP_REST_Request( "POST", "/backups-for-restic/v1/database-changes/" . $arguments[0] . "/discard-review" ) );
				$first  = $review()->get_status();
				global $wpdb;
				$host         = $wpdb->dbhost;
				$handle       = $wpdb->dbh;
				$wpdb->dbhost = "127.0.0.1:1";
				$wpdb->dbh    = mysqli_init();
				$response     = $review();
				$wpdb->dbhost = $host;
				$wpdb->dbh    = $handle;
				$wpdb->ready  = true;
				$data         = $response->get_data();
				echo "\n", wp_json_encode( [ $first, $response->get_status(), $data["code"] ?? null, $data["message"] ?? null, $data["data"]["hint"] ?? null ] );
				',
				[ $failing ]
			);
			[ $first, $status, $code, $message, $hint ] = json_decode( TestSite::last_line( $output ), true );
			self::assertSame( 200, $first, $output );
			self::assertSame( [ 500, 'backups_for_restic_database_discard_failed' ], [ $status, $code ], $output );
			self::assertSame( 'The plugin could not ask the database server whether it holds the private tables of this database restore.', $message );
			self::assertStringContainsString( 'Nothing was discarded. The log of that database restore job names the database error: “Show the log of this job” beside the review of the discard opens it', $hint );
			self::assertStringNotContainsString( 'The log of the job contains', $message . ' ' . $hint );
			// The log of that job names what the database raised, whichever PHP's mysqli raises for the handle.
			$entries = array_map( static fn ( string $line ): mixed => json_decode( $line, true ), array_filter( explode( "\n", TestSite::job_log( $failing ) ) ) );
			$failure = end( $entries );
			self::assertIsArray( $failure );
			self::assertSame( [ 'error', 'database_discard_failed' ], [ $failure['level'] ?? null, $failure['context']['code'] ?? null ] );
			self::assertContains( $failure['context']['cause'] ?? null, [ 'The database restore connection is no longer usable.', 'The database connection could not be opened again.' ] );
			self::assertArrayHasKey( 'database_owner', TestSite::job_document( $failing )['data'] ?? [] );
		} finally {
			foreach ( [ $verified, $damaged, $failing ] as $job ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
			}
		}
	}

	public function test_a_site_that_allows_no_file_changes_answers_why_the_page_cannot_discard(): void {
		$job = '20261126T120000Z-0d1505';
		TestSite::database_binding( $job, true, [], 'succeeded' );
		try {
			// As DISALLOW_FILE_MODS does: WordPress then gives no account update_core.
			$output                                        = TestSite::php(
				'
				wp_set_current_user( 1 );
				add_filter( "file_mod_allowed", "__return_false" );
				$route  = "/backups-for-restic/v1/database-changes/" . $arguments[0];
				$fields = static function ( array $data ): array {
					$data = array_intersect_key( $data, [ "write_allowed" => true, "write_lacking" => true, "file_mods_disallowed" => true, "review_token" => true ] );
					ksort( $data, SORT_STRING );
					return $data;
				};
				$review = rest_do_request( new WP_REST_Request( "POST", $route . "/discard-review" ) );
				$listed = rest_do_request( new WP_REST_Request( "GET", "/backups-for-restic/v1/database-changes/foreign" ) );
				echo "\n", wp_json_encode(
					[
						$review->get_status(),
						$fields( $review->get_data() ),
						$fields( $listed->get_data() ),
						rest_do_request( new WP_REST_Request( "POST", $route . "/discard" ) )->get_status(),
						rest_do_request( new WP_REST_Request( "POST", $route . "/discarded-done" ) )->get_status(),
					]
				);
				',
				[ $job ]
			);
			[ $status, $review, $listed, $discard, $done ] = json_decode( TestSite::last_line( $output ), true );
			self::assertSame( 200, $status, $output );
			// The guard stays as the undo's; before, the answer did not say why, and the page could not either.
			self::assertSame(
				[
					'file_mods_disallowed' => true,
					'review_token'         => null,
					'write_allowed'        => false,
					'write_lacking'        => [ 'update_core' ],
				],
				$review
			);
			self::assertSame(
				[
					'file_mods_disallowed' => true,
					'write_allowed'        => false,
					'write_lacking'        => [ 'update_core' ],
				],
				$listed
			);
			self::assertSame( [ 403, 403 ], [ $discard, $done ] );
			self::assertArrayHasKey( 'database_owner', TestSite::job_document( $job )['data'] ?? [] );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
		}
	}

	public function test_tables_are_marked_as_taken_care_of_only_under_the_runner_lock(): void {
		$job = '20261126T120000Z-0d1506';
		TestSite::database_binding(
			$job,
			true,
			[
				'database_owner'     => null,
				'database_discarded' => [
					'at'       => time(),
					'user'     => 1,
					'via'      => 'rest',
					'job'      => $job,
					'database' => 'elsewhere',
					'server'   => '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002',
					'tables'   => [ '_bfr_r_' . str_repeat( 'c', 32 ) . '_p' ],
				],
			],
			'succeeded'
		);
		$route = '/database-changes/' . $job . '/discarded-done';
		try {
			// Another request holds the runner lock, as the pruning of the job history does.
			$lock = fopen( TestSite::private_dir() . '/runner.lock', 'c' );
			self::assertIsResource( $lock );
			self::assertTrue( flock( $lock, LOCK_EX ) );
			try {
				[ $status, $error ] = TestSite::api( 'POST', $route, [] );
				// Before, the job document was saved without the lock.
				self::assertSame( [ 409, 'backups_for_restic_database_discarded_busy' ], [ $status, $error['code'] ?? null ] );
				self::assertArrayNotHasKey( 'done', TestSite::job_document( $job )['data']['database_discarded'] ?? [] );
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
			// Once the test lets go of the lock, a heartbeat may hold it for a moment.
			[ $status, $done, $raw ] = self::unless_busy( static fn (): array => TestSite::api( 'POST', $route, [] ), 'database_discarded_busy' );
			self::assertSame( 200, $status, $raw );
			self::assertSame( $job, $done['done']['job'] ?? null );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
		}
	}

	public function test_a_failure_of_a_discard_that_the_service_does_not_name_is_answered_as_unexpected(): void {
		// No failure that a site can cause reaches this answer any more, so the route's mapping is
		// asked directly with failures as a defect, the private directory and a refusal raise them.
		$output = TestSite::php(
			'
			$routes = new BackupsForRestic\WordPress\RestRoutes( new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ) );
			$answer = static function ( Throwable $failure ) use ( $routes ): array {
				$error = ( new ReflectionMethod( $routes, "discard_error" ) )->invoke( $routes, $failure );
				return [ $error->get_error_code(), $error->get_error_data()["status"] ?? null, $error->get_error_message() . " " . ( $error->get_error_data()["hint"] ?? "" ) ];
			};
			echo "\n", wp_json_encode(
				[
					$answer( new BackupsForRestic\Exception\InvalidArgument( "A defect." ) ),
					$answer( new BackupsForRestic\Exception\StorageFailed( "A file could not be read." ) ),
					$answer( new BackupsForRestic\Exception\StorageFailed( "The jobs could not be listed.", [ "code" => "database_discard_storage" ] ) ),
					$answer( new BackupsForRestic\Exception\DatabaseFailed( "The server could not be asked.", [ "code" => "database_discard_failed" ] ) ),
					$answer( new BackupsForRestic\Exception\JobConflict( "Running.", [ "code" => "database_binding_busy" ] ) ),
				]
			);
			'
		);
		[ $defect, $unnamed, $storage, $database, $busy ] = json_decode( TestSite::last_line( $output ), true );
		// Before, each failure that the route did not list was answered as a database failure that the log of the job names.
		foreach ( [ $defect, $unnamed ] as $unexpected ) {
			self::assertSame( [ 'backups_for_restic_database_discard_unexpected', 500 ], [ $unexpected[0], $unexpected[1] ], $output );
			self::assertStringContainsString( 'The plugin recorded nothing about this error.', $unexpected[2] );
			self::assertStringNotContainsString( 'database error', $unexpected[2] );
		}
		self::assertSame( [ 'backups_for_restic_database_discard_storage', 500 ], [ $storage[0], $storage[1] ] );
		self::assertStringContainsString( 'Environment tab', $storage[2] );
		self::assertStringNotContainsString( 'The log of', $storage[2] );
		self::assertSame( [ 'backups_for_restic_database_discard_failed', 500 ], [ $database[0], $database[1] ] );
		self::assertStringContainsString( 'The log of that database restore job', $database[2] );
		self::assertSame( [ 'backups_for_restic_database_binding_busy', 409 ], [ $busy[0], $busy[1] ] );
	}

	public function test_a_site_whose_database_name_is_not_utf8_reviews_and_discards_a_binding_and_the_answer_is_utf8(): void {
		$job = '20261126T120000Z-0d1504';
		TestSite::database_binding( $job, true, [], 'succeeded' );
		try {
			[ $output, $name ] = TestSite::in_latin1_database(
				static fn ( string $name ): array => [
					TestSite::php(
						'
						wp_set_current_user( 1 );
						$route  = "/backups-for-restic/v1/database-changes/" . $arguments[0];
						$review = static fn (): WP_REST_Response => rest_do_request( new WP_REST_Request( "POST", $route . "/discard-review" ) );
						// A first review reads what WordPress keeps in this request\'s cache from its own database.
						$first = $review()->get_status();
						global $wpdb;
						$wpdb->dbh->set_charset( "latin1" );
						$selected = $wpdb->dbh->select_db( mb_convert_encoding( $arguments[1], "ISO-8859-1", "UTF-8" ) );
						$reviewed = $review();
						$data     = $reviewed->get_data();
						// What the REST API sends: the server encodes the answer so.
						$sent      = wp_json_encode( rest_get_server()->response_to_data( $reviewed, false ) );
						$confirmed = new WP_REST_Request( "POST", $route . "/discard" );
						$confirmed->set_header( "Content-Type", "application/json" );
						$confirmed->set_body( (string) wp_json_encode( [ "reviewed" => true, "site_url" => $data["site_url"] ?? null, "digest" => $data["digest"] ?? null, "review_token" => $data["review_token"] ?? null, "user_password" => $arguments[2] ] ) );
						// The heartbeat of a WP-Cron run that a request started can hold the runner lock for a moment.
						for ( $attempt = 1; ; ++$attempt ) {
							$discarded = rest_do_request( $confirmed );
							if ( "backups_for_restic_database_discard_busy" !== ( $discarded->get_data()["code"] ?? null ) || $attempt >= 40 ) {
								break;
							}
							usleep( 250000 );
						}
						$wpdb->dbh->set_charset( "utf8mb4" );
						$wpdb->dbh->select_db( DB_NAME );
						echo "\n", base64_encode( (string) wp_json_encode( [ $first, $selected, $reviewed->get_status(), $sent, $discarded->get_status(), wp_json_encode( rest_get_server()->response_to_data( $discarded, false ) ) ] ) );
						',
						[ $job, $name, TestSite::ADMIN_PASSWORD ]
					),
					$name,
				]
			);
			[ $first, $selected, $status, $sent, $discarded, $answer ] = json_decode( (string) base64_decode( TestSite::last_line( $output ), true ), true );
			self::assertSame( [ 200, true ], [ $first, $selected ], $output );
			// Before, the digest of the review could not encode the name: a JsonException that no route caught.
			self::assertSame( 200, $status, $sent );
			$review = json_decode( $sent, true );
			self::assertIsArray( $review, $sent );
			self::assertSame( str_replace( 'ö', "\u{FFFD}", $name ), $review['connected']['database'] ?? null, 'The bytes that are not UTF-8 are answered as U+FFFD, as names in snapshots are.' );
			self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $review['digest'] ?? '' );
			// The digest is that of the bytes, so the confirmation of the answered review is accepted.
			self::assertSame( 200, $discarded, $answer );
			self::assertArrayNotHasKey( 'database_owner', TestSite::job_document( $job )['data'] ?? [ 'database_owner' => true ] );
		} finally {
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
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
					$unreadable => [ 409, 'backups_for_restic_job_unreadable', 'This version of the plugin cannot read the stored job ' . $unreadable . '.', 'A newer version of the plugin may have written it: install that version. If no version reads it, its document is damaged: once the job cannot be running any more, move its folder, which has the job\'s ID as its name, out of jobs/ in the private working directory and keep that copy. Meanwhile, new jobs and scheduled backups wait, and so do the removal of staged files, on request and at their expiry, and the pruning of the job history.' ],
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
			self::assertSame( 'A newer version of the plugin may have written it: install that version. If no version reads it, its document is damaged: once the job cannot be running any more, move its folder, which has the job\'s ID as its name, out of jobs/ in the private working directory and keep that copy. Meanwhile, new jobs and scheduled backups wait, and so do the removal of staged files, on request and at their expiry, and the pruning of the job history.', $error['data']['hint'] ?? null );
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
				self::assertStringContainsString( 'A newer version of the plugin may have written it: install that version.', (string) ( $answer['unreadable_jobs'][1]['hint'] ?? '' ) );
			}
			[ $status, $output ] = TestSite::wp( [ 'backups-for-restic', 'status' ] );
			self::assertSame( 0, $status, $output );
			foreach ( $expected as [ $id, , $message ] ) {
				self::assertStringContainsString( 'Stored job that cannot be read: ' . $id, $output );
				self::assertStringContainsString( $message, $output );
			}
			// Each warning says what waits meanwhile, scheduled backups included.
			self::assertSame( 2, substr_count( $output, 'Meanwhile, new jobs and scheduled backups wait, and so do the removal of staged files, on request and at their expiry, and the pruning of the job history.' ), $output );
		} finally {
			chmod( $folder, 0700 );
			foreach ( [ $unreadable, $inaccessible ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
		}
	}

	public function test_the_command_line_undoes_only_a_review_that_someone_confirmed(): void {
		$restore = '20261127T120000Z-0c8011';
		$file    = TestSite::root() . '/bfr-undo-digest.txt';
		// A file that an apply created and that is still as it left it.
		$apply = TestSite::applied_file( $restore, $file, 'installed by the apply' );
		self::assertFileExists( $file );
		$undo = static fn ( array $options, ?string $input = null ): array => TestSite::wp( [ 'backups-for-restic', 'undo', $apply, ...$options ], null, $input );
		try {
			// Before, each of these undid the apply without anyone having read its review.
			[ $status, $output ] = $undo( [ '--yes' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'undo no longer takes --yes, and nothing was undone.', $output );
			foreach ( [ '--digest', '--digest=' ] as $option ) {
				[ $status, $output ] = $undo( [ $option ] );
				self::assertSame( 1, $status, $output );
				self::assertStringContainsString( '--digest needs the digest that an earlier run of this command printed', $output );
			}
			// A piped answer is no one who read the review: it is printed, with its digest, and nothing is undone.
			[ $status, $output ] = $undo( [], "y\n" );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'There is no terminal to confirm the undo, so nothing was undone.', $output );
			self::assertSame( 1, preg_match( '/^Digest of this review: ([0-9a-f]{64})$/m', $output, $printed ), $output );
			self::assertFileExists( $file );
			// The digest of another review.
			[ $status, $output ] = $undo( [ '--digest=' . str_repeat( 'a', 64 ) ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'The live files are not as the review with this digest found them, so nothing was undone.', $output );
			self::assertFileExists( $file );
			// The digest that the review printed confirms it.
			[ $status, $output ] = $undo( [ '--digest=' . $printed[1] ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'The apply is fully undone', $output );
			self::assertFileDoesNotExist( $file );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			$folders = glob( TestSite::private_dir() . '/jobs/*', GLOB_ONLYDIR );
			foreach ( false === $folders ? [] : $folders as $folder ) {
				$document = TestSite::job_document( basename( $folder ) );
				if ( in_array( basename( $folder ), [ $apply, $restore ], true ) || $apply === ( $document['data']['apply'] ?? null ) ) {
					TempDir::remove( $folder );
				}
			}
			TempDir::remove( TestSite::private_dir() . '/restores/' . $restore );
		}
	}

	public function test_an_apply_whose_undo_records_cannot_be_read_is_listed_as_such_and_its_review_refused(): void {
		$site  = TestSite::root();
		$apply = '20261127T130000Z-0c9010';
		TestSite::undoable_apply( $apply, [ [ $site . '/bfr-unreadable-records.txt', $site . '/bfr-unreadable-records.txt', 'create' ] ] );
		// A folder at the name of the undone marker: whether the apply was undone cannot be established.
		mkdir( TestSite::private_dir() . '/jobs/' . $apply . '/undo/reverted', 0700 );
		try {
			[ $status, $listed, $raw ] = TestSite::api( 'GET', '/undo' );
			self::assertSame( 200, $status, $raw );
			$entry = array_values( array_filter( (array) ( $listed['jobs'] ?? [] ), static fn ( array $job ): bool => $apply === ( $job['job'] ?? null ) ) );
			self::assertCount( 1, $entry, $raw );
			self::assertSame( 'unreadable', $entry[0]['state'] ?? null, $raw );
			[ $status, $error, $raw ] = TestSite::api( 'POST', '/undo/' . $apply . '/plan', [] );
			self::assertSame( [ 409, 'backups_for_restic_undo_records_unreadable', 'The plugin cannot tell whether this apply can still be undone.' ], [ $status, $error['code'] ?? null, $error['message'] ?? null ], $raw );
			self::assertStringContainsString( 'keeps the saved original files and the staged files', (string) ( $error['data']['hint'] ?? '' ) );
			// Its journal and the staging of its restore stay.
			self::assertFileExists( TestSite::private_dir() . '/jobs/' . $apply . '/undo/attempted' );
		} finally {
			foreach ( [ $apply, substr( $apply, 0, -1 ) . '1' ] as $id ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $id );
			}
			TempDir::remove( TestSite::private_dir() . '/restores/' . substr( $apply, 0, -1 ) . '1' );
		}
	}

	public function test_the_command_line_says_of_a_refused_undo_what_the_page_says(): void {
		// No apply has this ID: the undo is refused as one that can no longer be undone.
		[ $status, $output ] = TestSite::wp( [ 'backups-for-restic', 'undo', '20261127T110000Z-0c7001' ] );
		self::assertSame( 1, $status, $output );
		// Before: "This apply has no available undo.", the exception's technical message.
		self::assertStringContainsString( 'Error: This apply can no longer be undone. An apply can be undone for 24 hours after it ended', $output );
		self::assertStringNotContainsString( 'no available undo', $output );
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
				$answers[ $id ] = [ $answer['job']['state'] ?? null, $answer['job']['result'] ?? null, $answer['job']['error']['code'] ?? null, $answer['job']['error']['message'] ?? null ];
			}
			// What the admin page shows a stopped apply or undo from: the state, the counts and the error.
			self::assertSame(
				[
					$undo   => [
						'cancelled',
						[
							'restored'    => 1,
							'removed'     => 2,
							'kept'        => 0,
							'unchanged'   => 0,
							'apply_state' => 'partly_undone',
						],
						'maintenance_stuck',
						'The site could not leave maintenance mode.',
					],
					$apply  => [
						'cancelled',
						[
							'entries_completed' => 2,
							'entries_attempted' => 3,
						],
						'maintenance_stuck',
						'The site could not leave maintenance mode.',
					],
					$backup => [ 'cancelled', [], null, null ],
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
