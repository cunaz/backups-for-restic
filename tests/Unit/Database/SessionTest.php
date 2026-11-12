<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
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
			self::assertCount( 9, $db->writes );
			self::assertSame( 'ROLLBACK', $db->writes[0] );
			self::assertContains( 'SET SESSION character_set_results = NULL', $db->writes );
			self::assertSame( 'SET SESSION sql_mode = ' . Session::literal( 'NO_BACKSLASH_ESCAPES' ), $db->writes[8] );
		}
	}

	private function connection(): object {
		return new class() implements Connection {
			public array $writes      = [];
			public string $refuse     = '';
			public string $autocommit = '1';
			public function rows( string $sql ): array {
				return [
					[
						'autocommit'               => $this->autocommit,
						'character_set_client'     => 'latin1',
						'character_set_connection' => 'latin1',
						'character_set_results'    => null,
						'collation_connection'     => 'latin1_swedish_ci',
						'time_zone'                => '+04:00',
						'foreign_key_checks'       => '0',
						'unique_checks'            => '0',
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
