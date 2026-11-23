<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Continuity;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\PluginList;
use BackupsForRestic\Database\Restore\Ledger;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\RehearsalReport;
use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\Apply\DatabaseGeneration;
use BackupsForRestic\Jobs\Apply\ExchangeSite;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Database\Restore\MysqliConnection as Direct;
use BackupsForRestic\Database\Restore\SessionDrain;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\WordPressRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every live table in this suite belongs to one randomly named disposable database. */
final class DatabaseExchangeTest extends TestCase {

	/** This plugin's path in the list of active plugins. */
	private const string OWN = 'backups-for-restic/backups-for-restic.php';

	/** The only plugin that the backup's list holds: the backup lacks this plugin. */
	private const string SNAPSHOT_PLUGIN = 'snapshot-plugin/snapshot-plugin.php';

	/** A plugin activated on the site after the backup. */
	private const string LATER_PLUGIN = 'activated-later/activated-later.php';

	private MysqliConnection $admin;
	private MysqliConnection $db;
	private string $database  = '';
	private string $directory = '';
	private Direct $direct;
	private Manifest $manifest;
	private TableImporter $importer;

	protected function setUp(): void {
		$admin = MysqliConnection::connect();
		if ( null === $admin ) {
			self::markTestSkipped( 'No local MySQL or MariaDB server.' );
		}
		$this->admin    = $admin;
		$this->database = 'bfr_exchange_' . bin2hex( random_bytes( 6 ) );
		$admin->execute( 'CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$db = MysqliConnection::connect( $this->database );
		self::assertNotNull( $db );
		$this->db        = $db;
		$this->direct    = new Direct( $db->mysqli );
		$this->directory = TempDir::create( 'database-exchange' );
		$db->execute( 'CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,option_name VARCHAR(191) NOT NULL UNIQUE,option_value LONGTEXT NOT NULL,autoload VARCHAR(20) NOT NULL DEFAULT \'yes\') ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE wp_users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_login VARCHAR(60),user_pass VARCHAR(255),user_nicename VARCHAR(50),user_email VARCHAR(100),user_url VARCHAR(100),user_registered DATETIME,user_activation_key VARCHAR(255),user_status INT,display_name VARCHAR(250)) ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,meta_key VARCHAR(255),meta_value LONGTEXT,KEY user_id (user_id)) ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE wp_posts (id INT PRIMARY KEY,title TEXT) ENGINE=InnoDB COMMENT=\'posts comment\'' );
		$db->execute( 'CREATE TABLE wp_snapshot_only (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$options = [
			'home'                                => 'snapshot-home',
			'siteurl'                             => 'snapshot-url',
			'cron'                                => self::events( 'snapshot_event' ),
			'active_plugins'                      => serialize( [ self::SNAPSHOT_PLUGIN ] ),
			'db_version'                          => '12345',
			'wp_user_roles'                       => serialize( WordPressRoles::defaults() ),
			'_transient_doing_cron'               => 'snapshot-lock',
			'backups_for_restic_settings'         => 'snapshot-settings',
			'_transient_backups_for_restic_probe' => 'snapshot-probe',
			'front_page'                          => 'snapshot-page',
		];
		foreach ( $options as $name => $value ) {
			$db->execute( 'INSERT INTO wp_options (option_name,option_value) VALUES (' . self::text( $name ) . ',' . self::text( $value ) . ')' );
		}
		$db->execute( "INSERT INTO wp_users (ID,user_login,user_pass,user_email) VALUES (1,'admin','snapshot-pass','admin@example.com'),(2,'editor','editor-pass','editor@example.com')" );
		$db->execute( 'INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (1,' . self::text( 'wp_capabilities' ) . ',' . self::text( serialize( [ 'administrator' => true ] ) ) . "),(1,'session_tokens','snapshot-sessions'),(2," . self::text( 'wp_capabilities' ) . ',' . self::text( serialize( [ 'subscriber' => true ] ) ) . ')' );
		$db->execute( "INSERT INTO wp_posts VALUES (1,'snapshot-post')" );
		$dump = new DatabaseDump( $db, $this->directory . '/state', 'wp_', [], 1767225600 );
		foreach ( $dump->files() as $file ) {
			$source = ( $file->source )( null );
			$fp     = fopen( $this->directory . '/' . $file->name, 'wb' );
			$bytes  = $source->read( 65536 );
			while ( '' !== $bytes ) {
				fwrite( $fp, $bytes );
				$bytes = $source->read( 65536 );
			}
			fclose( $fp );
		}
		$this->manifest = Manifest::parse( (string) file_get_contents( $this->directory . '/manifest.json' ), 'wp_' );
		$db->execute( 'DROP TABLE wp_snapshot_only' );
		$db->execute( 'CREATE TABLE wp_live_only (id INT) ENGINE=InnoDB' );
		// The site since the backup, as in the finding: the subscriber role gained two
		// capabilities, a plugin was activated, and the events and addresses moved on.
		$this->option( 'home', 'live-home' );
		$this->option( 'siteurl', 'live-url' );
		$this->option( 'cron', self::events( 'live_event', 'backups_for_restic_heartbeat' ) );
		$this->option( 'active_plugins', serialize( [ self::OWN, self::LATER_PLUGIN ] ) );
		$this->option( 'wp_user_roles', serialize( WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ) ) );
		$this->option( '_transient_doing_cron', 'live-lock' );
		$this->option( 'backups_for_restic_settings', 'live-settings' );
		$this->option( '_transient_backups_for_restic_probe', 'live-probe' );
		$this->option( 'front_page', 'live-page' );
		$db->execute( "UPDATE wp_users SET user_pass='live-pass' WHERE ID=1" );
		$db->execute( "UPDATE wp_usermeta SET meta_value='live-sessions' WHERE user_id=1 AND meta_key='session_tokens'" );
		$db->execute( "UPDATE wp_posts SET title='live-post'" );
		$this->importer = new TableImporter( $this->direct, $this->manifest, $this->directory, bin2hex( random_bytes( 16 ) ) );
		for ( $i = 0; $i < 100; ++$i ) {
			if ( $this->importer->tick( 16 )['done'] ) {
				return;
			}
		}
		self::fail( 'The verified import did not finish.' );
	}

	protected function tearDown(): void {
		if ( '' !== $this->database ) {
			$this->admin->execute( 'DROP DATABASE IF EXISTS `' . $this->database . '`' );
		}
		if ( '' !== $this->directory ) {
			TempDir::remove( $this->directory );
		}
	}

	/** A string as an SQL literal, independent of the session's escaping mode. */
	private static function text( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}

	/** Sets one option of the live site. */
	private function option( string $name, string $value ): void {
		$this->db->execute( 'UPDATE wp_options SET option_value=' . self::text( $value ) . ' WHERE option_name=' . self::text( $name ) );
		self::assertSame( 1, $this->db->mysqli->affected_rows, $name );
	}

	/**
	 * Options of a table, by name.
	 *
	 * @return array<string,string>
	 */
	private function options( string $table = 'wp_options' ): array {
		return array_column( $this->db->rows( 'SELECT option_name,option_value FROM `' . $table . '`' ), 'option_value', 'option_name' );
	}

	/**
	 * WP-Cron's event list with one recurring event per hook, as _set_cron_array() stores it.
	 *
	 * @param string ...$hooks Hook names.
	 */
	private static function events( string ...$hooks ): string {
		$events = [];
		foreach ( $hooks as $number => $hook ) {
			$events[ 1767225600 + $number ] = [
				$hook => [
					md5( serialize( [] ) ) => [
						'schedule' => 'hourly',
						'args'     => [],
						'interval' => 3600,
					],
				],
			];
		}
		return serialize( $events + [ 'version' => 2 ] );
	}

	/** The capabilities that a database restore needs, and this plugin's path when it matters. */
	private static function continuity( ?string $plugin = null ): Continuity {
		return Continuity::of( [ 'manage_options', ...WordPressRoles::DATABASE ], $plugin );
	}

	private function plan(): ExchangePlan {
		$owned  = $this->importer->tables;
		$digest = $owned->locked( $this->manifest, fn () => Ledger::content_digest( $this->direct, $owned, $this->manifest ) );
		self::assertIsString( $digest );
		$live = ( new SwapCatalog( $this->direct ) )->review( array_column( $this->manifest->tables, 'name' ) );
		return ExchangePlan::prepare( $this->direct, $this->manifest, $owned, $digest, $live['inspection']['ids'], $live['schemas'], 1 );
	}

	private function mysql_only(): void {
		if ( ( new SwapCatalog( $this->direct ) )->facts()['mariadb'] ) {
			self::markTestSkipped( 'MariaDB refuses the atomic exchange under WRITE locks, covered by the refusal test.' );
		}
	}

	private function prepared(): ExchangePlan {
		$plan   = $this->plan();
		$worker = new TableExchange( $this->direct );
		foreach ( array_keys( $plan->document['pairs'] ) as $number ) {
			$worker->finalize( $plan, $number );
		}
		return $plan;
	}

