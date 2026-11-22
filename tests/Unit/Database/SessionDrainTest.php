<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Session;
use BackupsForRestic\Database\Restore\SessionDrain;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The wait for requests that started before maintenance, against a scripted session list. */
final class SessionDrainTest extends TestCase {

	/**
	 * A server whose other sessions of the account `wp` in the database `site` change with
	 * every listing; the last listing repeats.
	 *
	 * @param list<list<string>>                   $listings Session IDs per listing.
	 * @param list<array<string,string|null>>|null $own The Performance Schema row of this session.
	 */
	private function server( array $listings, ?array $own = null ): object {
		$own ??= [ self::row( 'wp', 'site' ) ];
		return new class( $listings, $own ) implements Connection {
			/** @var list<string> */
			public array $queries = [];
			/** @var list<float> */
			public array $listed              = [];
			public ?DatabaseFailed $own_error = null;

			/**
			 * @param list<list<string>>              $listings
			 * @param list<array<string,string|null>> $own
			 */
			public function __construct( private array $listings, private array $own ) {}

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				if ( 'SELECT USER AS account,DB AS current_database FROM performance_schema.processlist WHERE ID=CONNECTION_ID()' === $sql ) {
					if ( null !== $this->own_error ) {
						throw $this->own_error;
					}
					return $this->own;
				}
				if ( str_starts_with( $sql, 'SELECT ID FROM performance_schema.processlist WHERE ' ) ) {
					$this->listed[] = microtime( true );
					$ids            = count( $this->listings ) > 1 ? array_shift( $this->listings ) : $this->listings[0];
					return array_map( static fn ( string $id ): array => [ 'ID' => $id ], $ids );
				}
				throw new \LogicException( 'Unexpected query: ' . $sql );
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'The drain must not change the database: ' . $sql );
			}
		};
	}

	/** @return array<string,string|null> */
	private static function row( string $account, ?string $database ): array {
		return [
			'account'          => $account,
			'current_database' => $database,
		];
	}

	public function test_waits_until_every_session_listed_after_the_grace_period_has_ended(): void {
		$server = $this->server( [ [ '10', '11' ], [ '10', '11', '12' ], [ '11', '12' ], [ '12', '13' ] ] );
		$start  = microtime( true );
		( new SessionDrain( 0.0, 5.0, 0.0, 0.001 ) )->wait( $server, 'site', $start, $start + 5.0 );
		self::assertCount( 4, $server->listed, 'Sessions that appeared after the first listing must not prolong the wait.' );
		self::assertLessThan( 0.5, microtime( true ) - $start, 'The drain polls instead of sleeping until the bound.' );
		self::assertSame( 'SELECT ID FROM performance_schema.processlist WHERE USER=' . Session::literal( 'wp' ) . ' AND DB=' . Session::literal( 'site' ) . ' AND ID<>CONNECTION_ID() ORDER BY ID LIMIT 4097', end( $server->queries ) );
	}

	public function test_the_sessions_are_listed_only_after_the_grace_period(): void {
		$server  = $this->server( [ [] ] );
		$entered = microtime( true );
		( new SessionDrain( 0.08, 5.0, 0.0, 0.001 ) )->wait( $server, 'site', $entered, $entered + 5.0 );
		self::assertCount( 1, $server->listed );
		self::assertGreaterThanOrEqual( $entered + 0.08, $server->listed[0] );
		self::assertLessThan( $entered + 0.3, $server->listed[0] );
	}

	public function test_a_grace_period_that_has_already_passed_does_not_wait_again(): void {
		$server = $this->server( [ [] ] );
		$start  = microtime( true );
		( new SessionDrain( 0.05, 5.0, 0.0, 0.001 ) )->wait( $server, 'site', $start - 10.0, $start + 5.0 );
		self::assertLessThan( 0.04, microtime( true ) - $start );
	}

	public function test_sessions_that_stay_open_are_refused_with_their_own_code_at_the_bound(): void {
		$server = $this->server( [ [ '10' ] ] );
		$start  = microtime( true );
		try {
			( new SessionDrain( 0.0, 0.1, 0.0, 0.01 ) )->wait( $server, 'site', $start, $start + 100.0 );
			self::fail( 'A session from before maintenance was ignored.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_exchange_sessions', $refused->context()['code'] ?? null );
		}
		$elapsed = microtime( true ) - $start;
		self::assertGreaterThanOrEqual( 0.1, $elapsed, 'The bound is the full waiting time.' );
		self::assertLessThan( 0.6, $elapsed, 'A long tick does not extend the bound.' );
		self::assertGreaterThan( 2, count( $server->listed ), 'The wait checks the sessions repeatedly.' );
		self::assertLessThan( 30, count( $server->listed ), 'The wait pauses between two checks.' );
	}

	public function test_the_wait_ends_with_the_tick_but_not_before_the_minimum(): void {
		$server = $this->server( [ [ '10' ] ] );
		$start  = microtime( true );
		try {
			( new SessionDrain( 0.0, 5.0, 0.0, 0.01 ) )->wait( $server, 'site', $start, $start + 0.1 );
			self::fail( 'A session from before maintenance was ignored.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_exchange_sessions', $refused->context()['code'] ?? null );
		}
		$elapsed = microtime( true ) - $start;
		self::assertGreaterThanOrEqual( 0.1, $elapsed );
		self::assertLessThan( 0.6, $elapsed, 'The tick deadline ends the wait before the bound.' );

		$start = microtime( true );
		try {
			( new SessionDrain( 0.0, 5.0, 0.1, 0.01 ) )->wait( $server, 'site', $start, $start - 30.0 );
			self::fail( 'A session from before maintenance was ignored.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_exchange_sessions', $refused->context()['code'] ?? null );
		}
		$elapsed = microtime( true ) - $start;
		self::assertGreaterThanOrEqual( 0.1, $elapsed, 'A spent tick still grants the minimum wait.' );
		self::assertLessThan( 0.6, $elapsed );
	}

	public function test_the_budget_covers_the_grace_period_and_the_minimum_wait(): void {
		self::assertSame( 7.0, ( new SessionDrain() )->budget() );
		self::assertSame( 0.75, ( new SessionDrain( 0.25, 9.0, 0.5 ) )->budget() );
	}

	/** @return iterable<string, array{list<array<string,string|null>>}> */
	public static function invisible(): iterable {
		yield 'performance schema disabled' => [ [] ];
		yield 'another database' => [ [ self::row( 'wp', 'other' ) ] ];
		yield 'no database' => [ [ self::row( 'wp', null ) ] ];
		yield 'anonymous account' => [ [ self::row( '', 'site' ) ] ];
		yield 'ambiguous' => [ [ self::row( 'wp', 'site' ), self::row( 'wp', 'site' ) ] ];
	}

	/** @param list<array<string,string|null>> $own */
	#[DataProvider( 'invisible' )]
	public function test_sessions_that_cannot_be_listed_name_the_performance_schema( array $own ): void {
		$server = $this->server( [ [] ], $own );
		try {
			( new SessionDrain( 0.0, 5.0, 0.0, 0.001 ) )->wait( $server, 'site', microtime( true ), microtime( true ) + 5.0 );
			self::fail( 'An invisible session list was trusted.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'performance_schema', $refused->context()['code'] ?? null );
		}
		self::assertSame( [], $server->listed );
	}

	/** @return iterable<string, array{int}> */
	public static function unavailable(): iterable {
		yield 'no access to the database' => [ 1044 ];
		yield 'no access to the table' => [ 1142 ];
		yield 'MySQL before 8.0.22' => [ 1146 ];
	}

	#[DataProvider( 'unavailable' )]
	public function test_a_missing_or_denied_session_table_names_the_performance_schema( int $code ): void {
		$server            = $this->server( [ [] ] );
		$server->own_error = new DatabaseFailed( 'refused', [ 'code' => $code ] );
		try {
			( new SwapCatalog( $server ) )->sessions( 'site' );
			self::fail( 'A missing session table was trusted.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'performance_schema', $refused->context()['code'] ?? null );
		}
	}

	public function test_another_server_error_is_not_mistaken_for_a_disabled_performance_schema(): void {
		$server            = $this->server( [ [] ] );
		$server->own_error = new DatabaseFailed( 'gone', [ 'code' => 2006 ] );
		try {
			( new SwapCatalog( $server ) )->sessions( 'site' );
			self::fail( 'A lost connection was reported as a configuration problem.' );
		} catch ( DatabaseFailed $lost ) {
			self::assertSame( 2006, $lost->context()['code'] ?? null );
		}
	}

	public function test_the_session_list_is_bounded_and_validated(): void {
		$catalog = new SwapCatalog( $this->server( [ array_map( 'strval', range( 1, SwapCatalog::MAX_SESSIONS ) ) ] ) );
		self::assertCount( SwapCatalog::MAX_SESSIONS, $catalog->sessions( 'site' ) );
		$catalog = new SwapCatalog( $this->server( [ array_map( 'strval', range( 1, SwapCatalog::MAX_SESSIONS + 1 ) ) ] ) );
		try {
			$catalog->sessions( 'site' );
			self::fail( 'An unbounded session list was accepted.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_exchange_sessions', $refused->context()['code'] ?? null );
		}
		foreach ( [ '0', 'abc', '', '1 ', '123456789012345678901' ] as $invalid ) {
			$catalog = new SwapCatalog( $this->server( [ [ '7', $invalid ] ] ) );
			try {
				$catalog->sessions( 'site' );
				self::fail( 'An unreadable session ID was accepted: ' . $invalid );
			} catch ( DatabaseFailed $refused ) {
				self::assertArrayNotHasKey( 'code', $refused->context() );
			}
		}
		$catalog = new SwapCatalog( $this->server( [ [ '7', '12345678901234567890' ] ] ) );
		self::assertSame( [ '7', '12345678901234567890' ], $catalog->sessions( 'site' ) );
	}
}
