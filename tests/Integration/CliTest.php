<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * The WP-CLI commands (`wp backups-for-restic ...`), run with a real WP-CLI on the test site:
 * what they print, how they exit, and that their jobs do in the repository what the same
 * jobs do when the admin page starts them.
 */
final class CliTest extends TestCase {

	private const string SETUP = 'test_setup_and_backup_through_wp_cron_restore_with_restic';

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
		if ( ! TestSite::has_wp_cli() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh wp-cli first.' );
		}
	}

	/**
	 * Runs a command of the plugin.
	 *
	 * @param list<string> $arguments Subcommand and options.
	 * @return array{int, string} Exit status and output.
	 */
	private static function command( array $arguments ): array {
		return TestSite::wp( [ 'backups-for-restic', ...$arguments ] );
	}

	/**
	 * The ID of the job that a command said it created.
	 */
	private static function created( string $output ): string {
		self::assertSame( 1, preg_match( '/^Job (\d{8}T\d{6}Z-[0-9a-f]{6}) created\.$/m', $output, $match ), $output );
		return $match[1];
	}

	/**
	 * IDs of the snapshots in the repository, as restic lists them.
	 *
	 * @return list<string>
	 */
	private static function snapshots(): array {
		$listed = json_decode( TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] ), true );
		self::assertIsArray( $listed );
		return array_values( array_map( 'strval', array_column( $listed, 'id' ) ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_doctor_status_and_snapshots_report_the_site(): void {
		TestSite::idle( 600 );

		[ $status, $output ] = self::command( [ 'doctor' ] );
		self::assertSame( 0, $status, $output );
		self::assertStringContainsString( 'Success: This server can run backups.', $output );
		self::assertMatchesRegularExpression( '/^\[(good|recommended)\] \S/m', $output );
		self::assertStringNotContainsString( '[critical]', $output );

		[ $status, $output ] = self::command( [ 'status' ] );
		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^Last backup: \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC$/m', $output );
		self::assertMatchesRegularExpression( '/^Active job: -$/m', $output );

		[ $status, $output ] = self::command( [ 'snapshots' ] );
		self::assertSame( 0, $status, $output );
		$listed = [];
		foreach ( explode( "\n", $output ) as $line ) {
			if ( 1 === preg_match( '/^([0-9a-f]{8})  \S+  \S+$/', $line, $match ) ) {
				$listed[] = $match[1];
			}
		}
		$expected = array_map( static fn ( string $id ): string => substr( $id, 0, 8 ), self::snapshots() );
		sort( $listed );
		sort( $expected );
		self::assertSame( $expected, $listed, $output );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_backup_with_wait_runs_the_job_in_the_command_and_names_the_snapshot(): void {
		TestSite::idle( 600 );
		TestSite::add_upload( 'cli.bin', 8 );
		$before = self::snapshots();

		[ $status, $output ] = self::command( [ 'backup', '--wait' ] );

		self::assertSame( 0, $status, $output );
		$id = self::created( $output );
		self::assertSame( 1, preg_match( '/^Success: Backup finished: snapshot ([0-9a-f]{8})\.$/m', $output, $match ), $output );
		$added = array_values( array_diff( self::snapshots(), $before ) );
		self::assertCount( 1, $added );
		self::assertStringStartsWith( $match[1], $added[0] );
		$job = TestSite::job( $id );
		self::assertSame( [ 'backup', 'cli', 'succeeded' ], [ $job['type'] ?? null, $job['trigger'] ?? null, $job['state'] ?? null ], (string) json_encode( $job ) );
		self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ) );
		TestSite::remove_upload( 'cli.bin' );
		TestSite::idle( 600 );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_forget_reports_how_many_snapshots_it_removed_and_kept(): void {
		TestSite::idle( 600 );

		[ $status, $output ] = self::command( [ 'forget', '--wait' ] );

		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^Success: Forget finished: \d+ snapshots removed, \d+ kept\.$/m', $output );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_prune_reports_what_it_deleted_and_freed(): void {
		TestSite::idle( 600 );

		[ $status, $output ] = self::command( [ 'prune', '--wait' ] );

		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^Success: Prune finished: \d+ packs deleted, .+ freed\.$/m', $output );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_check_reports_what_it_read_and_found(): void {
		TestSite::idle( 600 );

		[ $status, $output ] = self::command( [ 'check', '--read-data-subset=100', '--wait' ] );
		self::assertSame( 0, $status, $output );
		self::assertSame( 1, preg_match( '/^Success: The check found no errors\. (\d+) packs read, (\d+) blobs verified, \d+ warnings\.$/m', $output, $match ), $output );
		self::assertGreaterThan( 0, (int) $match[1], 'All packs were asked for.' );
		self::assertGreaterThan( 0, (int) $match[2] );
		$job = TestSite::job( self::created( $output ) );
		self::assertSame( [ 'check', 'cli', 'succeeded' ], [ $job['type'] ?? null, $job['trigger'] ?? null, $job['state'] ?? null ], (string) json_encode( $job ) );

		[ $status, $output ] = self::command( [ 'check', '--read-data-subset=many' ] );
		self::assertSame( 1, $status, $output );
		self::assertStringContainsString( 'Error: --read-data-subset must be a number from 0 to 100.', $output );
		self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_second_job_is_refused_and_tick_finishes_the_first(): void {
		TestSite::idle( 600 );
		TestSite::add_upload( 'cli-queued.bin', 8 );

		[ $status, $output ] = self::command( [ 'backup' ] );
		self::assertSame( 0, $status, $output );
		$id = self::created( $output );
		self::assertStringNotContainsString( 'Success:', $output, 'Without --wait the command only creates the job.' );

		[ $status, $output ] = self::command( [ 'check' ] );
		self::assertSame( 1, $status, $output );
		self::assertStringContainsString( 'Error: Another backup job is still running.', $output );

		[ $status, $output ] = self::command( [ 'tick', '--until-idle' ] );
		self::assertSame( 0, $status, $output );
		self::assertSame( 'succeeded', TestSite::job( $id )['state'] ?? null, $output );
		[ $status, $output ] = self::command( [ 'tick' ] );
		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^(idle|advanced|busy)/m', $output );
		TestSite::remove_upload( 'cli-queued.bin' );
		TestSite::idle( 600 );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_settings_are_listed_read_and_changed_with_the_rules_of_the_settings_page(): void {
		[ $status, $output ] = self::command( [ 'settings', 'list' ] );
		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^retention\.keep\.daily = \d+$/m', $output );
		self::assertMatchesRegularExpression( '/^setup\.completed = true$/m', $output );
		self::assertStringNotContainsString( TestSite::$repository_password, $output, 'Secrets are no settings.' );

		[ $status, $before ] = self::command( [ 'settings', 'get', 'retention.keep.daily' ] );
		self::assertSame( 0, $status, $before );
		try {
			[ $status, $output ] = self::command( [ 'settings', 'set', 'retention.keep.daily', '14' ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'Success: retention.keep.daily = 14', $output );
			[ , $settings ] = TestSite::api( 'GET', '/settings' );
			self::assertSame( 14, $settings['values']['retention.keep.daily'] ?? null, 'The settings page sees the change.' );

			[ $status, $output ] = self::command( [ 'settings', 'set', 'backup.extra_roots', '/srv/one', '/srv/two' ] );
			self::assertSame( 0, $status, $output );
			[ , $output ] = self::command( [ 'settings', 'get', 'backup.extra_roots' ] );
			self::assertSame( '/srv/one, /srv/two', TestSite::last_line( $output ) );

			// The same refusals as on the settings page.
			[ $status, $output ] = self::command( [ 'settings', 'set', 'retention.keep.within', '0d' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringStartsWith( 'Error:', TestSite::last_line( $output ) );
			[ $status, $output ] = self::command( [ 'settings', 'set', 'repository.url', 'https://collector.example.org/' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: This setting cannot be changed here.', $output );
			[ $status, $output ] = self::command( [ 'settings', 'get', 'no.such.setting' ] );
			self::assertSame( 1, $status, $output );
			[ , $settings ] = TestSite::api( 'GET', '/settings' );
			self::assertSame( '2d', $settings['values']['retention.keep.within'] ?? null );
			self::assertStringStartsWith( 'https://127.0.0.1:', (string) ( $settings['values']['repository.url'] ?? '' ) );
		} finally {
			self::command( [ 'settings', 'set', 'retention.keep.daily', TestSite::last_line( $before ) ] );
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'backup.extra_roots' => [] ] ] );
		}
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_logs_prints_what_a_job_did_and_cancel_stops_one(): void {
		TestSite::idle( 600 );
		[ $status, $output ] = self::command( [ 'cancel' ] );
		self::assertSame( 0, $status, $output );
		self::assertStringContainsString( 'No job is active.', $output );

		// More data than the first tick gets through, so that the request finds the job at work.
		TestSite::add_upload( 'cli-cancelled.bin', 1024 );
		try {
			[ $status, $output ] = self::command( [ 'backup' ] );
			self::assertSame( 0, $status, $output );
			$id = self::created( $output );

			[ $status, $output ] = self::command( [ 'cancel' ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'Success: ' . $id, $output );
			[ $status, $output ] = self::command( [ 'tick', '--until-idle' ] );
			self::assertSame( 0, $status, $output );
			self::assertSame( 'cancelled', TestSite::job( $id )['state'] ?? null, $output );
			self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ) );

			[ $status, $output ] = self::command( [ 'logs', $id ] );
			self::assertSame( 0, $status, $output );
			self::assertStringStartsWith( $id . ' Backup Cancelled', $output );
			self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d info +Job created\./m', $output );
			self::assertStringContainsString( 'Cancellation requested. {"user":"wp-cli"}', $output );
			self::assertStringContainsString( 'Job finished. {"state":"cancelled"}', $output );
			[ $status, $latest ] = self::command( [ 'logs', '--lines=2' ] );
			self::assertSame( 0, $status, $latest );
			self::assertStringStartsWith( $id, $latest, 'Without an ID it is the latest job.' );
			self::assertCount( 3, explode( "\n", trim( $latest ) ), 'The heading and two entries.' );

			[ $status, $output ] = self::command( [ 'logs', '20200101T000000Z-abcdef' ] );
			self::assertSame( 1, $status, $output );
			[ $status, $output ] = self::command( [ 'cancel', '20200101T000000Z-abcdef' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The job does not exist.', $output );
		} finally {
			TestSite::remove_upload( 'cli-cancelled.bin' );
		}
		TestSite::idle( 600 );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_forget_and_prune_are_refused_in_retention_mode_external(): void {
		TestSite::idle( 600 );
		try {
			[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'retention.mode' => 'external' ] ] );
			self::assertSame( 200, $status, $raw );
			foreach ( [ 'forget', 'prune' ] as $command ) {
				[ $status, $output ] = self::command( [ $command, '--wait' ] );
				self::assertSame( 1, $status, $output );
				self::assertStringContainsString( 'Error: Old snapshots are cleaned up from another computer', $output );
				self::assertStringNotContainsString( 'created.', $output );
			}
		} finally {
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'retention.mode' => 'plugin' ] ] );
		}
	}
}
