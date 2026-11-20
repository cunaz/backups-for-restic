<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * What a finished restore leaves on a real site, and its end (ADR 0040): the list, the
 * removal by an administrator, and the expiry that the heartbeat carries out. Runs on the
 * site and repository that WordPressSiteTest set up, and only where that site runs, which
 * is in CI.
 */
final class RestoreStagingTest extends TestCase {

	private const string SETUP = 'test_setup_and_backup_through_wp_cron_restore_with_restic';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	public static function tearDownAfterClass(): void {
		if ( TestSite::available() ) {
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'restore.staging_expiry_hours' => 24 ] ] );
			foreach ( array_diff( (array) scandir( TestSite::private_dir() . '/restores' ), [ '.', '..' ] ) as $name ) {
				TempDir::remove( TestSite::private_dir() . '/restores/' . $name );
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
	 * Asks for the restore of the uploads fixture and returns the answer.
	 *
	 * @return array{int, array<mixed>, string} Status, decoded body, raw body.
	 */
	private static function request( string $snapshot, bool $archive = false ): array {
		return TestSite::api(
			'POST',
			'/jobs',
			[
				'type'     => 'restore',
				'snapshot' => $snapshot,
				'paths'    => [ self::fixture() ],
				'archive'  => $archive,
			]
		);
	}

	/**
	 * Restores the uploads fixture into staging and returns the job once it succeeded.
	 *
	 * @return array<mixed> Job.
	 */
	private static function restore( string $snapshot, bool $archive = false ): array {
		TestSite::idle( 600 );
		[ $status, $started, $raw ] = self::request( $snapshot, $archive );
		self::assertSame( 201, $status, $raw );
		$job = TestSite::wait_for( (string) $started['job']['id'], 1800 );
		self::assertSame( 'succeeded', $job['state'], (string) json_encode( $job['error'] ) );
		return $job;
	}

	/**
	 * The entry of a job in GET /restores, and the whole answer.
	 *
	 * @return array{array<mixed>|null, array<mixed>} Entry, or null if the job is not listed; answer.
	 */
	private static function listed( string $id ): array {
		[ $status, $answer, $raw ] = TestSite::api( 'GET', '/restores' );
		self::assertSame( 200, $status, $raw );
		self::assertIsArray( $answer['restores'] ?? null, $raw );
		foreach ( $answer['restores'] as $entry ) {
			if ( is_array( $entry ) && $id === ( $entry['job'] ?? null ) ) {
				return [ $entry, $answer ];
			}
		}
		return [ null, $answer ];
	}

	/**
	 * Whether anything of a job's staging is on disk, listed or set aside for its removal.
	 */
	private static function on_disk( string $id ): bool {
		clearstatcache();
		return file_exists( TestSite::private_dir() . '/restores/' . $id ) || file_exists( TestSite::private_dir() . '/restores/' . Staging::REMOVING . $id );
	}

	/**
	 * @return string ID of the newest snapshot of the site.
	 */
	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_the_site_has_a_snapshot_to_restore_from(): string {
		TestSite::idle( 600 );
		[ $status, $listed, $raw ] = TestSite::api( 'GET', '/snapshots&fresh=1' );
		self::assertSame( 200, $status, $raw );
		$snapshot = (string) ( $listed['snapshots'][0]['id'] ?? '' );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $snapshot, $raw );
		return $snapshot;
	}

	/**
	 * Redeems the issued form as an anonymous browser, including its attachment headers.
	 *
	 * @param array<mixed> $form Validated fields returned by the plugin.
	 * @return array{int, string, string} Status, headers and body.
	 */
	private function redeem( array $form ): array {
		$url = $form['url'] ?? null;
		self::assertSame( TestSite::URL . '/wp-admin/admin-post.php', $url );
		self::assertIsArray( $form['fields'] ?? null );
		$handle = curl_init( $url );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_POSTFIELDS     => http_build_query( $form['fields'] ),
				CURLOPT_TIMEOUT        => 30,
			]
		);
		$raw = curl_exec( $handle );
		self::assertIsString( $raw );
		$split = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
		return [ (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ), substr( $raw, 0, $split ), substr( $raw, $split ) ];
	}

	#[Depends( 'test_the_site_has_a_snapshot_to_restore_from' )]
	public function test_single_use_downloads_serve_only_the_issued_staged_content( string $snapshot ): void {
		$job    = self::restore( $snapshot, true );
		$id     = (string) $job['id'];
		$route  = '/restores/' . $id . '/download';
		$file   = self::fixture() . '/file-0.bin';
		$staged = TestSite::private_dir() . '/restores/' . $id;
		self::assertTrue( self::listed( $id )[0]['archive'] ?? false );
		$before             = hash_file( 'sha256', $file );
		[ $exit, $preview ] = TestSite::wp( [ 'backups-for-restic', 'apply-plan', $id ] );
		self::assertSame( 0, $exit, $preview );
		// The development installation symlinks its plugin to the checkout, which can
		// contain the entire fixture site. The production ZIP installs only the plugin.
		$plugin    = realpath( TestSite::root() . '/wp-content/plugins/backups-for-restic' );
		$protected = false !== $plugin && str_starts_with( $file, $plugin . '/' );
		self::assertStringContainsString( $protected ? '"reason":"protected"' : '"reason":"identical_contents"', $preview );
		self::assertSame( $before, hash_file( 'sha256', $file ) );
		foreach ( [ [ 'file', $file, $staged . '/files' . $file ], [ 'archive', '', $staged . '/selection.zip' ], [ 'report', '', $staged . '/entries.jsonl' ] ] as [ $kind, $path, $expected ] ) {
			[ $issued, $form ] = TestSite::api(
				'POST',
				$route,
				[
					'kind' => $kind,
					'path' => $path,
				]
			);
			self::assertSame( 200, $issued );
			[ $status, $headers, $bytes ] = $this->redeem( $form );
			self::assertSame( 200, $status );
			self::assertStringContainsString( 'Content-Disposition: attachment;', $headers );
			self::assertSame( hash_file( 'sha256', $expected ), hash( 'sha256', $bytes ) );
			self::assertSame( 403, $this->redeem( $form )[0] );
		}
		[ $issued, $form ] = TestSite::api( 'POST', $route, [ 'kind' => 'archive' ] );
		self::assertSame( 200, $issued );
		self::assertSame( 200, TestSite::api( 'DELETE', '/restores/' . $id )[0] );
		self::assertSame( 404, $this->redeem( $form )[0], 'A token cannot serve removed staging.' );
	}

	#[Depends( 'test_the_site_has_a_snapshot_to_restore_from' )]
	public function test_a_finished_restore_is_listed_and_an_administrator_removes_it( string $snapshot ): void {
		$job = self::restore( $snapshot );
		$id  = (string) $job['id'];

		[ $entry, $answer ] = self::listed( $id );
		self::assertSame( 24, $answer['expiry_hours'] ?? null );
		self::assertNotNull( $entry, 'The finished restore is listed.' );
		self::assertSame( $snapshot, $entry['snapshot'] ?? null );
		self::assertSame( $job['result']['files'] ?? null, $entry['files'] ?? null );
		self::assertSame( $job['result']['bytes'] ?? null, $entry['bytes'] ?? null );
		self::assertGreaterThan( 0, $entry['bytes'] ?? 0 );
		self::assertSame( $job['finished_at'] ?? null, $entry['finished'] ?? null );
		self::assertSame( (int) ( $job['finished_at'] ?? 0 ) + 24 * 3600, $entry['expires'] ?? null );
		self::assertSame( 'restores/' . $id, $entry['staging'] ?? null );
		self::assertSame( 'restores/' . $id . '/entries.jsonl', $entry['report'] ?? null );
		self::assertFileExists( TestSite::private_dir() . '/' . $entry['report'] );

		// Only the ID of a job that has staging names something to remove.
		foreach (
			[
				'not-a-job'               => [ 400, 'backups_for_restic_invalid_job_id' ],
				$id . 'x'                 => [ 400, 'backups_for_restic_invalid_job_id' ],
				'20200101T000000Z-000000' => [ 404, 'backups_for_restic_restore_not_found' ],
			] as $name => [ $expected, $code ]
		) {
			[ $status, $error, $raw ] = TestSite::api( 'DELETE', '/restores/' . $name );
			self::assertSame( $expected, $status, $raw );
			self::assertSame( $code, $error['code'] ?? null, $raw );
		}
		self::assertDirectoryExists( TestSite::private_dir() . '/restores/' . $id . '/files' );

		[ $status, $answer, $raw ] = TestSite::api( 'DELETE', '/restores/' . $id );
		self::assertSame( 200, $status, $raw );
		self::assertSame(
			[
				'job'      => $id,
				'complete' => true,
			],
			$answer['removed'] ?? null,
			$raw
		);
		self::assertNotContains( $id, array_column( $answer['restores'] ?? [], 'job' ), $raw );
		self::assertFalse( self::on_disk( $id ), 'The staged files are gone.' );
		self::assertNull( self::listed( $id )[0] );
		self::assertNotNull( TestSite::job( $id ), 'The job and its log stay.' );

		[ $status, $error, $raw ] = TestSite::api( 'DELETE', '/restores/' . $id );
		self::assertSame( 404, $status, $raw );
		self::assertSame( 'backups_for_restic_restore_not_found', $error['code'] ?? null, $raw );
	}

	#[Depends( 'test_the_site_has_a_snapshot_to_restore_from' )]
	public function test_the_staging_of_a_restore_that_has_not_ended_is_not_removed( string $snapshot ): void {
		TestSite::idle( 600 );
		[ , $settings ] = TestSite::api( 'GET', '/settings' );
		$before         = [ 'advanced.loopback_chaining' => (string) ( $settings['values']['advanced.loopback_chaining'] ?? 'auto' ) ];
		// Without chaining nothing but a heartbeat advances the new job: it stays active.
		[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'advanced.loopback_chaining' => 'off' ] ] );
		self::assertSame( 200, $status, $raw );
		try {
			[ $status, $started, $raw ] = self::request( $snapshot );
			self::assertSame( 201, $status, $raw );
			$id = (string) $started['job']['id'];

			[ $status, $error, $raw ] = TestSite::api( 'DELETE', '/restores/' . $id );
			// A job that is active after the request was active when the request came.
			self::assertContains( TestSite::job_document( $id )['state'] ?? null, [ 'queued', 'running' ], 'The restore ended before the test could ask.' );
			self::assertSame( 409, $status, $raw );
			self::assertSame( 'backups_for_restic_restore_active', $error['code'] ?? null, $raw );
			self::assertNull( self::listed( $id )[0], 'A restore that has not ended is not offered.' );

			[ $status, , $raw ] = TestSite::api( 'POST', '/jobs/' . $id . '/cancel' );
			self::assertSame( 200, $status, $raw );
			TestSite::heartbeat();
			TestSite::idle( 600 );
			// A heartbeat of the site's own may have staged the last file before the request to
			// cancel came; such a restore succeeds (ADR 0037) and says nothing here.
			if ( 'cancelled' === ( TestSite::job( $id )['state'] ?? null ) ) {
				self::assertFalse( self::on_disk( $id ), 'A cancelled restore keeps no files.' );
				[ $status, $error, $raw ] = TestSite::api( 'DELETE', '/restores/' . $id );
				self::assertSame( 404, $status, $raw );
				self::assertSame( 'backups_for_restic_restore_not_found', $error['code'] ?? null, $raw );
			}
		} finally {
			TestSite::api( 'PUT', '/settings', [ 'values' => $before ] );
			TestSite::heartbeat();
			TestSite::idle( 600 );
		}
	}

	#[Depends( 'test_the_site_has_a_snapshot_to_restore_from' )]
	public function test_a_heartbeat_removes_staging_that_is_older_than_the_setting( string $snapshot ): void {
		$job = self::restore( $snapshot );
		$id  = (string) $job['id'];

		// As if the restore had ended two hours ago: the job document is where the time comes from.
		$document                = TestSite::job_document( $id );
		$finished                = time() - 2 * 3600;
		$document['finished_at'] = $finished;
		self::assertNotFalse( file_put_contents( TestSite::private_dir() . '/jobs/' . $id . '/job.json', (string) json_encode( $document ) ) );

		[ $entry ] = self::listed( $id );
		self::assertSame( $finished, $entry['finished'] ?? null, 'Two hours are less than the day that staging is kept by default.' );
		TestSite::heartbeat();
		self::assertDirectoryExists( TestSite::private_dir() . '/restores/' . $id . '/files' );

		foreach ( [ 0, 169 ] as $refused ) {
			[ $status, $error, $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'restore.staging_expiry_hours' => $refused ] ] );
			self::assertSame( 400, $status, $raw );
			self::assertArrayHasKey( 'restore.staging_expiry_hours', $error['data']['fields'] ?? [], $raw );
		}
		[ $status, $saved, $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'restore.staging_expiry_hours' => 1 ] ] );
		self::assertSame( 200, $status, $raw );
		self::assertSame( 1, $saved['values']['restore.staging_expiry_hours'] ?? null, $raw );
		try {
			[ $entry, $answer ] = self::listed( $id );
			self::assertSame( 1, $answer['expiry_hours'] ?? null );
			self::assertNull( $entry, 'What expired is not offered, whether or not a heartbeat has come.' );

			// A cron run that is under way lets a heartbeat pass, so ask until one has done it.
			$deadline = time() + 300;
			do {
				TestSite::heartbeat();
				sleep( 1 );
			} while ( self::on_disk( $id ) && time() < $deadline );
			self::assertFalse( self::on_disk( $id ), 'The heartbeat removed the staging that had expired.' );
			[ $entry, $answer ] = self::listed( $id );
			self::assertNull( $entry );
			self::assertSame( 0, $answer['removing'] ?? null );
			self::assertNotNull( TestSite::job( $id ), 'The job and its log stay.' );
		} finally {
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'restore.staging_expiry_hours' => 24 ] ] );
		}
	}
}
