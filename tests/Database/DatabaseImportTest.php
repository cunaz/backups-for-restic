<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\MysqliConnection as RestoreConnection;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\StagedDump;
use BackupsForRestic\Tests\Support\TempDir;

/** Real transactions, DDL and killed processes on both database engines in CI. */
final class DatabaseImportTest extends TestCase {

	private static ?MysqliConnection $admin = null;
	private MysqliConnection $db;
	private string $database  = '';
	private string $directory = '';
	private string $token;

	public static function setUpBeforeClass(): void {
		self::$admin = MysqliConnection::connect();
		if ( null === self::$admin ) {
			self::markTestSkipped( 'No MySQL or MariaDB server is reachable.' );
		}
	}

	protected function setUp(): void {
		$this->database = 'bfr_import_' . bin2hex( random_bytes( 6 ) );
		self::$admin?->execute( 'CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$db = MysqliConnection::connect( $this->database );
		self::assertNotNull( $db );
		$this->db        = $db;
		$this->directory = TempDir::create( 'database-import' );
		$this->token     = bin2hex( random_bytes( 16 ) );
	}

	protected function tearDown(): void {
		self::$admin?->execute( 'DROP DATABASE IF EXISTS `' . $this->database . '`' );
		TempDir::remove( $this->directory );
	}

	private function importer( Manifest $manifest ): TableImporter {
		return new TableImporter( new RestoreConnection( $this->db->mysqli ), $manifest, $this->directory, $this->token );
	}

	private function finish( TableImporter $importer ): void {
		for ( $tick = 0; $tick < 100; ++$tick ) {
			if ( $importer->tick( 1 )['done'] ) {
				return;
			}
		}
		self::fail( 'The bounded import did not complete in 100 units.' );
	}

	private function stage_dump(): Manifest {
		$dump = new DatabaseDump( $this->db, $this->directory . '/dump-state.json', 'wp_', [], 1767225600 );
		foreach ( $dump->files() as $file ) {
			$source = ( $file->source )( null );
			$handle = fopen( $this->directory . '/' . $file->name, 'wb' );
			for ( $bytes = $source->read( 65536 ); '' !== $bytes; $bytes = $source->read( 65536 ) ) {
				self::assertSame( strlen( $bytes ), fwrite( $handle, $bytes ) );
			}
			fclose( $handle );
		}
		return Manifest::parse( file_get_contents( $this->directory . '/manifest.json' ), 'wp_' );
	}

	public function test_real_dump_restores_table_contents_in_shadows_and_leaves_live_rows_alone(): void {
		$this->db->execute( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', time_zone = '+02:00'" );
		$this->db->execute( "CREATE TABLE wp_posts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(191), body LONGTEXT, data LONGBLOB, flag BIT(1), price DECIMAL(10,2), doubled DECIMAL(12,2) AS (price * 2) STORED, created TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, hidden INT INVISIBLE, CONSTRAINT positive_price CHECK (price >= 0)) ENGINE=InnoDB COMMENT='original comment'" );
		$values = [];
		for ( $i = 0; $i < 1500; ++$i ) {
			$body     = SqlFormatter::value( str_repeat( "row $i ; quote' \\ slash \n newline 😀 \0", 30 ), SqlFormatter::TEXT );
			$values[] = '(' . $i . ",'row " . $i . "'," . $body . ',0x00ff27,' . ( $i % 2 ) . ",1.25,'2020-02-03 04:05:06'," . ( $i + 100 ) . ')';
		}
		foreach ( array_chunk( $values, 100 ) as $batch ) {
			$this->db->execute( 'INSERT INTO wp_posts (id,title,body,data,flag,price,created,hidden) VALUES ' . implode( ',', $batch ) );
		}
		$this->db->execute( 'CREATE TABLE wp_nopk (a INT, b LONGTEXT) ENGINE=InnoDB' );
		$this->db->execute( "INSERT INTO wp_nopk VALUES (1,'duplicate'),(1,'duplicate'),(NULL,NULL)" );
		$this->db->execute( 'CREATE TABLE wp_empty (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$this->db->execute( "CREATE TABLE wp_oldengine (id INT, b CHAR(20)) ENGINE=MyISAM ROW_FORMAT=FIXED CHECKSUM=1 DELAY_KEY_WRITE=1 COMMENT='keep this'" );
		$this->db->execute( "INSERT INTO wp_oldengine VALUES (1,'one'),(2,NULL)" );
		$manifest = $this->stage_dump();
		$before   = [];
		foreach ( $manifest->tables as $table ) {
			$before[ $table['name'] ] = $this->db->rows( 'CHECKSUM TABLE `' . $table['name'] . '` EXTENDED' )[0]['Checksum'];
		}
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		foreach ( $manifest->tables as $number => $table ) {
			$shadow = $importer->tables->shadow( $number );
			if ( 'wp_oldengine' === $table['name'] ) {
				// CHECKSUM TABLE depends on physical row format, which differs across engines.
				self::assertSame( $this->db->rows( 'SELECT id,b FROM wp_oldengine ORDER BY id' ), $this->db->rows( 'SELECT id,b FROM `' . $shadow . '` ORDER BY id' ) );
			} else {
				self::assertSame( $before[ $table['name'] ], $this->db->rows( 'CHECKSUM TABLE `' . $shadow . '` EXTENDED' )[0]['Checksum'], $table['name'] );
			}
			self::assertSame( $before[ $table['name'] ], $this->db->rows( 'CHECKSUM TABLE `' . $table['name'] . '` EXTENDED' )[0]['Checksum'], 'The live table stays unchanged.' );
			if ( 'wp_posts' === $table['name'] ) {
				self::assertSame(
					[
						[
							'id'      => '0',
							'hidden'  => '100',
							'created' => '2020-02-03 04:05:06',
							'doubled' => '2.50',
						],
					],
					$this->db->rows( 'SELECT id, hidden, created, doubled FROM `' . $shadow . '` WHERE id=0' )
				);
			}
		}
		$ledger = $this->db->rows( 'SELECT original_engine, original_comment FROM `' . $importer->tables->progress() . '` WHERE id > 0 ORDER BY table_number' );
		self::assertContains(
			[
				'original_engine'  => 'MYISAM',
				'original_comment' => "'keep this'",
			],
			$ledger
		);
	}

	public function test_repeating_a_completed_import_preserves_every_row_and_its_ledger(): void {
		$manifest = StagedDump::write( $this->directory );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$rows_query   = 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '` ORDER BY a,b';
		$ledger_query = 'SELECT * FROM `' . $importer->tables->progress() . '` ORDER BY id';
		$rows         = $this->db->rows( $rows_query );
		$ledger       = $this->db->rows( $ledger_query );
		self::assertCount( 4, $rows );
		self::assertTrue( $importer->tick()['done'] );
		self::assertTrue( $this->importer( $manifest )->tick()['done'] );
		self::assertSame( $rows, $this->db->rows( $rows_query ) );
		self::assertSame( $ledger, $this->db->rows( $ledger_query ) );
	}

	public function test_generated_only_tables_restore_empty_tables_and_duplicate_rows(): void {
		$this->db->execute( 'CREATE TABLE wp_generated (g INT AS (42) STORED) ENGINE=InnoDB' );
		$this->db->execute( 'CREATE TABLE wp_generated_empty (g INT AS (42) STORED) ENGINE=InnoDB' );
		$this->db->execute( 'INSERT INTO wp_generated () VALUES (),(),()' );
		$importer = $this->importer( $this->stage_dump() );
		$this->finish( $importer );
		self::assertSame( [ [ 'g' => '42' ], [ 'g' => '42' ], [ 'g' => '42' ] ], $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
		self::assertSame( [], $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 1 ) . '`' ) );
	}

	public function test_session_settings_are_restored_after_a_tick(): void {
		$manifest = StagedDump::write( $this->directory );
		$this->db->execute( 'SET NAMES latin1' );
		$this->db->execute( "SET SESSION sql_mode='NO_BACKSLASH_ESCAPES,ANSI_QUOTES', time_zone='+04:00', unique_checks=0, foreign_key_checks=0" );
		$query  = 'SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results, @@collation_connection AS collation, @@sql_mode AS mode, @@time_zone AS zone, @@unique_checks AS unique_checks, @@foreign_key_checks AS foreign_key_checks';
		$before = $this->db->rows( $query );
		$this->importer( $manifest )->tick( 1 );
		self::assertSame( $before, $this->db->rows( $query ) );
	}

	public function test_an_active_foreign_transaction_is_neither_committed_nor_rolled_back(): void {
		$manifest = StagedDump::write( $this->directory );
		$this->db->execute( 'CREATE TABLE wp_live (id INT) ENGINE=InnoDB' );
		$this->db->execute( 'START TRANSACTION' );
		$this->db->execute( 'INSERT INTO wp_live VALUES (42)' );
		try {
			$this->importer( $manifest )->tick();
			self::fail( 'Import must refuse an already active transaction.' );
		} catch ( DatabaseFailed $expected ) {
			$other = MysqliConnection::connect( $this->database );
			self::assertNotNull( $other );
			self::assertSame( [], $other->rows( 'SELECT * FROM wp_live' ), 'The foreign row was not committed by DDL or START TRANSACTION.' );
			self::assertSame( [ [ 'id' => '42' ] ], $this->db->rows( 'SELECT * FROM wp_live' ), 'The foreign transaction was not rolled back either.' );
		} finally {
			$this->db->execute( 'ROLLBACK' );
		}
	}

	public function test_a_foreign_table_at_the_generated_name_is_never_dropped(): void {
		$manifest = StagedDump::write( $this->directory );
		$importer = $this->importer( $manifest );
		$name     = $importer->tables->shadow( 0 );
		$this->db->execute( 'CREATE TABLE `' . $name . '` (keep INT) ENGINE=InnoDB' );
		$this->db->execute( 'INSERT INTO `' . $name . '` VALUES (42)' );
		try {
			$importer->tick();
			self::fail( 'The colliding foreign table must be refused.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertSame( [ [ 'keep' => '42' ] ], $this->db->rows( 'SELECT * FROM `' . $name . '`' ) );
		}
	}

	public function test_a_truncation_warning_rolls_back_the_entire_batch_without_logging_values(): void {
		$sql      = str_replace( '(1,\'same\')', '(999,\'private-value\')', str_replace( '`a` int', '`a` tinyint', StagedDump::table() ) );
		$importer = $this->importer( StagedDump::write( $this->directory, $sql ) );
		$importer->tick( 1 );
		try {
			$importer->tick( 1 );
			self::fail( 'Truncated values must not count as a successful import.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertStringNotContainsString( 'private-value', $expected->getMessage() . json_encode( $expected->context() ) );
			self::assertNull( $expected->getPrevious() );
			self::assertSame( [], $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
			self::assertSame( '0', $this->db->rows( 'SELECT row_count FROM `' . $importer->tables->progress() . '` WHERE id=0' )[0]['row_count'] );
		}
	}

	public function test_an_insert_cannot_target_a_live_table(): void {
		$this->db->execute( 'CREATE TABLE wp_rows (a INT, b TEXT)' );
		$this->db->execute( "INSERT INTO wp_rows VALUES (42,'live')" );
		$sql = str_replace( 'INSERT INTO `wp_rows`', 'INSERT INTO `wp_users`', StagedDump::table() );
		try {
			$this->importer( StagedDump::write( $this->directory, $sql ) )->tick();
			self::fail( 'An unexpected INSERT destination must be refused.' );
		} catch ( FormatException $expected ) {
			self::assertSame(
				[
					[
						'a' => '42',
						'b' => 'live',
					],
				],
				$this->db->rows( 'SELECT * FROM wp_rows' )
			);
		}
	}

	/** @return iterable<string, array{string}> */
	public static function kill_points(): iterable {
		foreach ( [ 'ledger-created', 'shadow-created', 'rows-inserted', 'progress-written', 'before-commit', 'committed' ] as $point ) {
			yield $point => [ $point ];
		}
	}

	#[DataProvider( 'kill_points' )]
	public function test_killing_a_request_at_each_boundary_resumes_duplicate_rows_exactly_once( string $point ): void {
		$manifest = StagedDump::write( $this->directory );
		$marker   = $this->directory . '/kill-point';
		$process  = proc_open( [ PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Database/import-kill.php', $this->database, $this->directory, $this->token, $point ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$deadline = microtime( true ) + 15;
		$status   = proc_get_status( $process );
		while ( ! is_file( $marker ) && $status['running'] && microtime( true ) < $deadline ) {
			usleep( 10000 );
			$status = proc_get_status( $process );
		}
		$reached = is_file( $marker ) ? file_get_contents( $marker ) : '';
		proc_terminate( $process, 9 );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		self::assertTrue( $status['running'], $output );
		self::assertSame( $point, $reached, $output );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$rows = $this->db->rows( 'SELECT a,b FROM `' . $importer->tables->shadow( 0 ) . '` ORDER BY a,b' );
		self::assertSame(
			[
				[
					'a' => null,
					'b' => null,
				],
				[
					'a' => '1',
					'b' => 'same',
				],
				[
					'a' => '1',
					'b' => 'same',
				],
				[
					'a' => '2',
					'b' => "quote' ; \n line",
				],
			],
			$rows
		);
		self::assertTrue( $importer->tick()['done'] );
	}
}
