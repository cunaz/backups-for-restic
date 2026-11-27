<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Dump\Catalog;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Restore\Ledger;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\RehearsalReport;
use BackupsForRestic\Database\Restore\SwapCatalog;
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

	public function test_rehearsal_reports_server_versions_original_engines_and_live_only_tables(): void {
		$this->db->execute( 'CREATE TABLE wp_shared (id INT) ENGINE=InnoDB DEFAULT CHARSET=latin1' );
		$this->db->execute( 'CREATE TABLE wp_snapshot (id INT) ENGINE=MyISAM' );
		$this->db->execute( 'CREATE VIEW wp_view AS SELECT id FROM wp_shared' );
		$this->db->execute( 'CREATE TRIGGER wp_guard BEFORE UPDATE ON wp_shared FOR EACH ROW SET NEW.id=NEW.id' );
		$this->db->execute( 'INSERT INTO wp_shared VALUES (1),(2)' );
		$manifest = $this->stage_dump();
		$this->db->execute( 'DROP TABLE wp_snapshot' );
		$this->db->execute( 'CREATE TABLE wp_live (id INT) ENGINE=InnoDB' );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$report = ( new RehearsalReport( new RestoreConnection( $this->db->mysqli ) ) )->read( $manifest, $importer->tables );
		$tables = array_column( $report['tables'], null, 'name' );
		self::assertSame( $manifest->server, $report['destination_server'] );
		self::assertSame( $manifest->server, $report['snapshot_server'] );
		self::assertSame( 2, $report['rows'] );
		self::assertSame( 'live_only', $tables['wp_live']['comparison'] );
		self::assertSame( 'both', $tables['wp_shared']['comparison'] );
		self::assertSame( 'latin1', $tables['wp_shared']['snapshot_charset'] );
		self::assertSame( $tables['wp_shared']['live_collation'], $tables['wp_shared']['snapshot_collation'] );
		self::assertSame( 'snapshot_only', $tables['wp_snapshot']['comparison'] );
		self::assertSame( 'MYISAM', $tables['wp_snapshot']['snapshot_engine'] );
		self::assertSame( [ 'wp_view' ], $report['views_not_imported'] );
		self::assertSame( [ 'wp_shared' ], $manifest->trigger_tables );
		self::assertContains(
			[
				'code'  => 'snapshot_triggers',
				'table' => 'wp_shared',
			],
			$report['findings']
		);
		self::assertContains(
			[
				'code'  => 'snapshot_engine',
				'table' => 'wp_snapshot',
			],
			$report['findings']
		);
	}

	public function test_physical_ownership_survives_rename_and_changes_when_a_name_is_reused(): void {
		$this->db->execute( 'CREATE TABLE wp_live (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$this->db->execute( 'CREATE TABLE private_shadow (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$catalog = new SwapCatalog( new RestoreConnection( $this->db->mysqli ) );
		$before  = $catalog->inspect( [ 'wp_live', 'private_shadow', 'missing' ] );
		self::assertSame( [], $before['missing_privileges'] );
		self::assertSame( $before['facts']['mariadb'] ? [ 'write_locked_rename' ] : [], $before['blockers'] );
		self::assertArrayNotHasKey( 'missing', $before['ids'] );
		$this->db->execute( 'RENAME TABLE wp_live TO retained, private_shadow TO wp_live' );
		$after = $catalog->identities( $before['facts'], [ 'wp_live', 'retained' ] );
		self::assertSame( $before['ids']['wp_live'], $after['retained'] );
		self::assertSame( $before['ids']['private_shadow'], $after['wp_live'] );
		$this->db->execute( 'DROP TABLE retained' );
		$this->db->execute( 'CREATE TABLE retained (id INT PRIMARY KEY) ENGINE=InnoDB' );
		self::assertNotSame( $after['retained'], $catalog->identities( $before['facts'], [ 'retained' ] )['retained'] );
	}

	public function test_dictionary_detects_incoming_foreign_keys_outside_the_wordpress_database(): void {
		$foreign = $this->database . '_other';
		$this->db->execute( 'CREATE DATABASE `' . $foreign . '`' );
		try {
			$this->db->execute( 'CREATE TABLE wp_live (id INT PRIMARY KEY) ENGINE=InnoDB' );
			$this->db->execute( 'CREATE TABLE `' . $foreign . '`.other (id INT PRIMARY KEY, FOREIGN KEY (id) REFERENCES `' . $this->database . '`.wp_live(id)) ENGINE=InnoDB' );
			$catalog = new SwapCatalog( new RestoreConnection( $this->db->mysqli ) );
			self::assertContains( 'foreign_keys', $catalog->inspect( [ 'wp_live' ] )['blockers'] );
		} finally {
			$this->db->execute( 'DROP DATABASE `' . $foreign . '`' );
		}
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

	public function test_completed_transcript_binds_the_imported_values_even_with_identical_manifest_bytes(): void {
		$original = StagedDump::table();
		$bindings = [];
		$digests  = [];
		foreach ( [ $original, $original, str_replace( "'same'", "'edit'", $original ) ] as $sql ) {
			$this->token = bin2hex( random_bytes( 16 ) );
			$manifest    = StagedDump::write( $this->directory, $sql );
			$importer    = $this->importer( $manifest );
			$this->finish( $importer );
			$bindings[] = $manifest->fingerprint;
			$digests[]  = $importer->tables->locked( $manifest, fn () => Ledger::content_digest( new RestoreConnection( $this->db->mysqli ), $importer->tables, $manifest ) );
		}
		self::assertCount( 1, array_unique( $bindings ) );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $digests[0] );
		self::assertSame( $digests[0], $digests[1] );
		self::assertNotSame( $digests[0], $digests[2] );
	}

	public function test_an_older_ledger_resumes_without_claiming_a_content_binding(): void {
		$manifest = StagedDump::write( $this->directory );
		$importer = $this->importer( $manifest );
		$importer->tick( 1 );
		$this->db->execute( 'ALTER TABLE `' . $importer->tables->progress() . '` DROP COLUMN content_digest' );
		$this->finish( $importer );
		self::assertNull( $importer->tables->locked( $manifest, fn () => Ledger::content_digest( new RestoreConnection( $this->db->mysqli ), $importer->tables, $manifest ) ) );
		self::assertSame( '4', $this->db->rows( 'SELECT COUNT(*) AS n FROM `' . $importer->tables->shadow( 0 ) . '`' )[0]['n'] );
	}

	public function test_bounded_cleanup_replays_a_lost_cursor_and_leaves_other_tables_untouched(): void {
		$manifest = StagedDump::write( $this->directory );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$owned   = $importer->tables;
		$foreign = $owned->shadow( 1 );
		$this->db->execute( 'CREATE TABLE wp_live (n INT)' );
		$this->db->execute( 'CREATE TABLE `' . $foreign . '` (n INT)' );
		$this->db->execute( 'INSERT INTO `' . $foreign . '` VALUES (99)' );
		// A killed cleanup tick lost its job-file update. Replaying the removed shadow
		// and later a name occupied by a foreign object never removes that object.
		self::assertSame( 1, $owned->clean( 2, 0, 0.0 ) );
		self::assertSame( 1, $owned->clean( 2, 0, 0.0 ) );
		self::assertSame( 2, $owned->clean( 2, 1, 0.0 ) );
		self::assertTrue( $owned->exists( $owned->progress() ) );
		self::assertSame( 3, $owned->clean( 2, 2, 0.0 ) );
		self::assertFalse( $owned->exists( $owned->progress() ) );
		self::assertSame( [ [ 'n' => '99' ] ], $this->db->rows( 'SELECT * FROM `' . $foreign . '`' ) );
		self::assertSame( [], $this->db->rows( 'SELECT * FROM wp_live' ) );
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

	public function test_the_wordpress_mysql_mode_is_restored_even_when_setting_it_reports_a_deprecation(): void {
		$this->db->execute( "SET SESSION sql_mode='NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" );
		$before = $this->db->rows( 'SELECT @@SESSION.sql_mode AS mode' );
		$this->finish( $this->importer( StagedDump::write( $this->directory ) ) );
		self::assertSame( $before, $this->db->rows( 'SELECT @@SESSION.sql_mode AS mode' ) );
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


	public function test_catalog_session_settings_work_through_the_nonreconnecting_adapter(): void {
		$this->db->execute( 'CREATE TABLE wp_rows (a INT)' );
		$this->db->execute( 'SET SESSION sql_quote_show_create = 0' );
		$catalog = new Catalog( new RestoreConnection( $this->db->mysqli ) );
		self::assertStringStartsWith( 'CREATE TABLE `wp_rows`', $catalog->table( 'wp_rows' )->create );
		self::assertSame( '0', $this->db->rows( 'SELECT @@SESSION.sql_quote_show_create AS quoted' )[0]['quoted'] );
	}

	/** @return iterable<string, array{string, string}> */
	public static function dump_sessions(): iterable {
		yield 'quoting disabled' => [ '0', '' ];
		yield 'ANSI identifiers' => [ '1', 'ANSI_QUOTES' ];
		yield 'literal backslashes' => [ '1', 'NO_BACKSLASH_ESCAPES' ];
		yield 'combined' => [ '0', 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES' ];
	}

	#[DataProvider( 'dump_sessions' )]
	public function test_dump_schema_is_portable_across_session_modes_and_restores_site_settings( string $quoted, string $mode ): void {
		$value = SqlFormatter::value( "quote' \\slash\nline", SqlFormatter::TEXT );
		$this->db->execute( 'CREATE TABLE wp_rows (a INT, b VARCHAR(255) DEFAULT ' . $value . ' COMMENT ' . $value . ')' );
		$this->db->execute( 'INSERT INTO wp_rows VALUES (42,DEFAULT)' );
		if ( str_contains( $this->db->rows( 'SELECT VERSION() AS version' )[0]['version'], 'MariaDB' ) && str_contains( $mode, 'ANSI_QUOTES' ) ) {
			$mode .= ',NO_TABLE_OPTIONS,NO_FIELD_OPTIONS,NO_KEY_OPTIONS';
		}
		$this->db->execute( 'SET SESSION sql_quote_show_create = ' . $quoted . ', sql_mode = ' . SqlFormatter::value( $mode, SqlFormatter::TEXT ) );
		$query    = 'SELECT @@SESSION.sql_quote_show_create AS quoted, @@SESSION.sql_mode AS mode';
		$before   = $this->db->rows( $query );
		$manifest = $this->stage_dump();
		self::assertSame( $before, $this->db->rows( $query ) );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		self::assertSame( $this->db->rows( 'SELECT * FROM wp_rows' ), $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
		self::assertSame( $this->db->rows( 'SHOW FULL COLUMNS FROM wp_rows' ), $this->db->rows( 'SHOW FULL COLUMNS FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
		self::assertSame( $before, $this->db->rows( $query ) );
	}

	public function test_an_insert_cannot_target_a_live_table(): void {
		$this->db->execute( 'CREATE TABLE wp_rows (a INT, b TEXT)' );
		$this->db->execute( "INSERT INTO wp_rows VALUES (42,'live')" );
		$sql      = StagedDump::with_updated_trailer( str_replace( 'INSERT INTO `wp_rows`', 'INSERT INTO `wp_users`', StagedDump::table() ) );
		$importer = $this->importer( StagedDump::write( $this->directory, $sql ) );
		try {
			$importer->tick();
			self::fail( 'An unexpected INSERT destination must be refused.' );
		} catch ( FormatException $expected ) {
			self::assertSame( 'A database dump INSERT names an unexpected table.', $expected->getMessage() );
			self::assertSame( [], $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
			self::assertSame( '0', $this->db->rows( 'SELECT row_count FROM `' . $importer->tables->progress() . '` WHERE id=0' )[0]['row_count'] );
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

	/**
	 * A row limit as the server's settings, an account's init_connect or code that ran earlier
	 * in the request leave it on the site's session. Each test has a connection of its own, so
	 * what a test sets ends with it.
	 */
	public function test_a_row_limit_left_on_the_session_leaves_the_rehearsal_report_complete(): void {
		$this->db->execute( "CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes') ENGINE=InnoDB" );
		$this->db->execute( "INSERT INTO wp_options (option_name, option_value) VALUES ('wp_user_roles', 'a:0:{}'), ('active_plugins', 'a:0:{}')" );
		$this->db->execute( 'CREATE TABLE wp_checked (id INT PRIMARY KEY, CONSTRAINT wp_checked_positive CHECK (id > 0)) ENGINE=InnoDB' );
		$this->db->execute( 'CREATE TABLE wp_rechecked (id INT PRIMARY KEY, CONSTRAINT wp_rechecked_positive CHECK (id > 0)) ENGINE=InnoDB' );
		$manifest = $this->stage_dump();
		// CHECK constraint names are unique in a schema: the shadows take them over.
		$this->db->execute( 'DROP TABLE wp_checked, wp_rechecked' );
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$connection = new RestoreConnection( $this->db->mysqli );
		$expected   = ( new RehearsalReport( $connection ) )->read( $manifest, $importer->tables );
		$this->db->execute( 'SET SESSION sql_select_limit = 1' );
		$limited = ( new RehearsalReport( $connection ) )->read( $manifest, $importer->tables );
		$limit   = $this->db->rows( 'SELECT @@SESSION.sql_select_limit AS rows_limit' )[0]['rows_limit'];
		$this->db->execute( 'SET SESSION sql_select_limit = DEFAULT' );
		self::assertSame( '1', $limit, 'The report gives the session its own limit back.' );
		// The columns of the snapshot's options table and the tables with a CHECK constraint
		// were cut to one row before, without an error.
		self::assertSame( $expected, $limited );
		self::assertSame( base64_encode( 'a:0:{}' ), $limited['wordpress_state']['user_roles'] );
		self::assertSame( [ 'wp_checked', 'wp_rechecked' ], array_column( array_filter( $limited['findings'], static fn ( array $finding ): bool => 'snapshot_check' === $finding['code'] ), 'table' ) );
		// Two findings of each code, for two tables each, and one blocker of each.
		$blockers = $limited['swap_preflight']['blockers'];
		self::assertTrue( array_is_list( $blockers ) );
		self::assertSame( array_values( array_unique( $blockers ) ), $blockers );
		self::assertContains( 'snapshot_check', $blockers );
		self::assertContains( 'wordpress_tables', $blockers );
	}

	/** @return iterable<string, array{string}> */
	public static function completions(): iterable {
		yield 'chained' => [ 'CHAIN' ];
		yield 'released' => [ 'RELEASE' ];
	}

	#[DataProvider( 'completions' )]
	public function test_a_completion_type_of_the_session_neither_chains_a_transaction_nor_closes_the_connection( string $completion ): void {
		$this->db->execute( 'CREATE TABLE wp_after (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$manifest = StagedDump::write( $this->directory );
		$this->db->execute( "SET SESSION completion_type = '" . $completion . "'" );
		$importer = $this->importer( $manifest );
		// Every tick and the report open a Session of their own on the one connection, as a
		// request does that runs several ticks.
		$this->finish( $importer );
		$report = ( new RehearsalReport( new RestoreConnection( $this->db->mysqli ) ) )->read( $manifest, $importer->tables );
		// A write of WordPress after the restore's work is committed, as without the restore.
		$this->db->execute( 'INSERT INTO wp_after VALUES (1)' );
		$other = MysqliConnection::connect( $this->database );
		self::assertNotNull( $other );
		self::assertSame( [ [ 'id' => '1' ] ], $other->rows( 'SELECT id FROM wp_after' ), 'The site\'s session is not left in a transaction.' );
		self::assertSame( $completion, $this->db->rows( 'SELECT @@SESSION.completion_type AS completion' )[0]['completion'] );
		self::assertSame( 4, $report['rows'] );
		self::assertSame( '4', $other->rows( 'SELECT COUNT(*) AS n FROM `' . $importer->tables->shadow( 0 ) . '`' )[0]['n'] );
	}

	public function test_a_note_refuses_its_batch_also_when_the_session_does_not_count_notes(): void {
		$sql = "-- backups-for-restic-dump/1 table `wp_rows`\n"
			. "SET NAMES utf8mb4;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET SESSION time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
			. "DROP TABLE IF EXISTS `wp_rows`;\n"
			. "CREATE TABLE `wp_rows` (`a` int, `b` date) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
			// A DATE column drops the time with a note, not a warning.
			. "INSERT INTO `wp_rows` (`a`,`b`) VALUES (1,'2026-01-01 10:00:00');\n"
			. "-- end table rows=1 bytes=0\n";
		$importer = $this->importer( StagedDump::write( $this->directory, StagedDump::with_updated_trailer( $sql, 1 ), 1 ) );
		$importer->tick( 1 );
		$this->db->execute( 'SET SESSION sql_notes = 0' );
		try {
			$importer->tick( 1 );
			self::fail( 'A value that the server changed with a note was imported.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 1, $refused->context()['warnings'] ?? null, $refused->getMessage() );
		}
		self::assertSame( '0', $this->db->rows( 'SELECT @@SESSION.sql_notes AS notes' )[0]['notes'] );
		self::assertSame( [], $this->db->rows( 'SELECT * FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
		self::assertSame( '0', $this->db->rows( 'SELECT row_count FROM `' . $importer->tables->progress() . '` WHERE id=0' )[0]['row_count'] );
	}

	/** @return iterable<string, array{array<string,mixed>|null, string}> */
	public static function duplicate_reads(): iterable {
		$read = [
			'key'               => [ 'a' ],
			'restarts'          => 0,
			'largest_statement' => 50,
		];
		yield 'read at more than one moment' => [ [ 'snapshot' => false ] + $read, 'the dump read it at more than one moment, so a row that was deleted and inserted again with a new key in between can be in it twice.' ];
		yield 'read in one read view' => [ [ 'snapshot' => true ] + $read, 'the dump read all of its rows from one read view' ];
		yield 'not recorded' => [ null, 'the dump\'s manifest does not record how it read the table' ];
	}

	/** @param array<string,mixed>|null $read */
	#[DataProvider( 'duplicate_reads' )]
	public function test_a_duplicate_key_names_its_table_and_says_how_the_dump_read_it( ?array $read, string $explanation ): void {
		$sql = "-- backups-for-restic-dump/1 table `wp_rows`\n"
			. "SET NAMES utf8mb4;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET SESSION time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
			. "DROP TABLE IF EXISTS `wp_rows`;\n"
			. "CREATE TABLE `wp_rows` (`a` int NOT NULL, `b` longtext, UNIQUE KEY `a` (`a`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `wp_rows` (`a`,`b`) VALUES (1,'first-value');\n"
			// The same key again, as a row deleted and inserted again with a new primary key.
			. "INSERT INTO `wp_rows` (`a`,`b`) VALUES (1,'second-value');\n"
			. "-- end table rows=2 bytes=0\n";
		$importer = $this->importer( StagedDump::write( $this->directory, StagedDump::with_updated_trailer( $sql, 2 ), 2, $read ) );
		$importer->tick( 1 );
		$importer->tick( 1 );
		try {
			$importer->tick( 1 );
			self::fail( 'A duplicate key was imported.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_restore_duplicate', $refused->context()['code'] ?? null );
			self::assertSame( 'wp_rows', $refused->context()['table'] ?? null );
			self::assertStringStartsWith( 'Two rows of a table of the dump have the same value in a unique key; ', $refused->getMessage() );
			self::assertStringContainsString( $explanation, $refused->getMessage() );
			self::assertStringNotContainsString( 'value', strtolower( (string) json_encode( $refused->context() ) ) );
			self::assertNull( $refused->getPrevious() );
		}
		self::assertSame( [ [ 'b' => 'first-value' ] ], $this->db->rows( 'SELECT b FROM `' . $importer->tables->shadow( 0 ) . '`' ) );
		self::assertSame( '1', $this->db->rows( 'SELECT row_count FROM `' . $importer->tables->progress() . '` WHERE id=0' )[0]['row_count'] );
	}

	/** @return iterable<string, array{list<string>, bool, bool}> */
	public static function position_reads(): iterable {
		yield 'without a key, at more than one moment' => [ [], false, true ];
		yield 'without a key, in one statement' => [ [], true, false ];
		yield 'by its key, at more than one moment' => [ [ 'a' ], false, false ];
	}

	/** @param list<string> $key */
	#[DataProvider( 'position_reads' )]
	public function test_the_report_names_a_table_that_the_dump_read_by_position_at_more_than_one_moment( array $key, bool $snapshot, bool $named ): void {
		$manifest = StagedDump::write(
			$this->directory,
			null,
			4,
			[
				'key'               => $key,
				'snapshot'          => $snapshot,
				'restarts'          => 0,
				'largest_statement' => 60,
			]
		);
		$importer = $this->importer( $manifest );
		$this->finish( $importer );
		$report = ( new RehearsalReport( new RestoreConnection( $this->db->mysqli ) ) )->read( $manifest, $importer->tables );
		self::assertSame(
			$named,
			in_array(
				[
					'code'  => 'read_by_position',
					'table' => 'wp_rows',
				],
				$report['findings'],
				true
			)
		);
		self::assertNotContains( 'read_by_position', $report['swap_preflight']['blockers'], 'The finding describes the backup; it does not hold up the exchange.' );
	}
}
