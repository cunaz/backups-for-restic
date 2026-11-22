<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Dump\DedicatedConnection;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\WordPress\WpdbConnection;

require_once dirname( __DIR__ ) . '/Support/WordPress/wpdb.php';

/**
 * The dump format against a real MySQL or MariaDB server: what the plugin writes imports with
 * the plain client, as the disaster-recovery guide describes, and yields identical tables.
 * Needs a server (root over the local socket, or BFR_TEST_MYSQL_HOST/USER/PASSWORD).
 */
final class DatabaseDumpTest extends TestCase {

	private static ?MysqliConnection $admin = null;

	private static string $source = '';

	private static string $target = '';

	private string $directory = '';

	/** @var list<string> Warnings of the complete table sources read by read(). */
	private array $warnings = [];

	public static function setUpBeforeClass(): void {
		self::$admin = MysqliConnection::connect();
		if ( null === self::$admin ) {
			self::markTestSkipped( 'No MySQL or MariaDB server is reachable.' );
		}
		$suffix       = bin2hex( random_bytes( 4 ) );
		self::$source = 'bfr_dump_source_' . $suffix;
		self::$target = 'bfr_dump_target_' . $suffix;
		self::$admin->execute( 'CREATE DATABASE `' . self::$source . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		self::$admin->execute( 'CREATE DATABASE `' . self::$target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		self::fill( self::database( self::$source ) );
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$admin ) {
			self::$admin->execute( 'DROP DATABASE IF EXISTS `' . self::$source . '`' );
			self::$admin->execute( 'DROP DATABASE IF EXISTS `' . self::$target . '`' );
		}
	}

	protected function setUp(): void {
		$this->directory = TempDir::create( 'dump' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private static function database( string $name ): MysqliConnection {
		$connection = MysqliConnection::connect( $name );
		self::assertNotNull( $connection );
		return $connection;
	}

	/**
	 * Opens connections of the dump's own to the source database, as the WordPress adapter
	 * does with the site's connection settings.
	 *
	 * @return \Closure(?string): DedicatedConnection Factory.
	 */
	private static function dedicated(): \Closure {
		return static fn ( ?string $time_zone ): DedicatedConnection => new DedicatedConnection( self::database( self::$source )->mysqli, $time_zone );
	}

	/**
	 * Tables that exercise every code path: keyset and offset paging, composite and missing
	 * keys, binary data, invalid UTF-8 in a latin1 column, NUL bytes, line breaks, quotes,
	 * backslashes, emoji, NULLs, a generated column and a view.
	 */
	private static function fill( MysqliConnection $db ): void {
		// The generated column uses price: MySQL rejects generated columns over an AUTO_INCREMENT column.
		$db->execute(
			'CREATE TABLE bfr_posts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				title VARCHAR(255) NOT NULL,
				body LONGTEXT,
				created DATETIME NOT NULL,
				price DECIMAL(10,2),
				data LONGBLOB NULL,
				flag BIT(1) NOT NULL DEFAULT b\'0\',
				latin TEXT CHARACTER SET latin1,
				doubled DECIMAL(12,2) AS (price * 2) VIRTUAL,
				COMMENT_FIELD VARCHAR(20) COMMENT \'with a ; and a\nline break\'
			) ENGINE=InnoDB'
		);
		$values = [];
		for ( $i = 1; $i <= 3000; $i++ ) {
			$body     = 0 === $i % 7 ? 'NULL' : "'" . self::$admin?->mysqli->real_escape_string( str_repeat( "Line $i with 'quotes', \\backslashes\\, a ; semicolon\nnew line, emoji 😀, NUL \0 byte. ", $i % 50 + 1 ) ) . "'";
			$data     = 0 === $i % 3 ? 'NULL' : '0x' . bin2hex( random_bytes( $i % 200 + 1 ) . "\x00\xff" );
			$values[] = sprintf( "('Post %d', %s, '2026-0%d-1%d 12:34:56', %d.%02d, %s, b'%d', 'caf\u{e9} %d')", $i, $body, $i % 9 + 1, $i % 9, $i, $i % 100, $data, $i % 2, $i );
		}
		$db->mysqli->query( 'SET NAMES utf8mb4' );
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$db->execute( 'INSERT INTO bfr_posts (title, body, created, price, data, flag, latin) VALUES ' . implode( ',', $chunk ) );
		}
		$db->execute( 'CREATE TABLE bfr_meta (meta_key VARCHAR(191) NOT NULL PRIMARY KEY, meta_value LONGTEXT) DEFAULT CHARSET=utf8mb4' );
		$db->execute( "INSERT INTO bfr_meta VALUES ('a', 'x'), ('b', ''), ('c', NULL), ('äx', 'umlaut'), ('_wp_\\\\x', 'backslash key')" );
		$db->execute( 'CREATE TABLE bfr_nopk (a INT, b TEXT)' );
		$db->execute( "INSERT INTO bfr_nopk VALUES (1, 'one'), (1, 'one'), (NULL, NULL), (2, '')" );
		$db->execute( 'CREATE TABLE bfr_composite (a INT NOT NULL, b INT NOT NULL, c TEXT, PRIMARY KEY (a, b))' );
		$db->execute( "INSERT INTO bfr_composite VALUES (1, 2, 'x'), (1, 1, 'y'), (0, 5, 'z')" );
		$db->execute( 'CREATE TABLE bfr_empty (id INT PRIMARY KEY)' );
		$db->execute( 'CREATE TABLE other_table (id INT PRIMARY KEY)' );
		$db->execute( 'CREATE VIEW bfr_titles AS SELECT id, title FROM bfr_posts WHERE id < 10' );
	}

	/** @return iterable<string, array{bool}> */
	public static function connections(): iterable {
		yield 'the site connection' => [ false ];
		yield 'a connection of its own' => [ true ];
	}

	#[DataProvider( 'connections' )]
	public function test_dump_imports_with_the_mariadb_client_into_identical_tables( bool $dedicated ): void {
		$dump  = new DatabaseDump( self::database( self::$source ), $this->directory . '/state.json', 'bfr_', [], 1767323100, $dedicated ? self::dedicated() : null );
		$files = [];
		foreach ( $dump->files() as $file ) {
			$files[ $file->name ] = $this->read( $file->source, 3 );
		}

		self::assertSame( [ '000-header.sql', '010-bfr_composite.sql', '010-bfr_empty.sql', '010-bfr_meta.sql', '010-bfr_nopk.sql', '010-bfr_posts.sql', '900-views.sql', 'manifest.json' ], array_keys( $files ) );
		foreach ( $files as $name => $content ) {
			if ( str_starts_with( $name, '010-' ) ) {
				self::assertMatchesRegularExpression( '/\n-- end table rows=\d+ bytes=(\d+)\n\z/', $content, $name );
				preg_match( '/bytes=(\d+)\n\z/', $content, $match );
				self::assertSame( (int) $match[1], strrpos( $content, '-- end table rows=' ), $name . ': the trailer counts the bytes before it.' );
			}
		}

		$manifest = json_decode( $files['manifest.json'], true );
		self::assertIsArray( $manifest );
		self::assertSame( 'backups-for-restic-dump/1', $manifest['format'] );
		$posts = array_values( array_filter( $manifest['tables'], static fn ( array $table ): bool => 'bfr_posts' === $table['name'] ) )[0];
		self::assertSame( 3000, $posts['rows'] );
		self::assertSame( strlen( $files['010-bfr_posts.sql'] ), $posts['bytes'] );
		self::assertSame( [ 'bfr_titles' ], $manifest['views'] );
		self::assertStringNotContainsString( 'DEFINER=', $files['900-views.sql'], 'The definer account is removed so that the view imports anywhere.' );

		foreach ( $files as $name => $content ) {
			if ( str_ends_with( $name, '.sql' ) ) {
				file_put_contents( $this->directory . '/' . $name, $content );
			}
		}
		MysqliConnection::import( self::$target, array_map( 'strval', (array) glob( $this->directory . '/*.sql' ) ) );

		$source = self::database( self::$source );
		$target = self::database( self::$target );
		foreach ( [ 'bfr_posts', 'bfr_meta', 'bfr_nopk', 'bfr_composite', 'bfr_empty' ] as $table ) {
			self::assertSame( $source->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $target->rows( 'CHECKSUM TABLE ' . $table . ' EXTENDED' )[0]['Checksum'], $table . ': ' . $this->first_difference( $source, $target, $table ) );
		}
		self::assertSame( $source->rows( 'SELECT * FROM bfr_titles ORDER BY id' ), $target->rows( 'SELECT * FROM bfr_titles ORDER BY id' ) );
		self::assertSame( [], $target->rows( "SHOW TABLES LIKE 'other_table'" ) );
		self::assertSame( '6000.00', $target->rows( 'SELECT doubled FROM bfr_posts WHERE id = 3000' )[0]['doubled'], 'Generated columns are computed again.' );
	}

	public function test_resuming_after_a_floating_point_primary_key_in_exponent_notation_preserves_every_row(): void {
		$db = self::database( self::$source );
		$db->execute( 'CREATE TABLE float_pages (id DOUBLE PRIMARY KEY, body TEXT)' );
		$rows = [];
		for ( $id = 1; $id <= 800; ++$id ) {
			$rows[] = '(' . $id . "e-100, REPEAT('x', 200))";
		}
		$db->execute( 'INSERT INTO float_pages VALUES ' . implode( ',', $rows ) );
		$dump = new DatabaseDump( $db, $this->directory . '/float.json', 'float_', [], 1 );
		foreach ( $dump->files() as $file ) {
			if ( '010-float_pages.sql' === $file->name ) {
				$sql = $this->read( $file->source, 1 );
				self::assertStringContainsString( '-- end table rows=800 ', $sql );
				file_put_contents( $this->directory . '/float.sql', $sql );
				MysqliConnection::import( self::$target, [ $this->directory . '/float.sql' ] );
				self::assertSame( $db->rows( 'SELECT id,body FROM float_pages ORDER BY id' ), self::database( self::$target )->rows( 'SELECT id,body FROM float_pages ORDER BY id' ) );
				return;
			}
		}
		self::fail( 'The dump is missing the table.' );
	}

	public function test_resumed_and_uninterrupted_dumps_are_identical(): void {
		$whole   = '';
		$resumed = '';
		foreach ( ( new DatabaseDump( self::database( self::$source ), $this->directory . '/a.json', 'bfr_', [], 1 ) )->files() as $file ) {
			if ( '010-bfr_posts.sql' === $file->name ) {
				$whole = $this->read( $file->source, PHP_INT_MAX );
			}
		}
		foreach ( ( new DatabaseDump( self::database( self::$source ), $this->directory . '/b.json', 'bfr_', [], 1 ) )->files() as $file ) {
			if ( '010-bfr_posts.sql' === $file->name ) {
				$resumed = $this->read( $file->source, 1 );
			}
		}
		self::assertSame( $whole, $resumed );
		self::assertGreaterThan( 1000000, strlen( $whole ) );
	}

	/**
	 * Tables whose pages followed an offset in key order: a composite primary key as in
	 * wp_term_relationships, a string key under a case- and accent-insensitive collation, and
	 * a unique key without a primary key. After the first part, the two first rows are deleted;
	 * after the second, two rows are inserted before all others. Every row that the dump read
	 * stays in it once, none is skipped and none repeated.
	 */
	public function test_rows_present_for_the_whole_dump_appear_exactly_once_when_rows_before_the_cursor_change(): void {
		$db = self::database( self::$source );
		$db->execute( 'CREATE TABLE key_relationships (object_id BIGINT UNSIGNED NOT NULL, term_taxonomy_id BIGINT UNSIGNED NOT NULL, term_order INT NOT NULL DEFAULT 0, note VARCHAR(500) NOT NULL, PRIMARY KEY (object_id, term_taxonomy_id), KEY term_taxonomy_id (term_taxonomy_id)) ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE key_names (name VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL PRIMARY KEY, body VARCHAR(500) NOT NULL) ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE key_codes (code CHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL, label VARCHAR(500) NOT NULL, UNIQUE KEY code (code)) ENGINE=InnoDB' );
		$relationships = [];
		$names         = [];
		$codes         = [];
		for ( $i = 1; $i <= 2000; ++$i ) {
			$relationships[] = sprintf( "(%d, %d, %d, '%s')", intdiv( $i + 2, 3 ), ( $i % 3 ) + 1, $i % 5, str_repeat( 'r', 400 ) );
			$names[]         = sprintf( "('%s%05d%s', '%s')", [ 'alpha', 'Ålpha', 'ÉCHO', 'echo', 'Zeta', 'zeta ß' ][ $i % 6 ], $i, 0 === $i % 7 ? ' ' : '', str_repeat( 'n', 400 ) );
			$codes[]         = sprintf( "('%s%05d', '%s')", [ 'a', 'z', 'å', 'ä', 'ö', 'A' ][ $i % 6 ], $i, str_repeat( 'c', 400 ) );
		}
		foreach ( [
			'key_relationships' => $relationships,
			'key_names'         => $names,
			'key_codes'         => $codes,
		] as $table => $values ) {
			foreach ( array_chunk( $values, 500 ) as $chunk ) {
				$db->execute( 'INSERT INTO ' . $table . ' VALUES ' . implode( ',', $chunk ) );
			}
		}
		$orders   = [
			'key_relationships' => 'object_id, term_taxonomy_id',
			'key_names'         => 'name',
			'key_codes'         => 'code',
		];
		$inserted = [
			'key_relationships' => "(0, 1, 0, 'new'), (0, 2, 0, 'new')",
			'key_names'         => "('!00000', 'new'), ('!00001', 'new')",
			'key_codes'         => "('!0000', 'new'), ('!0001', 'new')",
		];
		$expected = [];
		foreach ( $orders as $table => $order ) {
			$expected[ $table ] = $db->rows( 'SELECT * FROM ' . $table . ' ORDER BY ' . $order . ', HEX(' . explode( ',', $order )[0] . ')' );
		}
		$parts = [];

		$this->dump_and_import(
			$db,
			'key_',
			static function ( string $file, int $part ) use ( $db, $orders, $inserted, &$parts ): void {
				$table           = substr( $file, 4, -4 );
				$parts[ $table ] = $part;
				if ( 1 === $part ) {
					$db->execute( 'DELETE FROM ' . $table . ' ORDER BY ' . $orders[ $table ] . ' LIMIT 2' );
				} elseif ( 2 === $part ) {
					$db->execute( 'INSERT INTO ' . $table . ' VALUES ' . $inserted[ $table ] );
				}
			}
		);

		$target = self::database( self::$target );
		foreach ( $orders as $table => $order ) {
			self::assertGreaterThanOrEqual( 2, $parts[ $table ] ?? 0, $table . ' was read in at least three parts, so both changes happened.' );
			self::assertSame( $expected[ $table ], $target->rows( 'SELECT * FROM ' . $table . ' ORDER BY ' . $order . ', HEX(' . explode( ',', $order )[0] . ')' ), $table . ': every row read once, nothing skipped, nothing repeated.' );
		}
	}

	/**
	 * Keys of every type that can order pages, each with the same changes before the cursor:
	 * unsigned 64-bit values above PHP_INT_MAX, negative numbers, DECIMAL, DOUBLE, FLOAT (which
	 * the server prints with fewer digits than it holds), dates and times, TIMESTAMP across
	 * changes of daylight saving time, YEAR, ENUM and SET (which sort by index and bits), BIT,
	 * BINARY and VARBINARY with zero bytes, and a key over a DATE and an INT.
	 */
	public function test_keys_of_every_type_page_exactly_while_rows_before_the_cursor_change(): void {
		$db = self::database( self::$source );
		// Seeded and compared in UTC: in a zone with daylight saving time, two instants of the
		// hour that the zone repeats have one local time.
		$db->execute( "SET SESSION time_zone = '+00:00'" );
		// A day before the clocks go back in Europe: rows 24 and 25 fall into the repeated hour.
		$start    = gmmktime( 0, 0, 0, 10, 24, 2026 );
		$filler   = str_repeat( 'f', 400 );
		$types    = [
			'u64'  => [ 'BIGINT UNSIGNED', static fn ( int $i ): string => 1999 === $i ? '18446744073709551615' : '9223372036' . sprintf( '%09d', 854775808 + $i ), [ '1', '2' ] ],
			'i32'  => [ 'INT', static fn ( int $i ): string => (string) ( $i - 1000 ), [ '-2147483648', '-2147483647' ] ],
			'dec'  => [ 'DECIMAL(20,6)', static fn ( int $i ): string => sprintf( "'%s%d.%06d'", $i < 1000 ? '-' : '', abs( $i - 1000 ), ( $i * 7919 ) % 1000000 ), [ "'-99999999999999.999999'", "'-99999999999999.999998'" ] ],
			'dbl'  => [ 'DOUBLE', static fn ( int $i ): string => sprintf( '%.17g', ( $i - 1000 ) / 7 ), [ '-1e300', '-1e299' ] ],
			'flt'  => [ 'FLOAT', static fn ( int $i ): string => sprintf( '%.9g', ( $i - 1000 ) / 7 ), [ '-3e38', '-2e38' ] ],
			'dt6'  => [ 'DATETIME(6)', static fn ( int $i ): string => "'" . gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 3700 ) . sprintf( '.%06d', $i ) . "'", [ "'1000-01-01 00:00:00.000000'", "'1000-01-01 00:00:00.000001'" ] ],
			'ts'   => [ 'TIMESTAMP', static fn ( int $i ): string => 'FROM_UNIXTIME(' . ( $start + $i * 3601 ) . ')', [ 'FROM_UNIXTIME(1)', 'FROM_UNIXTIME(2)' ] ],
			'tm'   => [ 'TIME(3)', static fn ( int $i ): string => sprintf( 'SEC_TO_TIME(%d.%03d)', ( $i - 1000 ) * 2801, $i % 1000 ), [ "'-838:59:59.000'", "'-838:59:58.999'" ] ],
			'yr'   => [ 'YEAR', static fn ( int $i ): string => (string) ( 1901 + $i % 250 ), [ "'0000'", "'0000'" ], true ],
			'en'   => [ "ENUM('zeta','alpha','mid','beta')", static fn ( int $i ): string => "'" . [ 'zeta', 'alpha', 'mid', 'beta' ][ $i % 4 ] . "'", [ "'zeta'", "'zeta'" ], true ],
			'st'   => [ "SET('b','a','c')", static fn ( int $i ): string => "'" . [ '', 'b', 'a', 'b,a', 'c', 'b,c', 'a,c', 'b,a,c' ][ $i % 8 ] . "'", [ "''", "''" ], true ],
			'bt'   => [ 'BIT(12)', static fn ( int $i ): string => (string) ( $i + 2 ), [ '0', '1' ] ],
			'bin'  => [ 'BINARY(16)', static fn ( int $i ): string => '0x' . md5( (string) $i ), [ '0x00000000000000000000000000000000', '0x00000000000000000000000000000001' ] ],
			'vbin' => [ 'VARBINARY(20)', static fn ( int $i ): string => '0x' . bin2hex( chr( $i >> 8 ) . chr( $i & 255 ) . substr( md5( (string) $i, true ), 0, $i % 9 ) ), [ "''", '0x00' ] ],
			'cmp'  => [ 'DATE', static fn ( int $i ): string => "'" . gmdate( 'Y-m-d', 1700000000 + ( $i % 150 ) * 86400 ) . "'", [ "'1000-01-01'", "'1000-01-01'" ], true ],
		];
		$expected = [];
		foreach ( $types as $name => $definition ) {
			[ $type, $value ] = $definition;
			$composite        = $definition[3] ?? false;
			$db->execute( sprintf( 'CREATE TABLE kt_%s (k %s NOT NULL, n INT NOT NULL, filler VARCHAR(500) NOT NULL, PRIMARY KEY (k%s)) ENGINE=InnoDB', $name, $type, $composite ? ', n' : '' ) );
			$rows = [];
			for ( $i = 0; $i < 2000; ++$i ) {
				$rows[] = '(' . $value( $i ) . ', ' . $i . ", '" . $filler . "')";
			}
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				$db->execute( 'INSERT INTO kt_' . $name . ' VALUES ' . implode( ',', $chunk ) );
			}
			$expected[ $name ] = $db->rows( 'SELECT HEX(k) AS k, k + 0 AS v, n, filler FROM kt_' . $name . ' ORDER BY k, n' );
		}
		// The site's session reads in a zone with daylight saving time where the server has one;
		// the dump pages TIMESTAMP keys in UTC whatever it is.
		self::daylight_saving_zone( $db );

