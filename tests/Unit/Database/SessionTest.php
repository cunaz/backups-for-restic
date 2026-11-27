<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\Session;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Tests\Support\StagedDump;
use BackupsForRestic\Tests\Support\TempDir;

final class SessionTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'import-session' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_a_refused_transaction_guard_does_not_commit_or_rollback_foreign_work(): void {
		$db         = $this->connection();
		$db->refuse = 'SET TRANSACTION READ WRITE';
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'The guard must refuse a foreign transaction.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertSame( [ 'SET TRANSACTION READ WRITE' ], $db->writes );
		}
	}

	public function test_autocommit_off_refuses_before_any_statement_can_change_a_transaction(): void {
		$db             = $this->connection();
		$db->autocommit = '0';
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'Autocommit off must be refused.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertSame( [], $db->writes );
		}
	}

	public function test_cleanup_attempts_rollback_and_every_setting_even_if_one_fails(): void {
		$db         = $this->connection();
		$session    = new Session( $db, StagedDump::write( $this->directory ) );
		$db->writes = [];
		$db->refuse = 'ROLLBACK AND NO CHAIN NO RELEASE';
		try {
			$session->close();
			self::fail( 'A failed rollback must be reported.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertCount( 14, $db->writes );
			self::assertSame( 'ROLLBACK AND NO CHAIN NO RELEASE', $db->writes[0] );
			self::assertContains( 'SET SESSION character_set_results = NULL', $db->writes );
			self::assertSame( 'SET SESSION sql_mode = ' . Session::literal( 'NO_BACKSLASH_ESCAPES' ), $db->writes[13] );
		}
	}

	public function test_the_settings_that_change_a_result_without_an_error_are_set_and_restored(): void {
		$db      = $this->connection();
		$session = new Session( $db, StagedDump::write( $this->directory ) );
		self::assertSame( 'ROLLBACK AND NO CHAIN NO RELEASE', $db->writes[2], 'The guard ends its transaction whatever completion_type says.' );
		$set = implode( "\n", $db->writes );
		foreach ( [ 'sql_select_limit = 18446744073709551615', 'sql_notes = 1', 'sql_safe_updates = 0', 'completion_type = 0' ] as $setting ) {
			self::assertStringContainsString( $setting, $set );
		}
		$db->writes = [];
		$session->close();
		self::assertSame( 'ROLLBACK AND NO CHAIN NO RELEASE', $db->writes[0] );
		// A number as a number: in a numeric setting a hex literal is the number of its bytes.
		self::assertContains( 'SET SESSION sql_select_limit = 1', $db->writes );
		self::assertContains( 'SET SESSION sql_notes = 0', $db->writes );
		self::assertContains( 'SET SESSION sql_safe_updates = 1', $db->writes );
		self::assertContains( 'SET SESSION completion_type = ' . Session::literal( 'CHAIN' ), $db->writes );
	}

	public function test_the_notes_are_asked_for_once_the_row_limit_is_lifted_which_cuts_show_as_well(): void {
		$db = $this->connection();
		new Session( $db, StagedDump::write( $this->directory ) );
		self::assertSame( [ 5 ], $db->shown );
		self::assertStringContainsString( 'sql_select_limit = 18446744073709551615', $db->writes[4] );
		self::assertSame( 'SET SESSION sql_notes = 1', $db->writes[5] );
		self::assertCount( 6, $db->writes );
	}

	/** @return iterable<string, array{list<array<string, string>>}> */
	public static function verbosities(): iterable {
		$verbosity = [
			'Variable_name' => 'note_verbosity',
			'Value'         => 'basic,unusable_keys',
		];
		yield 'MariaDB, with sql_notes on' => [
			[
				$verbosity,
				[
					'Variable_name' => 'sql_notes',
					'Value'         => 'ON',
				],
			],
		];
		yield 'a MariaDB without sql_notes' => [ [ $verbosity ] ];
	}

	/**
	 * MariaDB records a note only when sql_notes and note_verbosity both let it, and warns that
	 * sql_notes is deprecated whenever it is set (11.4.13): its own variable is set instead.
	 *
	 * @param list<array<string, string>> $notes What the server reports.
	 */
	#[DataProvider( 'verbosities' )]
	public function test_a_server_with_note_verbosity_has_it_set_and_given_back_and_sql_notes_left_alone( array $notes ): void {
		$db        = $this->connection();
		$db->notes = $notes;
		$session   = new Session( $db, StagedDump::write( $this->directory ) );
		self::assertSame( "SET SESSION note_verbosity = 'basic'", $db->writes[5] );
		self::assertCount( 6, $db->writes );
		$db->writes = [];
		$session->close();
		self::assertSame( 'SET SESSION note_verbosity = ' . Session::literal( 'basic,unusable_keys' ), $db->writes[1] );
		self::assertCount( 14, $db->writes );
		self::assertStringNotContainsString( 'sql_notes', implode( "\n", $db->writes ) );
	}

	public function test_a_note_verbosity_without_notes_is_given_back_as_an_empty_string(): void {
		$db         = $this->connection();
		$db->notes  = [
			[
				'Variable_name' => 'note_verbosity',
				'Value'         => '',
			],
		];
		$session    = new Session( $db, StagedDump::write( $this->directory ) );
		$db->writes = [];
		$session->close();
		self::assertSame( "SET SESSION note_verbosity = ''", $db->writes[1] );
	}

	public function test_sql_notes_off_on_a_server_with_note_verbosity_refuses_with_its_code_and_gives_the_session_back(): void {
		$db        = $this->connection();
		$db->notes = [
			[
				'Variable_name' => 'note_verbosity',
				'Value'         => 'basic,explain',
			],
			[
				'Variable_name' => 'sql_notes',
				'Value'         => 'OFF',
			],
		];
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'A session that records no notes was used.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_restore_notes', $refused->context()['code'] ?? null );
		}
		// The guard, the two settings and the cleanup, which gives every setting back.
		self::assertCount( 18, $db->writes );
		self::assertSame( 'ROLLBACK AND NO CHAIN NO RELEASE', $db->writes[5] );
		self::assertContains( 'SET SESSION sql_select_limit = 1', $db->writes );
		self::assertDoesNotMatchRegularExpression( '/sql_notes|note_verbosity/', implode( "\n", $db->writes ) );
	}

	/** @return iterable<string, array{list<array<string, string>>}> */
	public static function unreadable_notes(): iterable {
		yield 'neither variable' => [ [] ];
		yield 'a value that is not a switch' => [
			[
				[
					'Variable_name' => 'sql_notes',
					'Value'         => '1; DROP TABLE wp_users',
				],
			],
		];
		yield 'a value that is not a switch, next to note_verbosity' => [
			[
				[
					'Variable_name' => 'note_verbosity',
					'Value'         => 'basic',
				],
				[
					'Variable_name' => 'sql_notes',
					'Value'         => '',
				],
			],
		];
	}

	/** @param list<array<string, string>> $notes What the server reports. */
	#[DataProvider( 'unreadable_notes' )]
	public function test_notes_that_the_server_does_not_report_as_a_switch_refuse_and_give_the_session_back( array $notes ): void {
		$db        = $this->connection();
		$db->notes = $notes;
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'A session whose notes are unknown was used.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertArrayNotHasKey( 'code', $refused->context() );
		}
		self::assertCount( 18, $db->writes );
		self::assertDoesNotMatchRegularExpression( '/sql_notes|note_verbosity/', implode( "\n", $db->writes ) );
	}

	public function test_an_import_reads_in_the_time_zone_that_the_dump_recorded_and_everything_else_in_utc(): void {
		StagedDump::write( $this->directory );
		$json = str_replace( '"time_zone":"+00:00"', '"time_zone":"+05:30"', (string) file_get_contents( $this->directory . '/manifest.json' ) );
		$db   = $this->connection();
		new Session( $db, Manifest::parse( $json, 'wp_' ) );
		self::assertStringContainsString( ', time_zone = ' . Session::literal( '+05:30' ) . ',', implode( "\n", $db->writes ) );
		$other = $this->connection();
		new Session( $other, null );
		self::assertStringContainsString( ', time_zone = ' . Session::literal( '+00:00' ) . ',', implode( "\n", $other->writes ) );
	}

	public function test_an_unexpected_row_limit_refuses_before_changing_the_session(): void {
		$db        = $this->connection();
		$db->limit = '1; DROP TABLE wp_users';
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'Invalid numeric metadata must not become SQL syntax.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertSame( [], $db->writes );
		}
	}

	public function test_schema_inspection_enables_quoted_identifiers_for_the_import_session(): void {
		$db      = $this->connection();
		$session = new Session( $db, StagedDump::write( $this->directory ) );
		self::assertStringContainsString( 'sql_quote_show_create = 1', implode( "\n", $db->writes ) );
		$session->close();
		self::assertContains( 'SET SESSION sql_quote_show_create = 0', $db->writes );
	}

	/** @return iterable<string, array{string}> */
	public static function booleans(): iterable {
		yield 'disabled' => [ '0' ];
		yield 'enabled' => [ '1' ];
	}

	#[DataProvider( 'booleans' )]
	public function test_boolean_settings_are_restored_as_numbers_not_binary_strings( string $value ): void {
		$db         = $this->connection();
		$db->checks = $value;
		$session    = new Session( $db, StagedDump::write( $this->directory ) );
		$db->writes = [];
		$session->close();
		self::assertContains( 'SET SESSION foreign_key_checks = ' . $value, $db->writes );
		self::assertContains( 'SET SESSION unique_checks = ' . $value, $db->writes );
		self::assertContains( 'SET SESSION sql_quote_show_create = ' . $value, $db->writes );
	}

	public function test_unexpected_boolean_metadata_refuses_before_changing_the_session(): void {
		$db         = $this->connection();
		$db->checks = '2';
		try {
			new Session( $db, StagedDump::write( $this->directory ) );
			self::fail( 'Invalid boolean metadata must not become SQL syntax.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertSame( [], $db->writes );
		}
	}

	private function connection(): object {
		return new class() implements Connection {
			public array $writes      = [];
			public string $refuse     = '';
			public string $autocommit = '1';
			public string $checks     = '0';
			public string $limit      = '1';
			/** @var list<array<string, string>> What SHOW SESSION VARIABLES reports about notes: MySQL's. */
			public array $notes = [
				[
					'Variable_name' => 'sql_notes',
					'Value'         => 'OFF',
				],
			];
			/** @var list<int> How many statements had been sent when the notes were read. */
			public array $shown = [];
			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SHOW ' ) ) {
					Assert::assertSame( "SHOW SESSION VARIABLES WHERE Variable_name IN ('note_verbosity', 'sql_notes')", $sql );
					$this->shown[] = count( $this->writes );
					return $this->notes;
				}
				return [
					[
						'autocommit'               => $this->autocommit,
						'character_set_client'     => 'latin1',
						'character_set_connection' => 'latin1',
						'character_set_results'    => null,
						'collation_connection'     => 'latin1_swedish_ci',
						'time_zone'                => '+04:00',
						'foreign_key_checks'       => $this->checks,
						'unique_checks'            => $this->checks,
						'sql_quote_show_create'    => $this->checks,
						'sql_select_limit'         => $this->limit,
						'sql_safe_updates'         => '1',
						'completion_type'          => 'CHAIN',
						'sql_mode'                 => 'NO_BACKSLASH_ESCAPES',
					],
				];
			}
			public function execute( string $sql ): int {
				$this->writes[] = $sql;
				if ( $this->refuse === $sql ) {
					throw new DatabaseFailed( 'Simulated database refusal.' );
				}
				return 0;
			}
		};
	}
}
