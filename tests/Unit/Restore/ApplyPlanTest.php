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
		// As the backup writes them: json_encode() escapes the slash of the format.
		$site = '{"format":"backups-for-restic-site\/1","created":"2026-11-20T10:00:00Z"}';
		file_put_contents( $stage . '/spool/manifest.json', $site );
		file_put_contents( $stage . '/spool/database/000-header.sql', "-- backups-for-restic-dump/1\nSET NAMES utf8mb4;\n" );
		file_put_contents( $stage . '/spool/database/010-wp_users.sql', "-- backups-for-restic-dump/1 table `wp_users`\nINSERT INTO `wp_users` VALUES (1,'admin','\$P\$hash');\n" );
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
		self::assertFileDoesNotExist( $old );
	}

	public function test_a_partial_selection_of_a_spool_is_recognized_by_what_the_backup_writes(): void {
		$old   = $this->live . '/backups-for-restic';
		$spool = $this->files . $old . '/spool';
		$table = "-- backups-for-restic-dump/1 table `wp_users`\nINSERT INTO `wp_users` VALUES (1);\n";
		$cases = [
			'one table file'            => [ 'database/010-wp_users.sql' => $table ],
			'the views file'            => [ 'database/900-views.sql' => "-- backups-for-restic-dump/1 views\n" ],
			'a later dump format'       => [ 'database/000-header.sql' => "-- backups-for-restic-dump/2\n" ],
			'the dump manifest'         => [ 'database/manifest.json' => '{"format":"backups-for-restic-dump\/1","tables":[]}' ],
			'the site manifest'         => [ 'manifest.json' => "{\n  \"format\": \"backups-for-restic-site/1\"\n}" ],
			'a table beside other data' => [
				'database/notes.txt'        => 'kept beside the dump',
				'database/010-wp_posts.sql' => $table,
			],
		];
		foreach ( $cases as $case => $files ) {
			TempDir::remove( $this->files . $old );
			foreach ( $files as $name => $content ) {
				if ( ! is_dir( dirname( $spool . '/' . $name ) ) ) {
					mkdir( dirname( $spool . '/' . $name ), 0700, true );
				}
				file_put_contents( $spool . '/' . $name, $content );
			}
			// A new plan: one plan remembers what it found in a staged directory.
			$plan = new ApplyPlan( [ $this->live ], [ $this->live . '/private' ], [ $this->live . '/wp-config.php' ] );
			foreach ( array_keys( $files ) as $name ) {
				self::assertSame( 'snapshot_spool', $plan->inspect( $this->files, $old . '/spool/' . $name )['reason'], $case . ': ' . $name );
			}
			self::assertSame( 'snapshot_spool', $plan->inspect( $this->files, $old . '/spool' )['reason'], $case );
		}
	}

	public function test_a_plan_remembers_only_a_bounded_number_of_examined_spools(): void {
		$stage = $this->files . $this->live;
		for ( $i = 0; $i < 300; $i++ ) {
			mkdir( $stage . '/queue-' . $i . '/spool', 0700, true );
			file_put_contents( $stage . '/queue-' . $i . '/spool/job', 'queued' );
		}
		mkdir( $stage . '/backup/spool/database', 0700, true );
		file_put_contents( $stage . '/backup/spool/database/010-wp_users.sql', "-- backups-for-restic-dump/1 table `wp_users`\n" );
		for ( $round = 0; $round < 2; $round++ ) {
			for ( $i = 0; $i < 300; $i++ ) {
				self::assertSame( 'create', $this->plan->inspect( $this->files, $this->live . '/queue-' . $i . '/spool/job' )['action'] );
			}
			self::assertSame( 'snapshot_spool', $this->plan->inspect( $this->files, $this->live . '/backup/spool/database/010-wp_users.sql' )['reason'] );
		}
		$remembered = new \ReflectionProperty( ApplyPlan::class, 'spools' );
		self::assertLessThanOrEqual( 256, count( $remembered->getValue( $this->plan ) ) );
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
		// An application's own spool with the same layout as a backup's, but none of its files.
		mkdir( $stage . '/queue/spool/database', 0700, true );
		file_put_contents( $stage . '/queue/spool/manifest.json', '{"version":3,"format":"queue-site\/1","note":"backups-for-restic-site"}' );
		file_put_contents( $stage . '/queue/spool/database/jobs.json', '{"jobs":[]}' );
		file_put_contents( $stage . '/queue/spool/database/manifest.json', '{"format":"queue\/1"}' );
		file_put_contents( $stage . '/queue/spool/database/export.sql', "-- MySQL dump 10.13\n-- backups-for-restic-dump/1\n" );
		file_put_contents( $stage . '/queue/spool/database/empty.sql', '' );
		mkdir( $stage . '/queue/spool/database/010-wp_users.sql' );
		$rows = array_column( iterator_to_array( $this->plan->actions( $this->files ) ), 'action', 'path' );
		foreach ( [ '/mailer', '/mailer/spool', '/mailer/spool/queue', '/mailer/database', '/spool', '/queue/spool', '/queue/spool/database', '/queue/spool/database/010-wp_users.sql' ] as $directory ) {
			self::assertSame( 'mkdir', $rows[ $this->live . $directory ], $directory );
		}
		foreach ( [ '/mailer/spool/queue/message.eml', '/mailer/spool/database.sql', '/mailer/manifest.json', '/spool/manifest.json.bak', '/queue/spool/manifest.json', '/queue/spool/database/jobs.json', '/queue/spool/database/manifest.json', '/queue/spool/database/export.sql', '/queue/spool/database/empty.sql' ] as $file ) {
			self::assertSame( 'create', $rows[ $this->live . $file ], $file );
		}
	}

	public function test_a_staged_spool_that_cannot_be_read_counts_as_the_backups(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root reads every file.' );
		}
		$stage = $this->files . $this->live;
		mkdir( $stage . '/a/spool/database', 0700, true );
		file_put_contents( $stage . '/a/spool/database/010-wp_users.sql', "-- backups-for-restic-dump/1 table `wp_users`\n" );
		chmod( $stage . '/a/spool/database/010-wp_users.sql', 0000 );
		mkdir( $stage . '/b/spool', 0700, true );
		file_put_contents( $stage . '/b/spool/manifest.json', '{"format":"backups-for-restic-site\/1"}' );
		chmod( $stage . '/b/spool/manifest.json', 0000 );
		mkdir( $stage . '/c/spool/database', 0700, true );
		chmod( $stage . '/c/spool/database', 0000 );
		try {
			foreach ( [ '/a/spool/database/010-wp_users.sql', '/b/spool/manifest.json', '/c/spool/database' ] as $path ) {
				self::assertSame( 'snapshot_spool', $this->plan->inspect( $this->files, $this->live . $path )['reason'], $path );
			}
		} finally {
			chmod( $stage . '/a/spool/database/010-wp_users.sql', 0600 );
			chmod( $stage . '/b/spool/manifest.json', 0600 );
			chmod( $stage . '/c/spool/database', 0700 );
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