	public function test_rename_and_undo_keep_both_content_generations_and_refresh_the_current_admin(): void {
		$catalog = new SwapCatalog( $this->direct );
		if ( $catalog->facts()['mariadb'] ) {
			self::assertContains( 'write_locked_rename', $catalog->inspect( [ 'wp_users' ] )['blockers'] );
			$this->expectException( DatabaseFailed::class );
			$this->plan();
			return;
		}
		$live   = $this->options();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		self::assertSame( 'prepared', $worker->status( $plan ) );
		$worker->run( $plan, 1, false, self::continuity() );
		$worker->run( $plan, 1, false, self::continuity() );
		self::assertSame( 'applied', $worker->status( $plan ) );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		self::assertSame( 'live-pass', $this->db->rows( 'SELECT user_pass FROM wp_users WHERE ID=1' )[0]['user_pass'] );
		self::assertSame( 'live-sessions', $this->db->rows( "SELECT meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key='session_tokens'" )[0]['meta_value'] );
		$opts = $this->options();
		// Kept from the running site: addresses, database version, WP-Cron's lock, this plugin's options and transients.
		foreach ( [ 'home', 'siteurl', 'db_version', '_transient_doing_cron', 'backups_for_restic_settings', '_transient_backups_for_restic_probe' ] as $kept ) {
			self::assertSame( $live[ $kept ], $opts[ $kept ], $kept );
		}
		// From the backup: roles, active plugins, scheduled events and everything else.
		self::assertSame( serialize( WordPressRoles::defaults() ), $opts['wp_user_roles'] );
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN ] ), $opts['active_plugins'] );
		self::assertSame( self::events( 'snapshot_event' ), $opts['cron'] );
		self::assertSame( 'snapshot-page', $opts['front_page'] );
		$this->db->execute( "INSERT INTO wp_posts VALUES (2,'after-apply')" );
		$this->db->execute( "UPDATE wp_users SET user_pass='after-pass' WHERE ID=1" );
		$this->db->execute( "INSERT INTO wp_users (ID,user_login) VALUES (3,'after-user')" );
		$this->db->execute( "UPDATE wp_usermeta SET meta_value='after-sessions' WHERE user_id=1 AND meta_key='session_tokens'" );
		$this->db->execute( "UPDATE wp_options SET option_value='after-settings' WHERE option_name='backups_for_restic_settings'" );
		// Changed in the restored generation while it was live: none of it comes back with the undo.
		$this->option( 'wp_user_roles', serialize( WordPressRoles::with( 'author', [ 'promote_users' ] ) ) );
		$this->option( 'active_plugins', serialize( [ self::SNAPSHOT_PLUGIN, 'after-apply/after-apply.php' ] ) );
		$this->option( 'cron', self::events( 'after_apply_event' ) );
		$this->db->execute( 'INSERT INTO wp_snapshot_only VALUES (123)' );
		$worker->run( $plan, 1, true, self::continuity() );
		$worker->run( $plan, 1, true, self::continuity() );
		self::assertSame( 'undone', $worker->status( $plan ) );
		self::assertSame( [ [ 'title' => 'live-post' ] ], $this->db->rows( 'SELECT title FROM wp_posts' ) );
		self::assertSame( 'after-pass', $this->db->rows( 'SELECT user_pass FROM wp_users WHERE ID=1' )[0]['user_pass'] );
		self::assertSame( 'after-sessions', $this->db->rows( "SELECT meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key='session_tokens'" )[0]['meta_value'] );
		$undone = $this->options();
		self::assertSame( 'after-settings', $undone['backups_for_restic_settings'] );
		// The generation that comes back has its own roles, active plugins and scheduled events.
		foreach ( [ 'wp_user_roles', 'active_plugins', 'cron', 'front_page' ] as $own ) {
			self::assertSame( $live[ $own ], $undone[ $own ], $own );
		}
		foreach ( $plan->document['pairs'] as $number => $pair ) {
			$retained = '`' . $plan->owned->shadow( $number ) . '`';
			if ( 'wp_posts' === $pair['name'] ) {
				self::assertSame( 'after-apply', $this->db->rows( 'SELECT title FROM ' . $retained . ' WHERE id=2' )[0]['title'] );
			} elseif ( 'wp_users' === $pair['name'] ) {
				self::assertSame( 'after-user', $this->db->rows( 'SELECT user_login FROM ' . $retained . ' WHERE ID=3' )[0]['user_login'] );
			} elseif ( 'wp_snapshot_only' === $pair['name'] ) {
				self::assertSame( '123', $this->db->rows( 'SELECT id FROM ' . $retained )[0]['id'] );
			} elseif ( 'wp_options' === $pair['name'] ) {
				self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN, 'after-apply/after-apply.php' ] ), $this->options( trim( $retained, '`' ) )['active_plugins'] );
			}
		}
	}

	public function test_ambiguous_admin_id_and_unchanged_name_with_a_different_physical_table_refuse(): void {
		$this->mysql_only();
		$this->db->execute( "UPDATE wp_users SET user_login='different-admin' WHERE ID=1" );
		try {
			$this->plan();
			self::fail( 'A conflicting snapshot identity was accepted.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_admin_collision', $error->context()['code'] ?? null );
		}
		$this->db->execute( "UPDATE wp_users SET user_login='admin' WHERE ID=1" );
		$plan = $this->prepared();
		$this->db->execute( 'RENAME TABLE wp_posts TO outside_original' );
		$this->db->execute( 'CREATE TABLE wp_posts LIKE outside_original' );
		$this->db->execute( "INSERT INTO wp_posts VALUES (1,'replacement-table')" );
		try {
			( new TableExchange( $this->direct ) )->run( $plan, 1, false, self::continuity() );
			self::fail( 'A same-named replacement was exchanged.' );
		} catch ( DatabaseFailed ) {
			self::assertSame( 'replacement-table', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
			self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( $plan ) );
		}
	}
	private function observing( \Closure $callback ): Connection {
		return new class( $this->direct, $callback ) implements Connection {
			public function __construct( private Direct $direct, private \Closure $callback ) {}

			public function rows( string $sql ): array {
				return $this->direct->rows( $sql );
			}

			public function execute( string $sql ): int {
				( $this->callback )( $sql, false );
				$result = $this->direct->execute( $sql );
				( $this->callback )( $sql, true );
				return $result;
			}
		};
	}

	public function test_killing_the_worker_on_either_side_of_rename_resumes_from_the_physical_witness(): void {
		$this->mysql_only();
		$plan                      = $this->prepared();
		$worker                    = new TableExchange( $this->direct );
		[ , $token, $fingerprint ] = explode( ':', $plan->owned->owner );
		file_put_contents(
			$this->directory . '/exchange-plan.json',
			json_encode(
				[
					'plan'        => $plan->document,
					'token'       => $token,
					'fingerprint' => $fingerprint,
				],
				JSON_THROW_ON_ERROR
			)
		);
		$marker = $this->directory . '/exchange-kill';
		foreach ( [ 'apply', 'undo' ] as $phase ) {
			$undo = 'undo' === $phase;
			foreach ( [ 'before', 'after' ] as $point ) {
				$process = proc_open( [ PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Database/exchange-kill.php', $this->database, $this->directory, $phase, $point ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
				self::assertIsResource( $process );
				fclose( $pipes[0] );
				$deadline = microtime( true ) + 30;
				while ( ! is_file( $marker ) && proc_get_status( $process )['running'] && microtime( true ) < $deadline ) {
					usleep( 10000 );
				}
				$reached = is_file( $marker ) ? file_get_contents( $marker ) : '';
				proc_terminate( $process, 9 );
				$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $process );
				self::assertSame( $point, $reached, $output );
				unlink( $marker );
				self::assertSame( 'after' === $point ? ( $undo ? 'undone' : 'applied' ) : ( $undo ? 'applied' : 'prepared' ), $worker->status( $plan ) );
			}
			$worker->run( $plan, 1, $undo, self::continuity() );
		}
	}

	public function test_a_waiting_writer_lands_on_the_live_name_after_the_exchange(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$writer = MysqliConnection::connect( $this->database );
		self::assertNotNull( $writer );
		$queued = false;
		$probe  = $this->observing(
			static function ( string $sql, bool $after ) use ( $writer, &$queued ): void {
				if ( ! $queued && $after && str_starts_with( $sql, 'LOCK TABLES ' ) ) {
					self::assertTrue( $writer->mysqli->query( "INSERT INTO wp_posts VALUES (123,'arrived-during-exchange')", MYSQLI_ASYNC ) );
					$queued = true;
				}
			}
		);
		( new TableExchange( $probe ) )->run( $plan, 1, false, self::continuity() );
		self::assertTrue( $queued );
		self::assertTrue( $writer->mysqli->reap_async_query() );
		self::assertSame( 'arrived-during-exchange', $this->db->rows( 'SELECT title FROM wp_posts WHERE id=123' )[0]['title'] );
	}

	public function test_bounded_cleanup_replays_without_dropping_a_live_or_replaced_table(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		$worker->run( $plan, 1, false, self::continuity() );
		$number = array_search( 'wp_posts', array_column( $plan->document['pairs'], 'name' ), true );
		self::assertIsInt( $number );
		$foreign = $plan->owned->retained( $number );
		$this->db->execute( 'RENAME TABLE `' . $foreign . '` TO manually_retained' );
		$this->db->execute( 'CREATE TABLE `' . $foreign . '` (id INT) ENGINE=InnoDB' );
		$this->db->execute( 'INSERT INTO `' . $foreign . '` VALUES (42)' );
		self::assertSame( 1, $worker->clean( $plan, 'applied', 0, 0 ) );
		self::assertSame( 1, $worker->clean( $plan, 'applied', 0, 0 ) );
		$done = count( $plan->document['pairs'] ) + 1;
		self::assertSame( $done, $worker->clean( $plan, 'applied', 1, microtime( true ) + 10 ) );
		self::assertSame( $done, $worker->clean( $plan, 'applied', 0, microtime( true ) + 10 ) );
		self::assertSame( '42', $this->db->rows( 'SELECT id FROM `' . $foreign . '`' )[0]['id'] );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM manually_retained' )[0]['title'] );
	}
	public function test_the_approving_credential_and_capabilities_are_checked_under_the_write_lock(): void {
		$this->mysql_only();
		$plan = $this->prepared();
		$auth = ( new WordPressState( $this->direct ) )->authority( 'wp_', 1 );
		$this->db->execute( "UPDATE wp_usermeta SET meta_value='revoked' WHERE user_id=1 AND meta_key='wp_capabilities'" );
		try {
			( new TableExchange( $this->direct ) )->run( $plan, 1, false, self::continuity(), $auth );
			self::fail( 'The exchange accepted revoked authority.' );
		} catch ( DatabaseFailed $failure ) {
			self::assertSame( 'database_exchange_changed', $failure->context()['code'] ?? null );
			self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( $plan ) );
		}
	}

	/**
	 * Stages this suite's dump as a successful rehearsal and builds the database job services
	 * of one site around it. The default drain keeps its grace period and its quiet interval
	 * short for the tests.
	 *
	 * @return array{changes:DatabaseChanges,runner:Runner,jobs:JobStore,staging:Staging,clock:FrozenClock,source:Job,files:string,site:string}
	 */
	private function services( ?SessionDrain $drain = null, ?\Closure $refresh = null, ?\Closure $connection = null ): array {
		$clock   = new FrozenClock();
		$random  = new SystemRandom();
		$jobs    = new JobStore( $this->directory . '/jobs', new Redactor(), $random );
		$staging = new Staging( $this->directory . '/restores', $jobs, $clock );
		$source  = $jobs->create( JobType::Restore, 'manual', $clock->seconds - 1, [ 'snapshot' => str_repeat( 'a', 64 ) ] );
		$root    = $this->directory . '/restores/' . $source->id;
		$files   = $root . '/files/source/database';
		mkdir( $files, 0700, true );
		copy( $this->directory . '/manifest.json', $files . '/manifest.json' );
		foreach ( $this->manifest->tables as $table ) {
			copy( $this->directory . '/' . $table['file'], $files . '/' . $table['file'] );
		}
		$report = [
			'snapshot'         => $source->data['snapshot'],
			'prefix'           => 'wp_',
			'source_directory' => '/source/database',
			'manifest_sha256'  => $this->manifest->fingerprint,
		] + ( new RehearsalReport( $this->direct ) )->read( $this->manifest, $this->importer->tables );
		$bytes  = json_encode( $report, JSON_THROW_ON_ERROR );
		file_put_contents( $root . '/database-preview.json', $bytes );
		$source->result      = [
			'database_report_sha256' => hash( 'sha256', $bytes ),
			'snapshot'               => $source->data['snapshot'],
		];
		$source->state       = JobState::Succeeded;
		$source->finished_at = $clock->seconds;
		$jobs->save( $source );
		$site = $this->directory . '/site';
		mkdir( $site );
		$changes = new DatabaseChanges(
			$jobs,
			$staging,
			$clock,
			$random,
			1,
			'wp_',
			$connection ?? fn (): Direct => $this->direct,
			static fn ( int $id ): array => 1 === $id ? [] : [ 'database_account' ],
			new FileMaintenance( $site ),
			$refresh ?? static function (): void {},
			new ExchangeSite( self::OWN, static fn (): array => [ 'manage_options', ...WordPressRoles::DATABASE ], static fn ( string $plugin ): bool => self::LATER_PLUGIN === $plugin ),
			$drain ?? new SessionDrain( 0.05, 10.0, 5.0, 0.02, 0.1 )
		);
		return [
			'changes' => $changes,
			'runner'  => new Runner( $jobs, new RunnerLock( $this->directory . '/runner.lock' ), $changes, $clock, $random ),
			'jobs'    => $jobs,
			'staging' => $staging,
			'clock'   => $clock,
			'source'  => $source,
			'files'   => $files,
			'site'    => $site,
		];
	}

	/**
	 * Ticks a database job until it ends or, with a step, until that step is next.
	 *
	 * @param \Closure(Job):bool|null $until Stop condition before the end.
	 */
	private static function advance( Runner $runner, Job $queued, ?\Closure $until = null ): Job {
		for ( $i = 0; $i < 400; ++$i ) {
			$job = $runner->tick( 0.08 )->job;
			self::assertNotNull( $job );
			self::assertSame( $queued->id, $job->id );
			if ( $job->state->is_terminal() || ( null !== $until && $until( $job ) ) ) {
				return $job;
			}
		}
		self::fail( 'The database job did not reach the expected state.' );
	}

	/**
	 * Queues the reviewed apply of this suite's rehearsal and runs it up to the exchange.
	 *
	 * @param array{changes:DatabaseChanges,runner:Runner,source:Job} $services Job services.
	 */
	private static function before_swap( array $services ): Job {
		$preview = $services['changes']->preview( $services['source']->id, 1 );
		self::assertTrue( $preview['applicable'], implode( ',', $preview['blockers'] ) );
		$queued = $services['changes']->start_apply( $services['source']->id, 1, $preview['digest'] );
		return self::advance( $services['runner'], $queued, static fn ( Job $job ): bool => 'swapping_database' === $job->step );
	}

	/**
	 * Reads the physical table that holds a logical table in the given generation.
	 *
	 * @param 'live'|'retained' $where Live site name or private retained name.
	 */
	private function holder( Job $owner, string $table, string $where ): string {
		$plan   = DatabaseGeneration::load( $this->direct, $owner )->exchange();
		$number = array_search( $table, array_column( $plan->document['pairs'], 'name' ), true );
		self::assertIsInt( $number );
		return '`' . ( 'live' === $where ? $table : $plan->owned->retained( $number ) ) . '`';
	}

	/**
	 * Runs the apply while a request from before maintenance writes a post and then its
	 * metadata, and checks that both stay in the generation that was live when it began.
	 *
	 * @param SessionDrain $drain Wait for that request.
	 * @param string|null  $reconnect Seconds the request spends without a session, or null to keep it.
	 */
	private function in_flight( SessionDrain $drain, ?string $reconnect ): void {
		$services = $this->services( $drain );
		$job      = self::before_swap( $services );
		$started  = $this->directory . '/writer-started';
		$command  = [ PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Database/exchange-writer.php', $this->database, $services['site'] . '/.maintenance', $started, '1.0' ];
		$process  = proc_open( null === $reconnect ? $command : [ ...$command, $reconnect ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		try {
			fclose( $pipes[0] );
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $started ) && proc_get_status( $process )['running'] && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			self::assertFileExists( $started );
			$applied = self::advance( $services['runner'], $job );
			$output  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$status  = proc_close( $process );
			$process = null;
		} finally {
			self::reap( $process, $pipes );
		}
		self::assertSame( 0, $status, $output );
		self::assertSame( 'written', $output );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		// The post and its metadata stay together in the generation that was live when they began.
		self::assertSame( [], $this->db->rows( 'SELECT id FROM wp_posts WHERE id=9001' ) );
		self::assertSame( [], $this->db->rows( "SELECT umeta_id FROM wp_usermeta WHERE meta_key='in_flight_post'" ) );
		self::assertSame( 'in-flight-post', $this->db->rows( 'SELECT title FROM ' . $this->holder( $applied, 'wp_posts', 'retained' ) . ' WHERE id=9001' )[0]['title'] );
		self::assertSame( '9001', $this->db->rows( 'SELECT meta_value FROM ' . $this->holder( $applied, 'wp_usermeta', 'retained' ) . " WHERE meta_key='in_flight_post'" )[0]['meta_value'] );
	}

	/**
	 * Ends a child process that a failed step left behind: it is killed while it still runs,
	 * its pipes are closed and it is reaped, so a failing test blocks neither on it nor on
	 * the database session it holds.
	 *
	 * @param resource|null       $process Child process, or null once it has been closed.
	 * @param array<int,resource> $pipes Its pipes.
	 */
	private static function reap( mixed $process, array $pipes ): void {
		if ( is_resource( $process ) && proc_get_status( $process )['running'] ) {
			proc_terminate( $process, 9 );
		}
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		if ( is_resource( $process ) ) {
			proc_close( $process );
		}
	}

	public function test_the_exchange_waits_for_a_request_that_started_before_maintenance(): void {
		$this->mysql_only();
		$this->in_flight( new SessionDrain( 0.05, 10.0, 5.0, 0.02, 0.1 ), null );
	}

	public function test_the_exchange_waits_for_a_request_that_reconnects_during_maintenance(): void {
		$this->mysql_only();
		// The request is without a session for 0.1 s, well inside the quiet interval.
		$this->in_flight( new SessionDrain( 0.05, 10.0, 5.0, 0.02, 0.5 ), '0.1' );
	}

	public function test_a_session_that_outlasts_the_wait_stops_the_exchange_with_its_own_code(): void {
		$this->mysql_only();
		$services = $this->services( new SessionDrain( 0.05, 0.3, 0.3, 0.02, 0.1 ) );
		$job      = self::before_swap( $services );
		$open     = MysqliConnection::connect( $this->database ); // For example a long WP-CLI process.
		self::assertNotNull( $open );
		$job = self::advance( $services['runner'], $job, static fn ( Job $job ): bool => $job->consecutive_failures > 0 );
		self::assertSame( JobState::Running, $job->state );
		self::assertSame( 'swapping_database', $job->step );
		self::assertGreaterThan( $services['clock']->seconds, $job->not_before );
		$codes = array_column( array_column( $services['jobs']->log( $job )->read( 20 ), 'context' ), 'code' );
		self::assertContains( 'database_exchange_sessions', $codes );
		self::assertFileDoesNotExist( $services['site'] . '/.maintenance' );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( DatabaseGeneration::load( $this->direct, $job )->exchange() ) );
		// The retry after the back-off succeeds once the session has ended.
		$open->mysqli->close();
		$services['clock']->seconds = $job->not_before + 1;
		$applied                    = self::advance( $services['runner'], $job );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	public function test_the_session_list_names_other_sessions_of_the_account_in_the_site_database(): void {
		$this->mysql_only();
		$catalog  = new SwapCatalog( $this->direct );
		$database = $catalog->facts()['database'];
		self::assertSame( [], $catalog->sessions( $database ) );
		$peer      = MysqliConnection::connect( $this->database );
		$elsewhere = MysqliConnection::connect();
		self::assertNotNull( $peer );
		self::assertNotNull( $elsewhere );
		$id = $peer->rows( 'SELECT CONNECTION_ID() AS id' )[0]['id'];
		self::assertSame( [ $id ], $catalog->sessions( $database ), 'Only a session in the site database of the same account is listed.' );
		self::assertNotContains( 'performance_schema', $catalog->inspect( [ 'wp_posts' ] )['blockers'] );
		$peer->mysqli->close();
		$deadline = microtime( true ) + 1;
		while ( [] !== $catalog->sessions( $database ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		self::assertSame( [], $catalog->sessions( $database ) );
	}

	/** Whether a table of this name exists in the test database. */
	private function present( string $table ): bool {
		return [] !== $this->db->rows( 'SELECT 1 AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'' . trim( $table, '`' ) . '\'' );
	}

	public function test_a_stuck_newer_generation_does_not_keep_an_older_one_from_expiring(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( 'applied', $applied->result['database_generation'] );
		$retained = $this->holder( $applied, 'wp_posts', 'retained' );
		self::assertTrue( $this->present( $retained ) );
		$facts                       = ( new SwapCatalog( $this->direct ) )->facts();
		$services['clock']->seconds += DatabaseGeneration::WINDOW + 1;
		$stuck                       = new Job( gmdate( 'Ymd\THis\Z', $services['clock']->seconds ) . '-abcdef', JobType::DatabaseApply, 'manual', $services['clock']->seconds );
		$stuck->state                = JobState::Failed;
		$stuck->data                 = [
			'database_owner'   => [
				'version'     => 1,
				'prefix'      => 'wp_',
				'fingerprint' => $this->manifest->fingerprint,
				'count'       => 1,
				'token'       => bin2hex( random_bytes( 16 ) ),
				'database'    => $facts['database'],
				'uuid'        => $facts['uuid'],
			],
			'database_cleanup' => 'unreadable',
		];
		$services['jobs']->save( $stuck );
		try {
			$services['changes']->expire( false, microtime( true ) + 10 );
			self::fail( 'The stuck generation was not reported.' );
		} catch ( FormatException $error ) {
			self::assertSame( 'The database cleanup cursor is unreadable.', $error->getMessage() );
		}
		self::assertTrue( $services['jobs']->get( $stuck->id )?->result['database_cleanup_pending'] ?? false );
		self::assertArrayNotHasKey( 'database_owner', $services['jobs']->get( $applied->id )?->data ?? [ 'database_owner' => true ] );
		self::assertFalse( $this->present( $retained ), 'The older, expired generation was dropped.' );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	/** The binding of a job as a failover to another server leaves it: a new server UUID. */
	private static function failover( JobStore $jobs, Job $owner ): Job {
		$moved = $jobs->get( $owner->id );
		self::assertNotNull( $moved );
		$moved->data['database_owner']['uuid'] = '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002';
		if ( isset( $moved->data['exchange_plan'] ) ) {
			$moved->data['exchange_plan']['uuid'] = '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002';
		}
		$jobs->save( $moved );
		return $moved;
	}

	/**
	 * Names of the private tables in the test database.
	 *
	 * @return list<string>
	 */
	private function private_tables(): array {
		return array_column( $this->db->rows( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '\\_bfr\\_r\\_%' ORDER BY TABLE_NAME" ), 'TABLE_NAME' );
	}

	public function test_a_binding_of_another_server_lets_backups_start_and_is_discarded_without_dropping_anything(): void {
		$this->mysql_only();
		$services = $this->services();
		$job      = self::failover( $services['jobs'], self::before_swap( $services ) );
		$private  = $this->private_tables();
		self::assertNotSame( [], $private );
		$failed = self::advance( $services['runner'], $job );
		self::assertSame( JobState::Failed, $failed->state );
		self::assertSame( 'database_exchange_foreign', $failed->error?->code );
		self::assertIsInt( $failed->data['database_foreign'] ?? null );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		// Backups and other jobs go on; a new database apply waits for the decision.
		$backup        = $services['jobs']->create( JobType::Backup, 'schedule', $services['clock']->seconds );
		$backup->state = JobState::Succeeded;
		$services['jobs']->save( $backup );
		self::assertSame( [], DatabaseGeneration::exclusions( $this->direct, $failed ) );
		$preview = $services['changes']->preview( $services['source']->id, 1 );
		self::assertFalse( $preview['applicable'] );
		self::assertContains( 'database_exchange_foreign', $preview['blockers'] );
		try {
			$services['jobs']->create( JobType::DatabaseApply, 'manual', $services['clock']->seconds );
			self::fail( 'A new database apply started before the old binding was settled.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'database_exchange_foreign', $refused->context()['code'] ?? null );
		}
		$discarded = $services['changes']->discard( $failed->id, 1, 'wp-cli' );
		$own       = array_values( array_filter( $private, static fn ( string $name ): bool => str_starts_with( $name, '_bfr_r_' . $failed->data['database_owner']['token'] . '_' ) ) );
		self::assertNotSame( [], $own );
		self::assertSame( [], array_diff( $own, $discarded['tables'] ), 'Every private table of the binding is named for the administrator.' );
		self::assertSame( $private, $this->private_tables(), 'Nothing is dropped on a server that cannot be verified.' );
		self::assertArrayNotHasKey( 'database_owner', $services['jobs']->get( $failed->id )?->data ?? [ 'database_owner' => true ] );
		self::assertTrue( $services['changes']->preview( $services['source']->id, 1 )['applicable'] );
	}

	public function test_uninstall_forgets_a_generation_of_another_server_and_drops_nothing(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( 'applied', $applied->result['database_generation'] );
		$moved   = self::failover( $services['jobs'], $applied );
		$private = $this->private_tables();
		self::assertSame( [], DatabaseGeneration::exclusions( $this->direct, $moved ), 'An all-tables backup keeps every table it cannot verify.' );
		self::assertTrue( $services['changes']->expire( true, microtime( true ) + 10 ) );
		self::assertArrayNotHasKey( 'database_owner', $services['jobs']->get( $moved->id )?->data ?? [ 'database_owner' => true ] );
		self::assertSame( $private, $this->private_tables() );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	/**
	 * The site's connection as WordPress requests see it. A lost reply closes the session,
	 * the rest of that request reaches only the closed handle, and the next request opens a
	 * new session. While the server is down, no request gets one.
	 *
	 * @return array{0:\Closure():Connection,1:\ArrayObject<string,mixed>} Provider and its switches.
	 */
	private function requests(): array {
		$wire     = new \ArrayObject(
			[
				'request' => 0,
				'opened'  => 0,
				'down'    => false,
				'lose'    => null,
			]
		);
		$provider = function () use ( $wire ): Connection {
			if ( true === $wire['down'] ) {
				throw new DatabaseFailed( 'The database server is unreachable.', [ 'code' => 2006 ] );
			}
			if ( $wire['opened'] !== $wire['request'] && self::gone( $this->db->mysqli ) ) {
				$fresh = MysqliConnection::connect( $this->database );
				self::assertNotNull( $fresh );
				$this->db     = $fresh;
				$this->direct = new Direct( $fresh->mysqli );
			}
			$wire['opened'] = $wire['request'];
			return new class( $this->direct, $this->db->mysqli, $wire ) implements Connection {
				/** @param \ArrayObject<string,mixed> $wire */
				public function __construct( private Direct $direct, private \mysqli $mysqli, private \ArrayObject $wire ) {}

				public function rows( string $sql ): array {
					return $this->direct->rows( $sql );
				}

				public function execute( string $sql ): int {
					$rows = $this->direct->execute( $sql );
					$lose = $this->wire['lose'];
					if ( $lose instanceof \Closure && str_starts_with( $sql, 'RENAME TABLE ' ) ) {
						// The server committed; the reply never arrives and the session is gone.
						$this->wire['lose'] = null;
						$this->mysqli->close();
						$lose();
						throw new DatabaseFailed( 'The reply to a committed statement was lost.', [ 'code' => 2013 ] );
					}
					return $rows;
				}
			};
		};
		return [ $provider, $wire ];
	}

	/** Whether a test connection was closed. */
	private static function gone( \mysqli $mysqli ): bool {
		try {
			return ! is_int( $mysqli->thread_id );
		} catch ( \Error ) {
			return true;
		}
	}

	/**
	 * Applies the rehearsal, then loses the reply to the undo's committed RENAME while a
	 * cancellation arrives, so that the runner moves on to cleanup in the same request.
	 *
	 * @return array{services:array{changes:DatabaseChanges,runner:Runner,jobs:JobStore,staging:Staging,clock:FrozenClock,source:Job,files:string,site:string},wire:\ArrayObject<string,mixed>,apply:Job,undo:Job}
	 */
	private function lost_undo_reply(): array {
		[ $provider, $wire ] = $this->requests();
		$services            = $this->services( connection: $provider );
		$apply               = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( 'applied', $apply->result['database_generation'] );
		$services['clock']->seconds += 10;
		$review                      = $services['changes']->preview_undo( $apply->id, 1 );
		self::assertTrue( $review['applicable'], implode( ',', $review['blockers'] ) );
		$undo         = self::advance( $services['runner'], $services['changes']->start_undo( $apply->id, 1, $review['digest'] ), static fn ( Job $job ): bool => 'swapping_database' === $job->step );
		$jobs         = $services['jobs'];
		$wire['lose'] = static function () use ( $jobs, $undo ): void {
			$jobs->request_cancel( $undo );
		};
		$undo         = self::advance( $services['runner'], $undo, static fn ( Job $job ): bool => null === $wire['lose'] );
		// The cleanup did not touch the closed session and waits for a later request.
		self::assertSame( JobState::Running, $undo->state );
		self::assertSame( 'cleaning_up', $undo->step );
		self::assertSame( JobState::Cancelled, $undo->outcome );
		self::assertFileDoesNotExist( $services['site'] . '/.maintenance' );
		$owner = $jobs->get( $apply->id );
		self::assertNotNull( $owner );
		self::assertSame( 'applied', $owner->data['database_generation'] );
		self::assertSame(
			[
				'job' => $undo->id,
				'at'  => $undo->data['database_swapping_at'],
			],
			$owner->data['database_undo_attempt'] ?? null
		);
		$check = MysqliConnection::connect( $this->database );
		self::assertNotNull( $check );
		self::assertSame( 'undone', ( new TableExchange( new Direct( $check->mysqli ) ) )->status( DatabaseGeneration::load( new Direct( $check->mysqli ), $owner )->exchange() ) );
		$check->mysqli->close();
		return [
			'services' => $services,
			'wire'     => $wire,
			'apply'    => $apply,
			'undo'     => $undo,
		];
	}

	public function test_a_lost_reply_to_the_undo_is_reconciled_by_its_cleanup_on_a_new_session(): void {
		$this->mysql_only();
		[ 'services' => $services, 'wire' => $wire, 'apply' => $apply, 'undo' => $undo ] = $this->lost_undo_reply();
		++$wire['request'];
		$undone = self::advance( $services['runner'], $undo );
		self::assertSame( JobState::Succeeded, $undone->state, $undone->error?->message ?? '' );
		self::assertSame( 'undone', $undone->result['database_generation'] );
		$owner = $services['jobs']->get( $apply->id );
		self::assertNotNull( $owner );
		self::assertSame( 'undone', $owner->data['database_generation'] );
		self::assertSame( $undone->data['database_swapping_at'] + DatabaseGeneration::WINDOW, $owner->data['retained_until'] );
		self::assertArrayNotHasKey( 'database_undo_attempt', $owner->data );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	public function test_the_heartbeat_reconciles_an_undo_whose_worker_gave_up_after_a_lost_reply(): void {
		$this->mysql_only();
		[ 'services' => $services, 'wire' => $wire, 'apply' => $apply, 'undo' => $undo ] = $this->lost_undo_reply();
		$wire['down'] = true;
		++$wire['request'];
		$waiting = $services['runner']->tick( 0.08 )->job;
		self::assertNotNull( $waiting );
		self::assertFalse( $waiting->state->is_terminal(), 'An unreachable server keeps the cleanup waiting.' );
		$services['clock']->seconds += 601;
		$ended                       = self::advance( $services['runner'], $undo );
		self::assertSame( JobState::Cancelled, $ended->state );
		self::assertSame( 'applied', $services['jobs']->get( $apply->id )?->data['database_generation'] );
		// The server returns after the original window: the heartbeat records the committed undo.
		$wire['down'] = false;
		++$wire['request'];
		$services['clock']->seconds = $apply->result['retained_until'] + 1;
		self::assertTrue( $services['changes']->expire( false, microtime( true ) + 10 ) );
		$owner = $services['jobs']->get( $apply->id );
		self::assertNotNull( $owner );
		self::assertSame( 'undone', $owner->data['database_generation'] );
		self::assertSame( $ended->data['database_swapping_at'] + DatabaseGeneration::WINDOW, $owner->data['retained_until'] );
		$worker = $services['jobs']->get( $undo->id );
		self::assertNotNull( $worker );
		self::assertSame( 'undone', $worker->result['database_generation'] ?? null );
		self::assertStringContainsString( 'the heartbeat recorded it', implode( ' ', $worker->warnings ) );
		// The replaced generation expires after its own window.
		$services['clock']->seconds = $owner->data['retained_until'] + 1;
		for ( $i = 0; $i < 16; ++$i ) {
			if ( ! isset( $services['jobs']->get( $apply->id )?->data['database_owner'] ) ) {
				break;
			}
			$services['changes']->expire( false, microtime( true ) + 1 );
		}
		self::assertArrayNotHasKey( 'database_owner', $services['jobs']->get( $apply->id )?->data ?? [ 'database_owner' => true ] );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	/** @return iterable<string, array{string}> */
	public static function unwritable(): iterable {
		yield 'the undo job' => [ 'undo' ];
		yield 'the apply job' => [ 'apply' ];
	}

	/**
	 * The heartbeat records a lost undo on both jobs even when it cannot write one of them
	 * the first time; the next heartbeat repeats the recovery and warns once.
	 *
	 * @param 'undo'|'apply' $locked Job whose directory is read-only during the first heartbeat.
	 */
	#[DataProvider( 'unwritable' )]
	public function test_the_recovery_of_a_lost_undo_survives_a_failed_save( string $locked ): void {
		$this->mysql_only();
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Directory permissions do not bind root.' );
		}
		[ 'services' => $services, 'wire' => $wire, 'apply' => $apply, 'undo' => $undo ] = $this->lost_undo_reply();
		$wire['down'] = true;
		++$wire['request'];
		$services['runner']->tick( 0.08 );
		$services['clock']->seconds += 601;
		$ended                       = self::advance( $services['runner'], $undo );
		self::assertSame( JobState::Cancelled, $ended->state );
		$wire['down'] = false;
		++$wire['request'];
		$services['clock']->seconds = $apply->result['retained_until'] + 1;
		$directory                  = $services['jobs']->directory( 'undo' === $locked ? $ended : $apply );
		chmod( $directory, 0500 );
		try {
			$services['changes']->expire( false, microtime( true ) + 10 );
			self::fail( 'The heartbeat saved a job into a read-only directory.' );
		} catch ( StorageFailed ) {
			$owner = $services['jobs']->get( $apply->id );
			self::assertNotNull( $owner );
			self::assertSame( 'applied', $owner->data['database_generation'], 'The owner keeps the attempt until it is recorded.' );
			self::assertArrayHasKey( 'database_undo_attempt', $owner->data );
		} finally {
			chmod( $directory, 0700 );
		}
		self::assertTrue( $services['changes']->expire( false, microtime( true ) + 10 ) );
		$owner  = $services['jobs']->get( $apply->id );
		$worker = $services['jobs']->get( $undo->id );
		self::assertNotNull( $owner );
		self::assertNotNull( $worker );
		self::assertSame( 'undone', $owner->data['database_generation'] );
		self::assertArrayNotHasKey( 'database_undo_attempt', $owner->data );
		self::assertSame( $ended->data['database_swapping_at'] + DatabaseGeneration::WINDOW, $owner->data['retained_until'] );
		self::assertSame( 'undone', $worker->result['database_generation'] ?? null );
		self::assertSame( $owner->data['retained_until'], $worker->result['retained_until'] ?? null );
		self::assertCount( 1, array_filter( $worker->warnings, static fn ( string $warning ): bool => str_contains( $warning, 'the heartbeat recorded it' ) ) );
	}

	/** Rebuilds a table as OPTIMIZE TABLE does for InnoDB, which assigns it a new TABLE_ID. */
	private function optimize( string $table ): string {
		$name   = trim( $table, '`' );
		$before = ( new SwapCatalog( $this->direct ) )->identities( ( new SwapCatalog( $this->direct ) )->facts(), [ $name ] )[ $name ] ?? null;
		$this->db->rows( 'OPTIMIZE TABLE `' . $name . '`' );
		$after = ( new SwapCatalog( $this->direct ) )->identities( ( new SwapCatalog( $this->direct ) )->facts(), [ $name ] )[ $name ] ?? null;
		self::assertNotNull( $after );
		self::assertNotSame( $before, $after, 'OPTIMIZE TABLE is expected to renumber an InnoDB table.' );
		return $after;
	}

	/**
	 * Runs a reviewed undo of the given apply to its end.
	 *
	 * @param array{changes:DatabaseChanges,runner:Runner} $services Job services.
	 */
	private static function undo( array $services, Job $apply ): Job {
		$review = $services['changes']->preview_undo( $apply->id, 1 );
		self::assertTrue( $review['applicable'], implode( ',', $review['blockers'] ) );
		return self::advance( $services['runner'], $services['changes']->start_undo( $apply->id, 1, $review['digest'] ) );
	}

	/** Expires the generations of one owner as the heartbeat would, after its window. */
	private static function expire_all( array $services, Job $owner ): Job {
		for ( $i = 0; $i < 16; ++$i ) {
			$job = $services['jobs']->get( $owner->id );
			self::assertNotNull( $job );
			if ( ! isset( $job->data['database_owner'] ) ) {
				return $job;
			}
			$services['changes']->expire( false, microtime( true ) + 2 );
		}
		self::fail( 'The generation did not expire.' );
	}

	public function test_an_undo_binds_to_a_live_table_that_was_optimized_after_the_apply(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		$this->db->execute( "INSERT INTO wp_posts VALUES (77,'after-apply')" );
		$rebuilt = $this->optimize( 'wp_posts' );
		$undone  = self::undo( $services, $applied );
		self::assertSame( JobState::Succeeded, $undone->state, $undone->error?->message ?? '' );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		// The optimized table is retained under its new identity and expires after the window.
		$owner    = $services['jobs']->get( $applied->id );
		$plan     = DatabaseGeneration::load( $this->direct, $owner )->exchange();
		$number   = array_search( 'wp_posts', array_column( $plan->document['pairs'], 'name' ), true );
		$retained = $plan->owned->shadow( $number );
		self::assertSame( $rebuilt, $plan->document['pairs'][ $number ]['after'] );
		self::assertSame( 'after-apply', $this->db->rows( 'SELECT title FROM `' . $retained . '` WHERE id=77' )[0]['title'] );
		$services['clock']->seconds = $owner->data['retained_until'] + 1;
		self::assertArrayNotHasKey( 'database_rebuilt', self::expire_all( $services, $applied )->data );
		self::assertFalse( $this->present( $retained ) );
	}

	public function test_a_rebuilt_witness_is_recognised_for_undo_and_expiry(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		$plan     = DatabaseGeneration::load( $this->direct, $applied )->exchange();
		$witness  = $plan->owned->marker( 'applied' );
		$this->optimize( $witness );
		self::assertTrue( $services['changes']->preview_undo( $applied->id, 1 )['applicable'], 'A rebuilt witness does not cost the undo.' );
		// Without an undo the heartbeat recognises the witness and expires the generation.
		$services['clock']->seconds = $applied->data['retained_until'] + 1;
		$expired                    = self::expire_all( $services, $applied );
		self::assertContains( 'database_exchange_rebuilt', array_column( array_column( $services['jobs']->log( $expired )->read( 20 ), 'context' ), 'code' ) );
		self::assertFalse( $this->present( $witness ), 'The witness is dropped last under its new identity.' );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	public function test_a_rebuilt_retained_table_refuses_the_undo_and_is_kept_and_reported_at_expiry(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		$retained = trim( $this->holder( $applied, 'wp_posts', 'retained' ), '`' );
		$this->optimize( $retained );
		$review = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertFalse( $review['applicable'] );
		self::assertContains( 'database_exchange_rebuilt', $review['blockers'] );
		$services['clock']->seconds = $applied->data['retained_until'] + 1;
		$expired                    = self::expire_all( $services, $applied );
		self::assertSame( [ $retained ], $expired->data['database_rebuilt'] ?? null );
		self::assertStringContainsString( $retained, implode( ' ', $expired->warnings ) );
		self::assertTrue( $this->present( $retained ), 'An object whose identity is not proven is never dropped.' );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM `' . $retained . '`' )[0]['title'] );
		self::assertSame( [ $retained ], array_values( array_filter( $this->private_tables(), static fn ( string $name ): bool => str_starts_with( $name, '_bfr_r_' . $applied->data['database_owner']['token'] ) ) ) );
	}

	public function test_a_retained_table_rebuilt_while_expiry_runs_over_several_ticks_is_reported(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		$plan     = DatabaseGeneration::load( $this->direct, $applied )->exchange();
		$replaced = array_keys( array_filter( $plan->document['pairs'], static fn ( array $pair ): bool => null !== $pair['before'] ) );
		self::assertGreaterThan( 1, count( $replaced ) );
		$last                       = $plan->owned->retained( max( $replaced ) );
		$services['clock']->seconds = $applied->data['retained_until'] + 1;
		// A spent tick drops one table and stops.
		self::assertFalse( $services['changes']->expire( false, microtime( true ) - 1 ) );
		$started = $services['jobs']->get( $applied->id );
		self::assertNotNull( $started );
		self::assertSame( 'applied', $started->data['database_cleanup_generation'] ?? null );
		self::assertArrayNotHasKey( 'database_rebuilt', $started->data );
		self::assertTrue( $this->present( $last ) );
		$this->optimize( $last );
		$expired = self::expire_all( $services, $applied );
		self::assertSame( [ $last ], $expired->data['database_rebuilt'] ?? null );
		self::assertStringContainsString( $last, implode( ' ', $expired->warnings ) );
		self::assertTrue( $this->present( $last ), 'An object whose identity is not proven is never dropped.' );
		self::assertSame( [ $last ], array_values( array_filter( $this->private_tables(), static fn ( string $name ): bool => str_starts_with( $name, '_bfr_r_' . $applied->data['database_owner']['token'] ) ) ) );
	}

	public function test_a_batch_beyond_max_allowed_packet_is_refused_before_it_is_sent(): void {
		$this->db->execute( 'CREATE TABLE wp_big (id INT PRIMARY KEY, v LONGTEXT) ENGINE=InnoDB' );
		$this->db->execute( "INSERT INTO wp_big VALUES (1, REPEAT('x', 1100000))" );
		$directory = $this->directory . '/big';
		mkdir( $directory );
		foreach ( ( new DatabaseDump( $this->db, $directory . '/state', 'wp_', [], 1767225600 ) )->files() as $file ) {
			$source = ( $file->source )( null );
			$fp     = fopen( $directory . '/' . $file->name, 'wb' );
			for ( $bytes = $source->read( 65536 ); '' !== $bytes; $bytes = $source->read( 65536 ) ) {
				fwrite( $fp, $bytes );
			}
			fclose( $fp );
		}
		$manifest = Manifest::parse( (string) file_get_contents( $directory . '/manifest.json' ), 'wp_' );
		$original = $this->admin->rows( 'SELECT @@GLOBAL.max_allowed_packet AS packet' )[0]['packet'];
		self::assertMatchesRegularExpression( '/\A[0-9]+\z/', (string) $original );
		$this->admin->execute( 'SET GLOBAL max_allowed_packet=1048576' );
		try {
			$small = MysqliConnection::connect( $this->database ); // The limit applies to sessions opened after the change.
			self::assertNotNull( $small );
			$session  = new Direct( $small->mysqli );
			$importer = new TableImporter( $session, $manifest, $directory, bin2hex( random_bytes( 16 ) ) );
			try {
				for ( $i = 0; $i < 100; ++$i ) {
					$importer->tick( 16 );
				}
				self::fail( 'The oversized batch was sent.' );
			} catch ( DatabaseFailed $refused ) {
				self::assertSame( 'database_restore_packet', $refused->context()['code'] ?? null, $refused->getMessage() );
			}
			self::assertSame( [ [ 'ok' => '1' ] ], $session->rows( 'SELECT 1 AS ok' ), 'The session is intact: the batch was never sent.' );
		} finally {
			$this->admin->execute( 'SET GLOBAL max_allowed_packet=' . $original );
		}
	}

	public function test_settings_that_break_the_exchange_are_preflight_blockers_named_after_them(): void {
		$this->mysql_only();
		$catalog  = new SwapCatalog( $this->direct );
		$settings = [ 'innodb_table_locks', 'binlog_format', 'wsrep_on' ];
		self::assertSame( [], array_values( array_intersect( $catalog->inspect( [ 'wp_posts' ] )['blockers'], $settings ) ) );
		$this->db->execute( 'SET SESSION innodb_table_locks=0' );
		self::assertContains( 'innodb_table_locks', $catalog->inspect( [ 'wp_posts' ] )['blockers'] );
		$this->db->execute( 'SET SESSION innodb_table_locks=1' );
		$plan   = $this->prepared();
		$binary = 'ON' === ( $this->db->rows( "SHOW VARIABLES LIKE 'log_bin'" )[0]['Value'] ?? 'OFF' );
		// A privileged session may choose statement-based logging, as the account of this suite can.
		$this->db->execute( "SET SESSION binlog_format='STATEMENT'" );
		$blockers = $catalog->inspect( [ 'wp_posts' ] )['blockers'];
		if ( ! $binary ) {
			self::assertNotContains( 'binlog_format', $blockers );
			return;
		}
		self::assertContains( 'binlog_format', $blockers );
		try {
			( new TableExchange( $this->direct ) )->run( $plan, 1, false, self::continuity() );
			self::fail( 'The exchange ran under statement-based logging.' );
		} catch ( DatabaseFailed ) {
			self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( $plan ) );
			self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		}
		$this->db->execute( "SET SESSION binlog_format='ROW'" );
	}

	public function test_expiry_needs_innodb_table_locks_but_not_the_settings_that_only_the_exchange_needs(): void {
		$this->mysql_only();
		$plan     = $this->prepared();
		$exchange = new TableExchange( $this->direct );
		$exchange->run( $plan, 1, false, self::continuity() );
		$private = $this->private_tables();
		self::assertNotSame( [], $private );
		// Every DROP of a private table runs under the write barrier of the exchange.
		$this->db->execute( 'SET SESSION innodb_table_locks=0' );
		try {
			$exchange->clean( $plan, 'applied', 0, microtime( true ) + 15 );
			self::fail( 'Expiry dropped private tables without InnoDB table locks.' );
		} catch ( DatabaseFailed ) {
			self::assertSame( $private, $this->private_tables() );
		} finally {
			$this->db->execute( 'SET SESSION innodb_table_locks=1' );
		}
		// Statement-based logging only breaks the exchange's copy into AUTO_INCREMENT tables.
		$binary = 'ON' === ( $this->db->rows( "SHOW VARIABLES LIKE 'log_bin'" )[0]['Value'] ?? 'OFF' );
		if ( $binary ) {
			$this->db->execute( "SET SESSION binlog_format='STATEMENT'" );
			self::assertContains( 'binlog_format', ( new SwapCatalog( $this->direct ) )->settings() );
		}
		try {
			self::assertSame( count( $plan->document['pairs'] ) + 1, $exchange->clean( $plan, 'applied', 0, microtime( true ) + 15 ) );
		} finally {
			if ( $binary ) {
				$this->db->execute( "SET SESSION binlog_format='ROW'" );
			}
		}
		self::assertSame( [], $this->private_tables() );
	}

	public function test_durable_jobs_keep_the_recovery_window_through_pruning_and_expire_only_private_generations(): void {
		$this->mysql_only();
		$site     = $this->directory . '/site';
		$flushes  = 0;
		$services = $this->services(
			refresh: static function () use ( &$flushes, $site ): void {
				self::assertFileExists( $site . '/.maintenance' );
				++$flushes;
			}
		);
		[ 'changes' => $changes, 'runner' => $runner, 'jobs' => $jobs, 'staging' => $staging, 'clock' => $clock, 'source' => $source, 'files' => $files ] = $services;
		$finish  = static function ( Job $queued ) use ( $runner, $site ): Job {
			for ( $i = 0; $i < 100; ++$i ) {
				$job = $runner->tick( 0.08 )->job;
				self::assertNotNull( $job );
				self::assertSame( $queued->id, $job->id );
				if ( $job->state->is_terminal() ) {
					self::assertSame( JobState::Succeeded, $job->state, $job->error?->message ?? '' );
					self::assertFileDoesNotExist( $site . '/.maintenance' );
					return $job;
				}
			}
			self::fail( 'Database job failed to finish.' );
		};
		$preview = $changes->preview( $source->id, 1 );
		self::assertTrue( $preview['applicable'], implode( ',', $preview['blockers'] ) );
		self::assertFalse( $changes->preview( $source->id, 2 )['applicable'] );
		$queued          = $changes->start_apply( $source->id, 1, $preview['digest'] );
		$clock->seconds += 3601;
		try {
			$staging->remove( $source->id, microtime( true ) + 1 );
			self::fail( 'The active database apply lost its staging pin.' );
		} catch ( JobConflict ) {
			self::assertDirectoryExists( $files );
		}
		$apply = $finish( $queued );
		self::assertSame( 'applied', $apply->result['database_generation'] );
		self::assertSame( 1, $flushes );
		self::assertCount( 1, $changes->undoable() );
		self::assertCount( 5, DatabaseGeneration::exclusions( $this->direct, $apply ) );
		self::assertTrue( $staging->remove( $source->id, microtime( true ) + 2 ) );
		$clock->seconds += 1;
		$backup          = $jobs->create( JobType::Backup, 'schedule', $clock->seconds );
		$backup->state   = JobState::Succeeded;
		$jobs->save( $backup );
		$jobs->prune( 1, $clock->seconds );
		self::assertNotNull( $jobs->get( $apply->id ) );
		$undo = $changes->preview_undo( $apply->id, 1 );
		self::assertTrue( $undo['applicable'], implode( ',', $undo['blockers'] ) );
		$queued          = $changes->start_undo( $apply->id, 1, $undo['digest'] );
		$clock->seconds += 86401;
		self::assertTrue( $changes->expire( false, microtime( true ) + 2 ) );
		$undone = $finish( $queued );
		self::assertSame( 'undone', $undone->result['database_generation'] );
		self::assertSame( 2, $flushes );
		self::assertSame( [], $changes->undoable() );
		self::assertTrue( $changes->expire( false, microtime( true ) + 2 ) );
		self::assertCount( 6, DatabaseGeneration::exclusions( $this->direct, $jobs->get( $apply->id ) ) );
		$clock->seconds += 86401;
		for ( $i = 0; $i < 16; ++$i ) {
			if ( ! isset( $jobs->get( $apply->id )->data['database_owner'] ) ) {
				break;
			}
			$changes->expire( false, microtime( true ) + 0.01 );
		}
		self::assertArrayNotHasKey( 'database_owner', $jobs->get( $apply->id )->data );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
	}

	public function test_a_site_account_with_only_the_documented_grants_can_apply_undo_and_expire(): void {
		$this->mysql_only();
		$user = 'bfr_grants_' . bin2hex( random_bytes( 5 ) );
		$host = $this->admin->rows( "SELECT SUBSTRING_INDEX(USER(), '@', -1) AS host" )[0]['host'];
		self::assertMatchesRegularExpression( '/\A[A-Za-z0-9.:-]+\z/', $host );
		$account   = "'" . $user . "'@'" . $host . "'";
		$password  = bin2hex( random_bytes( 24 ) );
		$endpoint  = getenv( 'BFR_TEST_MYSQL_HOST' );
		$endpoint  = is_string( $endpoint ) && '' !== $endpoint ? $endpoint : 'localhost';
		$connect   = fn (): Direct => new Direct( new \mysqli( $endpoint, $user, $password, $this->database ) );
		$wildcards = '0' === $this->admin->rows( 'SELECT @@GLOBAL.partial_revokes AS enabled' )[0]['enabled'];
		$scope     = $wildcards ? str_replace( '_', '\\_', $this->database ) : $this->database;
		$this->admin->execute( 'CREATE USER ' . $account . " IDENTIFIED BY '" . $password . "'" );
		try {
			$this->admin->execute( 'GRANT ' . implode( ', ', SwapCatalog::PRIVILEGES ) . ' ON `' . $scope . '`.* TO ' . $account );
			$names        = array_column( $this->manifest->tables, 'name' );
			$this->direct = $connect();
			$missing      = ( new SwapCatalog( $this->direct ) )->inspect( $names );
			self::assertSame( [ 'PROCESS' ], $missing['missing_privileges'] );
			self::assertSame( [ 'dictionary_privilege' ], $missing['blockers'] );
			// Without PROCESS the Performance Schema lists only this account's sessions, so the
			// list cannot be shown to hold every connection while another account is connected.
			$peer    = $connect();
			$catalog = new SwapCatalog( $this->direct );
			self::assertNull( $catalog->sessions( $this->database ) );
			unset( $peer );

			// Rehearsal completes even without access to the native InnoDB dictionary.
			$this->importer = new TableImporter( $this->direct, $this->manifest, $this->directory, bin2hex( random_bytes( 16 ) ) );
			for ( $i = 0; $i < 100; ++$i ) {
				$progress = $this->importer->tick( 16 );
				if ( $progress['done'] ) {
					break;
				}
			}
			self::assertTrue( $progress['done'] );
			$this->admin->execute( 'GRANT PROCESS ON *.* TO ' . $account );
			$this->direct = $connect(); // Global privileges take effect on a new connection.
			$catalog      = new SwapCatalog( $this->direct );
			self::assertSame( [], $catalog->inspect( $names )['blockers'] );
			$peer     = $connect();
			$sessions = $catalog->sessions( $this->database );
			self::assertNotNull( $sessions, 'With PROCESS the list is shown to hold every connection.' );
			self::assertContains( $peer->rows( 'SELECT CONNECTION_ID() AS id' )[0]['id'], $sessions );
			unset( $peer );
			$this->db->execute( 'CREATE VIEW reporting_posts AS SELECT title FROM wp_posts' );
			self::assertContains( 'SHOW VIEW', $catalog->inspect( $names )['missing_privileges'] );
			$this->admin->execute( 'GRANT SHOW VIEW ON `' . $scope . '`.* TO ' . $account );
			$this->direct = $connect();
			self::assertContains( 'live_views', ( new SwapCatalog( $this->direct ) )->inspect( $names )['blockers'] );
			$this->db->execute( 'DROP VIEW reporting_posts' );
			$this->admin->execute( 'REVOKE SHOW VIEW ON `' . $scope . '`.* FROM ' . $account );
			$this->direct = $connect();
			$plan         = $this->prepared();
			$this->admin->execute( 'REVOKE TRIGGER ON `' . $scope . '`.* FROM ' . $account );
			$worker = new TableExchange( $connect() );
			try {
				$worker->run( $plan, 1, false, self::continuity() );
				self::fail( 'The apply accepted a grant revoked after confirmation.' );
			} catch ( DatabaseFailed ) {
				self::assertSame( 'prepared', $worker->status( $plan ) );
				self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
			}
			$this->admin->execute( 'GRANT TRIGGER ON `' . $scope . '`.* TO ' . $account );
			$worker = new TableExchange( $connect() );
			$worker->run( $plan, 1, false, self::continuity() );
			self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
			$worker->review_undo( $plan, 1 );
			$worker->run( $plan, 1, true, self::continuity() );
			self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
			$end = count( $plan->document['pairs'] ) + 1;
			self::assertSame( $end, $worker->clean( $plan, 'undone', 0, microtime( true ) + 15 ) );

			if ( $wildcards ) {
				// Multiple overlapping wildcard GRANTs do not add up to a broader grant.
				$overlap = substr( $scope, 0, -1 ) . '%';
				$this->admin->execute( 'GRANT SELECT ON `' . $overlap . '`.* TO ' . $account );
				self::assertContains( 'TRIGGER', ( new SwapCatalog( $connect() ) )->inspect( $names )['missing_privileges'] );
			}
		} finally {
			$this->admin->execute( 'DROP USER ' . $account );
		}
	}

	public function test_a_target_view_created_after_approval_blocks_the_exchange(): void {
		$this->mysql_only();
		$plan = $this->prepared();
		$this->db->execute( 'CREATE VIEW reporting_posts AS SELECT title FROM wp_posts' );
		self::assertContains( 'live_views', ( new SwapCatalog( $this->direct ) )->inspect( [ 'wp_posts' ] )['blockers'] );
		try {
			( new TableExchange( $this->direct ) )->run( $plan, 1, false, self::continuity() );
			self::fail( 'The exchange ignored a dependent destination view.' );
		} catch ( DatabaseFailed ) {
			self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM reporting_posts' )[0]['title'] );
			self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( $plan ) );
		}
	}

	/**
	 * The private options table of a generation: the import that an apply brings live, or for
	 * 'retained' the earlier generation that an undo brings back.
	 *
	 * @param 'shadow'|'retained' $which Generation.
	 */
	private static function private_options( ExchangePlan $plan, string $which = 'shadow' ): string {
		$number = array_search( 'wp_options', array_column( $plan->document['pairs'], 'name' ), true );
		self::assertIsInt( $number );
		return 'shadow' === $which ? $plan->owned->shadow( $number ) : $plan->owned->retained( $number );
	}

	/** Sets one option in a private table of the exchange. */
	private function private_option( string $table, string $name, string $value ): void {
		$this->db->execute( 'UPDATE `' . $table . '` SET option_value=' . self::text( $value ) . ' WHERE option_name=' . self::text( $name ) );
		self::assertSame( 1, $this->db->mysqli->affected_rows, $name );
	}

	/**
	 * What an account may do under the live roles, as WordPress computes it from the stored values.
	 *
	 * @param list<string> $capabilities Capabilities to check.
	 * @return list<string> Those it lacks.
	 */
	private function lacks( int $user, array $capabilities ): array {
		$own = $this->db->rows( "SELECT meta_value FROM wp_usermeta WHERE user_id=$user AND meta_key='wp_capabilities'" )[0]['meta_value'] ?? null;
		return RoleMap::parse( $this->options()['wp_user_roles'] )->missing( RoleMap::own( $own ), $capabilities );
	}

	public function test_the_finding_a_restore_brings_back_the_backups_roles_plugins_and_events_and_its_undo_the_sites(): void {
		$this->mysql_only();
		$live     = $this->options();
		$events   = [];
		$services = $this->services(
			refresh: function () use ( &$events ): void {
				// Called after the exchange, in maintenance: WordPress reads the event list that is now live.
				$events[] = $this->options()['cron'];
			}
		);
		// After the backup, the subscriber role gained manage_options and edit_users and a plugin was activated.
		self::assertSame( [], $this->lacks( 2, [ 'manage_options', 'edit_users' ] ) );
		$review = $services['changes']->preview( $services['source']->id, 1 );
		self::assertTrue( $review['applicable'], implode( ',', $review['blockers'] ) );
		self::assertSame( [ 'site_address', 'database_version', 'cron_lock', 'plugin_data', 'administrator' ], $review['kept'] );
		self::assertSame( [ 'roles', 'active_plugins', 'scheduled_events' ], $review['restored'] );
		self::assertSame(
			[
				'entry' => self::OWN,
				'added' => true,
			],
			$review['plugin']
		);
		self::assertSame(
			[
				[
					'plugin' => self::LATER_PLUGIN,
					'file'   => true,
				],
			],
			$review['plugins']['deactivated']['entries']
		);
		self::assertSame(
			[
				[
					'plugin' => self::SNAPSHOT_PLUGIN,
					'file'   => false,
				],
			],
			$review['plugins']['activated']['entries']
		);
		self::assertSame( [ 'subscriber' ], array_column( $review['roles']['entries'], 'role' ) );
		self::assertSame( [ 'edit_users', 'manage_options' ], $review['roles']['entries'][0]['removed']['entries'] );
		self::assertSame( [], $review['missing_capabilities'] );
		$applied = self::advance( $services['runner'], $services['changes']->start_apply( $services['source']->id, 1, $review['digest'] ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		$restored = $this->options();
		// Every restored account has the backup's roles: the subscriber's injected capabilities are gone.
		self::assertSame( serialize( WordPressRoles::defaults() ), $restored['wp_user_roles'] );
		self::assertSame( [ 'manage_options', 'edit_users' ], $this->lacks( 2, [ 'manage_options', 'edit_users' ] ) );
		self::assertSame( [], $this->lacks( 1, [ 'manage_options', ...WordPressRoles::DATABASE ] ) );
		// The plugin activated after the backup is inactive; this plugin was added to the backup's list.
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN, self::OWN ] ), $restored['active_plugins'] );
		// The backup's events are live, without the heartbeat, when WordPress refreshes after the exchange.
		self::assertSame( self::events( 'snapshot_event' ), $restored['cron'] );
		self::assertSame( [ self::events( 'snapshot_event' ) ], $events );
		foreach ( [ 'home', 'siteurl', 'db_version', '_transient_doing_cron', 'backups_for_restic_settings', '_transient_backups_for_restic_probe' ] as $kept ) {
			self::assertSame( $live[ $kept ], $restored[ $kept ], $kept );
		}
		self::assertSame( 'snapshot-page', $restored['front_page'] );
		self::assertSame( 'live-sessions', $this->db->rows( "SELECT meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key='session_tokens'" )[0]['meta_value'] );

		// The undo shows and brings back the earlier generation's own roles, plugins and events.
		$services['clock']->seconds += 10;
		$undo                        = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertTrue( $undo['applicable'], implode( ',', $undo['blockers'] ) );
		self::assertSame( $review['kept'], $undo['kept'] );
		self::assertFalse( $undo['plugin']['added'] );
		self::assertSame( [ self::SNAPSHOT_PLUGIN ], array_column( $undo['plugins']['deactivated']['entries'], 'plugin' ) );
		self::assertSame( [ self::LATER_PLUGIN ], array_column( $undo['plugins']['activated']['entries'], 'plugin' ) );
		self::assertSame( [ 'edit_users', 'manage_options' ], $undo['roles']['entries'][0]['added']['entries'] );
		$undone = self::advance( $services['runner'], $services['changes']->start_undo( $applied->id, 1, $undo['digest'] ) );
		self::assertSame( JobState::Succeeded, $undone->state, $undone->error?->message ?? '' );
		$back = $this->options();
		foreach ( [ 'wp_user_roles', 'active_plugins', 'cron', 'front_page', 'home', 'backups_for_restic_settings' ] as $name ) {
			self::assertSame( $live[ $name ], $back[ $name ], $name );
		}
		self::assertSame( [ self::events( 'snapshot_event' ), self::events( 'live_event', 'backups_for_restic_heartbeat' ) ], $events );
	}

	public function test_this_plugin_is_added_once_on_the_private_target_and_never_after_the_exchange(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		$shadow = self::private_options( $plan );
		// The exchange refuses a generation that would leave this plugin inactive.
		try {
			$worker->run( $plan, 1, false, self::continuity( self::OWN ) );
			self::fail( 'The exchange brought live a list of active plugins without this plugin.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_plugins_invalid', $refused->context()['code'] ?? null );
			self::assertSame( 'prepared', $worker->status( $plan ) );
		}
		self::assertTrue( $worker->activate( $plan, false, self::OWN ) );
		self::assertFalse( $worker->activate( $plan, false, self::OWN ) );
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN, self::OWN ] ), $this->options( $shadow )['active_plugins'] );
		// A list that has this plugin already stays as the backup stored it.
		$listed = serialize(
			[
				2 => self::OWN,
				5 => self::SNAPSHOT_PLUGIN,
			]
		);
		$this->private_option( $shadow, 'active_plugins', $listed );
		self::assertFalse( $worker->activate( $plan, false, self::OWN ) );
		self::assertSame( $listed, $this->options( $shadow )['active_plugins'] );
		self::assertSame( serialize( [ self::OWN, self::LATER_PLUGIN ] ), $this->options()['active_plugins'], 'The live list is not touched before the exchange.' );
		$worker->run( $plan, 1, false, self::continuity( self::OWN ) );
		self::assertSame( $listed, $this->options()['active_plugins'] );
		// Once the generation is live, nothing is written to it from here.
		$this->option( 'active_plugins', serialize( [ self::SNAPSHOT_PLUGIN ] ) );
		self::assertFalse( $worker->activate( $plan, false, self::OWN ) );
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN ] ), $this->options()['active_plugins'] );
		// The undo adds it to the earlier generation, which has it already.
		self::assertFalse( $worker->activate( $plan, true, self::OWN ) );
		$retained = self::private_options( $plan, 'retained' );
		$this->private_option( $retained, 'active_plugins', serialize( [ self::LATER_PLUGIN ] ) );
		self::assertTrue( $worker->activate( $plan, true, self::OWN ) );
		self::assertSame( serialize( [ self::LATER_PLUGIN, self::OWN ] ), $this->options( $retained )['active_plugins'] );
		$worker->run( $plan, 1, true, self::continuity( self::OWN ) );
		self::assertSame( serialize( [ self::LATER_PLUGIN, self::OWN ] ), $this->options()['active_plugins'] );
	}

	public function test_this_plugin_is_never_written_into_a_replaced_private_table(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$shadow = self::private_options( $plan );
		$this->db->execute( 'RENAME TABLE `' . $shadow . '` TO moved_shadow' );
		$this->db->execute( 'CREATE TABLE `' . $shadow . '` LIKE moved_shadow' );
		$this->db->execute( 'INSERT INTO `' . $shadow . '` SELECT * FROM moved_shadow' );
		try {
			( new TableExchange( $this->direct ) )->activate( $plan, false, self::OWN );
			self::fail( 'This plugin was written into a table that replaced the private one.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertStringContainsString( 'replaced', $refused->getMessage() );
		}
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN ] ), $this->options( $shadow )['active_plugins'] );
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN ] ), $this->options( 'moved_shadow' )['active_plugins'] );
	}

	public function test_an_administrator_whom_the_other_roles_leave_without_a_capability_is_refused_at_review_and_exchange(): void {
		$this->mysql_only();
		// The approving account has a role that only the running site defines.
		$this->option(
			'wp_user_roles',
			serialize(
				WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ) + [
					'site_owner' => [
						'name'         => 'Site owner',
						'capabilities' => array_fill_keys( [ 'read', 'manage_options', ...WordPressRoles::DATABASE ], true ),
					],
				]
			)
		);
		$this->db->execute( 'UPDATE wp_usermeta SET meta_value=' . self::text( serialize( [ 'site_owner' => true ] ) ) . " WHERE user_id=1 AND meta_key='wp_capabilities'" );
		$services = $this->services();
		$review   = $services['changes']->preview( $services['source']->id, 1 );
		self::assertFalse( $review['applicable'] );
		self::assertContains( 'database_admin_capabilities', $review['blockers'] );
		self::assertSame( [ 'manage_options', 'edit_users', 'promote_users', 'update_core' ], $review['missing_capabilities'] );
		self::assertSame( [ 'site_owner', 'subscriber' ], array_column( $review['roles']['entries'], 'role' ) );
		self::assertSame( 'removed', $review['roles']['entries'][0]['change'] );
		try {
			$services['changes']->start_apply( $services['source']->id, 1, $review['digest'] );
			self::fail( 'A review with a capability blocker was confirmed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'database_exchange_changed', $refused->context()['code'] ?? null );
		}
	}

	public function test_a_generation_that_would_leave_the_administrator_without_a_capability_is_refused_before_the_exchange(): void {
		$this->mysql_only();
		$services = $this->services();
		$job      = self::before_swap( $services );
		// The import that would become live: its administrator role lost two capabilities.
		$plan  = DatabaseGeneration::load( $this->direct, $job )->exchange();
		$roles = WordPressRoles::defaults();
		unset( $roles['administrator']['capabilities']['update_core'], $roles['administrator']['capabilities']['promote_users'] );
		$this->private_option( self::private_options( $plan ), 'wp_user_roles', serialize( $roles ) );
		$failed = self::advance( $services['runner'], $job );
		self::assertSame( JobState::Failed, $failed->state );
		self::assertSame( 'database_admin_capabilities', $failed->error?->code );
		self::assertFileDoesNotExist( $services['site'] . '/.maintenance' );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		self::assertSame( serialize( WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ) ), $this->options()['wp_user_roles'] );
	}

	public function test_an_undo_that_would_leave_the_administrator_without_a_capability_is_refused(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		// While the restored generation is live, the administrator moves to a role that only it defines.
		$this->option(
			'wp_user_roles',
			serialize(
				WordPressRoles::defaults() + [
					'restorer' => [
						'name'         => 'Restorer',
						'capabilities' => array_fill_keys( [ 'read', 'manage_options', ...WordPressRoles::DATABASE ], true ),
					],
				]
			)
		);
		$this->db->execute( 'UPDATE wp_usermeta SET meta_value=' . self::text( serialize( [ 'restorer' => true ] ) ) . " WHERE user_id=1 AND meta_key='wp_capabilities'" );
		$services['clock']->seconds += 10;
		$undo                        = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertFalse( $undo['applicable'] );
		self::assertSame( [ 'database_admin_capabilities' ], $undo['blockers'] );
		self::assertSame( [ 'manage_options', 'edit_users', 'promote_users', 'update_core' ], $undo['missing_capabilities'] );
		// Under the earlier generation's roles the same account as administrator may undo.
		$this->db->execute( 'UPDATE wp_usermeta SET meta_value=' . self::text( serialize( [ 'administrator' => true ] ) ) . " WHERE user_id=1 AND meta_key='wp_capabilities'" );
		self::assertTrue( $services['changes']->preview_undo( $applied->id, 1 )['applicable'] );
	}

	public function test_the_running_sites_roles_and_plugins_that_cannot_be_read_do_not_block_the_restore(): void {
		$this->mysql_only();
		// The review's case: someone with an option-write primitive gave the subscriber role two
		// capabilities in a form that WordPress reads and honours but that the strict reader refuses,
		// and activated a plugin through a named entry of the list.
		$tampered                       = WordPressRoles::with( 'subscriber', [ 'manage_options', 'promote_users' ] );
		$tampered['subscriber']['note'] = 'x';
		$this->option( 'wp_user_roles', serialize( $tampered ) );
		$this->option(
			'active_plugins',
			serialize(
				[
					self::OWN,
					'k' => self::LATER_PLUGIN,
				]
			)
		);
		$services = $this->services();
		$review   = $services['changes']->preview( $services['source']->id, 1 );
		self::assertTrue( $review['applicable'], implode( ',', $review['blockers'] ) );
		self::assertFalse( $review['roles']['readable'] );
		self::assertFalse( $review['plugins']['readable'] );
		self::assertSame( [ 'administrator', 'author', 'contributor', 'editor', 'subscriber' ], array_column( $review['roles']['entries'], 'role' ) );
		self::assertSame( [ 'added' ], array_values( array_unique( array_column( $review['roles']['entries'], 'change' ) ) ) );
		self::assertSame( [ self::SNAPSHOT_PLUGIN ], array_column( $review['plugins']['activated']['entries'], 'plugin' ) );
		self::assertSame( [], $review['plugins']['deactivated']['entries'] );
		$applied = self::advance( $services['runner'], $services['changes']->start_apply( $services['source']->id, 1, $review['digest'] ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		self::assertSame( serialize( WordPressRoles::defaults() ), $this->options()['wp_user_roles'] );
		self::assertSame( [ 'manage_options', 'promote_users' ], $this->lacks( 2, [ 'manage_options', 'promote_users' ] ) );
		self::assertSame( serialize( [ self::SNAPSHOT_PLUGIN, self::OWN ] ), $this->options()['active_plugins'] );
		// The undo would bring the tampered values back and cannot check the administrator under
		// them: it is refused, as the review of the restore said.
		$services['clock']->seconds += 10;
		$undo                        = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertFalse( $undo['applicable'] );
		self::assertSame( [ 'database_plugins_invalid', 'database_roles_invalid' ], $undo['blockers'] );
	}

	public function test_a_running_site_without_a_role_map_restores_the_backups_roles(): void {
		$this->mysql_only();
		$this->db->execute( "DELETE FROM wp_options WHERE option_name='wp_user_roles'" );
		$services = $this->services();
		$review   = $services['changes']->preview( $services['source']->id, 1 );
		self::assertTrue( $review['applicable'], implode( ',', $review['blockers'] ) );
		self::assertFalse( $review['roles']['readable'] );
		$applied = self::advance( $services['runner'], $services['changes']->start_apply( $services['source']->id, 1, $review['digest'] ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		self::assertSame( serialize( WordPressRoles::defaults() ), $this->options()['wp_user_roles'] );
	}

	public function test_an_undo_compares_the_live_roles_and_plugins_only_and_refuses_unreadable_earlier_ones(): void {
		$this->mysql_only();
		$services = $this->services();
		$applied  = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		$services['clock']->seconds += 10;
		// The restored generation, which the undo takes out of service: its values are only compared.
		$restored                       = WordPressRoles::defaults();
		$restored['subscriber']['note'] = 'x';
		$this->option( 'wp_user_roles', serialize( $restored ) );
		$this->option(
			'active_plugins',
			serialize(
				[
					7 => self::OWN,
					9 => self::SNAPSHOT_PLUGIN,
				]
			)
		);
		$undo = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertTrue( $undo['applicable'], implode( ',', $undo['blockers'] ) );
		self::assertFalse( $undo['roles']['readable'] );
		self::assertTrue( $undo['plugins']['readable'] );
		self::assertSame( [ self::LATER_PLUGIN ], array_column( $undo['plugins']['activated']['entries'], 'plugin' ) );
		// The earlier generation, which the undo brings back: its values have to be readable.
		$plan     = DatabaseGeneration::load( $this->direct, $services['jobs']->get( $applied->id ) )->exchange();
		$retained = self::private_options( $plan, 'retained' );
		$this->private_option( $retained, 'active_plugins', str_repeat( 'x', PluginList::MAX_BYTES + 1 ) );
		$refused = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertSame( [ 'database_plugins_too_large' ], $refused['blockers'] );
		$this->private_option( $retained, 'active_plugins', serialize( [ self::OWN, self::LATER_PLUGIN ] ) );
		$undone = self::advance( $services['runner'], $services['changes']->start_undo( $applied->id, 1, $services['changes']->preview_undo( $applied->id, 1 )['digest'] ) );
		self::assertSame( JobState::Succeeded, $undone->state, $undone->error?->message ?? '' );
		self::assertSame( serialize( WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ) ), $this->options()['wp_user_roles'] );
	}

	public function test_an_unreadable_record_of_the_administrators_capabilities_is_named_alike_in_both_reviews(): void {
		$this->mysql_only();
		$duplicate = 'INSERT INTO wp_usermeta (user_id,meta_key,meta_value) SELECT user_id,meta_key,meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key=' . self::text( 'wp_capabilities' );
		$services  = $this->services();
		$this->db->execute( $duplicate );
		$review = $services['changes']->preview( $services['source']->id, 1 );
		self::assertSame( [ 'database_admin_capabilities_unreadable' ], $review['blockers'] );
		self::assertSame( [], $review['missing_capabilities'] );
		$this->db->execute( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='wp_capabilities' ORDER BY umeta_id DESC LIMIT 1" );
		$applied = self::advance( $services['runner'], self::before_swap( $services ) );
		self::assertSame( JobState::Succeeded, $applied->state, $applied->error?->message ?? '' );
		$services['clock']->seconds += 10;
		$this->db->execute( $duplicate );
		$undo = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertSame( [ 'database_admin_capabilities_unreadable' ], $undo['blockers'] );
		self::assertSame( [], $undo['missing_capabilities'] );
		$this->db->execute( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='wp_capabilities'" );
		$undo = $services['changes']->preview_undo( $applied->id, 1 );
		self::assertSame( [ 'database_admin_capabilities_unreadable' ], $undo['blockers'] );
		self::assertSame( [], $undo['missing_capabilities'] );
	}

	public function test_a_role_map_over_the_bound_is_refused_as_too_large_at_review_and_exchange(): void {
		$this->mysql_only();
		$number = array_search( 'wp_options', array_column( $this->manifest->tables, 'name' ), true );
		self::assertIsInt( $number );
		$large = serialize(
			WordPressRoles::defaults() + [
				'bulk' => [
					'name'         => 'Bulk',
					'capabilities' => [ str_repeat( 'c', RoleMap::MAX_NAME ) => true ],
				],
			]
		);
		$large = substr( $large, 0, -1 ) . str_repeat( ' ', RoleMap::MAX_BYTES ) . '}';
		$this->private_option( $this->importer->tables->shadow( $number ), 'wp_user_roles', $large );
		$services = $this->services();
		$report   = json_decode( (string) file_get_contents( $services['files'] . '/../../../database-preview.json' ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( strlen( $large ), $report['wordpress_state']['user_roles'], 'The report keeps the length of a value too long to read.' );
		$review = $services['changes']->preview( $services['source']->id, 1 );
		self::assertSame( [ 'database_roles_too_large' ], $review['blockers'] );
		// The same at the exchange, in the generation that it brings live: here the plan's import is
		// the rehearsal's, which already holds the large role map.
		$plan = $this->prepared();
		self::assertSame( $large, $this->options( self::private_options( $plan ) )['wp_user_roles'] );
		try {
			( new TableExchange( $this->direct ) )->run( $plan, 1, false, self::continuity() );
			self::fail( 'A role map beyond the bound was brought live.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_roles_too_large', $refused->context()['code'] ?? null );
			self::assertSame( 'prepared', ( new TableExchange( $this->direct ) )->status( $plan ) );
		}
	}

	public function test_roles_and_plugin_lists_that_wordpress_does_not_write_are_refused_in_the_generation_brought_live(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		$shadow = self::private_options( $plan );
		$this->private_option( $shadow, 'active_plugins', 'a:1:{i:0;s:9:"hello.php";' );
		try {
			$worker->activate( $plan, false, self::OWN );
			self::fail( 'A broken list of active plugins was extended.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_plugins_invalid', $refused->context()['code'] ?? null );
		}
		$this->private_option( $shadow, 'wp_user_roles', serialize( [ 'administrator' => [ 'name' => 'Administrator' ] ] ) );
		try {
			$worker->run( $plan, 1, false, self::continuity() );
			self::fail( 'A role map that WordPress does not write was brought live.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_roles_invalid', $refused->context()['code'] ?? null );
			self::assertSame( 'prepared', $worker->status( $plan ) );
			self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		}
	}

	public function test_a_backups_role_map_that_wordpress_does_not_write_is_refused_at_the_review(): void {
		$this->mysql_only();
		// The rehearsal reads the snapshot's values from its import.
		$number = array_search( 'wp_options', array_column( $this->manifest->tables, 'name' ), true );
		self::assertIsInt( $number );
		$shadow = $this->importer->tables->shadow( $number );
		$this->private_option(
			$shadow,
			'wp_user_roles',
			serialize(
				[
					'administrator' => [
						'name'         => 'Administrator',
						'capabilities' => [ 'manage_options' => new \stdClass() ],
					],
				]
			)
		);
		$this->private_option( $shadow, 'active_plugins', serialize( [ self::SNAPSHOT_PLUGIN, self::SNAPSHOT_PLUGIN ] ) );
		$services = $this->services();
		$review   = $services['changes']->preview( $services['source']->id, 1 );
		self::assertFalse( $review['applicable'] );
		self::assertContains( 'database_roles_invalid', $review['blockers'] );
		self::assertContains( 'database_plugins_invalid', $review['blockers'] );
		self::assertSame( [], $review['roles']['entries'] );
		self::assertSame( [], $review['plugins']['activated']['entries'] );
	}

	public function test_a_review_from_before_the_comparison_or_with_changed_facts_cannot_be_confirmed(): void {
		$this->mysql_only();
		$services = $this->services();
		$review   = $services['changes']->preview( $services['source']->id, 1 );
		self::assertTrue( $review['applicable'] );
		// A plugin activated after the review changes what the review compared.
		$this->option( 'active_plugins', serialize( [ self::OWN, self::LATER_PLUGIN, 'another/another.php' ] ) );
		self::assertNotSame( $review['digest'], $services['changes']->preview( $services['source']->id, 1 )['digest'] );
		try {
			$services['changes']->start_apply( $services['source']->id, 1, $review['digest'] );
			self::fail( 'A review whose facts changed was confirmed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'database_exchange_changed', $refused->context()['code'] ?? null );
		}
		// The same after the confirmation: the exchange binds the live list of active plugins.
		$job = self::before_swap( $services );
		$this->option( 'active_plugins', serialize( [ self::OWN, self::LATER_PLUGIN ] ) );
		$failed = self::advance( $services['runner'], $job );
		self::assertSame( 'database_exchange_changed', $failed->error?->code );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		// A job confirmed before the review compared roles and plugins holds no capabilities.
		$services['clock']->seconds += 10;
		$job                         = self::before_swap( $services );
		unset( $job->data['capabilities'] );
		$services['jobs']->save( $job );
		$failed = self::advance( $services['runner'], $job );
		self::assertSame( 'database_exchange_changed', $failed->error?->code );
		self::assertSame( 'live-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		// A rehearsal from before the comparison has no stored roles and plugins in its report.
		$report = $services['files'] . '/../../../database-preview.json';
		$bytes  = (string) file_get_contents( $report );
		$data   = json_decode( $bytes, true, flags: JSON_THROW_ON_ERROR );
		unset( $data['wordpress_state'] );
		$bytes = json_encode( $data, JSON_THROW_ON_ERROR );
		file_put_contents( $report, $bytes );
		$source                                   = $services['jobs']->get( $services['source']->id );
		$source->result['database_report_sha256'] = hash( 'sha256', $bytes );
		$services['jobs']->save( $source );
		try {
			$services['changes']->preview( $services['source']->id, 1 );
			self::fail( 'A rehearsal without the stored roles and plugins was reviewed.' );
		} catch ( JobConflict $refused ) {
			self::assertSame( 'database_review_required', $refused->context()['code'] ?? null );
		}
	}
}
