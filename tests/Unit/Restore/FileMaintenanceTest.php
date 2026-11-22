<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\WordPress\FileMaintenanceGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileMaintenanceTest extends TestCase {

	private const string ID = '20261120T000000Z-123456';

	private const string PLUGIN = 'backups-for-restic/backups-for-restic.php';

	private const string API = 'https://example.com/wp-json/backups-for-restic/v1';

	private const string ADMIN = 'https://example.com/wp-admin/admin.php';

	/** What the site's database holds while the job runs. */
	private const array ACTIVE = [ 'another/another.php', self::PLUGIN ];

	/** A due event as WordPress stores it. */
	private const array EVENT = [
		'schedule' => false,
		'args'     => [],
	];

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
					[],
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

	public function test_a_form_body_cannot_send_an_admitted_request_to_another_route(): void {
		$token  = bin2hex( random_bytes( 32 ) );
		$grant  = hash( 'sha256', $token );
		$cancel = '/backups-for-restic/v1/jobs/' . self::ID . '/cancel';
		// WP::parse_request() takes rest_route from $_POST before the permalink and $_GET.
		$requests = [
			'pretty cancel' => [ self::API, '/wp-json' . $cancel, 'POST', [] ],
			'plain cancel'  => [ 'https://example.com/?rest_route=/backups-for-restic/v1', '/', 'POST', [ 'rest_route' => $cancel ] ],
			'admin page'    => [ self::API, '/wp-admin/admin.php', 'GET', [ 'page' => 'backups-for-restic' ] ],
		];
		foreach ( $requests as $name => [ $api, $uri, $method, $query ] ) {
			$admitted = static fn ( array $body ): bool => FileMaintenanceGate::admitted(
				self::ID,
				$grant,
				$api,
				self::ADMIN,
				[
					'REQUEST_URI'    => $uri,
					'REQUEST_METHOD' => $method,
				],
				$query,
				$body,
				[ 'bfr_file_control' => $token ]
			);
			self::assertTrue( $admitted( [] ), $name );
			self::assertTrue( $admitted( [ 'status' => 'inactive' ] ), $name );
			self::assertFalse( $admitted( [ 'rest_route' => '/wp/v2/plugins/backups-for-restic/backups-for-restic' ] ), $name );
			self::assertFalse( $admitted( [ 'rest_route' => [ '/wp/v2/plugins' ] ] ), $name );
			self::assertFalse( $admitted( [ 'rest_route' => '' ] ), $name );
		}
		self::assertFalse(
			FileMaintenanceGate::admitted(
				self::ID,
				$grant,
				'https://example.com/?rest_route=/backups-for-restic/v1',
				self::ADMIN,
				[
					'REQUEST_URI'    => '/',
					'REQUEST_METHOD' => 'POST',
				],
				[ 'rest_route' => $cancel ],
				[ 'rest_route' => $cancel ],
				[ 'bfr_file_control' => $token ]
			),
			'A plain-permalink route belongs in the address only.'
		);
	}

	/**
	 * Command lines and whether WP-CLI runs one of the plugin's commands with them.
	 *
	 * @return iterable<string, array{mixed, bool}>
	 */
	public static function command_lines(): iterable {
		yield 'plugin command' => [ [ 'wp', 'backups-for-restic', 'tick', '--until-idle' ], true ];
		yield 'documented recovery' => [ [ '/usr/local/bin/wp', '--skip-plugins=affected-plugin', '--skip-themes', 'backups-for-restic', 'undo', self::ID ], true ];
		yield 'global parameters around the command' => [ [ 'wp-cli.phar', '--path=/var/www/html', '--url=https://example.com/', '--user=admin', '--allow-root', 'backups-for-restic', 'undo', self::ID, '--yes', '--skip-plugins=a,b' ], true ];
		yield 'flags and negations' => [ [ 'wp', '--no-color', '--skip-plugins', '--quiet', 'backups-for-restic', 'status' ], true ];
		yield 'alias' => [ [ 'wp', '@staging', 'backups-for-restic', 'status' ], true ];
		yield 'value with spaces and equals signs' => [ [ 'wp', '--exec=$a = 1;', 'backups-for-restic', 'doctor' ], true ];
		yield 'cron of the system' => [ [ 'wp', 'cron', 'event', 'run', '--due-now' ], false ];
		yield 'plugin management' => [ [ 'wp', 'plugin', 'deactivate', 'backups-for-restic' ], false ];
		yield 'plugin activation' => [ [ 'wp', '--path=/var/www/html', 'plugin', 'activate', 'another' ], false ];
		yield 'help about the command' => [ [ 'wp', 'help', 'backups-for-restic' ], false ];
		yield 'code that runs the tick' => [ [ 'wp', 'eval', 'do_action( "backups_for_restic_tick" );' ], false ];
		yield 'similar name' => [ [ 'wp', 'backups-for-restic-extra', 'status' ], false ];
		yield 'other case' => [ [ 'wp', 'Backups-For-Restic', 'status' ], false ];
		yield 'double dash is positional for WP-CLI' => [ [ 'wp', '--', 'backups-for-restic', 'status' ], false ];
		yield 'parameter without a name is positional' => [ [ 'wp', '--=x', 'backups-for-restic', 'status' ], false ];
		yield 'alias after a parameter' => [ [ 'wp', '--path=/var/www/html', '@staging', 'backups-for-restic' ], false ];
		yield 'no command' => [ [ 'wp', '--path=/var/www/html' ], false ];
		yield 'only the script' => [ [ 'wp' ], false ];
		yield 'empty' => [ [], false ];
		yield 'not a list' => [ [ 'a' => 'wp' ], false ];
		yield 'not an array' => [ 'wp backups-for-restic', false ];
		yield 'not a string' => [ [ 'wp', 7, 'backups-for-restic' ], false ];
	}

	#[DataProvider( 'command_lines' )]
	public function test_only_the_plugins_own_wp_cli_command_is_recognised( mixed $argv, bool $own ): void {
		self::assertSame( $own, FileMaintenanceGate::own_command( $argv ) );
	}

	public function test_other_wp_cli_commands_boot_as_during_core_maintenance(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$this->enter( $root );
			foreach ( [ [ 'wp', 'cron', 'event', 'run', '--due-now' ], [ 'wp', 'plugin', 'activate', 'another' ], [ 'wp', 'plugin', 'deactivate', 'backups-for-restic' ] ] as $argv ) {
				foreach ( [ false, true ] as $doing_cron ) {
					$boot = self::boot(
						$root,
						[
							'cli'        => true,
							'doing_cron' => $doing_cron,
							'argv'       => $argv,
						]
					);
					$name = implode( ' ', $argv ) . ( $doing_cron ? ' with DOING_CRON' : '' );
					// WP-CLI's enable_maintenance_mode filter then lets the command run normally.
					self::assertSame( (float) filemtime( $root . '/.maintenance' ), $boot['upgrading'], $name );
					self::assertSame( [], $boot['hooks'], $name );
					self::assertSame( self::ACTIVE, $boot['plugins'], $name );
					self::assertNull( $boot['ready'], $name );
				}
			}
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_admitted_requests_release_only_this_plugins_events_and_never_store_the_plugin_list(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$token = bin2hex( random_bytes( 32 ) );
			$this->enter( $root, hash( 'sha256', $token ) );
			$control  = [
				'server'  => [
					'REQUEST_URI'    => '/wp-json/backups-for-restic/v1/jobs/' . self::ID,
					'REQUEST_METHOD' => 'GET',
				],
				'cookies' => [ 'bfr_file_control' => $token ],
			];
			$requests = [
				'plugin command'      => [
					'cli'  => true,
					'argv' => [ 'wp', 'backups-for-restic', 'tick' ],
				],
				'documented recovery' => [
					'cli'  => true,
					'argv' => [ 'wp', '--skip-plugins=affected-plugin', '--skip-themes', 'backups-for-restic', 'undo', self::ID ],
				],
				'wp-cron.php'         => [ 'doing_cron' => true ],
				// With ALTERNATE_WP_CRON a control request runs wp-cron.php itself after boot.
				'control request'     => $control,
				'cancellation'        => [
					'server' => [
						'REQUEST_URI'    => '/wp-json/backups-for-restic/v1/jobs/' . self::ID . '/cancel',
						'REQUEST_METHOD' => 'POST',
					],
				] + $control,
			];
			foreach ( $requests as $name => $request ) {
				$boot = self::boot( $root, $request );
				self::assertSame( 0.0, $boot['upgrading'], $name );
				self::assertSame( [ self::PLUGIN ], $boot['plugins'], $name );
				self::assertSame( dirname( __DIR__, 3 ) . '/src/WordPress', $boot['theme'], $name );
				self::assertSame(
					[
						100 => [ 'backups_for_restic_tick' => [ 'a' => self::EVENT ] ],
						200 => [ 'backups_for_restic_heartbeat' => [ 'b' => self::EVENT ] ],
					],
					$boot['ready'],
					$name
				);
				self::assertSame(
					[
						'activate_plugin'    => false,
						'deactivate_plugins' => false,
						'update_option'      => false,
					],
					$boot['stores'],
					$name
				);
			}
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_refused_requests_add_no_hooks_and_store_normally(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$token    = bin2hex( random_bytes( 32 ) );
			$cancel   = '/wp-json/backups-for-restic/v1/jobs/' . self::ID . '/cancel';
			$requests = [
				'visitor'            => [
					'server' => [
						'REQUEST_URI'    => '/',
						'REQUEST_METHOD' => 'GET',
					],
				],
				'form body route'    => [
					'server'  => [
						'REQUEST_URI'    => $cancel,
						'REQUEST_METHOD' => 'POST',
					],
					'post'    => [ 'rest_route' => '/wp/v2/plugins/backups-for-restic/backups-for-restic' ],
					'cookies' => [ 'bfr_file_control' => $token ],
				],
				'command of another' => [
					'cli'  => true,
					'argv' => [ 'wp', 'option', 'update', 'active_plugins', '[]', '--format=json' ],
				],
			];
			$this->enter( $root, hash( 'sha256', $token ) );
			foreach ( $requests as $name => $request ) {
				$boot = self::boot( $root, $request );
				self::assertSame( (float) filemtime( $root . '/.maintenance' ), $boot['upgrading'], $name );
				self::assertSame( [], $boot['hooks'], $name );
				self::assertSame( self::ACTIVE, $boot['plugins'], $name );
				self::assertSame( '/themes/site', $boot['theme'], $name );
				self::assertTrue( $boot['stores']['update_option'], $name );
			}
		} finally {
			TempDir::remove( $root );
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

	public function test_later_ticks_keep_the_marker_that_the_first_tick_rendered(): void {
		$root = TempDir::create( 'maintenance' );
		try {
			$token  = bin2hex( random_bytes( 32 ) );
			$grant  = hash( 'sha256', $token );
			$dir    = $this->enter( $root, $grant );
			$marker = $root . '/.maintenance';
			$script = (string) file_get_contents( $marker );
			$inode  = fileinode( $marker );
			// Under the marker a tick boots without the theme and ordinary plugins, whose
			// rest_url_prefix, home_url or admin_url filters set the URLs of the first tick.
			$unfiltered = new FileMaintenance( $root, self::PLUGIN, 'https://example.com/?rest_route=/backups-for-restic/v1', 'https://example.com/other/wp-admin/admin.php' );
			$unfiltered->enter( self::ID, $dir, $grant );
			clearstatcache( true );
			self::assertSame( $script, file_get_contents( $marker ) );
			self::assertSame( $inode, fileinode( $marker ) );
			$boot = self::boot(
				$root,
				[
					'server'  => [
						'REQUEST_URI'    => '/wp-json/backups-for-restic/v1/jobs/' . self::ID,
						'REQUEST_METHOD' => 'GET',
					],
					'cookies' => [ 'bfr_file_control' => $token ],
				]
			);
			self::assertSame( 0.0, $boot['upgrading'] );

			// The proof still protects the private copy, which is the live marker as well.
			file_put_contents( $dir . '/maintenance.php', '<?php $upgrading = 0.0;' . "\n" );
			$this->assert_unavailable( $unfiltered, $dir, $grant );
			file_put_contents( $dir . '/maintenance.php', $script );
			file_put_contents( $dir . '/maintenance.sha256', 'not a proof' );
			$this->assert_unavailable( $unfiltered, $dir, $grant );
			unlink( $dir . '/maintenance.sha256' );
			$this->assert_unavailable( $unfiltered, $dir, $grant );
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

	/**
	 * Publishes the job's marker with this installation's URLs.
	 *
	 * @param string $root WordPress root.
	 * @param string $grant Control grant, empty for a job from the command line.
	 * @return string Private job directory.
	 */
	private function enter( string $root, string $grant = '' ): string {
		$dir = $root . '/jobs/' . self::ID;
		mkdir( $dir, 0700, true );
		( new FileMaintenance( $root, self::PLUGIN, self::API, self::ADMIN ) )->enter( self::ID, $dir, $grant );
		return $dir;
	}

	private function assert_unavailable( FileMaintenance $service, string $dir, string $grant ): void {
		try {
			$service->enter( self::ID, $dir, $grant );
			self::fail( 'A private marker without a matching proof must not be renewed.' );
		} catch ( StorageFailed $refused ) {
			self::assertSame( 'The private maintenance marker is unavailable.', $refused->getMessage() );
		}
	}

	/**
	 * Boots a separate PHP process the way WordPress boots up to its maintenance check:
	 * without the plugin's autoloader and with only the functions core has loaded by then.
	 *
	 * @param string               $root WordPress root with the marker.
	 * @param array<string, mixed> $request What the process sees: cli, doing_cron, argv, server, post, cookies.
	 * @return array<string, mixed> The marker's decision and what the hooks it added do later.
	 */
	private static function boot( string $root, array $request ): array {
		$file = $root . '/request.json';
		file_put_contents(
			$file,
			json_encode(
				$request + [
					'root'           => $root,
					'cli'            => false,
					'doing_cron'     => false,
					'argv'           => [ 'index.php' ],
					'server'         => [],
					'get'            => [],
					'post'           => [],
					'cookies'        => [],
					'active_plugins' => self::ACTIVE,
					'cron'           => [
						100           => [
							'backups_for_restic_tick' => [ 'a' => self::EVENT ],
							'another_plugin_event'    => [ 'c' => self::EVENT ],
						],
						200           => [ 'backups_for_restic_heartbeat' => [ 'b' => self::EVENT ] ],
						time() + 3600 => [ 'backups_for_restic_tick' => [ 'd' => self::EVENT ] ],
					],
				],
				JSON_THROW_ON_ERROR
			)
		);
		$process = proc_open(
			[ PHP_BINARY, '-d', 'display_errors=stderr', dirname( __DIR__, 2 ) . '/Fixtures/maintenance/boot.php', $file ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $output . $errors );
		$result = json_decode( $output, true, 16, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );
		return $result;
	}
}
