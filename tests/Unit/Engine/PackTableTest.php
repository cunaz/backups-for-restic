<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Engine\Prune\PackTable;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class PackTableTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'pack-table' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_duplicate_updates_and_replayed_batches_survive_reopening(): void {
		$file = $this->directory . '/table';
		PackTable::create( $file, 20000 );
		$expected = [];
		for ( $pass = 0; $pass < 2; ++$pass ) {
			$table = new PackTable( $file );
			for ( $i = 0; $i < 200; ++$i ) {
				$name = hash( 'sha256', (string) $i );
				$size = 0 === $i ? -1 : $i + $pass;
				$table->put( $name, $size );
				$expected[ $name ] = $size;
			}
			$table->close();
		}
		$table    = new PackTable( $file );
		$position = 0;
		$actual   = [];
		do {
			$batch = $table->batch( $position, 7 );
			self::assertLessThanOrEqual( 7, $batch['position'] - $position );
			foreach ( $batch['entries'] as $entry ) {
				$actual[ $entry['name'] ] = $entry['size'];
				$table->mark_unindexed( $entry['slot'] );
			}
			$position = $batch['position'];
		} while ( ! $batch['done'] );
		$table->close();
		ksort( $expected );
		ksort( $actual );
		self::assertSame( $expected, $actual );
		self::assertSame( 0600, fileperms( $file ) & 0777 );
		$table = new PackTable( $file );
		foreach ( $table->batch( 0, 1024 )['entries'] as $entry ) {
			self::assertTrue( $entry['unindexed'] );
			self::assertSame( $expected[ $entry['name'] ], $table->size( $entry['name'] ) );
		}
		self::assertNull( $table->size( hash( 'sha256', 'absent' ) ) );
		$table->close();
	}

	public function test_incomplete_payload_without_commit_flag_is_replayed_safely(): void {
		$file = $this->directory . '/table';
		PackTable::create( $file, 100 );
		// A killed insertion wrote bytes into an empty slot but did not commit its flag.
		$handle = fopen( $file, 'r+b' );
		fseek( $handle, 29 );
		fwrite( $handle, str_repeat( 'x', 40 ) );
		fclose( $handle );
		$table = new PackTable( $file );
		$name  = str_repeat( 'a', 64 );
		$table->put( $name, PHP_INT_MAX );
		$table->put( $name, PHP_INT_MAX );
		$batch = $table->batch( 0 );
		$table->close();
		self::assertCount( 1, $batch['entries'] );
		self::assertSame( $name, $batch['entries'][0]['name'] );
		self::assertSame( PHP_INT_MAX, $batch['entries'][0]['size'] );
	}

	public function test_truncated_work_file_is_refused(): void {
		$file = $this->directory . '/table';
		PackTable::create( $file, 100 );
		$handle = fopen( $file, 'r+b' );
		ftruncate( $handle, 20 );
		fclose( $handle );
		$this->expectException( StorageFailed::class );
		new PackTable( $file );
	}
}
