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
