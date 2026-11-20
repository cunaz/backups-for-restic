<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\Ledger;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\MysqliConnection as Direct;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\TableExchange;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** Every live table in this suite belongs to one randomly named disposable database. */
final class DatabaseExchangeTest extends TestCase {

	private MysqliConnection $admin;
	private MysqliConnection $db;
	private string $database = '';
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
		$db->execute( "INSERT INTO wp_options (option_name,option_value) VALUES ('home','snapshot-home'),('siteurl','snapshot-url'),('cron','snapshot-cron'),('active_plugins','snapshot-plugins'),('db_version','12345'),('wp_user_roles','snapshot-roles'),('backups_for_restic_settings','snapshot-settings'),('front_page','snapshot-page')" );
		$db->execute( "INSERT INTO wp_users (ID,user_login,user_pass,user_email) VALUES (1,'admin','snapshot-pass','admin@fixture.test'),(2,'editor','editor-pass','editor@fixture.test')" );
		$db->execute( "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (1,'wp_capabilities','snapshot-caps'),(1,'session_tokens','snapshot-sessions'),(2,'wp_capabilities','editor')" );
		$db->execute( "INSERT INTO wp_posts VALUES (1,'snapshot-post')" );
		$dump = new DatabaseDump( $db, $this->directory . '/state', 'wp_', [], 1767225600 );
		foreach ( $dump->files() as $file ) {
			$source = ( $file->source )( null );
			$fp     = fopen( $this->directory . '/' . $file->name, 'wb' );
			while ( '' !== ( $bytes = $source->read( 65536 ) ) ) {
				fwrite( $fp, $bytes );
			}
			fclose( $fp );
		}
		$this->manifest = Manifest::parse( (string) file_get_contents( $this->directory . '/manifest.json' ), 'wp_' );
		$db->execute( 'DROP TABLE wp_snapshot_only' );
		$db->execute( 'CREATE TABLE wp_live_only (id INT) ENGINE=InnoDB' );
		$db->execute( "UPDATE wp_options SET option_value=REPLACE(option_value,'snapshot','live')" );
		$db->execute( "UPDATE wp_users SET user_pass='live-pass' WHERE ID=1" );
		$db->execute( "UPDATE wp_usermeta SET meta_value=REPLACE(meta_value,'snapshot','live') WHERE user_id=1" );
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
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		self::assertSame( 'prepared', $worker->status( $plan ) );
		$worker->run( $plan, 1, false );
		$worker->run( $plan, 1, false );
		self::assertSame( 'applied', $worker->status( $plan ) );
		self::assertSame( 'snapshot-post', $this->db->rows( 'SELECT title FROM wp_posts' )[0]['title'] );
		self::assertSame( 'live-pass', $this->db->rows( 'SELECT user_pass FROM wp_users WHERE ID=1' )[0]['user_pass'] );
		$opts = array_column( $this->db->rows( 'SELECT option_name,option_value FROM wp_options' ), 'option_value', 'option_name' );
		self::assertSame( 'live-settings', $opts['backups_for_restic_settings'] );
		self::assertSame( 'live-cron', $opts['cron'] );
		self::assertSame( 'live-home', $opts['home'] );
		self::assertSame( 'live-url', $opts['siteurl'] );
		self::assertSame( 'live-roles', $opts['wp_user_roles'] );
		self::assertSame( 'snapshot-page', $opts['front_page'] );
		$this->db->execute( "INSERT INTO wp_posts VALUES (2,'after-apply')" );
		$this->db->execute( "UPDATE wp_users SET user_pass='after-pass' WHERE ID=1" );
		$this->db->execute( "INSERT INTO wp_users (ID,user_login) VALUES (3,'after-user')" );
		$this->db->execute( "UPDATE wp_usermeta SET meta_value='after-sessions' WHERE user_id=1 AND meta_key='session_tokens'" );
		$this->db->execute( "UPDATE wp_options SET option_value='after-settings' WHERE option_name='backups_for_restic_settings'" );
		$this->db->execute( 'INSERT INTO wp_snapshot_only VALUES (123)' );
		$worker->run( $plan, 1, true );
		$worker->run( $plan, 1, true );
		self::assertSame( 'undone', $worker->status( $plan ) );
		self::assertSame( [ [ 'title' => 'live-post' ] ], $this->db->rows( 'SELECT title FROM wp_posts' ) );
		self::assertSame( 'after-pass', $this->db->rows( 'SELECT user_pass FROM wp_users WHERE ID=1' )[0]['user_pass'] );
		self::assertSame( 'after-sessions', $this->db->rows( "SELECT meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key='session_tokens'" )[0]['meta_value'] );
		self::assertSame( 'after-settings', $this->db->rows( "SELECT option_value FROM wp_options WHERE option_name='backups_for_restic_settings'" )[0]['option_value'] );
		foreach ( $plan->document['pairs'] as $number => $pair ) {
			$retained = '`' . $plan->owned->shadow( $number ) . '`';
			if ( 'wp_posts' === $pair['name'] ) {
				self::assertSame( 'after-apply', $this->db->rows( 'SELECT title FROM ' . $retained . ' WHERE id=2' )[0]['title'] );
			} elseif ( 'wp_users' === $pair['name'] ) {
				self::assertSame( 'after-user', $this->db->rows( 'SELECT user_login FROM ' . $retained . ' WHERE ID=3' )[0]['user_login'] );
			} elseif ( 'wp_snapshot_only' === $pair['name'] ) {
				self::assertSame( '123', $this->db->rows( 'SELECT id FROM ' . $retained )[0]['id'] );
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
			( new TableExchange( $this->direct ) )->run( $plan, 1, false );
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

	public function test_retries_on_either_side_of_the_atomic_exchange_use_the_physical_witness(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		foreach ( [ false, true ] as $undo ) {
			foreach ( [ false, true ] as $committed ) {
				$fault = $this->observing(
					static function ( string $sql, bool $after ) use ( $committed ): void {
						if ( $after === $committed && str_starts_with( $sql, 'RENAME TABLE ' ) ) {
							throw new \RuntimeException( 'Lost exchange response.' );
						}
					}
				);
				try {
					( new TableExchange( $fault ) )->run( $plan, 1, $undo );
					self::fail( 'Expected simulated request loss at RENAME.' );
				} catch ( \RuntimeException $e ) {
					self::assertSame( 'Lost exchange response.', $e->getMessage() );
				}
				self::assertSame( $committed ? ( $undo ? 'undone' : 'applied' ) : ( $undo ? 'applied' : 'prepared' ), $worker->status( $plan ) );
			}
			$worker->run( $plan, 1, $undo );
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
		( new TableExchange( $probe ) )->run( $plan, 1, false );
		self::assertTrue( $queued );
		self::assertTrue( $writer->mysqli->reap_async_query() );
		self::assertSame( 'arrived-during-exchange', $this->db->rows( 'SELECT title FROM wp_posts WHERE id=123' )[0]['title'] );
	}

	public function test_bounded_cleanup_replays_without_dropping_a_live_or_replaced_table(): void {
		$this->mysql_only();
		$plan   = $this->prepared();
		$worker = new TableExchange( $this->direct );
		$worker->run( $plan, 1, false );
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
}
