<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Tests\Support\TestSite;

/**
 * A repository server that its operator switches to append-only mode, and back (ADR 0010).
 * The plugin notices the first by itself and stops deleting; it never starts deleting again
 * by itself, because a server that accepts deletions again may be one that an attacker
 * reconfigured.
 */
final class AppendOnlyTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! TestSite::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh, bin/test-site.sh install and bin/test-site.sh start first.' );
		}
	}

	/**
	 * IDs of the snapshots in the repository, as restic lists them.
	 *
	 * @return list<string>
	 */
	private static function snapshots(): array {
		$listed = json_decode( TestSite::restic()->must( [ 'snapshots', '--json', '--no-lock' ] ), true );
		self::assertIsArray( $listed );
		$ids = array_map( 'strval', array_column( $listed, 'id' ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @return array<string, mixed> The retention part of the dashboard, with the server's mode as the dashboard shows it.
	 */
	private static function retention(): array {
		[ $status, $dashboard, $raw ] = TestSite::api( 'GET', '/status' );
		self::assertSame( 200, $status, $raw );
		return [ 'append_only' => $dashboard['repository']['append_only'] ?? null ] + (array) ( $dashboard['retention'] ?? [] );
	}

	#[DependsExternal( WordPressSiteTest::class, 'test_setup_and_backup_through_wp_cron_restore_with_restic' )]
	public function test_retention_follows_the_server_into_append_only_mode_and_not_back(): void {
		TestSite::idle( 600 );
		[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'retention.mode' => 'plugin' ] ] );
		self::assertSame( 200, $status, $raw );
		$before = self::snapshots();
		self::assertNotSame( [], $before );

		try {
			// The operator turns append-only mode on.
			TestSite::server()->restart( true );

			$forget = TestSite::wait_for( TestSite::start( 'forget' ), 600 );
			self::assertSame( 'succeeded', $forget['state'], (string) json_encode( $forget ) );
			self::assertSame( 'append_only', $forget['result']['skipped'] ?? null, 'The job found that it may not delete, and deleted nothing.' );
			$retention = self::retention();
			self::assertSame( 'external', $retention['mode'], (string) json_encode( $retention ) );
			self::assertSame( 'yes', $retention['append_only'] );
			self::assertGreaterThan( 0, $retention['switched_at'] );
			foreach ( [ 'forget', 'prune' ] as $type ) {
				[ $status, $refused, $raw ] = TestSite::api( 'POST', '/jobs', [ 'type' => $type ] );
				self::assertSame( 409, $status, $raw );
				self::assertSame( 'backups_for_restic_retention_external', $refused['code'] ?? null, $raw );
			}

			// Backups go on: an append-only server takes new data and lets a client remove its own lock.
			TestSite::add_upload( 'append-only.bin', 8 );
			$backup = TestSite::wait_for( TestSite::start(), 1800 );
			self::assertSame( 'succeeded', $backup['state'], (string) json_encode( $backup ) );
			TestSite::idle( 600 );
			self::assertCount( count( $before ) + 1, self::snapshots() );
			self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ) );

			// The operator turns it off again. The daily probe finds that out; make it due now.
			TestSite::server()->restart( false );
			TestSite::state( 'append_only', [ 'checked_at' => 1 ] );
			$deadline = time() + 180;
			do {
				TestSite::heartbeat();
				$retention = self::retention();
				sleep( 1 );
			} while ( 0 === (int) ( $retention['proposed_since'] ?? 0 ) && time() < $deadline );

			self::assertGreaterThan( 0, (int) $retention['proposed_since'], 'The dashboard proposes to switch back: ' . (string) json_encode( $retention ) );
			self::assertSame( 'external', $retention['mode'], 'Only an administrator switches back.' );
			self::assertSame( 'no', $retention['append_only'] );
			[ $status, $refused, $raw ] = TestSite::api( 'POST', '/jobs', [ 'type' => 'forget' ] );
			self::assertSame( 409, $status, $raw );
			self::assertCount( count( $before ) + 1, self::snapshots(), 'Nothing was deleted by itself.' );

			// The administrator decides.
			[ $status, , $raw ] = TestSite::api( 'PUT', '/settings', [ 'values' => [ 'retention.mode' => 'plugin' ] ] );
			self::assertSame( 200, $status, $raw );
			$retention = self::retention();
			self::assertSame( [ 'plugin', 0, 0 ], [ $retention['mode'], (int) $retention['proposed_since'], (int) $retention['switched_at'] ], (string) json_encode( $retention ) );
			$forget = TestSite::wait_for( TestSite::start( 'forget' ), 600 );
			self::assertSame( 'succeeded', $forget['state'], (string) json_encode( $forget ) );
			self::assertArrayNotHasKey( 'skipped', (array) $forget['result'] );
		} finally {
			TestSite::server()->restart( false );
			TestSite::remove_upload( 'append-only.bin' );
			TestSite::api( 'PUT', '/settings', [ 'values' => [ 'retention.mode' => 'plugin' ] ] );
		}
		self::assertStringContainsString( 'no errors were found', TestSite::restic()->must( [ 'check' ] ) );
	}
}
