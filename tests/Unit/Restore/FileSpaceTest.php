<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\FileSpace;
use PHPUnit\Framework\TestCase;

final class FileSpaceTest extends TestCase {

	public function test_native_filesystem_hint_and_capacity_preserve_integer_bounds(): void {
		$space = new FileSpace( __DIR__ );
		$unit  = max( 1, stat( __DIR__ )['blksize'] );
		self::assertSame( 0, $space->allocation( 0 ) );
		self::assertSame( $unit, $space->allocation( 1 ) );
		self::assertSame( $unit, $space->allocation( $unit ) );
		self::assertSame( $unit * 2, $space->allocation( $unit + 1 ) );
		self::assertSame( PHP_INT_MAX, $space->allocation( PHP_INT_MAX ) );
		self::assertSame( PHP_INT_MAX, FileSpace::add( PHP_INT_MAX - 3, 4 ) );
		$estimate = $space->estimate( 0 );
		self::assertSame( 0, $estimate['required_bytes'] );
		if ( function_exists( 'disk_free_space' ) && false !== disk_free_space( __DIR__ ) ) {
			self::assertIsInt( $estimate['available_bytes'] );
			self::assertTrue( $estimate['enough_space'] );
		} else {
			self::assertNull( $estimate['enough_space'] );
		}
	}

	public function test_only_canonical_reviewed_file_lengths_can_be_charged(): void {
		$row             = ApplyManifest::row( 'snapshot', 'target', 'replace', 'test' );
		$row['identity'] = '1:1:1:1:33152:12:0:0/1:2:1:1:33152:9223372036854775808:0:0';
		$this->expectException( StorageFailed::class );
		( new FileSpace( __DIR__ ) )->row( $row );
	}
}
