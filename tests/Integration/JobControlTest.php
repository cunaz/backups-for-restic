<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * What an administrator does to a site that is set up, around running jobs: asking for the
 * recovery kit, changing the repository, cancelling, deactivating the plugin, entering the
 * repository password again. Runs on the site and repository that WordPressSiteTest set up.
 */
final class JobControlTest extends TestCase {

	private const string SETUP = 'test_setup_and_backup_through_wp_cron_restore_with_restic';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	/**
	 * Starts a backup with enough new data to be caught in the middle, and waits until a tick
	 * is in it.
	 *
	 * @return string Job ID.
	 */
	private static function backup_in_progress( string $file ): string {
		TestSite::add_upload( $file, 512 );
		TestSite::idle( 600 );
		$id = TestSite::start();
		// Creating the job chained a tick; this request starts one where loopback requests fail.
		TestSite::cron( 3 );
		TestSite::wait_for_backup_tick( $id, 120 );
		return $id;
	}

	private static function locks(): string {
		return trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_the_recovery_kit_needs_the_wordpress_password_of_the_administrator(): void {
		[ , $settings ] = TestSite::api( 'GET', '/settings' );
		$before         = (array) ( $settings['kit_views'] ?? [] );
		foreach ( [ [], [ 'user_password' => 'not the password' ], [ 'user_password' => TestSite::$repository_password ] ] as $proof ) {
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/recovery-kit', $proof );
			self::assertSame( 403, $status, $raw );
			self::assertSame( 'backups_for_restic_wrong_user_password', $refused['code'] ?? null, $raw );
			self::assertStringNotContainsString( TestSite::$repository_password, $raw );
		}

		// A refused request showed nothing, so nothing is recorded for it.
		[ , $settings ] = TestSite::api( 'GET', '/settings' );
		self::assertSame( $before, (array) ( $settings['kit_views'] ?? [] ) );
		$recorded = count( $before );
		$asked    = time();

		[ $status, $answer, $raw ] = TestSite::api( 'POST', '/recovery-kit', [ 'user_password' => TestSite::ADMIN_PASSWORD ] );
		self::assertSame( 200, $status, $raw );
		self::assertStringContainsString( "RESTIC_PASSWORD='" . TestSite::$repository_password . "'", (string) ( $answer['recovery_kit'] ?? '' ) );
		self::assertStringContainsString( TestSite::server()->url( 'site' ), (string) $answer['recovery_kit'] );

		// Who saw it, when and on which way.
		[ , $settings, $raw ] = TestSite::api( 'GET', '/settings' );
		$views                = (array) ( $settings['kit_views'] ?? [] );
		self::assertCount( min( 5, $recorded + 1 ), $views, $raw );
		self::assertSame( [ 'admin', 'settings' ], [ $views[0]['login'] ?? null, $views[0]['via'] ?? null ], $raw );
		self::assertGreaterThanOrEqual( $asked, $views[0]['at'] ?? 0, $raw );
		self::assertSame( 'create', $views[ count( $views ) - 1 ]['via'] ?? null, 'The wizard showed it first: ' . $raw );
		self::assertStringNotContainsString( TestSite::$repository_password, $raw );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_running_job_keeps_its_repository_and_can_be_cancelled(): void {
		$id = self::backup_in_progress( 'cancelled.bin' );
		try {
			$this->refuse_changes_and_cancel( $id );
		} finally {
			TestSite::remove_upload( 'cancelled.bin' );
		}
	}

	private function refuse_changes_and_cancel( string $id ): void {
		self::assertNotSame( '', self::locks(), 'The backup holds its lock.' );

		// Nothing that the job works with changes under it.
		$requests = [
			[ 'POST', '/repository/save', TestSite::connection() ],
			[ 'POST', '/repository/create', [ 'user_password' => TestSite::ADMIN_PASSWORD ] ],
			[
				'POST',
				'/repository/connect',
				[
					'user_password' => TestSite::ADMIN_PASSWORD,
					'password'      => 'another password',
				],
			],
			[ 'DELETE', '/cache', null ],
			[ 'POST', '/jobs', [ 'type' => 'check' ] ],
		];
		foreach ( $requests as [ $method, $route, $data ] ) {
			[ $status, $refused, $raw ] = TestSite::api( $method, $route, $data );
			self::assertSame( 409, $status, $method . ' ' . $route . ': ' . $raw );
			self::assertSame( 'backups_for_restic_job_conflict', $refused['code'] ?? null, $method . ' ' . $route . ': ' . $raw );
		}

		[ $status, $answer, $raw ] = TestSite::api( 'POST', '/jobs/' . $id . '/cancel' );
		self::assertSame( 200, $status, $raw );
		self::assertTrue( $answer['job']['cancel_requested'] ?? null, $raw );

		$job = TestSite::wait_for( $id, 300 );
		self::assertSame( 'cancelled', $job['state'], (string) json_encode( $job ) );
		self::assertNull( $job['error'] ?? null );
		self::assertStringNotContainsString( 'Snapshot saved.', TestSite::job_log( $id ) );
		self::assertSame( '', self::locks(), 'The cleanup released the lock.' );
		self::assertDirectoryDoesNotExist( TestSite::private_dir() . '/jobs/' . $id . '/backup', 'The checkpoint of a cancelled backup is of no use.' );
		self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_deactivating_the_plugin_ends_the_running_job_and_releases_its_lock(): void {
		$id = self::backup_in_progress( 'deactivated.bin' );
		self::assertNotSame( '', self::locks(), 'The backup holds its lock.' );

		try {
			TestSite::plugin( false );

			// Nothing drives the job from here on: no tick comes after the deactivation.
			$left = TestSite::wait_for_end( $id, 120 );
			self::assertSame( 'cancelled', $left['state'], (string) json_encode( $left ) );
			self::assertStringContainsString( '"reason":"deactivation"', TestSite::job_log( $id ) );
			self::assertSame( '', self::locks(), 'No lock stays in the repository for half an hour.' );
			self::assertStringEndsWith( 'heartbeat=false', TestSite::php( 'echo "\nheartbeat=", var_export( wp_next_scheduled( "backups_for_restic_heartbeat" ), true );' ), 'The heartbeat is unscheduled.' );
			[ $status ] = TestSite::api( 'GET', '/status' );
			self::assertSame( 404, $status, 'The plugin is off.' );
		} finally {
			TestSite::plugin( true );
			TestSite::remove_upload( 'deactivated.bin' );
		}

		[ $status, $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
		self::assertTrue( $dashboard['setup_completed'] ?? null, 'Deactivation keeps all data.' );
		self::assertNull( $dashboard['active_job'] ?? null, $raw );
		self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_wrong_repository_password_fails_the_unlock_and_the_right_one_repairs_it(): void {
		TestSite::idle( 600 );
		[ , $before ] = TestSite::api( 'GET', '/status' );
		self::assertNotSame( '', (string) ( $before['last_backup']['snapshot'] ?? '' ), 'The site has a backup in this repository.' );

		[ $status, $answer, $raw ] = TestSite::api(
			'POST',
			'/repository/connect',
			[
				'user_password' => TestSite::ADMIN_PASSWORD,
				'password'      => 'not the password of this repository',
			]
		);
		self::assertSame( 201, $status, $raw );
		self::assertSame( 'unlock', $answer['job']['type'] ?? null, $raw );
		$job = TestSite::wait_for( (string) $answer['job']['id'], 600 );
		self::assertSame( 'failed', $job['state'], (string) json_encode( $job ) );
		self::assertSame( 'wrong_password', $job['error']['code'] ?? null, (string) json_encode( $job ) );
		[ $status, , $raw ] = TestSite::api( 'GET', '/snapshots&fresh=1' );
		self::assertSame( 409, $status, 'Without the key there is nothing to list: ' . $raw );

		[ $status, $answer, $raw ] = TestSite::api(
			'POST',
			'/repository/connect',
			[
				'user_password' => TestSite::ADMIN_PASSWORD,
				'password'      => TestSite::$repository_password,
			]
		);
		self::assertSame( 201, $status, $raw );
		self::assertStringContainsString( "RESTIC_PASSWORD='" . TestSite::$repository_password . "'", (string) ( $answer['recovery_kit'] ?? '' ) );
		$job = TestSite::wait_for( (string) $answer['job']['id'], 600 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job ) );

		[ $status, $listed, $raw ] = TestSite::api( 'GET', '/snapshots&fresh=1' );
		self::assertSame( 200, $status, $raw );
		self::assertNotSame( [], $listed['snapshots'] ?? [], $raw );
		[ , $after ] = TestSite::api( 'GET', '/status' );
		self::assertSame( $before['last_backup']['snapshot'], $after['last_backup']['snapshot'] ?? null, 'It is the same repository: what the plugin remembers about it stays.' );
		[ , $settings ] = TestSite::api( 'GET', '/settings' );
		self::assertSame( 64, strlen( (string) ( $settings['values']['repository.id'] ?? '' ) ), 'The unlock recorded which repository it opened.' );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_repository_requests_verify_certificates_whatever_the_sites_filters_say(): void {
		TestSite::idle( 600 );
		$certificate = (string) file_get_contents( TestSite::server()->certificate );
		try {
			// The server's certificate is from its own authority. Without it uploaded, nothing may be sent there.
			[ $status, , $raw ] = TestSite::api( 'DELETE', '/repository/ca-certificate' );
			self::assertSame( 200, $status, $raw );
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/repository/test', TestSite::connection() );
			self::assertSame( 502, $status, $raw );
			self::assertSame( 'backups_for_restic_repository_unreachable', $refused['code'] ?? null, $raw );

			// The Doctor says so as well, as a hint: a server may be down for a moment.
			self::assertSame( [ 'recommended', 'unreachable' ], self::finding( 'repository_server', true ) );

			// A snippet that switches verification off for the whole site does not reach these requests.
			TestSite::hostile_filters( true );
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/repository/test', TestSite::connection() );
			self::assertSame( 502, $status, 'The certificate was accepted without being verified: ' . $raw );
			self::assertSame( 'backups_for_restic_repository_unreachable', $refused['code'] ?? null, $raw );

			// And the uploaded certificate still counts while the snippet is active.
			[ $status, , $raw ] = TestSite::api( 'POST', '/repository/ca-certificate', [ 'pem' => $certificate ] );
			self::assertSame( 200, $status, $raw );
			[ $status, $tested, $raw ] = TestSite::api( 'POST', '/repository/test', TestSite::connection() );
			self::assertSame( 200, $status, $raw );
			self::assertTrue( $tested['report']['exists'] ?? null, $raw );
		} finally {
			TestSite::hostile_filters( false );
			TestSite::api( 'POST', '/repository/ca-certificate', [ 'pem' => $certificate ] );
		}
		self::assertSame( [ 'good', 'reached' ], self::finding( 'repository_server', true ) );
		self::assertSame( [ 'good', 'off' ], self::finding( 'jit', false ), 'The test site runs without the JIT compiler.' );
	}

	/**
	 * One finding of the Doctor.
	 *
	 * @return array{string, string} Status and code.
	 */
	private static function finding( string $id, bool $fresh ): array {
		[ $status, $environment, $raw ] = TestSite::api( 'GET', '/environment' . ( $fresh ? '&fresh=1' : '' ) );
		self::assertSame( 200, $status, $raw );
		$found = array_values( array_filter( $environment['checks'] ?? [], static fn ( mixed $check ): bool => is_array( $check ) && $id === ( $check['id'] ?? null ) ) );
		return [ (string) ( $found[0]['status'] ?? '' ), (string) ( $found[0]['code'] ?? '' ) ];
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_changed_salts_are_repaired_by_entering_the_two_passwords_again(): void {
		TestSite::idle( 600 );
		$before = TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] );

		// Somebody replaces a security key in wp-config.php: nothing that is stored decrypts any more.
		$config   = TestSite::root() . '/wp-config.php';
		$original = (string) file_get_contents( $config );
		$changed  = preg_replace( "/(define\\(\\s*'NONCE_SALT',\\s*')[^']*('\\s*\\);)/", '${1}' . bin2hex( random_bytes( 32 ) ) . '${2}', $original, 1, $count );
		self::assertSame( 1, $count );
		file_put_contents( $config, (string) $changed );
		try {
			// The web server's opcode cache looks at the file again after two seconds.
			sleep( 3 );

			[ , $environment, $raw ] = TestSite::api( 'GET', '/environment' );
			$secrets                 = array_values( array_filter( $environment['checks'] ?? [], static fn ( mixed $check ): bool => is_array( $check ) && 'secrets' === ( $check['id'] ?? null ) ) );
			self::assertSame( [ 'critical', 'undecryptable' ], [ $secrets[0]['status'] ?? null, $secrets[0]['code'] ?? null ], $raw );
			$job = TestSite::wait_for( TestSite::start(), 300 );
			self::assertSame( [ 'failed', 'secrets_undecryptable' ], [ $job['state'], $job['error']['code'] ?? null ], (string) json_encode( $job ) );

			// What the forms of the Settings tab send: the REST password first, then the repository password.
			[ $status, , $raw ] = TestSite::api( 'POST', '/repository/save', TestSite::connection() );
			self::assertSame( 200, $status, $raw );
			$unlock = self::unlock_again();
			self::assertSame( 'succeeded', $unlock['state'] ?? null, (string) json_encode( $unlock ) );

			[ , $environment, $raw ] = TestSite::api( 'GET', '/environment' );
			$secrets                 = array_values( array_filter( $environment['checks'] ?? [], static fn ( mixed $check ): bool => is_array( $check ) && 'secrets' === ( $check['id'] ?? null ) ) );
			self::assertSame( 'good', $secrets[0]['status'] ?? null, $raw );
			$backup = TestSite::wait_for( TestSite::start(), 1800 );
			self::assertSame( 'succeeded', $backup['state'], (string) json_encode( $backup ) );
			TestSite::idle( 600 );
			self::assertCount( count( (array) json_decode( $before, true ) ) + 1, (array) json_decode( TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] ), true ), 'The repository is the same, with one snapshot more.' );
		} catch ( \Throwable $failure ) {
			// The later tests need a site whose passwords can be read: the old key back, and
			// both passwords stored once more under it, whatever this test got done.
			file_put_contents( $config, $original );
			sleep( 3 );
			try {
				TestSite::idle( 600 );
				TestSite::api( 'POST', '/repository/save', TestSite::connection() );
				self::unlock_again();
			} catch ( \Throwable $second ) {
				// The failure that counts is the first one; it is thrown below.
				fwrite( STDERR, 'The test site could not be repaired: ' . $second->getMessage() . "\n" );
			}
			throw $failure;
		}
	}

	/**
	 * Enters the repository password again, as the Settings tab does, and waits for the unlock.
	 *
	 * @return array<mixed> The unlock job, or an empty array if none was created.
	 */
	private static function unlock_again(): array {
		[ $status, $answer ] = TestSite::api(
			'POST',
			'/repository/connect',
			[
				'user_password' => TestSite::ADMIN_PASSWORD,
				'password'      => TestSite::$repository_password,
			]
		);
		return 201 === $status ? TestSite::wait_for( (string) ( $answer['job']['id'] ?? '' ), 600 ) : [];
	}

	/**
	 * The Doctor's finding about the private directory, and whether the admin page is asked to
	 * let the browser look.
	 *
	 * @return array{string, string, bool} Status, code and the request for a browser probe.
	 */
	private static function private_directory( bool $fresh ): array {
		[ $status, $environment, $raw ] = TestSite::api( 'GET', '/environment' . ( $fresh ? '&fresh=1' : '' ) );
		self::assertSame( 200, $status, $raw );
		$found = array_values( array_filter( $environment['checks'] ?? [], static fn ( mixed $check ): bool => is_array( $check ) && 'private_directory' === ( $check['id'] ?? null ) ) );
		return [ (string) ( $found[0]['status'] ?? '' ), (string) ( $found[0]['code'] ?? '' ), true === ( $environment['browser_probe'] ?? null ) ];
	}

	/**
	 * What the admin page's script does when it is asked for a browser probe: asks for a
	 * probe file, requests it without credentials, and reports what came back.
	 *
	 * @return array{int, bool|null} The status the visit got, and what the report made of it.
	 */
	private static function probe_as_a_browser(): array {
		[ $status, $probe, $raw ] = TestSite::api( 'POST', '/environment/probe' );
		self::assertSame( 200, $status, $raw );
		self::assertStringStartsWith( TestSite::URL . '/wp-content/backups-for-restic/probe/', (string) ( $probe['url'] ?? '' ), $raw );
		[ $seen, $body ]           = TestSite::visit( (string) $probe['url'] );
		[ $status, $report, $raw ] = TestSite::api(
			'POST',
			'/environment/probe/report',
			[
				'probe'  => $probe['probe'] ?? '',
				'status' => $seen,
				'body'   => substr( $body, 0, 1024 ),
			]
		);
		self::assertSame( 200, $status, $raw );
		self::assertArrayHasKey( 'exposed', $report, $raw );
		return [ $seen, $report['exposed'] ];
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_browser_checks_the_private_directory_where_the_site_cannot_request_itself(): void {
		TestSite::idle( 600 );
		$deny  = TestSite::private_dir() . '/.htaccess';
		$rules = (string) file_get_contents( $deny );
		try {
			TestSite::no_loopback( true );
			self::assertSame( [ 'recommended', 'unverified', true ], self::private_directory( true ), 'The site\'s own probe gets no answer.' );

			self::assertSame( [ 403, false ], self::probe_as_a_browser() );
			self::assertSame( [ 'recommended', 'protected_inside_web_root', true ], self::private_directory( false ), 'The browser saw the directory refused.' );
			self::assertSame( [], (array) glob( TestSite::private_dir() . '/probe/*' ), 'No probe file is left.' );

			// A server that ignores the deny file: the browser gets the probe file, and that is proof.
			file_put_contents( $deny, "# Emptied by a test.\n" );
			self::assertSame( [ 200, true ], self::probe_as_a_browser() );
			self::assertSame( [ 'critical', 'exposed', true ], self::private_directory( false ) );
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_environment_blocked', $refused['code'] ?? null, $raw );

			// A report that no probe file stands behind says nothing.
			[ $status, $report, $raw ] = TestSite::api(
				'POST',
				'/environment/probe/report',
				[
					'status' => 403,
					'body'   => 'Forbidden',
				]
			);
			self::assertSame( 200, $status, $raw );
			self::assertArrayHasKey( 'exposed', $report, $raw );
			self::assertNull( $report['exposed'], $raw );
			self::assertSame( [ 'critical', 'exposed', true ], self::private_directory( false ), 'It cannot talk the finding away either.' );

			file_put_contents( $deny, $rules );
			self::assertSame( [ 403, false ], self::probe_as_a_browser() );
			self::assertSame( [ 'recommended', 'protected_inside_web_root', true ], self::private_directory( false ) );
		} finally {
			file_put_contents( $deny, $rules );
			TestSite::no_loopback( false );
			TestSite::api( 'GET', '/environment&fresh=1' );
		}
		self::assertSame( [ 'recommended', 'protected_inside_web_root', false ], self::private_directory( false ), 'With requests to itself the site needs no browser.' );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_repository_requests_verify_the_host_name_whatever_the_sites_filters_say(): void {
		TestSite::idle( 600 );
		// The server's certificate names 127.0.0.1 only. Under the name localhost the same
		// server answers, with a certificate that is trusted but is not for that name.
		$connection = TestSite::connection();
		$other      = str_replace( '//127.0.0.1:', '//localhost:', $connection['url'] );
		self::assertNotSame( $connection['url'], $other );
		$handle = curl_init( $other );
		self::assertNotFalse( $handle );
		// The name check is off for this one request of the test itself: it shows that the
		// server is reached under this name and that only the name stands in the way.
		curl_setopt_array(
			$handle,
			[
				CURLOPT_CAINFO         => TestSite::server()->certificate,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 10,
			]
		);
		curl_exec( $handle );
		self::assertSame( 0, curl_errno( $handle ), 'The server is not reached as localhost: ' . curl_error( $handle ) );

		try {
			TestSite::hostile_filters( true );
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/repository/test', [ 'url' => $other ] + $connection );
			self::assertSame( 502, $status, 'A certificate for another name was accepted: ' . $raw );
			self::assertSame( 'backups_for_restic_repository_unreachable', $refused['code'] ?? null, $raw );
		} finally {
			TestSite::hostile_filters( false );
		}
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_the_setup_is_not_completed_while_the_private_directory_is_served(): void {
		TestSite::idle( 600 );
		$deny  = TestSite::private_dir() . '/.htaccess';
		$rules = (string) file_get_contents( $deny );
		try {
			// A server that ignores the deny file: the test site's router honours what the file says.
			file_put_contents( $deny, "# Emptied by a test.\n" );
			[ $status, $environment, $raw ] = TestSite::api( 'GET', '/environment&fresh=1' );
			self::assertSame( 200, $status, $raw );
			$private = array_values( array_filter( $environment['checks'] ?? [], static fn ( mixed $check ): bool => is_array( $check ) && 'private_directory' === ( $check['id'] ?? null ) ) );
			self::assertSame( [ 'critical', 'exposed' ], [ $private[0]['status'] ?? null, $private[0]['code'] ?? null ], $raw );

			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_environment_blocked', $refused['code'] ?? null, $raw );

			$job = TestSite::wait_for( TestSite::start(), 300 );
			self::assertSame( 'failed', $job['state'], 'No backup writes working files into a directory that the web can read.' );
			self::assertSame( 'environment', $job['error']['code'] ?? null, (string) json_encode( $job ) );
		} finally {
			file_put_contents( $deny, $rules );
			TestSite::api( 'GET', '/environment&fresh=1' );
		}

		[ $status, , $raw ] = TestSite::api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );
		self::assertSame( 200, $status, $raw );
	}
}
