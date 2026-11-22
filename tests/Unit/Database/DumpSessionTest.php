<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\SharedConnection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobError;
use PHPUnit\Framework\TestCase;

final class DumpSessionTest extends TestCase {

	private const string SAVE    = 'SET @bfr_dump_client = @@SESSION.character_set_client, @bfr_dump_results = @@SESSION.character_set_results, @bfr_dump_collation = @@SESSION.collation_connection, @bfr_dump_time_zone = @@SESSION.time_zone, @bfr_dump_sort_length = @@SESSION.max_sort_length, @bfr_dump_select_limit = @@SESSION.sql_select_limit, @bfr_dump_lock_wait = @@SESSION.lock_wait_timeout, SESSION character_set_client = utf8mb4, character_set_results = utf8mb4, character_set_connection = utf8mb4';
	private const string RESTORE = 'SET SESSION character_set_client = @bfr_dump_client, character_set_results = @bfr_dump_results, collation_connection = @bfr_dump_collation, time_zone = @bfr_dump_time_zone, max_sort_length = @bfr_dump_sort_length, sql_select_limit = @bfr_dump_select_limit, lock_wait_timeout = @bfr_dump_lock_wait, @bfr_dump_client = NULL, @bfr_dump_results = NULL, @bfr_dump_collation = NULL, @bfr_dump_time_zone = NULL, @bfr_dump_sort_length = NULL, @bfr_dump_select_limit = NULL, @bfr_dump_lock_wait = NULL';

	public function test_every_query_runs_between_the_dump_settings_and_the_site_settings(): void {
		$site       = $this->site();
		$connection = new SharedConnection( $site );

		self::assertSame( [ [ 'a' => '1' ] ], $connection->rows( 'SELECT 1 AS a' ) );
		self::assertSame( [ [ 'a' => '1' ] ], $connection->rows( 'SELECT 1 AS a' ) );

		$probe = array_slice( $site->statements, 0, 3 );
		self::assertMatchesRegularExpression( '/\ASET @bfr_dump_probe = 0x[0-9a-f]+\z/', $probe[0] );
		self::assertSame( [ 'SELECT HEX(@bfr_dump_probe) AS probe LIMIT 1', 'SET @bfr_dump_probe = NULL' ], array_slice( $probe, 1 ) );
		$query = [ self::SAVE . ', time_zone = 0x2b30303a3030, max_sort_length = 8388608, sql_select_limit = 18446744073709551615, lock_wait_timeout = 5', 'SELECT 1 AS a', self::RESTORE ];
		self::assertSame( [ ...$query, ...$query ], array_slice( $site->statements, 3 ), 'The probe runs once; every query is wrapped.' );
	}

	public function test_a_dump_that_keeps_the_session_time_zone_does_not_set_one(): void {
		$site = $this->site();
		( new SharedConnection( $site, null ) )->rows( 'SELECT 1 AS a' );
		self::assertSame( [ self::SAVE . ', max_sort_length = 8388608, sql_select_limit = 18446744073709551615, lock_wait_timeout = 5', 'SELECT 1 AS a', self::RESTORE ], array_slice( $site->statements, 3 ) );
	}

