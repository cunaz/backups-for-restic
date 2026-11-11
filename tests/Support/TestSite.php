<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use PHPUnit\Framework\Assert;
use BackupsForRestic\Storage\PrivateDirectory;

/**
 * The disposable WordPress of bin/test-site.sh, as the integration tests reach it: over HTTP
 * like a browser and a cron service do, on disk for what only the files show, and with PHP
 * inside WordPress for what an administrator does outside the plugin's own interface
 * (deactivating it, creating users).
 */
final class TestSite {

	public const string URL = 'http://127.0.0.1:8890';

	/** The administrator's WordPress password, as bin/test-site.sh install sets it. */
	public const string ADMIN_PASSWORD = 'admin-password-test-only';

	public const string PLUGIN = 'backups-for-restic/backups-for-restic.php';

	/** Password of the repository that the setup test created; the later tests need it for restic. */
	public static string $repository_password = '';

	private static ?RestServer $server = null;

	/**
	 * The rest-server that holds the site's repository, started on first use and stopped when
	 * the tests end. One server for all test classes: the site is set up once.
	 */
	public static function server(): RestServer {
		if ( null === self::$server ) {
			$server       = new RestServer();
			self::$server = $server;
			register_shutdown_function( $server->stop( ... ) );
		}
		return self::$server;
	}

	/**
	 * restic on the site's repository.
	 */
	public static function restic(): ResticCli {
		return new ResticCli( self::server(), 'site', self::$repository_password );
	}

	/**
	 * What the wizard sends to test and save the site's repository server.
	 *
	 * @return array{server: string, url: string, username: string, password: string}
	 */
	public static function connection(): array {
		return [
			'server'   => 'rest-server',
			'url'      => self::server()->url( 'site' ),
			'username' => RestServer::USER,
			'password' => RestServer::PASSWORD,
		];
	}

	/**
	 * The site's directory.
	 */
	public static function root(): string {
		return dirname( __DIR__, 2 ) . '/.tools/site';
	}

	/**
	 * The plugin's private directory.
	 */
	public static function private_dir(): string {
		return self::root() . '/wp-content/' . PrivateDirectory::NAME;
	}

	/**
	 * The administrator's application password as "user:password", or '' before
	 * bin/test-site.sh install.
	 */
	public static function administrator(): string {
		$file        = dirname( __DIR__, 2 ) . '/.tools/site-app-password';
		$credentials = is_file( $file ) ? file_get_contents( $file ) : false;
		return false === $credentials ? '' : trim( $credentials );
	}