		$this->dump_and_import(
			$db,
			'kt_',
			static function ( string $file, int $part ) use ( $db, $types ): void {
				$name = substr( $file, 7, -4 );
				if ( 1 === $part ) {
					$db->execute( 'DELETE FROM kt_' . $name . ' ORDER BY k, n LIMIT 2' );
				} elseif ( 2 === $part ) {
					[ $first, $second ] = $types[ $name ][2];
					$db->execute( sprintf( "INSERT INTO kt_%s VALUES (%s, -2, 'new'), (%s, -1, 'new')", $name, $first, $second ) );
				}
			}
		);

		$target = self::database( self::$target );
		$target->execute( "SET SESSION time_zone = '+00:00'" );
		foreach ( array_keys( $types ) as $name ) {
			self::assertSame( $expected[ $name ], $target->rows( 'SELECT HEX(k) AS k, k + 0 AS v, n, filler FROM kt_' . $name . ' ORDER BY k, n' ), 'kt_' . $name );
		}
		self::assertSame(
			[
				[ 'at' => (string) ( $start + 24 * 3601 ) ],
				[ 'at' => (string) ( $start + 25 * 3601 ) ],
			],
			$target->rows( 'SELECT UNIX_TIMESTAMP(k) AS at FROM kt_ts WHERE n IN (24, 25) ORDER BY n' ),
			'Both instants of the repeated hour, as seeded.'
		);
	}

	/**
	 * A transient that expires during a tick is deleted and set again with a new option_id,
	 * after the pages already read. On a connection of its own, the tick reads the table from
	 * one snapshot: the option appears once, with its value from the start of the tick.
	 */
	public function test_a_row_deleted_and_inserted_again_with_a_new_key_within_one_tick_is_read_once(): void {
		$db = self::database( self::$source );
		$db->execute( 'CREATE TABLE snap_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, UNIQUE KEY option_name (option_name)) ENGINE=InnoDB' );
		$values = [];
		for ( $i = 1; $i <= 3000; ++$i ) {
			$values[] = sprintf( "('%s', '%s')", 10 === $i ? '_transient_feed' : sprintf( 'option_%05d', $i ), str_repeat( 'v', 400 ) );
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$db->execute( 'INSERT INTO snap_options (option_name, option_value) VALUES ' . implode( ',', $chunk ) );
		}
		$expected = $db->rows( 'SELECT * FROM snap_options ORDER BY option_id' );

		$files = $this->dump_with_change(
			$db,
			'snap_',
			'010-snap_options.sql',
			static function () use ( $db ): void {
				$db->execute( "DELETE FROM snap_options WHERE option_name = '_transient_feed'" );
				$db->execute( "INSERT INTO snap_options (option_name, option_value) VALUES ('_transient_feed', 'renewed')" );
			}
		);

		self::assertSame( $expected, self::database( self::$target )->rows( 'SELECT * FROM snap_options ORDER BY option_id' ), 'The option is there once, as the snapshot saw it.' );
		$table = self::manifest_table( $files, 'snap_options' );
		self::assertTrue( $table['snapshot'], 'The manifest records that the rows come from one snapshot.' );
		self::assertSame( [ 'option_id' ], $table['key'] );
	}

	/**
	 * A table without a key, read with one statement on the dump's own connection: rows
	 * deleted while it is read are still in the dump, because the statement reads one snapshot.
	 * Read in several parts, or in pages through the site's connection, it is marked in the
	 * manifest and the backup warns.
	 */
	public function test_a_table_without_a_key_is_read_in_one_statement_or_marked_when_it_is_not(): void {
		$db = self::database( self::$source );
		$db->execute( 'CREATE TABLE nokey_log (at INT, message VARCHAR(700)) ENGINE=InnoDB' );
		$values = [];
		// About 3.7 MB: more than one statement of 1 MiB even after half of it is deleted.
		for ( $i = 1; $i <= 6000; ++$i ) {
			$values[] = sprintf( "(%d, '%s')", $i % 1000, str_repeat( 'm', 600 + $i % 7 ) );
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$db->execute( 'INSERT INTO nokey_log VALUES ' . implode( ',', $chunk ) );
		}
		$query    = 'SELECT at, message FROM nokey_log ORDER BY at, message';
		$expected = $db->rows( $query );

		$files = $this->dump_with_change( $db, 'nokey_', '010-nokey_log.sql', static fn () => $db->execute( 'DELETE FROM nokey_log WHERE at < 500' ) );

		self::assertSame( $expected, self::database( self::$target )->rows( $query ), 'Every row, duplicates included, as the statement saw them.' );
		$table = self::manifest_table( $files, 'nokey_log' );
		self::assertSame( [], $table['key'] );
		self::assertTrue( $table['snapshot'] );
		self::assertSame( [], $this->warnings );

		foreach ( [
			'several parts on its own connection' => self::dedicated(),
			'pages through the site connection'   => null,
		] as $case => $dedicated ) {
			$this->warnings = [];
			$manifest       = $this->dump_and_import( $db, 'nokey_', null, $dedicated );
			$entry          = array_values( array_filter( $manifest['tables'], static fn ( array $table ): bool => 'nokey_log' === $table['name'] ) )[0];
			self::assertFalse( $entry['snapshot'], $case );
			self::assertCount( 1, $this->warnings, $case );
			self::assertStringContainsString( 'nokey_log has no primary key', $this->warnings[0], $case );
			self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ), $case );
		}
	}

	/**
	 * A plugin update adds a column between two ticks. The CREATE already written has no such
	 * column, so the table starts over with the new definition instead of continuing with
	 * INSERT statements that the CREATE does not fit.
	 */
	#[DataProvider( 'connections' )]
	public function test_a_column_added_between_two_ticks_starts_the_table_over( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS pin_items' );
		$this->fill_items( $db, 'pin_items' );

		$manifest = $this->dump_and_import(
			$db,
			'pin_',
			static function ( string $file, int $part ) use ( $db ): void {
				if ( 1 === $part ) {
					$db->execute( 'ALTER TABLE pin_items ADD COLUMN added INT NOT NULL DEFAULT 7' );
				}
			},
			$dedicated ? self::dedicated() : null
		);

		$target = self::database( self::$target );
		// The columns as the server uses them; SHOW CREATE TABLE prints an inherited collation differently.
		self::assertSame( $db->rows( 'SHOW FULL COLUMNS FROM pin_items' ), $target->rows( 'SHOW FULL COLUMNS FROM pin_items' ) );
		self::assertSame( $db->rows( 'SELECT * FROM pin_items ORDER BY id' ), $target->rows( 'SELECT * FROM pin_items ORDER BY id' ) );
		self::assertSame( 1, $manifest['tables'][0]['restarts'] );
	}

	public function test_a_table_that_changes_its_definition_at_every_tick_stops_the_dump_with_its_own_code(): void {
		$db = self::database( self::$source );
		$this->fill_items( $db, 'unstable_items' );
		try {
			$this->dump_and_import(
				$db,
				'unstable_',
				static fn ( string $file, int $part ) => $db->execute( 'ALTER TABLE unstable_items ADD COLUMN c' . $part . ' INT NOT NULL DEFAULT 0' ),
				self::dedicated()
			);
			self::fail( 'A table whose definition never holds cannot be backed up.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_unstable', JobError::from_throwable( $error )->code );
			self::assertSame( 'unstable_items', $error->context()['table'] );
		}
	}

	/**
	 * On the site's connection nothing holds the definition during a tick: a column added
	 * between two pages of one tick is found before the trailer, and the next tick starts
	 * the table over.
	 */
	public function test_on_the_site_connection_a_change_within_a_tick_starts_the_table_over(): void {
		$db = self::database( self::$source );
		$this->fill_items( $db, 'within_items' );

		$files = $this->dump_with_change( $db, 'within_', '010-within_items.sql', static fn () => $db->execute( 'ALTER TABLE within_items ADD COLUMN added INT NOT NULL DEFAULT 7' ), false );

		$target = self::database( self::$target );
		// The columns as the server uses them; SHOW CREATE TABLE prints an inherited collation differently.
		self::assertSame( $db->rows( 'SHOW FULL COLUMNS FROM within_items' ), $target->rows( 'SHOW FULL COLUMNS FROM within_items' ) );
		self::assertSame( $db->rows( 'SELECT * FROM within_items ORDER BY id' ), $target->rows( 'SELECT * FROM within_items ORDER BY id' ) );
		self::assertSame( 1, self::manifest_table( $files, 'within_items' )['restarts'] );
	}

	/**
	 * On a connection of its own, the tick holds the table's metadata lock from before the
	 * definition is read until the tick ends: an ALTER waits, here until its time runs out.
	 */
	public function test_on_its_own_connection_a_tick_keeps_the_definition_of_its_table(): void {
		$db = self::database( self::$source );
		$this->fill_items( $db, 'locked_items' );
		$other = self::database( self::$source );
		$other->execute( 'SET SESSION lock_wait_timeout = 1' );
		$waited = null;

		$files = $this->dump_with_change(
			$db,
			'locked_',
			'010-locked_items.sql',
			static function () use ( $other, &$waited ): void {
				try {
					$other->execute( 'ALTER TABLE locked_items ADD COLUMN added INT NOT NULL DEFAULT 7' );
					$waited = false;
				} catch ( \RuntimeException $error ) {
					$waited = str_contains( $error->getMessage(), 'Lock wait timeout' );
				}
			}
		);

		self::assertTrue( $waited, 'The ALTER had to wait for the end of the tick.' );
		self::assertSame( $db->rows( 'SELECT * FROM locked_items ORDER BY id' ), self::database( self::$target )->rows( 'SELECT * FROM locked_items ORDER BY id' ) );
		self::assertSame( 0, self::manifest_table( $files, 'locked_items' )['restarts'] );
		self::assertTrue( self::manifest_table( $files, 'locked_items' )['snapshot'] );
	}

	/**
	 * A LONGBLOB of 8.5 MiB is 17 MiB as a hex literal, more than the plugin's importer reads
	 * in one statement. The backup keeps it, as a statement of its own, and the manifest
	 * records the size, so that an importer can tell what max_allowed_packet it needs.
	 */
	public function test_a_row_above_16_mib_is_backed_up_as_a_statement_of_its_own(): void {
		$db = self::database( self::$source );
		$db->execute( 'CREATE TABLE huge_rows (id INT NOT NULL PRIMARY KEY, body LONGBLOB) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO huge_rows VALUES (1, 'small'), (2, REPEAT(0xA5, 8912896)), (3, 'small too')" );

		$files = [];
		foreach ( ( new DatabaseDump( $db, $this->directory . '/huge.json', 'huge_', [], 1767323100 ) )->files() as $file ) {
			$files[ $file->name ] = $this->read( $file->source, 1 );
		}

		$lines = explode( "\n", rtrim( $files['010-huge_rows.sql'], "\n" ) );
		$large = array_values( array_filter( $lines, static fn ( string $line ): bool => strlen( $line ) > TableSource::MAX_STATEMENT_BYTES ) );
		self::assertCount( 1, $large, 'One statement holds the large row alone.' );
		self::assertStringStartsWith( 'INSERT INTO `huge_rows` (`id`,`body`) VALUES (2,0x', $large[0] );
		// The digest of the stored bytes, not of HEX(body): MariaDB returns NULL for HEX() of a
		// value longer than half of max_allowed_packet, which is 16 MiB there by default.
		$digest = hash_init( 'sha256' );
		$end    = strlen( $large[0] ) - 2;
		for ( $at = strlen( 'INSERT INTO `huge_rows` (`id`,`body`) VALUES (2,0x' ); $at < $end; $at += 1048576 ) {
			hash_update( $digest, (string) hex2bin( substr( $large[0], $at, min( 1048576, $end - $at ) ) ) );
		}
		self::assertSame( $db->rows( 'SELECT SHA2(body, 256) AS digest FROM huge_rows WHERE id = 2' )[0]['digest'], hash_final( $digest ) );
		$trailer = (string) end( $lines );
		self::assertSame( '-- end table rows=3 bytes=' . ( strlen( $files['010-huge_rows.sql'] ) - strlen( $trailer ) - 1 ), $trailer );
		self::assertSame( strlen( $large[0] ), self::manifest_table( $files, 'huge_rows' )['largest_statement'] );
	}

	#[DataProvider( 'connections' )]
	public function test_a_table_of_large_rows_is_read_a_page_that_fits_at_a_time_and_imports_unchanged( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS wide_rows' );
		$db->execute( 'CREATE TABLE wide_rows (id INT NOT NULL PRIMARY KEY, body LONGBLOB, note TEXT) ENGINE=InnoDB' );
		for ( $id = 1; $id <= 12; ++$id ) {
			$db->execute( sprintf( "INSERT INTO wide_rows VALUES (%d, REPEAT(0x%02X, 3145728), 'row %d')", $id, $id, $id ) );
		}
		$db->execute( "INSERT INTO wide_rows VALUES (13, NULL, NULL), (14, '', '')" );

		// 72 MiB of SQL: written to files as it is read, not kept.
		$sql = [];
		foreach ( ( new DatabaseDump( $db, $this->directory . '/wide.json', 'wide_', [], 1767323100, $dedicated ? self::dedicated() : null ) )->files() as $file ) {
			$this->read_to_file( $file->source, $this->directory . '/' . $file->name );
			if ( str_ends_with( $file->name, '.sql' ) ) {
				$sql[] = $this->directory . '/' . $file->name;
			}
		}
		MysqliConnection::import( self::$target, $sql );
		$manifest = json_decode( (string) file_get_contents( $this->directory . '/manifest.json' ), true );
		self::assertIsArray( $manifest );

		$query = 'SELECT id, SHA2(body, 256) AS body, note FROM wide_rows ORDER BY id';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ) );
		$tables = array_column( $manifest['tables'], null, 'name' );
		self::assertSame( 14, $tables['wide_rows']['rows'] ?? null );
	}

	public function test_on_its_own_connection_a_row_too_large_for_the_memory_limit_is_refused_before_it_is_read(): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS big_keyless' );
		$db->execute( 'CREATE TABLE big_keyless (body LONGBLOB) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO big_keyless VALUES ('small'), (REPEAT(0x5A, 6291456))" );
		$files = ( new DatabaseDump( $db, $this->directory . '/big.json', 'big_', [], 1767323100, self::dedicated() ) )->files();
		$table = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-big_keyless.sql' === $file->name ) )[0];
		$limit = (string) ini_get( 'memory_limit' );
		$seen  = (int) ( mysqli_get_client_stats()['bytes_received'] ?? 0 );
		// The row of 6 MiB with its 12 MiB of SQL and the reserve for reading it do not fit.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 24 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test lowers the limit for one read and restores it.
		try {
			$this->read( $table->source, PHP_INT_MAX );
			self::fail( 'The row does not fit.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_row_memory', JobError::from_throwable( $error )->code );
			self::assertSame( 'big_keyless', $error->context()['table'] ?? null );
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
		}
		self::assertLessThan( 1048576, (int) ( mysqli_get_client_stats()['bytes_received'] ?? 0 ) - $seen, 'The stream did not fetch the row.' );
	}

	#[DataProvider( 'connections' )]
	public function test_a_latin1_row_is_sized_as_the_utf8mb4_it_arrives_in( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS conv_rows' );
		$db->execute( 'CREATE TABLE conv_rows (id INT NOT NULL PRIMARY KEY, body LONGTEXT CHARACTER SET latin1) ENGINE=InnoDB' );
		// 5 MiB of latin1 "é" arrive as 10 MiB of UTF-8, and take 20 MiB as SQL.
		$db->execute( 'INSERT INTO conv_rows VALUES (1, REPEAT(0xE9, 5242880))' );
		$files = ( new DatabaseDump( $db, $this->directory . '/conv.json', 'conv_', [], 1767323100, $dedicated ? self::dedicated() : null ) )->files();
		$table = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-conv_rows.sql' === $file->name ) )[0];
		$limit = (string) ini_get( 'memory_limit' );
		$seen  = (int) ( mysqli_get_client_stats()['bytes_received'] ?? 0 );
		// Room for the stored 5 MiB with their SQL, not for what arrives.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 40 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test lowers the limit for one read and restores it.
		try {
			$this->read( $table->source, PHP_INT_MAX );
			self::fail( 'The row does not fit.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_row_memory', JobError::from_throwable( $error )->code );
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
		}
		self::assertLessThan( 1048576, (int) ( mysqli_get_client_stats()['bytes_received'] ?? 0 ) - $seen, 'The row was refused before it was fetched.' );
	}

	public function test_on_the_site_connection_a_row_that_grows_after_its_size_was_read_is_read_again(): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS grow_rows' );
		$db->execute( 'CREATE TABLE grow_rows (id INT NOT NULL PRIMARY KEY, body LONGBLOB) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO grow_rows VALUES (1, 'one'), (2, 'two'), (3, 'three')" );
		$writer = self::database( self::$source );
		// The site's connection, which grows the second row to 6 MiB right after the first read
		// of sizes, and counts those reads. As SQL the row takes 12 MiB, within the 16 MiB that
		// MariaDB allows a statement by default.
		$site  = new class( $db, $writer ) implements Connection {
			public int $sizes = 0;

			public function __construct( private readonly MysqliConnection $db, private readonly MysqliConnection $writer ) {
			}

			public function rows( string $sql ): array {
				$rows = $this->db->rows( $sql );
				if ( str_contains( $sql, ' AS size FROM `grow_rows`' ) && 1 === ++$this->sizes ) {
					$this->writer->execute( 'UPDATE grow_rows SET body = REPEAT(0xA5, 6291456) WHERE id = 2' );
				}
				return $rows;
			}
		};
		$files = ( new DatabaseDump( $site, $this->directory . '/grow.json', 'grow_', [], 1767323100 ) )->files();
		$table = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-grow_rows.sql' === $file->name ) )[0];
		$limit = (string) ini_get( 'memory_limit' );
		// 48 MiB besides the reserve: with its SQL, a row may take a third of it as one of three
		// rows, about 5.3 MiB, and all of it on its own, about 16 MiB.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 64 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit for one read and restores it.
		try {
			$this->read_to_file( $table->source, $this->directory . '/010-grow_rows.sql' );
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
		}
		MysqliConnection::import( self::$target, [ $this->directory . '/010-grow_rows.sql' ] );

		$query = 'SELECT id, SHA2(body, 256) AS body FROM grow_rows ORDER BY id';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ), 'The grown row is backed up as it is now.' );
		self::assertGreaterThan( 1, $site->sizes, 'The page ended before the grown row, whose size was read again.' );
	}

	public function test_a_table_without_a_key_is_read_without_a_scan_for_its_largest_row(): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS scan_rows' );
		$db->execute( 'CREATE TABLE scan_rows (n INT, body LONGTEXT) ENGINE=InnoDB' );
		$values = [];
		for ( $i = 1; $i <= 600; ++$i ) {
			$values[] = sprintf( "(%d, REPEAT('s', %d))", $i, $i * 10 );
		}
		$db->execute( 'INSERT INTO scan_rows VALUES ' . implode( ',', $values ) );
		$session = null;
		$open    = static function ( ?string $time_zone ) use ( &$session ): DedicatedConnection {
			$session = self::database( self::$source );
			return new DedicatedConnection( $session->mysqli, $time_zone );
		};
		$files   = ( new DatabaseDump( $db, $this->directory . '/scan.json', 'scan_', [], 1767323100, $open ) )->files();
		$table   = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-scan_rows.sql' === $file->name ) )[0];

		// In one part, so that the session the rows came from is still open.
		$this->read( $table->source, PHP_INT_MAX );

		self::assertInstanceOf( MysqliConnection::class, $session );
		$scanned = (int) ( $session->rows( "SHOW SESSION STATUS LIKE 'Handler_read_rnd_next'" )[0]['Value'] ?? 0 );
		self::assertLessThan( 2 * 600, $scanned, 'The rows were read once, not once more to find the largest.' );
		self::assertGreaterThanOrEqual( 600, $scanned );
	}

	public function test_on_its_own_connection_two_large_rows_in_a_row_are_backed_up_one_after_the_other(): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS pair_rows' );
		$db->execute( 'CREATE TABLE pair_rows (body LONGBLOB) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO pair_rows VALUES ('first'), (REPEAT(0x5A, 6291456)), (REPEAT(0x5B, 6291456)), ('last')" );
		$files = ( new DatabaseDump( $db, $this->directory . '/pair.json', 'pair_', [], 1767323100, self::dedicated() ) )->files();
		$table = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-pair_rows.sql' === $file->name ) )[0];
		$limit = (string) ini_get( 'memory_limit' );
		// Room for one row of 6 MiB with its 12 MiB of SQL and the reserve, not for two such rows.
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 37 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test lowers the limit for one read and restores it.
		try {
			$this->read_to_file( $table->source, $this->directory . '/010-pair_rows.sql' );
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit of the test run.
		}
		MysqliConnection::import( self::$target, [ $this->directory . '/010-pair_rows.sql' ] );
		$query = 'SELECT SHA2(body, 256) AS body FROM pair_rows ORDER BY body';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ) );
	}

	#[DataProvider( 'connections' )]
	public function test_timestamps_of_both_halves_of_the_year_import_unchanged_under_a_daylight_saving_zone( bool $dedicated ): void {
		$db   = self::database( self::$source );
		$zone = self::daylight_saving_zone( $db );
		if ( null === $zone ) {
			self::markTestSkipped( 'The server knows no time zone with daylight saving time: no time zone tables, and a SYSTEM zone without it.' );
		}
		$db->execute( 'DROP TABLE IF EXISTS tz_events' );
		$db->execute( 'CREATE TABLE tz_events (id INT PRIMARY KEY, at TIMESTAMP(6) NULL, plain TIMESTAMP NULL)' );
		$instants = [
			1 => gmmktime( 10, 0, 0, 1, 15, 2026 ),
			2 => gmmktime( 10, 0, 0, 7, 15, 2026 ),
			// 02:30 local time twice in Berlin: before and after the clocks go back.
			3 => gmmktime( 0, 30, 0, 10, 25, 2026 ),
			4 => gmmktime( 1, 30, 0, 10, 25, 2026 ),
		];
		// Seeded in UTC: FROM_UNIXTIME() in the zone would give both instants of the repeated
		// hour one local time, and the insert would store one of them twice.
		$db->execute( "SET SESSION time_zone = '+00:00'" );
		foreach ( $instants as $id => $instant ) {
			$db->execute( sprintf( 'INSERT INTO tz_events VALUES (%d, FROM_UNIXTIME(%d.250000), FROM_UNIXTIME(%d))', $id, $instant, $instant ) );
		}
		$db->execute( 'INSERT INTO tz_events VALUES (5, NULL, NULL)' );
		$db->execute( "SET SESSION time_zone = '" . $zone . "'" );
		$session  = 'SELECT @@SESSION.time_zone AS zone';
		$before   = $db->rows( $session );
		$query    = 'SELECT id, UNIX_TIMESTAMP(at) AS at, UNIX_TIMESTAMP(plain) AS plain FROM tz_events ORDER BY id';
		$expected = [];
		foreach ( $instants as $id => $instant ) {
			$expected[] = [
				'id'    => (string) $id,
				'at'    => $instant . '.250000',
				'plain' => (string) $instant,
			];
		}
		$expected[] = [
			'id'    => '5',
			'at'    => null,
			'plain' => null,
		];
		self::assertSame( $expected, $db->rows( $query ), 'The table holds the instants it was given.' );

		$manifest = $this->dump_and_import( $db, 'tz_', null, $dedicated ? self::dedicated() : null );

		self::assertSame( $expected, self::database( self::$target )->rows( $query ), 'Every instant survives, including both of the repeated autumn hour.' );
		self::assertSame( '+00:00', $manifest['time_zone'] );
		self::assertStringContainsString( "SET SESSION time_zone = '+00:00';\n", (string) file_get_contents( $this->directory . '/010-tz_events.sql' ) );
		self::assertSame( $before, $db->rows( $session ), 'The site connection keeps its time zone.' );
	}

	#[DataProvider( 'connections' )]
	public function test_a_latin1_connection_dumps_and_restores_the_stored_bytes_of_latin1_and_utf8mb4_columns( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS cs_rows' );
		$db->execute( 'CREATE TABLE cs_rows (id INT PRIMARY KEY, legacy TEXT CHARACTER SET latin1, label VARCHAR(40) CHARACTER SET latin1, modern TEXT CHARACTER SET utf8mb4) DEFAULT CHARSET=utf8mb4' );
		$db->execute( "INSERT INTO cs_rows (id, modern) VALUES (1, '内容 Архив 😀 Åtgärd'), (2, NULL)" );
		// A site with DB_CHARSET latin1 sends UTF-8 bytes through a latin1 connection: the
		// latin1 columns store them byte for byte, "é" as C3 A9.
		$db->mysqli->set_charset( 'latin1' );
		$db->execute( "UPDATE cs_rows SET legacy = 'café Ål ünïcödé', label = 'naïve' WHERE id = 1" );
		$db->execute( "UPDATE cs_rows SET legacy = 0xe9ff00, label = '' WHERE id = 2" );
		$settings = 'SELECT @@SESSION.character_set_client AS client, @@SESSION.character_set_connection AS connection, @@SESSION.character_set_results AS results, @@SESSION.collation_connection AS collation';
		$before   = $db->rows( $settings );
		self::assertSame( 'latin1', $before[0]['results'] );

		$this->dump_and_import( $db, 'cs_', null, $dedicated ? self::dedicated() : null );

		$query = 'SELECT id, HEX(legacy) AS legacy, HEX(label) AS label, HEX(modern) AS modern FROM cs_rows ORDER BY id';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ), 'Both columns hold the same bytes after the import.' );
		self::assertSame( bin2hex( 'café Ål ünïcödé' ), strtolower( (string) $db->rows( $query )[0]['legacy'] ) );
		self::assertSame( $before, $db->rows( $settings ), 'The site connection keeps its character set.' );
	}

	#[DataProvider( 'connections' )]
	public function test_columns_named_with_digits_only_dump_and_import_unchanged( bool $dedicated ): void {
		$db = self::database( self::$source );
		// PHP turns a column name of digits into an integer array key.
		$db->execute( 'DROP TABLE IF EXISTS num_keyed, num_keyless' );
		$db->execute( 'CREATE TABLE num_keyed (`0` INT NOT NULL, `123` VARCHAR(20) NOT NULL, `-1` FLOAT, `07` VARCHAR(20), PRIMARY KEY (`0`, `123`)) ENGINE=InnoDB' );
		$db->execute( 'CREATE TABLE num_keyless (`1` INT, `2` VARCHAR(20)) ENGINE=InnoDB' );
		$values = [];
		for ( $i = 1; $i <= 1200; ++$i ) {
			$values[] = sprintf( "(%d, 'r%d', %d.5, '%d')", intdiv( $i, 3 ), $i, $i, $i );
		}
		$db->execute( 'INSERT INTO num_keyed VALUES ' . implode( ',', $values ) );
		$db->execute( 'INSERT INTO num_keyless SELECT `0`, `123` FROM num_keyed' );

		$manifest = $this->dump_and_import( $db, 'num_', null, $dedicated ? self::dedicated() : null );

		$target = self::database( self::$target );
		foreach ( [ 'SELECT * FROM num_keyed ORDER BY `0`, `123`', 'SELECT * FROM num_keyless ORDER BY `1`, `2`' ] as $query ) {
			self::assertSame( $db->rows( $query ), $target->rows( $query ), $query );
		}
		$tables = array_column( $manifest['tables'], null, 'name' );
		self::assertSame( [ '0', '123' ], $tables['num_keyed']['key'] ?? null, 'Pages followed the key over the columns 0 and 123.' );
	}

	#[DataProvider( 'connections' )]
	public function test_a_select_limit_left_on_a_session_cuts_no_read_short( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS lim_keyless, lim_a, lim_b, lim_c' );
		$db->execute( 'CREATE TABLE lim_keyless (a INT, b VARCHAR(20)) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO lim_keyless VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e')" );
		// More tables with triggers than the limit lets through.
		foreach ( [ 'lim_a', 'lim_b', 'lim_c' ] as $keyed ) {
			$db->execute( 'CREATE TABLE ' . $keyed . ' (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
			$db->execute( 'CREATE TRIGGER ' . $keyed . '_insert BEFORE INSERT ON ' . $keyed . ' FOR EACH ROW SET NEW.id = NEW.id' );
			$db->execute( 'INSERT INTO ' . $keyed . ' VALUES (1), (2), (3), (4), (5)' );
		}
		// A limit that a plugin can leave on the site's session, or that every new session
		// inherits from the server's settings.
		$db->execute( 'SET SESSION sql_select_limit = 2' );
		$open = static function ( ?string $time_zone ): DedicatedConnection {
			$mysqli = self::database( self::$source )->mysqli;
			$mysqli->query( 'SET SESSION sql_select_limit = 2' );
			return new DedicatedConnection( $mysqli, $time_zone );
		};

		$manifest = $this->dump_and_import( $db, 'lim_', null, $dedicated ? $open : null );

		$target = self::database( self::$target );
		foreach ( [ 'lim_keyless', 'lim_a', 'lim_b', 'lim_c' ] as $table ) {
			self::assertSame( [ [ 'n' => '5' ] ], $target->rows( 'SELECT COUNT(*) AS n FROM ' . $table ), $table );
		}
		self::assertSame( [ 'lim_a', 'lim_b', 'lim_c' ], $manifest['trigger_tables'], 'The grants and the triggers were read in full.' );
		self::assertSame( [ [ 'l' => '2' ] ], $db->rows( 'SELECT @@SESSION.sql_select_limit AS l' ), 'The site connection keeps its limit.' );
	}

	#[DataProvider( 'connections' )]
	public function test_a_table_that_another_connection_keeps_locked_stops_the_tick_for_a_later_try( bool $dedicated ): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS lock_rows' );
		$db->execute( 'CREATE TABLE lock_rows (id INT NOT NULL PRIMARY KEY, body VARCHAR(20)) ENGINE=InnoDB' );
		$db->execute( "INSERT INTO lock_rows VALUES (1, 'a'), (2, 'b')" );
		// Without a bound of the dump's own a session would wait this long for the lock.
		$db->execute( 'SET SESSION lock_wait_timeout = 30' );
		$open = static function ( ?string $time_zone ): DedicatedConnection {
			$mysqli = self::database( self::$source )->mysqli;
			$mysqli->query( 'SET SESSION lock_wait_timeout = 30' );
			return new DedicatedConnection( $mysqli, $time_zone, 1 );
		};
		// The site's connection as WordPress gives it to the dump.
		$site   = $dedicated ? $db : new WpdbConnection( new \wpdb( $db->mysqli, 'localhost', 'unused', 'unused', self::$source ) );
		$files  = ( new DatabaseDump( $site, $this->directory . '/lock.json', 'lock_', [], 1767323100, $dedicated ? $open : null ) )->files();
		$table  = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-lock_rows.sql' === $file->name ) )[0];
		$holder = self::database( self::$source );
		$holder->execute( 'LOCK TABLES lock_rows WRITE' );
		$started = microtime( true );
		try {
			$this->read( $table->source, PHP_INT_MAX );
			self::fail( 'The table is locked.' );
		} catch ( DatabaseFailed $error ) {
			$job_error = JobError::from_throwable( $error );
			self::assertSame( 'database_dump_locked', $job_error->code );
			self::assertTrue( $job_error->retryable, 'The job tries again later.' );
			self::assertSame( 'lock_rows', $error->context()['table'] ?? null );
		} finally {
			$holder->execute( 'UNLOCK TABLES' );
		}
		self::assertLessThan( 20, microtime( true ) - $started, 'The dump waits as long as it allows, not as the session would.' );
		self::assertSame( [ [ 'wait' => '30' ] ], $db->rows( 'SELECT @@SESSION.lock_wait_timeout AS wait' ), 'The site connection keeps its own wait.' );

		self::assertStringContainsString( "INSERT INTO `lock_rows` (`id`,`body`) VALUES (1,'a'),(2,'b');", $this->read( $table->source, PHP_INT_MAX ), 'The next try reads the table.' );
	}

	public function test_a_zero_filled_key_wider_than_any_integer_pages_by_its_value(): void {
		$db = self::database( self::$source );
		$db->execute( 'DROP TABLE IF EXISTS zf_rows' );
		// ZEROFILL pads to the display width: 21 and 65 characters here.
		$db->execute( 'CREATE TABLE zf_rows (k INT(21) UNSIGNED ZEROFILL NOT NULL, d DECIMAL(65,0) UNSIGNED ZEROFILL NOT NULL, body VARCHAR(100) NOT NULL, PRIMARY KEY (k, d)) ENGINE=InnoDB' );
		$values = [];
		for ( $i = 1; $i <= 1200; ++$i ) {
			$values[] = sprintf( "(%d, %d, 'row %d')", intdiv( $i, 2 ) * 7, $i, $i );
		}
		$db->execute( 'INSERT INTO zf_rows VALUES ' . implode( ',', $values ) );
		self::assertSame( [ [ 'k' => '000000000000000000007' ] ], $db->rows( 'SELECT k FROM zf_rows WHERE k > 0 ORDER BY k LIMIT 1' ) );

		$manifest = $this->dump_and_import( $db, 'zf_' );

		$query = 'SELECT * FROM zf_rows ORDER BY k, d';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ) );
		$tables = array_column( $manifest['tables'], null, 'name' );
		self::assertSame( 1200, $tables['zf_rows']['rows'] ?? null );
	}

	public function test_through_a_drop_in_that_reads_tables_elsewhere_the_dump_reads_nothing_of_them(): void {
		$primary = self::database( self::$source );
		$replica = self::database( self::$source );
		// A drop-in that sends statements naming a table to a second connection.
		$site  = new class( $primary, $replica ) implements Connection {
			/** @var list<string> */
			public array $routed = [];

			public function __construct( private readonly MysqliConnection $primary, private readonly MysqliConnection $replica ) {
			}

			public function rows( string $sql ): array {
				if ( str_contains( $sql, ' FROM `' ) ) {
					$this->routed[] = $sql;
					return $this->replica->rows( $sql );
				}
				return $this->primary->rows( $sql );
			}
		};
		$files = ( new DatabaseDump( $site, $this->directory . '/routed.json', 'bfr_', [], 1767323100 ) )->files();
		$table = array_values( array_filter( $files, static fn ( VirtualFile $file ): bool => '010-bfr_posts.sql' === $file->name ) )[0];
		try {
			$this->read( $table->source, PHP_INT_MAX );
			self::fail( 'The table would be read without the dump settings.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_session', JobError::from_throwable( $error )->code );
			self::assertSame( 'bfr_posts', $error->context()['table'] ?? null );
		}
		self::assertCount( 1, $site->routed, 'Only the read-back reached the second connection.' );
		self::assertStringContainsString( 'bfr_dump_one', $site->routed[0] );
	}

	public function test_definitions_with_cyrillic_cjk_and_nordic_text_import_unchanged(): void {
		$db = self::database( self::$source );
		// "х" is D1 85, "内" is E5 86 85 and "Å" is C3 85: the byte 0x85 must survive one_line().
		$db->execute( "CREATE TABLE utf_archive (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Архив', `Åtgärd` ENUM('内容','à la carte','Архив') NOT NULL DEFAULT '内容' COMMENT '内容 Архив', `note` VARCHAR(40) NOT NULL DEFAULT 'Åtgärd: архив', KEY `Åtgärd_index` (`Åtgärd`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Архив 内容'" );
		$db->execute( "INSERT INTO utf_archive (`Åtgärd`, note) VALUES ('Архив', 'х'), ('à la carte', DEFAULT), (DEFAULT, '内')" );

		$this->dump_and_import( $db, 'utf_' );

		$target = self::database( self::$target );
		self::assertSame( $db->rows( 'SHOW CREATE TABLE utf_archive' ), $target->rows( 'SHOW CREATE TABLE utf_archive' ) );
		self::assertSame( $db->rows( 'SELECT * FROM utf_archive ORDER BY id' ), $target->rows( 'SELECT * FROM utf_archive ORDER BY id' ) );
	}

	/**
	 * A table of 3,000 rows of about 500 bytes, read in several parts with a stop after every read.
	 */
	private function fill_items( MysqliConnection $db, string $table ): void {
		$db->execute( 'CREATE TABLE ' . $table . ' (id INT NOT NULL PRIMARY KEY, body VARCHAR(500) NOT NULL) ENGINE=InnoDB' );
		$values = [];
		for ( $i = 1; $i <= 3000; ++$i ) {
			$values[] = sprintf( "(%d, '%s')", $i, str_repeat( 'i', 480 ) );
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$db->execute( 'INSERT INTO ' . $table . ' VALUES ' . implode( ',', $chunk ) );
		}
	}

	/**
	 * Dumps the tables with a prefix in one tick, on a connection of the dump's own unless
	 * $dedicated is false. One table is changed after its first block was read, while its
	 * source is still open, and the files are imported into the target database.
	 *
	 * @param \Closure(): mixed $change Changes the table.
	 * @return array<string, string> File contents by name.
	 */
	private function dump_with_change( MysqliConnection $db, string $prefix, string $changed, \Closure $change, bool $dedicated = true ): array {
		$files = [];
		foreach ( ( new DatabaseDump( $db, $this->directory . '/' . $prefix . 'change.json', $prefix, [], 1767323100, $dedicated ? self::dedicated() : null ) )->files() as $file ) {
			$files[ $file->name ] = $this->read( $file->source, PHP_INT_MAX, null, $changed === $file->name ? static fn () => $change() : null );
		}
		foreach ( $files as $name => $content ) {
			if ( str_ends_with( $name, '.sql' ) ) {
				file_put_contents( $this->directory . '/' . $name, $content );
			}
		}
		MysqliConnection::import( self::$target, array_map( fn ( string $name ): string => $this->directory . '/' . $name, array_values( array_filter( array_keys( $files ), static fn ( string $name ): bool => str_ends_with( $name, '.sql' ) ) ) ) );
		return $files;
	}

	/**
	 * A table's entry in the manifest of dumped files.
	 *
	 * @param array<string, string> $files File contents by name.
	 * @return array<string, mixed> Entry.
	 */
	private static function manifest_table( array $files, string $name ): array {
		$manifest = json_decode( $files['manifest.json'], true );
		self::assertIsArray( $manifest );
		foreach ( $manifest['tables'] as $table ) {
			if ( $name === $table['name'] ) {
				return $table;
			}
		}
		self::fail( 'The manifest does not list ' . $name . '.' );
	}

	/**
	 * Dumps the tables with a prefix in pages with a stop after every read, writes the files
	 * and imports them into the target database with the command-line client.
	 *
	 * @param (\Closure(string, int): void)|null $between   Runs between two parts of a table file, with its name and the part number.
	 * @param \Closure|null                      $dedicated Opens the dump's own connection, or null for the site connection only.
	 * @return array<string, mixed> The decoded manifest.
	 */
	private function dump_and_import( Connection $db, string $prefix, ?\Closure $between = null, ?\Closure $dedicated = null ): array {
		$files = [];
		foreach ( ( new DatabaseDump( $db, $this->directory . '/' . $prefix . 'state.json', $prefix, [], 1767323100, $dedicated ) )->files() as $file ) {
			$files[ $file->name ] = $this->read( $file->source, 1, null === $between ? null : static fn ( int $part ) => $between( $file->name, $part ) );
			if ( str_ends_with( $file->name, '.sql' ) ) {
				file_put_contents( $this->directory . '/' . $file->name, $files[ $file->name ] );
			}
		}
		$sql = array_map( fn ( string $name ): string => $this->directory . '/' . $name, array_filter( array_keys( $files ), static fn ( string $name ): bool => str_ends_with( $name, '.sql' ) ) );
		MysqliConnection::import( self::$target, array_values( $sql ) );
		$manifest = json_decode( $files['manifest.json'], true );
		self::assertIsArray( $manifest );
		return $manifest;
	}

	/**
	 * A time zone whose offset differs between January and July: a named one where the server
	 * has time zone tables, otherwise its system zone if that has daylight saving time.
	 */
	private static function daylight_saving_zone( MysqliConnection $db ): ?string {
		foreach ( [ 'Europe/Berlin', 'SYSTEM' ] as $zone ) {
			try {
				$db->execute( "SET SESSION time_zone = '" . $zone . "'" );
			} catch ( \RuntimeException ) {
				continue;
			}
			$offsets = $db->rows( sprintf( 'SELECT TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(%1$d), CONVERT_TZ(FROM_UNIXTIME(%1$d), @@SESSION.time_zone, \'+00:00\')) AS winter, TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(%2$d), CONVERT_TZ(FROM_UNIXTIME(%2$d), @@SESSION.time_zone, \'+00:00\')) AS summer', gmmktime( 12, 0, 0, 1, 15, 2026 ), gmmktime( 12, 0, 0, 7, 15, 2026 ) ) );
			if ( null !== $offsets[0]['winter'] && $offsets[0]['winter'] !== $offsets[0]['summer'] ) {
				return $zone;
			}
		}
		return null;
	}

	/**
	 * Reads a virtual file like the backup does, asking a resumable source to stop after the
	 * given number of reads and continuing with its cursor. As the engine does, it drops what
	 * it has when a source starts over, and keeps the warnings of the complete source.
	 *
	 * @param \Closure(?string): \BackupsForRestic\Support\ByteSource $open    Source factory.
	 * @param (\Closure(int): void)|null                               $between Runs after each part that stopped, with its number.
	 * @param (\Closure(): void)|null                                  $during  Runs once, after the first block, while the first source is open.
	 */
	private function read( \Closure $open, int $reads_per_tick, ?\Closure $between = null, ?\Closure $during = null ): string {
		$content = '';
		$cursor  = null;
		$part    = 0;
		do {
			$source = $open( $cursor );
			if ( null !== $cursor && $source instanceof ResumableByteSource && method_exists( $source, 'restarted' ) && $source->restarted() ) {
				$content = '';
			}
			$reads = 0;
			for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
				$content .= $block;
				if ( null !== $during ) {
					$during();
					$during = null;
				}
				if ( ++$reads === $reads_per_tick && $source instanceof ResumableByteSource ) {
					$source->stop_at_boundary();
				}
			}
			$cursor = $source instanceof ResumableByteSource ? $source->cursor() : null;
			if ( null !== $cursor && null !== $between ) {
				$between( ++$part );
			}
		} while ( null !== $cursor );
		if ( $source instanceof ResumableByteSource && method_exists( $source, 'warnings' ) ) {
			array_push( $this->warnings, ...$source->warnings() );
		}
		return $content;
	}

	/**
	 * Reads a virtual file as read() does with a stop after every read, into a file instead of
	 * a string, for files too large to hold in the memory limit of the test run.
	 *
	 * @param \Closure(?string): \BackupsForRestic\Support\ByteSource $open Source factory.
	 */
	private function read_to_file( \Closure $open, string $path ): void {
		$handle = fopen( $path, 'wb' );
		self::assertIsResource( $handle );
		$cursor = null;
		do {
			$source = $open( $cursor );
			if ( null !== $cursor && $source instanceof ResumableByteSource && $source->restarted() ) {
				ftruncate( $handle, 0 );
				rewind( $handle );
			}
			$reads = 0;
			for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
				fwrite( $handle, $block );
				if ( 1 === ++$reads && $source instanceof ResumableByteSource ) {
					$source->stop_at_boundary();
				}
			}
			$cursor = $source instanceof ResumableByteSource ? $source->cursor() : null;
		} while ( null !== $cursor );
		fclose( $handle );
	}

	/**
	 * Describes the first row and column that differ, for a readable failure.
	 */
	private function first_difference( MysqliConnection $source, MysqliConnection $target, string $table ): string {
		$expected = $source->rows( 'SELECT * FROM ' . $table );
		$actual   = $target->rows( 'SELECT * FROM ' . $table );
		if ( count( $expected ) !== count( $actual ) ) {
			return sprintf( '%d rows instead of %d', count( $actual ), count( $expected ) );
		}
		foreach ( $expected as $index => $row ) {
			foreach ( $row as $column => $value ) {
				if ( $value !== ( $actual[ $index ][ $column ] ?? null ) ) {
					return sprintf( 'row %d, column %s: %s instead of %s', $index, $column, var_export( $actual[ $index ][ $column ] ?? null, true ), var_export( $value, true ) );
				}
			}
		}
		$create_source = $source->rows( 'SHOW CREATE TABLE ' . $table )[0]['Create Table'] ?? '';
		$create_target = $target->rows( 'SHOW CREATE TABLE ' . $table )[0]['Create Table'] ?? '';
		return $create_source === $create_target ? 'rows are equal' : "structure differs:\n" . $create_source . "\n" . $create_target;
	}
}
