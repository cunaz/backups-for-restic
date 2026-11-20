<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\FileWorkPending;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class DirectorySpellingsTest extends TestCase {

	private string $root;
	private string $folder;
	private string $workspace;

	protected function setUp(): void {
		$this->root      = TempDir::create( 'directory-spellings' );
		$this->folder    = $this->root . '/names';
		$this->workspace = $this->root . '/private';
		mkdir( $this->folder, 0700 );
		mkdir( $this->workspace, 0700 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	private function stable(): void {
		clearstatcache( true, $this->folder );
		while ( time() <= filectime( $this->folder ) ) {
			usleep( 10000 );
		}
	}

	public function test_a_partially_indexed_directory_resumes_with_exact_and_folded_collisions(): void {
		for ( $i = 0; $i < 180; ++$i ) {
			touch( $this->folder . '/photo-' . $i );
		}
		touch( $this->folder . '/Öl' );
		touch( $this->folder . '/Logo' );
		touch( $this->folder . '/logo' );
		$this->stable();
		$start = new DirectorySpellings( $this->workspace, 0 );
		self::assertFalse( $start->known( $this->folder ) );
		try {
			$start->unique( $this->folder, 'photo-120' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			self::assertTrue( $start->known( $this->folder ) );
		}
		$resumed = new DirectorySpellings( $this->workspace, INF );
		self::assertTrue( $resumed->unique( $this->folder, 'photo-120' ) );
		self::assertTrue( $resumed->unique( $this->folder, 'missing' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'PHOTO-120' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'öl' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'Logo' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'logo' ) );
		touch( $this->folder . '/PHOTO-120' );
		$this->stable();
		self::assertFalse( $resumed->unique( $this->folder, 'photo-120' ) );
	}

	public function test_an_incomplete_persisted_record_fails_closed(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		$first = new DirectorySpellings( $this->workspace, INF );
		self::assertTrue( $first->unique( $this->folder, 'original' ) );
		$files = glob( $this->workspace . '/spell-*.names' );
		self::assertCount( 1, $files );
		file_put_contents( $files[0], '' );
		$this->expectException( StorageFailed::class );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' );
	}
}
