<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\TempDir;

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

	public function test_dump_imports_with_the_mariadb_client_into_identical_tables(): void {
		$dump  = new DatabaseDump( self::database( self::$source ), $this->directory . '/state.json', 'bfr_', [], 1767323100 );
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

	public function test_timestamps_of_both_halves_of_the_year_import_unchanged_under_a_daylight_saving_zone(): void {
		$db   = self::database( self::$source );
		$zone = self::daylight_saving_zone( $db );
		if ( null === $zone ) {
			self::markTestSkipped( 'The server knows no time zone with daylight saving time: no time zone tables, and a SYSTEM zone without it.' );
		}
		$db->execute( 'CREATE TABLE tz_events (id INT PRIMARY KEY, at TIMESTAMP(6) NULL, plain TIMESTAMP NULL)' );
		$instants = [
			1 => gmmktime( 10, 0, 0, 1, 15, 2026 ),
			2 => gmmktime( 10, 0, 0, 7, 15, 2026 ),
			// 02:30 local time twice in Berlin: before and after the clocks go back.
			3 => gmmktime( 0, 30, 0, 10, 25, 2026 ),
			4 => gmmktime( 1, 30, 0, 10, 25, 2026 ),
		];
		foreach ( $instants as $id => $instant ) {
			$db->execute( sprintf( 'INSERT INTO tz_events VALUES (%d, FROM_UNIXTIME(%d.250000), FROM_UNIXTIME(%d))', $id, $instant, $instant ) );
		}
		$db->execute( 'INSERT INTO tz_events VALUES (5, NULL, NULL)' );
		$session = 'SELECT @@SESSION.time_zone AS zone';
		$before  = $db->rows( $session );

		$manifest = $this->dump_and_import( $db, 'tz_' );

		$query = 'SELECT id, UNIX_TIMESTAMP(at) AS at, UNIX_TIMESTAMP(plain) AS plain FROM tz_events ORDER BY id';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ), 'Every instant survives, including both of the repeated autumn hour.' );
		self::assertSame( (string) $instants[2], self::database( self::$target )->rows( $query )[1]['plain'] );
		self::assertSame( '+00:00', $manifest['time_zone'] );
		self::assertStringContainsString( "SET SESSION time_zone = '+00:00';\n", (string) file_get_contents( $this->directory . '/010-tz_events.sql' ) );
		self::assertSame( $before, $db->rows( $session ), 'The site connection keeps its time zone.' );
	}

	public function test_a_latin1_connection_dumps_and_restores_the_stored_bytes_of_latin1_and_utf8mb4_columns(): void {
		$db = self::database( self::$source );
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

		$this->dump_and_import( $db, 'cs_' );

		$query = 'SELECT id, HEX(legacy) AS legacy, HEX(label) AS label, HEX(modern) AS modern FROM cs_rows ORDER BY id';
		self::assertSame( $db->rows( $query ), self::database( self::$target )->rows( $query ), 'Both columns hold the same bytes after the import.' );
		self::assertSame( bin2hex( 'café Ål ünïcödé' ), strtolower( (string) $db->rows( $query )[0]['legacy'] ) );
		self::assertSame( $before, $db->rows( $settings ), 'The site connection keeps its character set.' );
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
	 * Dumps the tables with a prefix in pages with a stop after every read, writes the files
	 * and imports them into the target database with the command-line client.
	 *
	 * @return array<string, mixed> The decoded manifest.
	 */
	private function dump_and_import( MysqliConnection $db, string $prefix ): array {
		$files = [];
		foreach ( ( new DatabaseDump( $db, $this->directory . '/' . $prefix . 'state.json', $prefix, [], 1767323100 ) )->files() as $file ) {
			$files[ $file->name ] = $this->read( $file->source, 1 );
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
	 * given number of reads and continuing with its cursor.
	 *
	 * @param \Closure(?string): \BackupsForRestic\Support\ByteSource $open Source factory.
	 */
	private function read( \Closure $open, int $reads_per_tick ): string {
		$content = '';
		$cursor  = null;
		do {
			$source = $open( $cursor );
			$reads  = 0;
			for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
				$content .= $block;
				if ( ++$reads === $reads_per_tick && $source instanceof ResumableByteSource ) {
					$source->stop_at_boundary();
				}
			}
			$cursor = $source instanceof ResumableByteSource ? $source->cursor() : null;
		} while ( null !== $cursor );
		return $content;
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