	/**
	 * Whether the site is installed and answers.
	 */
	public static function available(): bool {
		if ( '' === self::administrator() || ! RestServer::available() ) {
			return false;
		}
		$handle = curl_init( self::URL . '/wp-login.php' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 5,
			]
		);
		curl_exec( $handle );
		return 200 === curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	}

	/**
	 * Calls the plugin's REST API.
	 *
	 * @param array<string, mixed>|null $data        JSON body.
	 * @param string|null               $credentials "user:password" of an application password; the administrator's by default, '' for none.
	 * @return array{int, array<mixed>, string} Status, decoded body, raw body.
	 */
	public static function api( string $method, string $route, ?array $data = null, ?string $credentials = null ): array {
		$credentials ??= self::administrator();
		$handle        = curl_init( self::URL . '/?rest_route=/backups-for-restic/v1' . $route );
		$options       = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
		];
		if ( '' !== $credentials ) {
			$options[ CURLOPT_USERPWD ] = $credentials;
		}
		if ( null !== $data ) {
			$options[ CURLOPT_POSTFIELDS ] = (string) json_encode( $data );
		}
		curl_setopt_array( $handle, $options );
		$body = curl_exec( $handle );
		if ( ! is_string( $body ) ) {
			// No HTTP response at all: report why, so a failing assertion shows the cause.
			return [ 0, [], $method . ' ' . $route . ': ' . curl_error( $handle ) ];
		}
		$decoded = json_decode( $body, true );
		return [ (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ), is_array( $decoded ) ? $decoded : [], $body ];
	}

	/**
	 * Requests wp-cron.php like an external cron service does.
	 *
	 * @param int $timeout Seconds to wait for the answer; the request goes on without the client.
	 */
	public static function cron( int $timeout = 60 ): void {
		$handle = curl_init( self::URL . '/wp-cron.php?doing_wp_cron' );
		curl_setopt_array(
			$handle,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $timeout,
			]
		);
		curl_exec( $handle );
	}

	/**
	 * A job as GET /jobs lists it. Unlike GET /jobs/{id}, the list never advances the job.
	 *
	 * @return array<mixed>|null Job, or null if the list does not have it.
	 */
	public static function job( string $id ): ?array {
		[ $status, $list, $raw ] = self::api( 'GET', '/jobs' );
		Assert::assertSame( 200, $status, $raw );
		foreach ( $list['jobs'] ?? [] as $job ) {
			if ( is_array( $job ) && $id === ( $job['id'] ?? null ) ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Starts a job and returns its ID.
	 *
	 * @param string $type backup, forget, prune or check.
	 */
	public static function start( string $type = 'backup' ): string {
		[ $status, $started, $raw ] = self::api( 'POST', '/jobs', [ 'type' => $type ] );
		Assert::assertSame( 201, $status, $raw );
		return (string) $started['job']['id'];
	}

	/**
	 * Drives a job with cron requests only and returns it once it ended.
	 *
	 * @return array<mixed> Job.
	 */
	public static function wait_for( string $id, int $timeout ): array {
		$deadline   = time() + $timeout;
		$unanswered = 0;
		do {
			self::cron();
			[ $status, $list ] = self::api( 'GET', '/jobs' );
			foreach ( $list['jobs'] ?? [] as $job ) {
				if ( is_array( $job ) && $id === $job['id'] && ! in_array( $job['state'], [ 'queued', 'running' ], true ) ) {
					return $job;
				}
			}
			// A server that is gone never finishes the job: say so now instead of at the deadline.
			$unanswered = 0 === $status ? $unanswered + 1 : 0;
			if ( $unanswered >= 30 ) {
				Assert::fail( 'The site stopped answering; see .tools/site-server.log.' );
			}
			sleep( 1 );
		} while ( time() < $deadline );
		Assert::fail( 'The job did not finish in time.' );
	}

	/**
	 * Waits, without driving anything, until a tick is in the middle of a backup: the job is
	 * running, a tick holds its lease, the engine has written its checkpoint and no snapshot
	 * is saved yet. The job document itself is only written when a tick starts and ends, so
	 * the step it names is the one the tick started with.
	 *
	 * @return array<mixed> The job document as the tick saved it when it took the lease.
	 */
	public static function wait_for_backup_tick( string $id, int $timeout ): array {
		$deadline = microtime( true ) + $timeout;
		do {
			$document = self::job_document( $id );
			$log      = self::job_log( $id );
			if ( in_array( $document['state'] ?? null, [ 'succeeded', 'failed', 'cancelled' ], true ) || str_contains( $log, 'Snapshot saved.' ) ) {
				Assert::fail( 'The backup was over before a tick was caught in it: ' . (string) json_encode( $document ) );
			}
			if ( 'running' === ( $document['state'] ?? null ) && '' !== ( $document['lease_owner'] ?? '' ) && is_file( self::private_dir() . '/jobs/' . $id . '/backup/state.json' ) ) {
				return $document;
			}
			usleep( 50_000 );
		} while ( microtime( true ) < $deadline );
		Assert::fail( 'No tick was caught in the backup; the job document says: ' . (string) json_encode( self::job_document( $id ) ) );
	}

	/**
	 * Waits, without driving anything, until a job has ended, and returns its document.
	 *
	 * @return array<mixed> Job document.
	 */
	public static function wait_for_end( string $id, int $timeout ): array {
		$deadline = microtime( true ) + $timeout;
		do {
			$document = self::job_document( $id );
			if ( in_array( $document['state'] ?? null, [ 'succeeded', 'failed', 'cancelled' ], true ) ) {
				return $document;
			}
			usleep( 200_000 );
		} while ( microtime( true ) < $deadline );
		Assert::fail( 'The job did not end by itself: ' . (string) json_encode( self::job_document( $id ) ) );
	}

	/**
	 * Drives cron until no job is queued or running. After a backup the heartbeat may start a
	 * forget job, which holds an exclusive lock; restic and new jobs wait for it.
	 */
	public static function idle( int $timeout ): void {
		$deadline = time() + $timeout;
		do {
			self::cron();
			[ $status, $list, $raw ] = self::api( 'GET', '/jobs' );
			Assert::assertSame( 200, $status, $raw );
			$active = array_filter( $list['jobs'] ?? [], static fn ( mixed $job ): bool => is_array( $job ) && in_array( $job['state'] ?? '', [ 'queued', 'running' ], true ) );
			if ( [] === $active ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );
		Assert::fail( 'Jobs were still active.' );
	}

	/**
	 * The job document on disk, which also has what the REST API leaves out: the lease.
	 *
	 * @return array<mixed> Document; empty if there is none (yet).
	 */
	public static function job_document( string $id ): array {
		$file     = self::private_dir() . '/jobs/' . $id . '/job.json';
		$document = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $document ) ? $document : [];
	}

	/**
	 * The job's event log as it is on disk.
	 */
	public static function job_log( string $id ): string {
		$file = self::private_dir() . '/jobs/' . $id . '/events.log';
		return is_file( $file ) ? (string) file_get_contents( $file ) : '';
	}

	/**
	 * Writes a file of random bytes into the uploads fixture, so that the next backup has that
	 * much to chunk, encrypt and upload.
	 */
	public static function add_upload( string $name, int $megabytes ): void {
		$directory = self::root() . '/wp-content/uploads/fixture';
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0755, true );
		}
		$handle = fopen( $directory . '/' . $name, 'w' );
		Assert::assertNotFalse( $handle );
		for ( $part = 0; $part < $megabytes; $part += 8 ) {
			fwrite( $handle, random_bytes( 8 * 1048576 ) );
		}
		fclose( $handle );
	}

	/**
	 * Removes a file that add_upload() wrote, so that later backups do not carry it.
	 */
	public static function remove_upload( string $name ): void {
		$file = self::root() . '/wp-content/uploads/fixture/' . $name;
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Stops the web server and waits until none of its processes is left, so that whatever a
	 * request was doing is over, finished or not.
	 */
	public static function kill_server(): void {
		exec( escapeshellarg( dirname( __DIR__, 2 ) . '/bin/test-site.sh' ) . ' stop 2>&1', $output, $status );
		Assert::assertSame( 0, $status, implode( "\n", $output ) );
		// Written so that the shell that runs pgrep does not match its own command line.
		$pattern = '[-]S 127[.]0[.]0[.]1:' . (string) parse_url( self::URL, PHP_URL_PORT );
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$left = [];
			exec( 'pgrep -f -- ' . escapeshellarg( $pattern ), $left );
			if ( [] === $left ) {
				return;
			}
			usleep( 100_000 );
		}
		Assert::fail( 'Processes of the web server outlived its stop: ' . implode( ' ', $left ) );
	}

	/**
	 * Switches certificate verification off for every HTTP request of the site, as snippets
	 * from the web do it (bin/test-site-hostile-filters.php), or stops doing so.
	 */
	public static function hostile_filters( bool $active ): void {
		$flag = self::root() . '/wp-content/bfr-hostile-filters';
		if ( $active ) {
			file_put_contents( $flag, '' );
		} elseif ( is_file( $flag ) ) {
			unlink( $flag );
		}
	}

	/**
	 * Starts the web server again.
	 */
	public static function start_server(): void {
		exec( escapeshellarg( dirname( __DIR__, 2 ) . '/bin/test-site.sh' ) . ' start 2>&1', $output, $status );
		Assert::assertSame( 0, $status, implode( "\n", $output ) );
	}

	/**
	 * Runs PHP inside the site's WordPress on the command line, with the plugin loaded as in
	 * every request, and returns what it printed. The code gets its arguments as $arguments.
	 *
	 * @param string       $code      PHP statements.
	 * @param list<string> $arguments Arguments for the code.
	 */
	public static function php( string $code, array $arguments = [] ): string {
		$program = '$_SERVER["HTTP_HOST"] = "127.0.0.1"; require $argv[1] . "/wp-load.php"; $arguments = array_slice( $argv, 2 ); ' . $code;
		$command = 'php -d display_errors=stderr -d opcache.jit=disable -r ' . escapeshellarg( $program ) . ' ' . escapeshellarg( self::root() );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		exec( $command . ' 2>&1', $output, $status );
		Assert::assertSame( 0, $status, implode( "\n", $output ) );
		return implode( "\n", $output );
	}

	/**
	 * Creates a user with a role and returns an application password for them as
	 * "user:password". A user of that name from an earlier run is replaced.
	 */
	public static function user( string $login, string $role ): string {
		$printed = self::php(
			'
			require_once ABSPATH . "wp-admin/includes/user.php";
			$existing = get_user_by( "login", $arguments[0] );
			if ( $existing instanceof WP_User ) {
				wp_delete_user( $existing->ID );
			}
			$id = wp_insert_user( [ "user_login" => $arguments[0], "user_pass" => bin2hex( random_bytes( 16 ) ), "user_email" => $arguments[0] . "@example.com", "role" => $arguments[1] ] );
			if ( is_wp_error( $id ) ) {
				fwrite( STDERR, $id->get_error_message() );
				exit( 1 );
			}
			$created = WP_Application_Passwords::create_new_application_password( $id, [ "name" => "integration tests" ] );
			if ( is_wp_error( $created ) ) {
				fwrite( STDERR, $created->get_error_message() );
				exit( 1 );
			}
			echo "\n", $arguments[0], ":", $created[0];
			',
			[ $login, $role ]
		);
		return self::last_line( $printed );
	}

	/**
	 * Lets the plugin's heartbeat run now instead of at its next five minutes: makes its cron
	 * event due and requests wp-cron.php. A cron run that is under way keeps the lock of
	 * WordPress for a moment and this request then does nothing, so callers repeat it until
	 * they see what the heartbeat was to do.
	 */
	public static function heartbeat(): void {
		self::php( 'wp_clear_scheduled_hook( "backups_for_restic_heartbeat" ); wp_schedule_event( time() - 1, "backups_for_restic_five_minutes", "backups_for_restic_heartbeat" );' );
		self::cron();
	}

	/**
	 * Reads one entry of the plugin's state option after writing the given fields into it. A
	 * field given as null is removed.
	 *
	 * @param string               $entry   Entry, such as schedule or append_only.
	 * @param array<string, mixed> $changes Fields to write.
	 * @return array<string, mixed> The entry afterwards.
	 */
	public static function state( string $entry, array $changes = [] ): array {
		$printed = self::php(
			'
			$state   = get_option( "backups_for_restic_state" );
			$state   = is_array( $state ) ? $state : [];
			$fields  = is_array( $state[ $arguments[0] ] ?? null ) ? $state[ $arguments[0] ] : [];
			$changes = json_decode( $arguments[1], true );
			foreach ( $changes as $name => $value ) {
				if ( null === $value ) {
					unset( $fields[ $name ] );
				} else {
					$fields[ $name ] = $value;
				}
			}
			if ( [] !== $changes ) {
				$state[ $arguments[0] ] = $fields;
				update_option( "backups_for_restic_state", $state, false );
			}
			echo "\n", json_encode( (object) $fields );
			',
			[ $entry, (string) json_encode( (object) $changes ) ]
		);
		$fields  = json_decode( self::last_line( $printed ), true );
		Assert::assertIsArray( $fields, $printed );
		return $fields;
	}

	/**
	 * The schedule slots that the heartbeat has handled, after writing the given ones.
	 *
	 * @param array<string, int|string|null> $changes Slots to write; null removes one.
	 * @return array<string, mixed> The slots afterwards.
	 */
	public static function schedule_state( array $changes = [] ): array {
		return self::state( 'schedule', $changes );
	}

	/**
	 * The last line of what a PHP program printed: whatever WordPress printed before it is
	 * not part of the answer.
	 */
	public static function last_line( string $printed ): string {
		return trim( (string) substr( $printed, (int) strrpos( "\n" . $printed, "\n" ) ) );
	}

	/**
	 * Deactivates or activates the plugin as the Plugins screen does, hooks included.
	 */
	public static function plugin( bool $active ): void {
		self::php(
			'
			require_once ABSPATH . "wp-admin/includes/plugin.php";
			if ( "1" === $arguments[1] ) {
				$result = activate_plugin( $arguments[0] );
				if ( is_wp_error( $result ) ) {
					fwrite( STDERR, $result->get_error_message() );
					exit( 1 );
				}
			} else {
				deactivate_plugins( $arguments[0] );
			}
			',
			[ self::PLUGIN, $active ? '1' : '0' ]
		);
	}
}
