<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Database\Dump\DedicatedConnection;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\WordPress\WpdbConnection;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/WordPress/wpdb.php';

/**
 * The dump's own connection, opened with the settings that wpdb connected with, against a real
 * server: it is used only when it reaches the site's server and database, and is encrypted
 * when the site's connection is. Needs a server like the rest of the database suite.
 */
final class DedicatedConnectionTest extends TestCase {

	private static ?MysqliConnection $admin = null;

	private static string $database = '';

	private static string $other = '';

	public static function setUpBeforeClass(): void {
		self::$admin = MysqliConnection::connect();
		if ( null === self::$admin ) {
			self::markTestSkipped( 'No MySQL or MariaDB server is reachable.' );
		}
		$suffix         = bin2hex( random_bytes( 4 ) );
		self::$database = 'bfr_dedicated_' . $suffix;
		self::$other    = 'bfr_dedicated_other_' . $suffix;
		self::$admin->execute( 'CREATE DATABASE `' . self::$database . '`' );
		self::$admin->execute( 'CREATE DATABASE `' . self::$other . '`' );
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$admin ) {
			self::$admin->execute( 'DROP DATABASE IF EXISTS `' . self::$database . '`' );
			self::$admin->execute( 'DROP DATABASE IF EXISTS `' . self::$other . '`' );
		}
	}

	public function test_a_connection_with_the_site_settings_reaches_the_same_database_with_the_dump_settings(): void {
		$site      = $this->site( self::$database );
		$lock      = '0x' . bin2hex( 'bfr_dump_' . bin2hex( ( new FixedRandom( 'lock' ) )->bytes( 8 ) ) );
		$dedicated = WpdbConnection::dedicated( $this->wpdb( $site, self::$database ), new FixedRandom( 'lock' ), '+00:00' );

		self::assertInstanceOf( DedicatedConnection::class, $dedicated );
		self::assertSame(
			[
				[
					'db'      => self::$database,
					'results' => 'utf8mb4',
					'zone'    => '+00:00',
					'mode'    => 'NO_AUTO_VALUE_ON_ZERO',
				],
			],
			$dedicated->rows( 'SELECT DATABASE() AS db, @@SESSION.character_set_results AS results, @@SESSION.time_zone AS zone, @@SESSION.sql_mode AS mode' )
		);
		self::assertNotSame( $site->rows( 'SELECT CONNECTION_ID() AS id' ), $dedicated->rows( 'SELECT CONNECTION_ID() AS id' ), 'It is a connection of its own.' );
		self::assertSame( [ [ 'holder' => null ] ], $site->rows( 'SELECT IS_USED_LOCK(' . $lock . ') AS holder' ), 'The lock that identified the server is released.' );
		$dedicated->close();
	}

	public function test_a_dump_that_keeps_the_session_time_zone_does_not_set_one(): void {
		$dedicated = WpdbConnection::dedicated( $this->wpdb( $this->site( self::$database ), self::$database ), new SystemRandom(), null );
		self::assertInstanceOf( DedicatedConnection::class, $dedicated );
		self::assertSame( self::$admin?->rows( 'SELECT @@GLOBAL.time_zone AS zone' ), $dedicated->rows( 'SELECT @@SESSION.time_zone AS zone' ) );
		$dedicated->close();
	}

	public function test_no_connection_when_the_settings_lead_to_another_database(): void {
		// A drop-in may keep settings in wpdb that are not those of its connection.
		self::assertNull( WpdbConnection::dedicated( $this->wpdb( $this->site( self::$database ), self::$other ), new SystemRandom(), '+00:00' ) );
	}

	public function test_no_connection_when_the_password_is_refused(): void {
		$site = $this->site( self::$database );
		$wpdb = new \wpdb( $site->mysqli, MysqliConnection::environment( 'BFR_TEST_MYSQL_HOST', 'localhost' ), MysqliConnection::environment( 'BFR_TEST_MYSQL_USER', 'root' ), 'not-the-password', self::$database );
		self::assertNull( WpdbConnection::dedicated( $wpdb, new SystemRandom(), '+00:00' ) );
	}

	public function test_no_unencrypted_connection_when_the_site_connection_is_encrypted(): void {
		$encrypted = mysqli_init();
		self::assertInstanceOf( \mysqli::class, $encrypted );
		[ $host, $port ] = $this->address();
		if ( ! @$encrypted->real_connect( $host, MysqliConnection::environment( 'BFR_TEST_MYSQL_USER', 'root' ), MysqliConnection::environment( 'BFR_TEST_MYSQL_PASSWORD', '' ), self::$database, $port, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT ) ) {
			self::markTestSkipped( 'The server accepts no encrypted connection.' );
		}
		$site = new MysqliConnection( $encrypted );
		if ( '' === ( $site->rows( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" )[0]['Value'] ?? '' ) ) {
			self::markTestSkipped( 'The server did not encrypt the connection.' );
		}
		self::assertNull( WpdbConnection::dedicated( $this->wpdb( $site, self::$database ), new SystemRandom(), '+00:00' ), 'Without MYSQL_CLIENT_FLAGS the dump connection would not be encrypted.' );
	}

	private function site( string $database ): MysqliConnection {
		$site = MysqliConnection::connect( $database );
		self::assertNotNull( $site );
		return $site;
	}

	private function wpdb( MysqliConnection $site, string $database ): \wpdb {
		return new \wpdb( $site->mysqli, MysqliConnection::environment( 'BFR_TEST_MYSQL_HOST', 'localhost' ), MysqliConnection::environment( 'BFR_TEST_MYSQL_USER', 'root' ), MysqliConnection::environment( 'BFR_TEST_MYSQL_PASSWORD', '' ), $database );
	}

	/**
	 * @return array{string, int|null} Host and port of the test server.
	 */
	private function address(): array {
		$host = MysqliConnection::environment( 'BFR_TEST_MYSQL_HOST', 'localhost' );
		return 1 === preg_match( '/^([^:]+):(\d+)$/', $host, $match ) ? [ $match[1], (int) $match[2] ] : [ $host, null ];
	}
}