	public function test_a_failed_query_still_restores_the_site_settings_and_reports_its_own_error(): void {
		$site = $this->site( failing: 'SELECT broken' );
		try {
			( new SharedConnection( $site ) )->rows( 'SELECT broken' );
			self::fail( 'The query error must reach the caller.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'query failed', $error->getMessage() );
		}
		self::assertSame( self::RESTORE, end( $site->statements ) );
	}

	public function test_the_query_error_wins_over_a_failed_restore(): void {
		$site = $this->site( failing: 'SELECT broken', restore_fails: true );
		$this->expectExceptionMessage( 'query failed' );
		( new SharedConnection( $site ) )->rows( 'SELECT broken' );
	}

	public function test_a_restore_that_finds_no_saved_values_fails_the_query(): void {
		// A connection that was replaced between the statements has lost the user variables.
		$site = $this->site( restore_fails: true );
		$this->expectExceptionMessage( 'restore failed' );
		( new SharedConnection( $site ) )->rows( 'SELECT 1 AS a' );
	}

	public function test_a_connection_that_does_not_keep_session_values_is_refused_before_any_read(): void {
		$site = $this->site( forgets: true );
		try {
			( new SharedConnection( $site ) )->rows( 'SELECT 1 AS a' );
			self::fail( 'Reads without the dump settings cannot be allowed.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_session', JobError::from_throwable( $error )->code );
		}
		self::assertNotContains( 'SELECT 1 AS a', $site->statements );
	}

	/**
	 * A site connection that records statements, keeps the probe value unless it forgets
	 * user variables, and fails where it is told to.
	 */
	public function test_a_table_is_shown_once_to_reach_the_session_with_the_dump_settings(): void {
		$site       = $this->sessions( false );
		$connection = new SharedConnection( $site );
		$connection->prove( 'wp_rows' );
		$count = count( $site->statements );
		$connection->prove( 'wp_rows' );

		self::assertSame( $count, count( $site->statements ), 'Once per table.' );
		$probe = array_values( array_filter( $site->statements, static fn ( string $sql ): bool => str_contains( $sql, ' FROM `wp_rows`' ) ) );
		self::assertSame( [ 'SELECT HEX(@bfr_dump_probe) AS probe, @@SESSION.character_set_results AS results, @@SESSION.time_zone AS zone, @@SESSION.max_sort_length AS sort_length, @@SESSION.sql_select_limit AS select_limit, @@SESSION.lock_wait_timeout AS lock_wait FROM `wp_rows` RIGHT JOIN (SELECT 1) AS bfr_dump_one ON FALSE LIMIT 1' ], $probe );
	}

	public function test_a_table_whose_statements_reach_another_session_is_refused_before_it_is_read(): void {
		// A drop-in that sends statements naming a table to a replica: the statements without a
		// table, the dump's settings among them, reach the primary.
		$site = $this->sessions( true );
		try {
			( new SharedConnection( $site ) )->prove( 'wp_rows' );
			self::fail( 'The table is read on a session without the dump settings.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_session', JobError::from_throwable( $error )->code );
			self::assertSame( 'wp_rows', $error->context()['table'] ?? null );
		}
		self::assertSame( [], array_filter( $site->statements, static fn ( string $sql ): bool => str_contains( $sql, 'FROM `wp_rows`' ) && ! str_contains( $sql, 'bfr_dump_one' ) ), 'Nothing of the table was read.' );
	}

	/**
	 * Sessions that keep the user variables and the dump settings they were given; with $route,
	 * statements that name a table reach a second session.
	 */
	private function sessions( bool $route ): Connection {
		return new class( $route ) implements Connection {

			/** @var list<string> */
			public array $statements = [];

			/** @var array<string, array{probe: string, dump: bool}> */
			private array $sessions = [
				'primary' => [
					'probe' => '',
					'dump'  => false,
				],
				'replica' => [
					'probe' => '',
					'dump'  => false,
				],
			];

			public function __construct( private readonly bool $route ) {
			}

			public function rows( string $sql ): array {
				$this->statements[] = $sql;
				$name               = $this->route && str_contains( $sql, ' FROM `' ) ? 'replica' : 'primary';
				if ( 1 === preg_match( '/\ASET @bfr_dump_probe = (0x[0-9a-f]+|NULL)\z/', $sql, $match ) ) {
					$this->sessions[ $name ]['probe'] = 'NULL' === $match[1] ? '' : strtoupper( substr( $match[1], 2 ) );
					return [];
				}
				if ( str_starts_with( $sql, 'SET @bfr_dump_client' ) || str_starts_with( $sql, 'SET SESSION character_set_client = @' ) ) {
					$this->sessions[ $name ]['dump'] = str_starts_with( $sql, 'SET @bfr_dump_client' );
					return [];
				}
				if ( ! str_starts_with( $sql, 'SELECT HEX(@bfr_dump_probe) AS probe' ) ) {
					return [];
				}
				$session = $this->sessions[ $name ];
				return [
					[
						'probe'        => '' === $session['probe'] ? null : $session['probe'],
						'results'      => $session['dump'] ? 'utf8mb4' : 'latin1',
						'zone'         => $session['dump'] ? '+00:00' : 'SYSTEM',
						'sort_length'  => $session['dump'] ? '8388608' : '1024',
						'select_limit' => '18446744073709551615',
						'lock_wait'    => $session['dump'] ? '5' : '31536000',
					],
				];
			}
		};
	}

	private function site( string $failing = '', bool $restore_fails = false, bool $forgets = false ): Connection {
		return new class( $failing, $restore_fails, $forgets ) implements Connection {

			/** @var list<string> */
			public array $statements = [];

			private string $probe = '';

			public function __construct( private readonly string $failing, private readonly bool $restore_fails, private readonly bool $forgets ) {
			}

			public function rows( string $sql ): array {
				$this->statements[] = $sql;
				if ( 1 === preg_match( '/\ASET @bfr_dump_probe = 0x([0-9a-f]+)\z/', $sql, $match ) ) {
					$this->probe = $this->forgets ? '' : strtoupper( $match[1] );
					return [];
				}
				if ( 'SELECT HEX(@bfr_dump_probe) AS probe LIMIT 1' === $sql ) {
					return [ [ 'probe' => '' === $this->probe ? null : $this->probe ] ];
				}
				if ( $sql === $this->failing ) {
					throw new DatabaseFailed( 'query failed' );
				}
				if ( $this->restore_fails && str_starts_with( $sql, 'SET SESSION character_set_client = @' ) ) {
					throw new DatabaseFailed( 'restore failed' );
				}
				return str_starts_with( $sql, 'SELECT 1' ) ? [ [ 'a' => '1' ] ] : [];
			}
		};
	}
}
