<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use BackupsForRestic\Database\Restore\Connection;
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
		$db->refuse = 'ROLLBACK';
		try {
			$session->close();
			self::fail( 'A failed rollback must be reported.' );
		} catch ( DatabaseFailed $expected ) {
			self::assertCount( 10, $db->writes );
			self::assertSame( 'ROLLBACK', $db->writes[0] );
			self::assertContains( 'SET SESSION character_set_results = NULL', $db->writes );
			self::assertSame( 'SET SESSION sql_mode = ' . Session::literal( 'NO_BACKSLASH_ESCAPES' ), $db->writes[9] );
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
			public function rows( string $sql ): array {
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
