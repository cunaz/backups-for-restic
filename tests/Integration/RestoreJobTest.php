<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Storage\PrivateDirectory;
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
			// Staging expires with package 2.3; until then the tests remove what they staged.
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
			'backups_for_restic_invalid_snapshot' => [
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
			'backups_for_restic_invalid_paths'    => [
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
		self::assertSame( TempDir::describe( self::fixture() ), TempDir::describe( $staging . '/files' . self::fixture() ) );
		self::assertFileEquals( self::fixture() . '/' . self::FILE, $staging . '/files' . self::fixture() . '/' . self::FILE );
		self::assertGreaterThan( 0, $job['result']['files'] ?? 0 );
		self::assertFileExists( $staging . '/entries.jsonl' );
		self::assertFileDoesNotExist( $staging . '/files' . TestSite::root() . '/wp-config.php', 'Only the selected directory is staged.' );
		self::assertDirectoryDoesNotExist( TestSite::private_dir() . '/jobs/' . $job['id'] . '/restore' );
		// A heartbeat may have started the forget that follows a backup; it holds a lock of its own.
		TestSite::idle( 600 );
		self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ), 'The restore released its lock.' );

		// What is staged is as private as the rest of the directory.
		$handle = curl_init( TestSite::URL . '/wp-content/' . PrivateDirectory::NAME . '/restores/' . $job['id'] . '/entries.jsonl' );
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
