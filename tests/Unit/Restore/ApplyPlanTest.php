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

	public function test_a_spool_from_a_moved_private_directory_is_never_applied(): void {
		// The backup ran while the private directory was still in the web root.
		$old   = $this->live . '/backups-for-restic';
		$stage = $this->files . $old;
		mkdir( $stage . '/spool/database', 0700, true );
		file_put_contents( $stage . '/spool/manifest.json', '{"format":"backups-for-restic-site/1"}' );
		file_put_contents( $stage . '/spool/database/000-header.sql', 'SET NAMES utf8mb4;' );
		file_put_contents( $stage . '/spool/database/010-wp_users.sql', 'INSERT INTO wp_users VALUES (1, "admin", "$P$hash");' );
		$rows = array_column( iterator_to_array( $this->plan->actions( $this->files ) ), null, 'path' );
		self::assertSame( 'mkdir', $rows[ $old ]['action'] );
		self::assertSame(
			[
				'path'        => $old . '/spool',
				'destination' => $old . '/spool',
				'action'      => 'skip',
				'reason'      => 'snapshot_spool',
			],
			$rows[ $old . '/spool' ]
		);
		self::assertSame( [], array_values( array_filter( array_keys( $rows ), static fn ( string $path ): bool => str_starts_with( $path, $old . '/spool/' ) ) ) );
		foreach ( [ '/spool/manifest.json', '/spool/database', '/spool/database/010-wp_users.sql' ] as $suffix ) {
			self::assertSame( 'snapshot_spool', $this->plan->inspect( $this->files, $old . $suffix )['reason'], $suffix );
		}
		// A selection of one table file or of the manifest alone is recognized as well.
		unlink( $stage . '/spool/manifest.json' );
		self::assertSame( 'snapshot_spool', $this->plan->inspect( $this->files, $old . '/spool/database/000-header.sql' )['reason'] );
		TempDir::remove( $stage . '/spool/database' );
		file_put_contents( $stage . '/spool/manifest.json', '{}' );
		self::assertSame( 'snapshot_spool', $this->plan->inspect( $this->files, $old . '/spool' )['reason'] );
		self::assertFileDoesNotExist( $old );
	}

	public function test_other_directories_named_spool_are_applied_as_usual(): void {
		$stage = $this->files . $this->live;
		mkdir( $stage . '/mailer/spool/queue', 0700, true );
		mkdir( $stage . '/spool' );
		file_put_contents( $stage . '/mailer/spool/queue/message.eml', 'queued' );
		file_put_contents( $stage . '/mailer/spool/database.sql', 'not a dump directory' );
		file_put_contents( $stage . '/mailer/manifest.json', 'not in a spool' );
		mkdir( $stage . '/mailer/database' );
		file_put_contents( $stage . '/spool/manifest.json.bak', 'other name' );
		$rows = array_column( iterator_to_array( $this->plan->actions( $this->files ) ), 'action', 'path' );
		foreach ( [ '/mailer', '/mailer/spool', '/mailer/spool/queue', '/mailer/database', '/spool' ] as $directory ) {
			self::assertSame( 'mkdir', $rows[ $this->live . $directory ], $directory );
		}
		foreach ( [ '/mailer/spool/queue/message.eml', '/mailer/spool/database.sql', '/mailer/manifest.json', '/spool/manifest.json.bak' ] as $file ) {
			self::assertSame( 'create', $rows[ $this->live . $file ], $file );
		}
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
