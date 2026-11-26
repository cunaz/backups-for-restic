<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Integration;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Tests\Support\TempDir;
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

	public function test_an_empty_fixture_uses_the_installed_wp_cli_and_can_supply_its_own(): void {
		$site     = TestSite::root();
		$previous = getenv( 'BFR_SITE_TOOLS_DIR' );
		$fixture  = TempDir::create( 'cli-tools' );
		try {
			putenv( 'BFR_SITE_TOOLS_DIR=' . $fixture );
			self::assertTrue( TestSite::has_wp_cli() );
			[ $status, $output ] = TestSite::wp( [ '--version' ], $site );
			self::assertSame( 0, $status, $output );
			self::assertMatchesRegularExpression( '/^WP-CLI [0-9]+\.[0-9]+\.[0-9]+/m', $output );

			mkdir( $fixture . '/bin' );
			file_put_contents( $fixture . '/bin/wp', '<?php echo "fixture WP-CLI";' );
			self::assertTrue( TestSite::has_wp_cli() );
			self::assertSame( [ 0, 'fixture WP-CLI' ], TestSite::wp( [ '--version' ], $site ) );
		} finally {
			putenv( false === $previous ? 'BFR_SITE_TOOLS_DIR' : 'BFR_SITE_TOOLS_DIR=' . $previous );
			TempDir::remove( $fixture );
		}
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_job_creation_reports_a_private_storage_error_without_a_trace(): void {
		$fixture = TempDir::create( 'cli-job-storage' );
		$private = new PrivateDirectory( $fixture . '/private' );
		try {
			$private->ensure();
			$override = $fixture . '/private-dir.php';
			file_put_contents( $override, '<?php define( "BACKUPS_FOR_RESTIC_PRIVATE_DIR", ' . var_export( $private->path, true ) . ' );' );
			$jobs = $private->subdirectory( 'jobs' );
			// A directory cannot be opened as a writable lock file, even when testing as root.
			mkdir( $jobs . '/' . JobStore::CREATE_LOCK );
			[ $status, $output ] = self::command( [ 'backup', '--require=' . $override ] );
			self::assertSame( 1, $status, $output );
			self::assertSame( 'Error: The job could not be started. Check free space and write permissions in the private working directory.', TestSite::last_line( $output ) );
			self::assertStringNotContainsString( $private->path, $output );
			self::assertStringNotContainsString( 'Stack trace:', $output );
			self::assertStringNotContainsString( 'Warning:', $output );
			self::assertSame( [ '.', '..', JobStore::CREATE_LOCK ], scandir( $jobs ) );
		} finally {
			TempDir::remove( $fixture );
		}
	}

	public function test_while_a_file_job_holds_maintenance_only_the_plugins_commands_boot_without_other_plugins(): void {
		[ $status, $output ] = TestSite::wp( [ 'plugin', 'activate', 'hello' ] );
		self::assertSame( 0, $status, $output );
		// The marker and the job's private copy are one inode, so they share a filesystem.
		$work = dirname( TestSite::root() ) . '/bfr-maintenance-' . bin2hex( random_bytes( 6 ) );
		$job  = '20261120T120000Z-0a0b0c';
		mkdir( $work . '/' . $job, 0700, true );
		file_put_contents(
			$work . '/probe.php',
			'<?php WP_CLI::add_wp_hook( "plugins_loaded", static function (): void {
				require_once ABSPATH . "wp-admin/includes/plugin.php";
				$stored = update_option( "active_plugins", [] );
				deactivate_plugins( "backups-for-restic/backups-for-restic.php", true );
				WP_CLI::log( "probe: hello=" . ( function_exists( "hello_dolly" ) ? "loaded" : "absent" ) . " stored=" . var_export( $stored, true ) );
			}, PHP_INT_MAX );'
		);
		// WP-CLI ignores maintenance, so an eval command can still release the marker.
		$marker = sprintf(
			'$marker = new BackupsForRestic\Restore\FileMaintenance( (string) realpath( ABSPATH ), "backups-for-restic/backups-for-restic.php", rest_url( "backups-for-restic/v1" ), admin_url( "admin.php" ) ); $job = %s; $dir = (string) realpath( %s );',
			var_export( $job, true ),
			var_export( $work . '/' . $job, true )
		);
		try {
			[ $status, $before ] = TestSite::wp( [ 'option', 'get', 'active_plugins', '--format=json' ] );
			self::assertSame( 0, $status, $before );
			[ $status, $output ] = TestSite::wp( [ 'eval', $marker . ' $marker->enter( $job, $dir, "" );' ] );
			self::assertSame( 0, $status, $output );
			self::assertFileExists( TestSite::root() . '/.maintenance' );

			[ $status, $output ] = TestSite::wp( [ 'plugin', 'list', '--status=active', '--field=name' ] );
			self::assertSame( 0, $status, $output );
			self::assertContains( 'hello', explode( "\n", $output ), 'Another command boots with the site\'s plugins.' );
			self::assertContains( 'backups-for-restic', explode( "\n", $output ) );
			foreach ( [ [ 'backups-for-restic', 'status' ], [ '--skip-plugins=hello', '--skip-themes', 'backups-for-restic', 'status' ] ] as $command ) {
				[ $status, $output ] = TestSite::wp( [ '--require=' . $work . '/probe.php', ...$command ] );
				self::assertSame( 0, $status, $output );
				self::assertStringContainsString( 'probe: hello=absent stored=false', $output );
				self::assertStringContainsString( 'Active job: -', $output );
			}
			[ $status, $after ] = TestSite::wp( [ 'option', 'get', 'active_plugins', '--format=json' ] );
			self::assertSame( 0, $status, $after );
			self::assertSame( $before, $after, 'No command stored the plugin list that the plugin\'s own commands boot with.' );
		} finally {
			[ $status, $output ] = TestSite::wp( [ 'eval', $marker . ' $marker->release( $job, $dir );' ] );
			TestSite::wp( [ 'plugin', 'deactivate', 'hello' ] );
			TempDir::remove( $work );
		}
		self::assertSame( 0, $status, $output );
		self::assertFileDoesNotExist( TestSite::root() . '/.maintenance' );
	}

	public function test_every_tick_of_one_process_reads_the_confirming_administrator_again(): void {
		$login = 'bfr-confirming-' . bin2hex( random_bytes( 4 ) );
		TestSite::user( $login, 'administrator' );
		try {
			// The policy that FileChanges applies before every tick, asked twice in one process
			// as by tick --until-idle. Between the two, another request takes the role away:
			// it writes the database, not this process's object cache.
			[ $status, $output ] = TestSite::wp(
				[
					'eval',
					sprintf(
						'$changes = ( new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ) )->file_changes();
						$allowed = ( new ReflectionProperty( $changes, "allowed" ) )->getValue( $changes );
						$user    = get_user_by( "login", %s );
						$first   = $allowed( $user->ID );
						global $wpdb;
						$wpdb->update( $wpdb->usermeta, [ "meta_value" => serialize( [ "editor" => true ] ) ], [ "user_id" => $user->ID, "meta_key" => $wpdb->get_blog_prefix() . "capabilities" ] );
						echo wp_json_encode( [ $first, $allowed( $user->ID ) ] );',
						var_export( $login, true )
					),
				]
			);
			self::assertSame( 0, $status, $output );
			self::assertSame( '[true,false]', TestSite::last_line( $output ), 'The second tick sees the role that the database holds now.' );
		} finally {
			TestSite::wp( [ 'user', 'delete', $login, '--yes' ] );
		}
	}

	public function test_a_permission_lost_before_a_file_job_starts_is_reported_as_such(): void {
		$login = 'bfr-starting-' . bin2hex( random_bytes( 4 ) );
		TestSite::user( $login, 'administrator' );
		try {
			// The confirmation is authorized for the administrator that this process loaded.
			// Before start_apply() checks the account again, another request takes the role
			// away in the database, as in a race between the two.
			[ $status, $output ] = TestSite::wp(
				[
					'eval',
					sprintf(
						'( new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ) )->private_dir()->ensure();
						$user = get_user_by( "login", %s );
						wp_set_password( "bfr-test-password", $user->ID );
						wp_set_current_user( $user->ID );
						$id     = "20261120T000000Z-0d0e0f";
						$digest = str_repeat( "a", 64 );
						$token  = wp_create_nonce( implode( "|", [ "backups_for_restic_live", home_url(), "files", "apply", $id, "default", $digest, "" ] ) );
						global $wpdb;
						$wpdb->update( $wpdb->usermeta, [ "meta_value" => serialize( [ "editor" => true ] ) ], [ "user_id" => $user->ID, "meta_key" => $wpdb->get_blog_prefix() . "capabilities" ] );
						$request = new WP_REST_Request( "POST", "/backups-for-restic/v1/restores/" . $id . "/apply" );
						$request->set_header( "content-type", "application/json" );
						$request->set_body( wp_json_encode( [ "reviewed" => true, "site_url" => home_url(), "digest" => $digest, "review_token" => $token, "user_password" => "bfr-test-password" ] ) );
						$response = rest_do_request( $request );
						echo wp_json_encode( [ $response->get_status(), $response->get_data()["code"] ?? null ] );',
						var_export( $login, true )
					),
				]
			);
			self::assertSame( 0, $status, $output );
			self::assertSame( '[409,"backups_for_restic_file_writes_refused"]', TestSite::last_line( $output ), 'The administrator learns that the permission is gone, not that other work is in the way.' );
		} finally {
			TestSite::wp( [ 'user', 'delete', $login, '--yes' ] );
		}
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_recovery_kit_requires_explicit_redirect_and_records_delivery_without_credentials(): void {
		$before            = TestSite::state( 'recovery_kit' );
		[ $exit, $output ] = self::command( [ 'recovery-kit' ] );
		self::assertNotSame( 0, $exit );
		self::assertFalse( str_contains( $output, TestSite::$repository_password ) );
		self::assertSame( $before, TestSite::state( 'recovery_kit' ) );

		[ $exit, $kit ] = self::command( [ 'recovery-kit', '--force' ] );
		self::assertSame( 0, $exit );
		self::assertTrue( str_contains( $kit, "RESTIC_PASSWORD='" . TestSite::$repository_password . "'" ) );
		$history = TestSite::state( 'recovery_kit' );
		self::assertSame( 'cli', $history['views'][0]['via'] ?? null );
		self::assertSame( 'WP-CLI', $history['views'][0]['login'] ?? null );
		self::assertFalse( str_contains( (string) json_encode( $history ), TestSite::$repository_password ) );
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
		// As strings: a short ID such as 053e4943 or 7278e184 is also a number to PHP, and a sort
		// that compares some IDs as numbers and others as strings depends on the order it starts from.
		sort( $listed, SORT_STRING );
		sort( $expected, SORT_STRING );
		self::assertSame( $expected, $listed, $output );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_snapshots_prints_a_host_name_with_its_control_characters_quoted(): void {
		TestSite::idle( 600 );
		// Any client of a shared repository chooses the host names of its snapshots.
		$host    = "evil\e[2J\e]0;spoofed\x07\nline";
		$fixture = TempDir::create( 'cli-host-name' );
		$id      = '';
		try {
			file_put_contents( $fixture . '/file.txt', 'a file of another client' );
			foreach ( explode( "\n", TestSite::restic()->must( [ 'backup', '--json', '--host', $host, $fixture . '/file.txt' ] ) ) as $line ) {
				$message = json_decode( $line, true );
				if ( is_array( $message ) && 'summary' === ( $message['message_type'] ?? null ) ) {
					$id = (string) ( $message['snapshot_id'] ?? '' );
				}
			}
			self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $id );

			[ $status, $output ] = self::command( [ 'snapshots' ] );

			self::assertSame( 0, $status, $output );
			self::assertMatchesRegularExpression( '/^' . substr( $id, 0, 8 ) . '  \S+  "evil\\\\x1b\[2J\\\\x1b\]0;spoofed\\\\a\\\\nline"$/m', $output );
			self::assertStringNotContainsString( "\e", $output, 'No escape sequence reaches the terminal.' );
			self::assertStringNotContainsString( "\x07", $output );
		} finally {
			if ( '' !== $id ) {
				TestSite::restic()->must( [ 'forget', $id ] );
			}
			TempDir::remove( $fixture );
		}
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_ls_lists_a_directory_of_a_snapshot(): void {
		TestSite::idle( 600 );
		$ids = self::snapshots();
		self::assertNotSame( [], $ids );
		// restic lists the oldest first; every backup of the site holds the fixture.
		$id      = $ids[ count( $ids ) - 1 ];
		$fixture = TestSite::root() . '/wp-content/uploads/fixture';

		[ $status, $output ] = self::command( [ 'ls', substr( $id, 0, 8 ), $fixture ] );
		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^- [0-7]{4} +8388608  \S+ +file-0\.bin$/m', $output );

		[ $status, $output ] = self::command( [ 'ls', $id ] );
		self::assertSame( 0, $status, $output );
		self::assertMatchesRegularExpression( '/^d [0-7]{4} +-  \S+ +\S+\/$/m', $output, 'The root of the snapshot holds a directory.' );

		$refusals = [
			'The snapshot has no directory with this path.' => [ $id, $fixture . '/file-0.bin' ],
			'This is not a path inside a snapshot.'        => [ $id, 'wp-content' ],
			'This is not a snapshot ID.'                   => [ 'latest' ],
			'The repository has no snapshot with this ID.' => [ str_repeat( 'ab', 32 ) ],
		];
		foreach ( $refusals as $message => $arguments ) {
			[ $status, $output ] = self::command( [ 'ls', ...$arguments ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: ' . $message, $output );
		}
		self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ) );
	}

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_restore_stages_only_the_selected_file(): void {
		TestSite::idle( 600 );
		$ids                 = self::snapshots();
		$id                  = $ids[ count( $ids ) - 1 ];
		$path                = TestSite::root() . '/wp-content/uploads/fixture/file-0.bin';
		[ $status, $output ] = self::command( [ 'restore', $id, '../outside' ] );
		self::assertSame( 1, $status, $output );
		self::assertStringNotContainsString( 'created.', $output );

		[ $status, $output ] = self::command( [ 'restore', substr( $id, 0, 8 ), $path, '--wait' ] );
		self::assertSame( 0, $status, $output );
		$job = self::created( $output );
		try {
			self::assertStringContainsString( 'Files staged in restores/' . $job, $output );
			$files = TestSite::private_dir() . '/restores/' . $job . '/files';
			self::assertFileEquals( $path, $files . $path );
			self::assertFileDoesNotExist( $files . TestSite::root() . '/wp-config.php' );
		} finally {
			TestSite::api( 'DELETE', '/restores/' . $job );
		}
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

	/**
	 * @return non-empty-list<string> Recent jobs, newest first.
	 */
	private static function recent_job_ids(): array {
		[ $status, $result ] = TestSite::api( 'GET', '/jobs' );
		self::assertSame( 200, $status );
		self::assertIsArray( $result['jobs'] );
		$ids = array_column( $result['jobs'], 'id' );
		self::assertNotEmpty( $ids );
		self::assertContainsOnlyString( $ids );
		return $ids;
	}

	public function test_undo_prints_its_review_and_refuses_or_records_an_apply_with_nothing_to_revert(): void {
		$site    = TestSite::root();
		$applies = [ '20261121T100000Z-0a0c00', '20261121T100000Z-0a0d00' ];
		TestSite::idle( 120 );
		try {
			// An entry that the site now maps to another live path is kept with its reason, and
			// nothing else is left: the undo is refused with the reasons in its review.
			TestSite::undoable_apply( $applies[0], [ [ $site . '/bfr-mapped.txt', $site . '/bfr-elsewhere.txt', 'create' ] ] );
			[ $status, $output ] = self::command( [ 'undo', $applies[0], '--yes' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( '"action":"keep"', $output );
			self::assertStringContainsString( '"reason":"destination_changed"', $output );
			self::assertStringContainsString( 'To restore: 0. To remove: 0. To keep: 1.', $output );
			self::assertStringContainsString( 'Error: Nothing of this apply can be undone now. Every entry it changed is already undone or is kept, for example because it changed after the apply or the site no longer permits the change.', $output );
			// An apply whose entries have nothing left to revert is recorded as undone by its
			// review, and is no longer offered.
			TestSite::undoable_apply( $applies[1], [ [ $site . '/bfr-never-written.txt', $site . '/bfr-never-written.txt', 'create' ] ] );
			[ $status, $output ] = self::command( [ 'undo', $applies[1], '--yes' ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'To restore: 0. To remove: 0. To keep: 1.', $output );
			self::assertStringContainsString( 'Success: Nothing is left to revert: every entry is as it was before the apply, or was removed since. The apply is now recorded as undone.', $output );
			self::assertFileExists( TestSite::private_dir() . '/jobs/' . $applies[1] . '/undo/reverted' );
			[ $status, $output ] = self::command( [ 'undo', $applies[1], '--yes' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'This apply has no available undo.', $output );
		} finally {
			foreach ( $applies as $apply ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $apply );
				TempDir::remove( TestSite::private_dir() . '/jobs/' . substr( $apply, 0, -1 ) . '1' );
				TempDir::remove( TestSite::private_dir() . '/restores/' . substr( $apply, 0, -1 ) . '1' );
			}
		}
	}

	public function test_undo_reports_what_an_undo_kept_also_when_it_stopped(): void {
		$undos  = [ '20261121T110000Z-0b0c01', '20261121T110000Z-0b0c02', '20261121T110000Z-0b0c03', '20261121T110000Z-0b0c04', '20261121T110000Z-0b0c05' ];
		$kept   = [ [ TestSite::root() . '/wp-content/uploads/bfr-kept.txt', 'changed_since_apply' ] ];
		$result = [
			'restore'              => '20261120T000000Z-0a0b02',
			'files_changed'        => 1,
			'restored'             => 1,
			'removed'              => 0,
			'unchanged'            => 0,
			'kept'                 => 1,
			'changed_after_review' => 0,
			'apply_state'          => 'partly_undone',
		];
		// What the undo command prints once its job has ended.
		$report = static fn ( string $id ): array => TestSite::wp(
			[
				'eval',
				sprintf(
					'( new ReflectionMethod( BackupsForRestic\WordPress\CliCommand::class, "report" ) )->invoke( new BackupsForRestic\WordPress\CliCommand( new BackupsForRestic\WordPress\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ) ), %s );',
					var_export( $id, true )
				),
			]
		);
		try {
			TestSite::ended_undo( $undos[0], 'succeeded', $result, $kept );
			[ $status, $output ] = $report( $undos[0] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( '"reason":"changed_since_apply"', $output );
			self::assertStringContainsString( 'Warning: Kept 1 entry: changed after the apply.', $output );
			self::assertStringContainsString( 'Success: The apply is partly undone: 1 restored, 0 removed, 1 kept, 0 unchanged. The kept entries are listed above with their reasons.', $output );
			// A cancelled or failed undo says what it did before it stopped, and lists what it kept.
			TestSite::ended_undo( $undos[1], 'cancelled', $result, $kept );
			[ $status, $output ] = $report( $undos[1] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Warning: Kept 1 entry: changed after the apply.', $output );
			self::assertStringContainsString( 'Error: The undo was cancelled. Before it stopped, it did this: 1 restored, 0 removed, 1 kept, 0 unchanged.', $output );
			TestSite::ended_undo( $undos[2], 'failed', $result, $kept, 'file_writes_refused' );
			[ $status, $output ] = $report( $undos[2] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( '"reason":"changed_since_apply"', $output );
			self::assertStringContainsString( 'Error: The site no longer allows these file changes, or the administrator who confirmed them may no longer make them. Before it stopped, it did this: 1 restored, 0 removed, 1 kept, 0 unchanged.', $output );
			// An undo that could not seal the list of what it kept says so, and still what it did.
			TestSite::ended_undo( $undos[3], 'succeeded', $result, [] );
			[ $status, $output ] = $report( $undos[3] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( "Warning: The list of the entries that this undo kept is not available; the job's log says why.", $output );
			self::assertStringContainsString( 'Success: The apply is partly undone: 1 restored, 0 removed, 1 kept, 0 unchanged. The kept entries stay undoable', $output );
			self::assertStringNotContainsString( 'listed above', $output );
			// A sealed list whose page was changed since cannot be read: the undo still says what it did, and how it ended.
			TestSite::ended_undo( $undos[4], 'succeeded', $result, $kept );
			$page = TestSite::private_dir() . '/jobs/' . $undos[4] . '/kept/rows-0.1';
			self::assertFileExists( $page );
			file_put_contents( $page, str_replace( 'changed_since_apply', 'deleted_since_apply', (string) file_get_contents( $page ) ) );
			[ $status, $output ] = $report( $undos[4] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'Warning: The list of the entries that this undo kept cannot be read to its end: a part of it is missing or was changed.', $output );
			self::assertStringNotContainsString( 'deleted_since_apply', $output );
			self::assertStringNotContainsString( 'Warning: Kept', $output );
			self::assertStringContainsString( 'Success: The apply is partly undone: 1 restored, 0 removed, 1 kept, 0 unchanged. The kept entries stay undoable', $output );
			self::assertStringNotContainsString( 'listed above', $output );
			// A stopped undo with such a list keeps its failure and its counts.
			TempDir::remove( TestSite::private_dir() . '/jobs/' . $undos[4] );
			TestSite::ended_undo( $undos[4], 'cancelled', $result, $kept );
			file_put_contents( $page, str_replace( 'changed_since_apply', 'deleted_since_apply', (string) file_get_contents( $page ) ) );
			[ $status, $output ] = $report( $undos[4] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Warning: The list of the entries that this undo kept cannot be read to its end', $output );
			self::assertStringContainsString( 'Error: The undo was cancelled. Before it stopped, it did this: 1 restored, 0 removed, 1 kept, 0 unchanged.', $output );
		} finally {
			foreach ( $undos as $undo ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $undo );
			}
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
			// WP-CLI can emit its own PHP startup diagnostics before invoking the plugin command.
			$header = preg_quote( $id . ' Backup Cancelled', '/' );
			self::assertMatchesRegularExpression( '/^' . $header . '\n\d{4}-\d\d-\d\d \d\d:\d\d:\d\d info +Job created\./m', $output );
			self::assertStringContainsString( 'Cancellation requested. {"user":"wp-cli"}', $output );
			self::assertStringContainsString( 'Job finished. {"state":"cancelled"}', $output );
			[ $status, $tail ] = self::command( [ 'logs', $id, '--lines=2' ] );
			self::assertSame( 0, $status, $tail );
			self::assertMatchesRegularExpression( '/^' . $header . '\n[^\n]+\n[^\n]+\z/m', trim( $tail ), 'The selected heading and exactly two log entries.' );

			// The heartbeat may start another job while the external CLI process is booting.
			$before              = self::recent_job_ids();
			[ $status, $latest ] = self::command( [ 'logs', '--lines=2' ] );
			$after               = self::recent_job_ids();
			self::assertSame( 0, $status, $latest );
			self::assertSame( 1, preg_match( '/^(\d{8}T\d{6}Z-[a-f0-9]{6}) /m', $latest, $match ) );
			$previous = array_search( $before[0], $after, true );
			self::assertIsInt( $previous );
			self::assertContains( $match[1], array_slice( $after, 0, $previous + 1 ), 'A job that was the latest during the command.' );

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

	#[DependsExternal( WordPressSiteTest::class, self::SETUP )]
	public function test_a_restore_of_a_snapshot_that_the_repository_does_not_hold_ends_with_the_restores_text(): void {
		TestSite::idle( 600 );
		[ $status, $output ] = self::command( [ 'restore', str_repeat( 'ab', 32 ), '--wait' ] );
		self::assertSame( 1, $status, $output );
		$id = self::created( $output );
		// Before: "Another program kept changing the repository while this job worked on it."
		self::assertStringContainsString( 'Error: The snapshot of this restore is not in the repository.', $output );
		self::assertStringNotContainsString( 'kept changing', $output );
		$job = TestSite::job( $id );
		self::assertSame( [ 'restore', 'failed', 'restore_snapshot_missing' ], [ $job['type'] ?? null, $job['state'] ?? null, $job['error']['code'] ?? null ], (string) json_encode( $job ) );
		self::assertStringContainsString( 'The restore stopped and kept no files.', (string) ( $job['error']['hint'] ?? '' ) );
		self::assertDirectoryDoesNotExist( TestSite::private_dir() . '/restores/' . $id );
		self::assertSame( '', trim( TestSite::restic()->must( [ 'list', 'locks', '--no-lock' ] ) ) );
	}

	public function test_database_discard_prints_its_review_and_needs_the_typed_site_address(): void {
		$jobs  = [ '20261126T130000Z-0d1600', '20261126T130000Z-0d1601', '20261126T130000Z-0d1602' ];
		$here  = '20261126T130000Z-0d1603';
		$typed = static fn ( string $job, string $input ): array => TestSite::wp_input( [ 'backups-for-restic', 'database-discard', $job ], $input );
		$kept  = static function ( string $job ): void {
			self::assertArrayHasKey( 'database_owner', TestSite::job_document( $job )['data'] ?? [], 'Nothing was discarded.' );
		};
		foreach ( $jobs as $job ) {
			TestSite::database_binding( $job, true );
		}
		TestSite::database_binding( $here, false );
		try {
			// A script of the earlier form gets an error, not a discard.
			[ $status, $output ] = self::command( [ 'database-discard', $jobs[0], '--yes' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: database-discard no longer takes --yes, and nothing was discarded.', $output );
			$kept( $jobs[0] );
			// Without the typed address it prints the review and discards nothing.
			[ $status, $output ] = $typed( $jobs[0], '' );
			self::assertSame( 1, $status, $output );
			foreach ( [ 'Its private tables are in the database ', ' on the server 7c4dd2a2-d1b4-11f1-9b6f-0242ac120002.', '  _bfr_r_' . str_repeat( 'c', 32 ) . '_b1', 'What stays:', '  The database that this site uses now: the discard changes none of its tables.', 'This database restore recorded no completed exchange.' ] as $part ) {
				self::assertStringContainsString( $part, $output );
			}
			self::assertSame( 1, preg_match( '/^Digest of this review: [0-9a-f]{64}$/m', $output ), $output );
			self::assertStringContainsString( 'Error: The typed address is not the site address, ' . TestSite::url() . '. Nothing was discarded.', $output );
			$kept( $jobs[0] );
			// The answer to the prompt of the earlier form is no site address either.
			[ $status, $output ] = $typed( $jobs[0], "y\n" );
			self::assertSame( 1, $status, $output );
			$kept( $jobs[0] );
			// A digest that is not the one of this review.
			[ $status, $output ] = self::command( [ 'database-discard', $jobs[0], '--site-url=' . TestSite::url(), '--digest=' . str_repeat( 'e', 64 ) ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The binding of this database restore is no longer as its review showed it, so it was not discarded.', $output );
			$kept( $jobs[0] );
			// Typed at the prompt, by the shell operator without a WordPress user.
			[ $status, $output ] = $typed( $jobs[0], TestSite::url() . "\n" );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'Success: The binding was discarded and nothing was dropped. Remove those of the 7 tables listed above that exist in the database ', $output );
			$recorded = TestSite::job_document( $jobs[0] )['data']['database_discarded'] ?? [];
			self::assertSame( [ 0, 'wp-cli' ], [ $recorded['user'] ?? null, $recorded['via'] ?? null ] );
			// A script names the address and the digest of a review that it was shown before.
			[ , $output ] = $typed( $jobs[1], '' );
			self::assertSame( 1, preg_match( '/^Digest of this review: ([0-9a-f]{64})$/m', $output, $digest ), $output );
			[ $status, $output ] = self::command( [ 'database-discard', $jobs[1], '--site-url=' . TestSite::url(), '--digest=' . $digest[1] ] );
			self::assertSame( 0, $status, $output );
			self::assertArrayNotHasKey( 'database_owner', TestSite::job_document( $jobs[1] )['data'] ?? [ 'database_owner' => true ] );
			// What cannot be discarded says what is the case.
			[ $status, $output ] = self::command( [ 'database-discard', $jobs[0], '--site-url=' . TestSite::url() ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The binding of this database restore was discarded already.', $output );
			[ $status, $output ] = self::command( [ 'database-discard', $here, '--site-url=' . TestSite::url() ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The private tables of this database restore are in the connected database, so its binding is not discarded.', $output );
			$kept( $here );
			// A selected WordPress user who may not change the database.
			TestSite::user( 'bfr-cli-editor', 'editor' );
			[ $status, $output ] = self::command( [ 'database-discard', $jobs[2], '--site-url=' . TestSite::url(), '--user=bfr-cli-editor' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( 'Error: The selected WordPress user may not change the database of this site.', $output );
			$kept( $jobs[2] );
		} finally {
			foreach ( [ ...$jobs, $here ] as $job ) {
				TempDir::remove( TestSite::private_dir() . '/jobs/' . $job );
			}
			TestSite::wp( [ 'user', 'delete', 'bfr-cli-editor', '--yes' ] );
		}
	}
}
