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

/**
 * The wait for requests that started before maintenance, against a scripted session list and
 * a clock that only the sleeper moves. No assertion depends on how fast the host runs.
 */
final class SessionDrainTest extends TestCase {

	private const string OWN = 'SELECT USER AS account,DB AS current_database FROM performance_schema.processlist WHERE ID=CONNECTION_ID()';

	private const string COUNTS = "SELECT (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Threads_connected') AS connected,(SELECT COUNT(*) FROM performance_schema.processlist WHERE COMMAND<>'Daemon') AS listed";

	/** Simulated time, in seconds. */
	private float $now = 1000.0;

	/** @var list<float> Requested sleeps. */
	private array $slept = [];

	/**
	 * A server whose other sessions of the account `wp` in the database `site` change with
	 * every listing; the last listing repeats. Counts are complete unless scripted.
	 *
	 * @param list<list<string>>                   $listings Session IDs per listing.
	 * @param list<array<string,string|null>>|null $own The Performance Schema row of this session.
	 * @param list<array{string,string}>           $counts Threads_connected and listed rows per reading.
	 */
	private function server( array $listings, ?array $own = null, array $counts = [] ): object {
		$own ??= [ self::row( 'wp', 'site' ) ];
		$clock = fn (): float => $this->now;
		return new class( $listings, $own, $counts, $clock ) implements Connection {
			/** @var list<string> */
			public array $queries = [];
			/** @var list<float> */
			public array $listed              = [];
			public ?DatabaseFailed $own_error = null;

			/**
			 * @param list<list<string>>              $listings
			 * @param list<array<string,string|null>> $own
			 * @param list<array{string,string}>      $counts
			 * @param \Closure():float                $clock
			 */
			public function __construct( private array $listings, private array $own, private array $counts, private \Closure $clock ) {}

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				if ( SessionDrainTest::own() === $sql ) {
					if ( null !== $this->own_error ) {
						throw $this->own_error;
					}
					return $this->own;
				}
				if ( SessionDrainTest::counts() === $sql ) {
					[ $connected, $listed ] = [] === $this->counts ? [ '3', '3' ] : array_shift( $this->counts );
					return [
						[
							'connected' => $connected,
							'listed'    => $listed,
						],
					];
				}
				if ( str_starts_with( $sql, 'SELECT ID FROM performance_schema.processlist WHERE ' ) ) {
					$this->listed[] = ( $this->clock )();
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

	public static function own(): string {
		return self::OWN;
	}

	public static function counts(): string {
		return self::COUNTS;
	}

	/** @return array<string,string|null> */
	private static function row( string $account, ?string $database ): array {
		return [
			'account'          => $account,
			'current_database' => $database,
		];
	}

	/** A drain on the simulated clock; the sleeper records each sleep and moves the clock. */
	private function drain( float $grace, float $bound, float $minimum, float $poll, float $quiet ): SessionDrain {
		return new SessionDrain(
			$grace,
			$bound,
			$minimum,
			$poll,
			$quiet,
			fn (): float => $this->now,
			function ( float $seconds ): void {
				$this->slept[] = $seconds;
				$this->now    += $seconds;
			}
		);
	}

	/**
	 * Runs a wait and returns its refusal code, or null if the exchange may start.
	 *
	 * @param float $entered Time maintenance was entered.
	 * @param float $deadline End of the tick.
	 */
	private function wait( SessionDrain $drain, object $server, float $entered, float $deadline ): ?string {
		self::assertInstanceOf( Connection::class, $server );
		try {
			$drain->wait( $server, 'site', $entered, $deadline );
			return null;
		} catch ( DatabaseFailed $refused ) {
			return (string) ( $refused->context()['code'] ?? '' );
		}
	}

	public function test_the_first_look_comes_after_the_grace_period(): void {
		$server = $this->server( [ [] ] );
		self::assertNull( $this->wait( $this->drain( 2.0, 60.0, 5.0, 0.25, 0.0 ), $server, 1000.0, 1100.0 ) );
		self::assertSame( [ 2.0 ], $this->slept );
		self::assertSame( [ 1002.0 ], $server->listed );
	}

	public function test_a_grace_period_that_has_already_passed_is_not_waited_for_again(): void {
		$server = $this->server( [ [] ] );
		self::assertNull( $this->wait( $this->drain( 2.0, 60.0, 5.0, 0.25, 0.0 ), $server, 990.0, 1100.0 ) );
		self::assertSame( [], $this->slept, 'No sleep is requested for a grace period that is over.' );
		self::assertSame( [ 1000.0 ], $server->listed );
	}

	public function test_the_account_must_stay_without_other_sessions_for_the_quiet_interval(): void {
		$server = $this->server( [ [] ] );
		self::assertNull( $this->wait( $this->drain( 0.0, 60.0, 5.0, 0.25, 1.0 ), $server, 1000.0, 1100.0 ) );
		self::assertSame( [ 1000.0, 1000.25, 1000.5, 1000.75, 1001.0 ], $server->listed );
		self::assertSame( [ 0.25, 0.25, 0.25, 0.25 ], $this->slept );
	}

	public function test_a_request_that_reconnects_under_a_new_id_is_waited_for(): void {
		// Session 10 ends, the same request comes back as 11 before the account was quiet long enough.
		$server = $this->server( [ [ '10' ], [], [ '11' ], [], [], [] ] );
		self::assertNull( $this->wait( $this->drain( 0.0, 60.0, 5.0, 0.25, 0.5 ), $server, 1000.0, 1100.0 ) );
		self::assertSame( [ 1000.0, 1000.25, 1000.5, 1000.75, 1001.0, 1001.25 ], $server->listed, 'The quiet interval starts again after every session.' );
	}

	public function test_sessions_that_stay_are_refused_with_their_own_code_at_the_bound(): void {
		$server = $this->server( [ [ '10' ] ] );
		self::assertSame( 'database_exchange_sessions', $this->wait( $this->drain( 0.0, 1.0, 0.0, 0.25, 0.0 ), $server, 1000.0, 2000.0 ) );
		self::assertSame( [ 1000.0, 1000.25, 1000.5, 1000.75, 1001.0 ], $server->listed, 'A long tick does not extend the bound, and the last look is at the bound.' );
		self::assertSame( 1001.0, $this->now );
	}

	public function test_the_last_pause_ends_at_the_bound(): void {
		$server = $this->server( [ [ '10' ] ] );
		self::assertSame( 'database_exchange_sessions', $this->wait( $this->drain( 0.0, 0.75, 0.0, 0.5, 0.0 ), $server, 1000.0, 2000.0 ) );
		self::assertSame( [ 0.5, 0.25 ], $this->slept );
		self::assertSame( [ 1000.0, 1000.5, 1000.75 ], $server->listed );
	}

	public function test_a_quiet_interval_cut_off_by_the_bound_does_not_count(): void {
		$server = $this->server( [ [ '10' ], [ '10' ], [], [] ] );
		self::assertSame( 'database_exchange_sessions', $this->wait( $this->drain( 0.0, 0.75, 0.0, 0.25, 1.0 ), $server, 1000.0, 2000.0 ) );
	}

	public function test_the_wait_ends_with_the_tick_but_not_before_the_minimum(): void {
		$server = $this->server( [ [ '10' ] ] );
		self::assertSame( 'database_exchange_sessions', $this->wait( $this->drain( 0.0, 5.0, 0.0, 0.25, 0.0 ), $server, 1000.0, 1000.5 ) );
		self::assertSame( 1000.5, $this->now, 'The tick deadline ends the wait before the bound.' );
		$this->now   = 2000.0;
		$this->slept = [];
		self::assertSame( 'database_exchange_sessions', $this->wait( $this->drain( 0.0, 5.0, 1.0, 0.25, 0.0 ), $server, 2000.0, 1970.0 ) );
		self::assertSame( 2001.0, $this->now, 'A spent tick still grants the minimum wait.' );
	}

	public function test_a_session_list_that_stays_incomplete_is_refused_as_such(): void {
		$server = $this->server( [ [] ], null, array_fill( 0, 40, [ '5', '3' ] ) );
		self::assertSame( 'performance_schema', $this->wait( $this->drain( 0.0, 1.0, 0.0, 0.25, 0.0 ), $server, 1000.0, 2000.0 ) );
		self::assertSame( [], $server->listed, 'An incomplete list is never read as quiet.' );
	}

	public function test_a_list_that_becomes_complete_lets_the_exchange_start(): void {
		$counts = [ [ '5', '3' ], [ '5', '3' ], [ '5', '3' ], [ '5', '5' ] ];
		$server = $this->server( [ [] ], null, $counts );
		self::assertNull( $this->wait( $this->drain( 0.0, 10.0, 0.0, 0.25, 0.0 ), $server, 1000.0, 2000.0 ) );
		self::assertSame( [ 1000.25 ], $server->listed );
	}

	public function test_an_incomplete_list_at_the_bound_is_reported_even_after_a_quiet_moment(): void {
		$counts = [ [ '3', '3' ], ...array_fill( 0, 30, [ '4', '3' ] ) ];
		$server = $this->server( [ [] ], null, $counts );
		self::assertSame( 'performance_schema', $this->wait( $this->drain( 0.0, 0.5, 0.0, 0.25, 1.0 ), $server, 1000.0, 2000.0 ) );
	}

	public function test_by_default_the_account_must_stay_quiet_for_a_second_after_a_two_second_grace(): void {
		$server = $this->server( [ [] ] );
		$drain  = new SessionDrain(
			clock: fn (): float => $this->now,
			sleep: function ( float $seconds ): void {
				$this->slept[] = $seconds;
				$this->now    += $seconds;
			}
		);
		self::assertNull( $this->wait( $drain, $server, 1000.0, 1100.0 ) );
		self::assertSame( 1002.0, $server->listed[0] );
		self::assertGreaterThanOrEqual( 1003.0, $this->now );
		self::assertLessThan( 1003.3, $this->now );
		self::assertSame( [ 2.0 ], array_values( array_unique( array_slice( $this->slept, 0, 1 ) ) ) );
		self::assertSame( [ 0.2 ], array_values( array_unique( array_slice( $this->slept, 1 ) ) ), 'The drain polls five times a second.' );
	}

	public function test_the_budget_covers_the_grace_period_and_the_minimum_wait(): void {
		self::assertSame( 7.0, ( new SessionDrain() )->budget() );
		self::assertSame( 0.75, ( new SessionDrain( 0.25, 9.0, 0.5 ) )->budget() );
	}

	public function test_completeness_tolerates_two_spoiled_readings_but_not_three(): void {
		$server = $this->server( [ [ '7' ] ], null, [ [ '4', '3' ], [ '4', '3' ], [ '4', '4' ] ] );
		self::assertSame( [ '7' ], ( new SwapCatalog( $server ) )->sessions( 'site' ) );
		self::assertSame( 3, count( array_keys( $server->queries, self::COUNTS, true ) ) );
		$server = $this->server( [ [ '7' ] ], null, [ [ '4', '3' ], [ '4', '3' ], [ '4', '3' ], [ '4', '4' ] ] );
		self::assertNull( ( new SwapCatalog( $server ) )->sessions( 'site' ) );
		self::assertSame( 3, count( array_keys( $server->queries, self::COUNTS, true ) ) );
		self::assertSame( [], $server->listed );
		$catalog = new SwapCatalog( $this->server( [ [ '7' ] ], null, [ [ '4', '5' ] ] ) );
		self::assertSame( [ '7' ], $catalog->sessions( 'site' ), 'Listed threads that are no connection do not make the list incomplete.' );
	}

	/** @return iterable<string, array{string,string}> */
	public static function unreadable_counts(): iterable {
		yield 'no connection count' => [ '', '3' ];
		yield 'no listed count' => [ '3', '' ];
		yield 'a negative count' => [ '-1', '3' ];
		yield 'not a number' => [ '3', 'x' ];
		yield 'too large' => [ '3', '1234567890' ];
	}

	#[DataProvider( 'unreadable_counts' )]
	public function test_unreadable_counts_are_refused( string $connected, string $listed ): void {
		$catalog = new SwapCatalog( $this->server( [ [] ], null, [ [ $connected, $listed ] ] ) );
		try {
			$catalog->sessions( 'site' );
			self::fail( 'Unreadable session counts were trusted.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertArrayNotHasKey( 'code', $refused->context() );
		}
	}

	public function test_the_preflight_names_an_incomplete_list_only_when_other_accounts_are_visible(): void {
		$catalog = new SwapCatalog( $this->server( [ [] ], null, array_fill( 0, 3, [ '4', '3' ] ) ) );
		self::assertSame( [ 'performance_schema' ], $catalog->visibility( 'site', true ) );
		$server = $this->server( [ [] ], null, array_fill( 0, 3, [ '4', '3' ] ) );
		self::assertSame( [], ( new SwapCatalog( $server ) )->visibility( 'site', false ), 'Without PROCESS the dictionary blocker already explains it.' );
		self::assertSame( [ self::OWN ], $server->queries );
		self::assertSame( [], ( new SwapCatalog( $this->server( [ [] ] ) ) )->visibility( 'site', true ) );
		self::assertSame( [ 'performance_schema' ], ( new SwapCatalog( $this->server( [ [] ], [] ) ) )->visibility( 'site', false ) );
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
		self::assertSame( 'performance_schema', $this->wait( $this->drain( 0.0, 5.0, 0.0, 0.25, 0.0 ), $server, 1000.0, 1005.0 ) );
		self::assertSame( [], $server->listed );
		self::assertSame( [], $this->slept, 'An invisible list is refused at once, without waiting.' );
		self::assertSame( [ 'performance_schema' ], ( new SwapCatalog( $this->server( [ [] ], $own ) ) )->visibility( 'site', true ) );
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
		$server  = $this->server( [ array_map( 'strval', range( 1, SwapCatalog::MAX_SESSIONS ) ) ] );
		$catalog = new SwapCatalog( $server );
		self::assertCount( SwapCatalog::MAX_SESSIONS, $catalog->sessions( 'site' ) ?? [] );
		self::assertSame( 'SELECT ID FROM performance_schema.processlist WHERE USER=' . Session::literal( 'wp' ) . ' AND DB=' . Session::literal( 'site' ) . ' AND ID<>CONNECTION_ID() ORDER BY ID LIMIT 4097', end( $server->queries ) );
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
