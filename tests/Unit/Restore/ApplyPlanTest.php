<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Tests\Support\TempDir;

final class ApplyPlanTest extends TestCase {

	private string $temp;
	private string $files;
	private string $live;
	private ApplyPlan $plan;

	protected function setUp(): void {
		$this->temp  = TempDir::create( 'apply-plan' );
		$this->files = $this->temp . '/staged';
		$this->live  = $this->temp . '/live';
		mkdir( $this->live );
		mkdir( $this->files . $this->live, 0700, true );
		$this->plan = new ApplyPlan( [ $this->live ], [ $this->live . '/private' ], [ $this->live . '/wp-config.php' ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->temp );
	}

	public function test_preview_distinguishes_new_replaced_and_protected_files_without_writing(): void {
		$stage = $this->files . $this->live;
		mkdir( $stage . '/new' );
		file_put_contents( $stage . '/new/file', 'new' );
		file_put_contents( $this->live . '/existing.php', 'old' );
		file_put_contents( $stage . '/existing.php', 'new' );
		file_put_contents( $stage . '/wp-config.php', 'private' );
		mkdir( $stage . '/private' );
		file_put_contents( $stage . '/private/token', 'private' );
		$rows = array_column( iterator_to_array( $this->plan->actions( $this->files ) ), 'action', 'path' );
		self::assertSame( 'mkdir', $rows[ $this->live . '/new' ] );
		self::assertSame( 'create', $rows[ $this->live . '/new/file' ] );
		self::assertSame( 'replace', $rows[ $this->live . '/existing.php' ] );
		self::assertSame( 'skip', $rows[ $this->live . '/wp-config.php' ] );
		self::assertSame( 'skip', $rows[ $this->live . '/private' ] );
		self::assertArrayNotHasKey( $this->live . '/private/token', $rows );
		self::assertFileDoesNotExist( $this->live . '/new' );
		self::assertSame( 'old', file_get_contents( $this->live . '/existing.php' ) );
	}

	public function test_recheck_refuses_aliases_links_untrusted_parents_and_a_disappeared_root(): void {
		$stage = $this->files . $this->live;
		mkdir( $stage . '/dir' );
		file_put_contents( $stage . '/dir/file', 'staged' );
		self::assertSame( 'create', $this->plan->inspect( $this->files, $this->live . '/dir/file' )['action'] );
		symlink( $this->temp, $this->live . '/dir' );
		self::assertSame( 'refuse', $this->plan->inspect( $this->files, $this->live . '/dir/file' )['action'] );
		unlink( $this->live . '/dir' );
		file_put_contents( $this->live . '/dir', 'not a directory' );
		self::assertSame( 'refuse', $this->plan->inspect( $this->files, $this->live . '/dir/file' )['action'] );
		symlink( $this->temp, $stage . '/link' );
		self::assertSame( 'refuse', $this->plan->inspect( $this->files, $this->live . '/link' )['action'] );
		foreach ( [ 'A', 'a' ] as $name ) {
			file_put_contents( $stage . '/' . $name, 'other' );
		}
		self::assertSame( 'refuse', $this->plan->inspect( $this->files, $this->live . '/a' )['action'] );
		file_put_contents( $stage . '/readme', 'other' );
		file_put_contents( $this->live . '/README', 'old' );
		self::assertSame( 'refuse', $this->plan->inspect( $this->files, $this->live . '/readme' )['action'] );
		foreach ( [ '../file', $this->live . '/../other', $this->live . '//etc/passwd', '/' ] as $path ) {
			self::assertSame( 'refuse', $this->plan->inspect( $this->files, $path )['action'] );
		}
		self::assertSame( 'skip', $this->plan->inspect( $this->files, $this->live . '-other/file' )['action'] );
	}
}
