<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\SwapCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The server settings that the live exchange depends on, as preflight blockers. */
final class SwapCatalogSettingsTest extends TestCase {

	private const string QUERY = "SHOW SESSION VARIABLES WHERE Variable_name IN ('binlog_format','innodb_table_locks','log_bin','sql_log_bin','wsrep_on')";

	/**
	 * A server with these session variables.
	 *
	 * @param array<string,string> $variables Name and value as SHOW VARIABLES reports them.
	 */
	private static function catalog( array $variables ): SwapCatalog {
		return new SwapCatalog(
			new class( $variables ) implements Connection {
				/** @param array<string,string> $variables */
				public function __construct( private array $variables ) {}

				public function rows( string $sql ): array {
					if ( SwapCatalogSettingsTest::query() !== $sql ) {
						throw new \LogicException( 'Unexpected query: ' . $sql );
					}
					$rows = [];
					foreach ( $this->variables as $name => $value ) {
						$rows[] = [
							'Variable_name' => $name,
							'Value'         => $value,
						];
					}
					return $rows;
				}

				public function execute( string $sql ): int {
					throw new \LogicException( 'The preflight changes nothing: ' . $sql );
				}
			}
		);
	}

	public static function query(): string {
		return self::QUERY;
	}

	/** @return array<string,string> A MySQL 8.4 default with binary logging. */
	private static function defaults(): array {
		return [
			'binlog_format'      => 'ROW',
			'innodb_table_locks' => 'ON',
			'log_bin'            => 'ON',
			'sql_log_bin'        => 'ON',
		];
	}

	/** @return iterable<string, array{array<string,string>, list<string>}> */
	public static function servers(): iterable {
		yield 'defaults' => [ self::defaults(), [] ];
		yield 'mixed logging' => [ [ 'binlog_format' => 'MIXED' ] + self::defaults(), [] ];
		yield 'statement logging' => [ [ 'binlog_format' => 'STATEMENT' ] + self::defaults(), [ 'binlog_format' ] ];
		yield 'statement logging in lower case' => [ [ 'binlog_format' => 'statement' ] + self::defaults(), [ 'binlog_format' ] ];
		yield 'statement format without a binary log' => [
			[
				'binlog_format' => 'STATEMENT',
				'log_bin'       => 'OFF',
			] + self::defaults(),
			[],
		];
		yield 'statement format with logging off for the session' => [
			[
				'binlog_format' => 'STATEMENT',
				'sql_log_bin'   => 'OFF',
			] + self::defaults(),
			[],
		];
		yield 'no InnoDB table locks' => [ [ 'innodb_table_locks' => 'OFF' ] + self::defaults(), [ 'innodb_table_locks' ] ];
		yield 'no InnoDB table lock setting' => [ array_diff_key( self::defaults(), [ 'innodb_table_locks' => true ] ), [ 'innodb_table_locks' ] ];
		yield 'Galera cluster' => [ [ 'wsrep_on' => 'ON' ] + self::defaults(), [ 'wsrep_on' ] ];
		yield 'Galera provider switched off' => [ [ 'wsrep_on' => 'OFF' ] + self::defaults(), [] ];
		yield 'everything at once' => [
			[
				'binlog_format'      => 'STATEMENT',
				'innodb_table_locks' => 'OFF',
				'log_bin'            => 'ON',
				'sql_log_bin'        => 'ON',
				'wsrep_on'           => 'ON',
			],
			[ 'innodb_table_locks', 'binlog_format', 'wsrep_on' ],
		];
	}

	/**
	 * @param array<string,string> $variables
	 * @param list<string>         $blockers
	 */
	#[DataProvider( 'servers' )]
	public function test_each_setting_that_breaks_the_exchange_is_a_blocker_of_its_own( array $variables, array $blockers ): void {
		self::assertSame( $blockers, self::catalog( $variables )->settings() );
	}

	public function test_only_disabled_innodb_table_locks_keep_an_expired_private_table(): void {
		// Expiry drops each table under the exchange's write barrier, which needs them.
		self::assertSame( [ 'performance_schema', 'binlog_format', 'wsrep_on' ], SwapCatalog::EXCHANGE_ONLY );
	}
}
