<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * Jobs that nobody starts: the heartbeat, a WP-Cron event every five minutes, starts the
 * backup when its slot has come, forget after a successful backup, and prune and check on
 * their own schedules. The tests make a slot due by rewriting which slot was handled last,
 * and let the heartbeat run through wp-cron.php as a visitor or a cron service would.
 */
final class ScheduleTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
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
	 * Lets heartbeats run until one has started a job, and returns that job once it ended.
	 *
	 * @param list<string> $known Jobs that existed before.
	 * @return array<mixed> Job.
	 */
	private static function started_by_the_heartbeat( array $known ): array {
		$deadline = time() + 180;
		do {
			TestSite::heartbeat();
			[ , $list ] = TestSite::api( 'GET', '/jobs' );
			foreach ( $list['jobs'] ?? [] as $job ) {
				if ( is_array( $job ) && ! in_array( $job['id'] ?? '', $known, true ) ) {
					return TestSite::wait_for( (string) $job['id'], 1800 );
				}
			}
			sleep( 1 );
		} while ( time() < $deadline );
		self::fail( 'The heartbeat started no job; handled slots: ' . (string) json_encode( TestSite::schedule_state() ) );
	}

	#[DependsExternal( WordPressSiteTest::class, 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_the_first_slot_after_the_setup_is_only_recorded(): void {
		TestSite::idle( 600 );
		[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'schedule.backup.frequency' => 'daily' ] ] );
		self::assertSame( 200, $status, $raw );
		TestSite::schedule_state(
			[
				'backup_slot' => null,
				'prune_slot'  => null,
				'check_slot'  => null,
			]
		);
		$known = self::known();

		$deadline = time() + 180;
		do {
			TestSite::heartbeat();
			$handled = TestSite::schedule_state();
			sleep( 1 );
		} while ( ! isset( $handled['backup_slot'] ) && time() < $deadline );

		self::assertGreaterThan( time() - 86400, $handled['backup_slot'] ?? 0, 'The latest slot counts as handled.' );
		self::assertLessThanOrEqual( time(), $handled['backup_slot'] );
		self::assertSame( [], array_values( array_diff( self::known(), $known ) ), 'The wizard runs the first backup; the schedule starts with the next slot.' );
	}

	#[Depends( 'test_the_first_slot_after_the_setup_is_only_recorded' )]
	public function test_a_backup_slot_that_has_come_starts_a_backup_and_then_forget(): void {
		TestSite::idle( 600 );
		TestSite::add_upload( 'scheduled.bin', 8 );
		// As if the last backup that the schedule started was long ago.
		TestSite::schedule_state( [ 'backup_slot' => 1 ] );

		$backup = self::started_by_the_heartbeat( self::known() );
		self::assertSame( [ 'backup', 'schedule', 'succeeded' ], [ $backup['type'], $backup['trigger'], $backup['state'] ], (string) json_encode( $backup ) );
		self::assertGreaterThan( 1, TestSite::schedule_state()['backup_slot'] ?? 0, 'The slot is handled; the next heartbeat does not start the backup again.' );

		$forget = self::started_by_the_heartbeat( self::known() );
		self::assertSame( [ 'forget', 'after_backup', 'succeeded' ], [ $forget['type'], $forget['trigger'], $forget['state'] ], (string) json_encode( $forget ) );
		self::assertSame( $backup['id'], TestSite::schedule_state()['forget_for'] ?? null );

		[ , $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( $backup['id'], $dashboard['last_backup']['job'] ?? null, $raw );
		self::assertGreaterThan( 0, $dashboard['maintenance']['forget']['finished'] ?? 0, $raw );
		TestSite::remove_upload( 'scheduled.bin' );
	}

	#[Depends( 'test_a_backup_slot_that_has_come_starts_a_backup_and_then_forget' )]
	public function test_prune_and_check_start_on_their_own_slots_one_after_the_other(): void {
		TestSite::idle( 600 );
		[ $status, , $raw ] = TestSite::api(
			'PUT',
			'/settings',
			[
				'values' => [
					'schedule.prune.frequency' => 'weekly',
					'schedule.check.frequency' => 'weekly',
				],
			]
		);
		self::assertSame( 200, $status, $raw );
		TestSite::schedule_state(
			[
				'prune_slot' => 1,
				'check_slot' => 1,
			]
		);

		$prune = self::started_by_the_heartbeat( self::known() );
		self::assertSame( [ 'prune', 'schedule', 'succeeded' ], [ $prune['type'], $prune['trigger'], $prune['state'] ], (string) json_encode( $prune ) );
		$check = self::started_by_the_heartbeat( self::known() );
		self::assertSame( [ 'check', 'schedule', 'succeeded' ], [ $check['type'], $check['trigger'], $check['state'] ], (string) json_encode( $check ) );

		$handled = TestSite::schedule_state();
		self::assertGreaterThan( 1, $handled['prune_slot'] ?? 0 );
		self::assertGreaterThan( 1, $handled['check_slot'] ?? 0 );
		self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
	}

	#[Depends( 'test_prune_and_check_start_on_their_own_slots_one_after_the_other' )]
	public function test_manual_schedules_start_nothing(): void {
		TestSite::idle( 600 );
		$values = [
			'schedule.backup.frequency' => 'manual',
			'schedule.prune.frequency'  => 'manual',
			'schedule.check.frequency'  => 'manual',
		];
		try {
			[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => $values ] );
			self::assertSame( 200, $status, $raw );
			TestSite::schedule_state(
				[
					'backup_slot' => 1,
					'prune_slot'  => 1,
					'check_slot'  => 1,
				]
			);
			$known = self::known();

			for ( $beat = 0; $beat < 3; $beat++ ) {
				TestSite::heartbeat();
				sleep( 2 );
			}

			self::assertSame( [], array_values( array_diff( self::known(), $known ) ) );
			[ , $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
			self::assertNull( $dashboard['next_backup'] ?? null, $raw );
		} finally {
			// Handled before the schedules come back, or the next heartbeat would start all three.
			TestSite::schedule_state(
				[
					'backup_slot' => time(),
					'prune_slot'  => time(),
					'check_slot'  => time(),
				]
			);
			TestSite::api(
				'PUT',
				'/settings',
				[
					'values' => [
						'schedule.backup.frequency' => 'daily',
						'schedule.prune.frequency'  => 'weekly',
						'schedule.check.frequency'  => 'monthly',
					],
				]
			);
		}
	}
}
