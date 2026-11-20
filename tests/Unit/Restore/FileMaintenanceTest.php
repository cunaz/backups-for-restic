<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\WordPress\FileMaintenanceGate;
use PHPUnit\Framework\TestCase;

final class FileMaintenanceTest extends TestCase {

	private const string ID = '20261120T000000Z-123456';

	public function test_scoped_control_request_works_with_pretty_and_plain_rest_urls(): void {
		$token = bin2hex( random_bytes( 32 ) );
		$grant = hash( 'sha256', $token );
		$admin = 'https://example.test/news/wp-admin/admin.php';
		$job   = '/jobs/' . self::ID;
		foreach ( [ '/news/wp-json/backups-for-restic/v1', '/news/?rest_route=/backups-for-restic/v1' ] as $api ) {
			$plain = str_contains( $api, '?' );
			$check = static function ( string $path, string $method, ?string $secret ) use ( $api, $admin, $plain, $grant ): bool {
				return FileMaintenanceGate::admitted(
					self::ID,
					$grant,
					'https://example.test' . $api,
					$admin,
					[
						'REQUEST_URI'    => $plain ? '/news/' : $api . $path,
						'REQUEST_METHOD' => $method,
					],
					$plain ? [ 'rest_route' => '/backups-for-restic/v1' . $path ] : [],
					null === $secret ? [] : [ 'bfr_file_control' => $secret ]
				);
			};
			self::assertTrue( $check( $job, 'GET', $token ) );
			self::assertTrue( $check( $job . '/cancel', 'POST', $token ) );
			self::assertFalse( $check( '/settings', 'PUT', $token ) );
			self::assertFalse( $check( $job, 'GET', null ) );
			self::assertFalse( $check( '/jobs/20261120T000000Z-ffffff', 'GET', $token ) );
			self::assertFalse( $check( $job . '/cancel', 'GET', $token ) );
		}
	}

	public function test_marker_remains_the_same_inode_between_ticks_and_expires_after_interruption(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$dir = $root . '/jobs/' . self::ID;
			mkdir( $dir, 0700, true );
			$marker  = $root . '/.maintenance';
			$service = new FileMaintenance( $root );
			$service->enter( self::ID, $dir, '' );
			$inode = fileinode( $marker );
			self::assertSame( fileinode( $dir . '/maintenance.php' ), $inode );
			touch( $marker, time() - 610 );
			clearstatcache( true );
			$upgrading = null;
			require $marker;
			self::assertIsFloat( $upgrading );
			self::assertLessThan( time() - 600, $upgrading );
			$service->enter( self::ID, $dir, '' );
			clearstatcache( true );
			self::assertSame( $inode, fileinode( $marker ) );
			require $marker;
			self::assertGreaterThan( time() - 600, $upgrading );
			$service->release( self::ID, $dir );
			self::assertFileDoesNotExist( $marker );
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_unrelated_updater_can_replace_marker_in_place_without_losing_it_at_cleanup(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$dir = $root . '/jobs/' . self::ID;
			mkdir( $dir, 0700, true );
			$service = new FileMaintenance( $root );
			$service->enter( self::ID, $dir, '' );
			file_put_contents( $root . '/.maintenance', 'unrelated marker' );
			$service->release( self::ID, $dir );
			self::assertSame( 'unrelated marker', file_get_contents( $root . '/.maintenance' ) );
		} finally {
			TempDir::remove( $root );
		}
	}
}
