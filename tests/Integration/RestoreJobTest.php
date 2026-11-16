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
		[ , $settings ]     = TestSite::api( 'GET', '/settings' );
		$frequency          = (string) ( $settings['values']['schedule.backup.frequency'] ?? 'daily' );
		[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'schedule.backup.frequency' => 'daily' ] ] );
		self::assertSame( 200, $status, $raw );
		// Without requests of the site to itself the new job stays queued until a heartbeat comes.
		TestSite::no_loopback( true );
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

			$deadline = time() + 180;
			do {
				TestSite::heartbeat();
				$job = TestSite::job( $restore );
				sleep( 1 );
			} while ( 'queued' === ( $job['state'] ?? '' ) && time() < $deadline );
			self::assertNotSame( 'queued', $job['state'] ?? '', 'A heartbeat found the restore and advanced it.' );

			// That heartbeat looked at the schedule first and could not start the backup.
			self::assertSame( 1, TestSite::schedule_state()['backup_slot'] ?? null, 'The slot stays unhandled while the restore is active.' );
			self::assertSame( [], array_values( array_diff( self::known(), $known ) ), 'No job started beside the restore.' );
			$job = TestSite::wait_for( $restore, 1800 );
			self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );

			// The next heartbeat finds the slot still due and starts the backup.
			$backup   = null;
			$deadline = time() + 180;
			do {
				TestSite::heartbeat();
				[ , $list ] = TestSite::api( 'GET', '/jobs' );
				foreach ( $list['jobs'] ?? [] as $candidate ) {
					if ( is_array( $candidate ) && ! in_array( $candidate['id'] ?? '', $known, true ) ) {
						$backup = TestSite::wait_for( (string) $candidate['id'], 1800 );
					}
				}
				sleep( 1 );
			} while ( null === $backup && time() < $deadline );
			self::assertNotNull( $backup, 'The heartbeat started no job; handled slots: ' . (string) json_encode( TestSite::schedule_state() ) );
			self::assertSame( [ 'backup', 'schedule', 'succeeded' ], [ $backup['type'], $backup['trigger'], $backup['state'] ], (string) json_encode( $backup ) );
			self::assertGreaterThan( 1, TestSite::schedule_state()['backup_slot'] ?? 0 );
		} finally {
			TestSite::no_loopback( false );
			// Handled before the next test: no later heartbeat starts this slot again.
			TestSite::schedule_state( [ 'backup_slot' => time() ] );
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'schedule.backup.frequency' => $frequency ] ] );
			TestSite::idle( 600 );
		}
	}
}
