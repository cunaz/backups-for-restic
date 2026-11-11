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
		foreach ( [ [], [ 'user_password' => 'not the password' ], [ 'user_password' => TestSite::$repository_password ] ] as $proof ) {
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/recovery-kit', $proof );
			self::assertSame( 403, $status, $raw );
			self::assertSame( 'backups_for_restic_wrong_user_password', $refused['code'] ?? null, $raw );
			self::assertStringNotContainsString( TestSite::$repository_password, $raw );
		}

		[ $status, $answer, $raw ] = TestSite::api( 'POST', '/recovery-kit', [ 'user_password' => TestSite::ADMIN_PASSWORD ] );
		self::assertSame( 200, $status, $raw );
		self::assertStringContainsString( "RESTIC_PASSWORD='" . TestSite::$repository_password . "'", (string) ( $answer['recovery_kit'] ?? '' ) );
		self::assertStringContainsString( TestSite::server()->url( 'site' ), (string) $answer['recovery_kit'] );
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
