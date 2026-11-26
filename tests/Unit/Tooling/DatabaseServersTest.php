<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Tests\Support\DatabaseServers;
use BackupsForRestic\Tests\Support\MutationProcess;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The database mutation gate runs each Infection thread against a database server of its own
 * (docs/04 §5.6): tests/Support/DatabaseServers.php gives a mutant process the server of its
 * thread and clears what an earlier process left there, bin/database-servers.sh runs one thread
 * per server, and bin/check-database-servers.php checks the servers before and after the gate.
 * A process without a server of its own reaches none and fails the gate; nothing falls back to
 * a shared server.
 */
final class DatabaseServersTest extends TestCase {

	/** The variables that the tests below change, with their values before. */
	private array $saved = [];

	protected function setUp(): void {
		foreach ( [ DatabaseServers::HOST, MutationProcess::REPORTED_VARIABLE, MutationProcess::MARKER_VARIABLE ] as $name ) {
			$this->saved[ $name ] = [ getenv( $name ), $_SERVER[ $name ] ?? null ];
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $name => [ $value, $server ] ) {
			putenv( false === $value ? $name : $name . '=' . $value );
			if ( null === $server ) {
				unset( $_SERVER[ $name ] );
			} else {
				$_SERVER[ $name ] = $server;
			}
		}
	}

	public function test_the_variable_names_the_servers_in_its_order(): void {
		self::assertSame( [ '127.0.0.1:33301', 'db.example.com:3306', '127.0.0.1:33302' ], DatabaseServers::hosts( " 127.0.0.1:33301  db.example.com:3306\n\t127.0.0.1:33302 " ) );
		self::assertSame( [], DatabaseServers::hosts( '  ' ) );
		self::assertSame( [], DatabaseServers::hosts( false ) );
	}

	public function test_a_process_uses_the_server_of_its_thread(): void {
		putenv( DatabaseServers::HOST . '=shared.example.com:3306' );
		self::assertNull( DatabaseServers::own( '2', '127.0.0.1:33301 127.0.0.1:33302 127.0.0.1:33303', false ) );
		self::assertSame( '127.0.0.1:33302', getenv( DatabaseServers::HOST ), 'The database suite and the programs it starts connect there.' );
		self::assertSame( '127.0.0.1:33302', $_SERVER[ DatabaseServers::HOST ] ?? null, 'So does a test that PHPUnit runs in a process of its own.' );
	}

	public function test_without_a_thread_the_server_stays_as_configured(): void {
		putenv( DatabaseServers::HOST . '=shared.example.com:3306' );
		self::assertNull( DatabaseServers::own( false, '127.0.0.1:33301', true ) );
		self::assertNull( DatabaseServers::own( '', '127.0.0.1:33301', true ) );
		self::assertSame( 'shared.example.com:3306', getenv( DatabaseServers::HOST ) );
	}

	/**
	 * Threads that the variable names no server for: TEST_TOKEN, BFR_TEST_MYSQL_HOSTS, and what
	 * the process says.
	 *
	 * @return array<string, array{string, string|false, string}>
	 */
	public static function threads_without_a_server(): array {
		return [
			'more threads than servers'  => [ '4', '127.0.0.1:33301 127.0.0.1:33302 127.0.0.1:33303', 'Thread 4 has no database server of its own: BFR_TEST_MYSQL_HOSTS names 3. The tests of this process reach no server.' ],
			'no servers named'           => [ '1', false, 'Thread 1 has no database server of its own: BFR_TEST_MYSQL_HOSTS names 0.' ],
			'an empty list'              => [ '1', ' ', 'Thread 1 has no database server of its own: BFR_TEST_MYSQL_HOSTS names 0.' ],
			'a thread numbered 0'        => [ '0', '127.0.0.1:33301', 'Thread 0 has no database server of its own' ],
			'a thread that is no number' => [ '1; DROP', '127.0.0.1:33301', 'Thread 1; DROP has no database server of its own' ],
		];
	}

	#[DataProvider( 'threads_without_a_server' )]
	public function test_a_thread_without_a_server_reaches_none_instead_of_a_shared_one( string $token, string|false $hosts, string $problem ): void {
		putenv( DatabaseServers::HOST . '=shared.example.com:3306' );
		self::assertStringStartsWith( $problem, (string) DatabaseServers::own( $token, $hosts, true ) );
		self::assertSame( DatabaseServers::NONE, getenv( DatabaseServers::HOST ) );
		self::assertSame( DatabaseServers::NONE, $_SERVER[ DatabaseServers::HOST ] ?? null );
	}

	public function test_a_mutant_process_whose_server_does_not_answer_says_so(): void {
		// A name under .invalid never resolves (RFC 6761), so the connection fails at once; a
		// closed port can take the whole connect timeout behind a packet filter.
		self::assertSame( 'The database server of this process, down.invalid:3306, does not answer: its tests skip.', DatabaseServers::own( '1', 'down.invalid:3306', true ) );
		self::assertSame( 'down.invalid:3306', getenv( DatabaseServers::HOST ) );
	}

	/**
	 * What an earlier process left: the sessions that the server lists, one list per query, and
	 * the statements that clearing the server sends.
	 *
	 * @return array<string, array{list<list<array<string, string>>>, list<string>}>
	 */
	public static function leftovers(): array {
		$list  = "SELECT ID,USER,DB,COMMAND FROM performance_schema.processlist WHERE ID<>CONNECTION_ID() AND COMMAND<>'Daemon'";
		$reset = 'SET GLOBAL max_allowed_packet=DEFAULT';
		return [
			'nothing left'                        => [ [ [] ], [ $list, $reset ] ],
			'two sessions left'                   => [
				[
					[
						[
							'ID'      => '7',
							'USER'    => 'root',
							'DB'      => 'bfr_exchange_0123456789ab',
							'COMMAND' => 'Query',
						],
						[
							'ID'      => '9',
							'USER'    => 'root',
							'DB'      => null,
							'COMMAND' => 'Sleep',
						],
					],
					[],
				],
				[ $list, 'KILL 7', 'KILL 9', $list, $reset ],
			],
			'a session that takes a while to end' => [
				[ [ [ 'ID' => '7' ] ], [ [ 'ID' => '7' ] ], [] ],
				[ $list, 'KILL 7', $list, 'KILL 7', $list, $reset ],
			],
		];
	}

	/**
	 * A mutant process ends every other session on its server, as each is one that a process of
	 * its thread left, which Infection stopped at its timeout, and sets max_allowed_packet back to
	 * the default, which that process may have lowered: so no process meets what another did.
	 *
	 * @param list<list<array<string, string>>> $lists      What the server lists, query by query.
	 * @param list<string>                      $statements What clearing it sends.
	 */
	#[DataProvider( 'leftovers' )]
	public function test_a_mutant_process_clears_its_server_of_what_an_earlier_one_left( array $lists, array $statements ): void {
		$server = self::server( $lists );
		self::assertNull( DatabaseServers::clear( $server, '127.0.0.1:33301' ) );
		self::assertSame( $statements, $server->sent );
	}

	public function test_a_session_that_does_not_end_fails_the_process_and_keeps_the_limit(): void {
		$server = self::server(
			array_fill(
				0,
				1000,
				[
					[
						'ID'      => '7',
						'USER'    => 'root',
						'DB'      => 'bfr_exchange_0123456789ab',
						'COMMAND' => 'Query',
					],
				]
			)
		);
		self::assertSame( 'Sessions that an earlier process left on 127.0.0.1:33301 did not end within 0.2 seconds: [{"ID":"7","USER":"root","DB":"bfr_exchange_0123456789ab","COMMAND":"Query"}].', DatabaseServers::clear( $server, '127.0.0.1:33301', 0.2 ) );
		self::assertNotContains( 'SET GLOBAL max_allowed_packet=DEFAULT', $server->sent );
	}

	public function test_a_server_that_refuses_to_list_its_sessions_fails_the_clearing(): void {
		$this->expectException( DatabaseFailed::class );
		DatabaseServers::clear( self::server( [] ), '127.0.0.1:33301' );
	}

	/**
	 * The database gate's bootstrap in a process as Infection starts one, with UserSpaceFiles in
	 * place of its interceptor: TEST_TOKEN, BFR_TEST_MYSQL_HOSTS, whether the process is a test in
	 * a process of its own, the server it then connects to, and the line it leaves in its report
	 * besides those that every mutant process states.
	 *
	 * @return array<string, array{string|null, string, bool, string, string}>
	 */
	public static function processes(): array {
		return [
			'a mutant process on its thread\'s server' => [ '2', 'down-1.invalid:3306 down-2.invalid:3306', false, 'down-2.invalid:3306', 'The database server of this process, down-2.invalid:3306, does not answer: its tests skip.' ],
			'more threads than servers'                => [ '3', 'down-1.invalid:3306 down-2.invalid:3306', false, DatabaseServers::NONE, 'Thread 3 has no database server of its own: BFR_TEST_MYSQL_HOSTS names 2. The tests of this process reach no server.' ],
			'no servers named'                         => [ '1', '', false, DatabaseServers::NONE, 'Thread 1 has no database server of its own: BFR_TEST_MYSQL_HOSTS names 0. The tests of this process reach no server.' ],
			'a test in a process of its own'           => [ '2', 'down-1.invalid:3306 down-2.invalid:3306', true, 'down-2.invalid:3306', '' ],
			'no thread, as outside a parallel run'     => [ null, 'down-1.invalid:3306 down-2.invalid:3306', false, 'initial.example.com:3306', '' ],
		];
	}

	/**
	 * A mutant process uses the server of its thread, clears it, and reports what goes wrong in
	 * its report, where bin/mutation-gate.sh fails the gate on it. A test that PHPUnit runs in a
	 * process of its own uses the same server and leaves its mutant process's sessions alone.
	 */
	#[DataProvider( 'processes' )]
	public function test_the_bootstrap_of_the_database_gate_gives_each_process_its_server( ?string $token, string $hosts, bool $isolated, string $host, string $problem ): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'database-servers' );
		try {
			mkdir( $scratch . '/reports' );
			$script = $scratch . '/process.php';
			file_put_contents(
				$script,
				'<?php
				declare( strict_types=1 );
				$root = $argv[1];
				require $root . "/tests/Support/UserSpaceFiles.php";
				\BackupsForRestic\Tests\Support\UserSpaceFiles::enable();
				require $root . "/tests/Mutation/database/bootstrap.php";
				echo json_encode( [ getenv( "BFR_TEST_MYSQL_HOST" ), $_SERVER["BFR_TEST_MYSQL_HOST"] ?? null ] );
				'
			);
			$environment = [
				'BFR_TEST_MYSQL_HOST'            => 'initial.example.com:3306',
				DatabaseServers::VARIABLE        => $hosts,
				MutationProcess::REPORT_VARIABLE => $scratch . '/reports',
				MutationProcess::MARKER_VARIABLE => $scratch . '/marker',
			] + getenv();
			unset( $environment[ DatabaseServers::TOKEN ], $environment[ MutationProcess::REPORTED_VARIABLE ] );
			if ( null !== $token ) {
				$environment[ DatabaseServers::TOKEN ] = $token;
			}
			if ( $isolated ) {
				// The report of the mutant process that the test belongs to.
				file_put_contents( $scratch . '/reports/report-1-parent', "Process 1 reported in.\n" );
				$environment[ MutationProcess::REPORTED_VARIABLE ] = $scratch . '/reports/report-1-parent';
			}
			$process = proc_open(
				[ PHP_BINARY, $script, $root ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'file', $scratch . '/stderr', 'w' ],
				],
				$pipes,
				null,
				$environment
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			self::assertSame( 0, proc_close( $process ), $output . file_get_contents( $scratch . '/stderr' ) );
			self::assertSame( [ $host, $host ], json_decode( $output, true ), 'The server of the database suite.' );
			self::assertFileDoesNotExist( $scratch . '/marker' );
			$reports = (array) glob( $scratch . '/reports/report-*' );
			self::assertCount( 1, $reports );
			$lines = (string) preg_replace( '/^Process [0-9]+:? /m', '', (string) file_get_contents( (string) $reports[0] ) );
			if ( $isolated ) {
				self::assertSame( "reported in.\n", $lines, 'The test states nothing and clears nothing.' );
				return;
			}
			$expected = "reported in.\ncan write durably.\n" . ( '' === $problem ? '' : $problem . "\n" ) . "ended with PHP's own file wrapper.\n";
			self::assertSame( $expected, $lines );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	/**
	 * What the servers answer, keyed by host, for the checks before and after the gate.
	 *
	 * @param array<string, array<string, string>|null> $changes Answers that differ from a clean one.
	 * @return array<string, array<string, string>|null>
	 */
	private static function answers( array $changes = [] ): array {
		$answers = [];
		foreach ( [ 'a.example.com:3306', 'b.example.com:3306', 'c.example.com:3306' ] as $number => $host ) {
			$answers[ $host ] = [
				'server_uuid'                              => '00000000-0000-0000-0000-00000000000' . $number,
				'version'                                  => '8.4.13',
				'Uptime'                                   => '7200',
				'performance_schema'                       => '1',
				'Performance_schema_thread_instances_lost' => '0',
				'Performance_schema_thread_classes_lost'   => '0',
			];
		}
		foreach ( $changes as $host => $change ) {
			$answers[ $host ] = null === $change ? null : $change + (array) $answers[ $host ];
		}
		return $answers;
	}

	/**
	 * Answers before the gate and what the check says against them.
	 *
	 * @return array<string, array{array<string, array<string, string>|null>, list<string>}>
	 */
	public static function before_answers(): array {
		return [
			'three servers of their own'       => [ self::answers(), [] ],
			'one server named twice'           => [ self::answers( [ 'c.example.com:3306' => [ 'server_uuid' => '00000000-0000-0000-0000-000000000000' ] ] ), [ 'a.example.com:3306 and c.example.com:3306 are one database server (server_uuid 00000000-0000-0000-0000-000000000000): each thread needs a server of its own.' ] ],
			'a server that does not answer'    => [ self::answers( [ 'b.example.com:3306' => null ] ), [ 'The database server b.example.com:3306 does not answer with the account of BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD.' ] ],
			'a server without an identity'     => [ self::answers( [ 'b.example.com:3306' => [ 'server_uuid' => '' ] ] ), [ 'The database server b.example.com:3306 reports no server_uuid, so it cannot be shown to be a server of its own.' ] ],
			'another version'                  => [ self::answers( [ 'c.example.com:3306' => [ 'version' => '8.4.12' ] ] ), [ 'The database server c.example.com:3306 runs 8.4.12 and a.example.com:3306 runs 8.4.13: a mutant must meet the same server on every thread.' ] ],
			'a Performance Schema that is off' => [ self::answers( [ 'a.example.com:3306' => [ 'performance_schema' => '0' ] ] ), [ 'The Performance Schema of the database server a.example.com:3306 is off, so the exchange cannot see the other sessions and its tests fail whatever the mutation.' ] ],
		];
	}

	/**
	 * @param array<string, array<string, string>|null> $answers  What the servers answer.
	 * @param list<string>                              $problems What the check says.
	 */
	#[DataProvider( 'before_answers' )]
	public function test_the_gate_starts_only_on_servers_of_their_own( array $answers, array $problems ): void {
		self::assertSame( $problems, DatabaseServers::before( array_keys( $answers ), $answers ) );
	}

	public function test_the_gate_does_not_start_without_servers(): void {
		self::assertSame( [ 'BFR_TEST_MYSQL_HOSTS names no database server. The database mutation gate runs each Infection thread against a server of its own: name one per thread, as host:port separated by spaces.' ], DatabaseServers::before( [], [] ) );
		self::assertSame( [ 'BFR_TEST_MYSQL_HOSTS names no database server, so it is unknown which servers the gate used: the database scores cannot be trusted.' ], DatabaseServers::after( [], [], 60 ) );
	}

	/**
	 * Answers after a gate that started an hour ago, and what the check says against its scores.
	 *
	 * @return array<string, array{array<string, array<string, string>|null>, list<string>}>
	 */
	public static function after_answers(): array {
		return [
			'every server ran through the gate' => [ self::answers(), [] ],
			'a server that restarted'           => [ self::answers( [ 'b.example.com:3306' => [ 'Uptime' => '1200' ] ] ), [ 'The database server b.example.com:3306 has run for 1200 seconds, but the gate started 3600 seconds ago: the server restarted during the gate, the tests that ran on it then failed or skipped whatever the mutation, and the database scores cannot be trusted.' ] ],
			'an Uptime that is no number'       => [ self::answers( [ 'b.example.com:3306' => [ 'Uptime' => '-5' ] ] ), [ 'The database server b.example.com:3306 reports no readable Uptime, so it is unknown whether it ran through the gate: the database scores cannot be trusted.' ] ],
			'a server that no longer answers'   => [ self::answers( [ 'c.example.com:3306' => null ] ), [ 'The database server c.example.com:3306 does not answer after the gate, so it is unknown whether it ran through it: the database scores cannot be trusted.' ] ],
			'thread instances lost'             => [ self::answers( [ 'a.example.com:3306' => [ 'Performance_schema_thread_instances_lost' => '3' ] ] ), [ 'a.example.com:3306: The Performance Schema of the database server could not instrument every thread during the run (Performance_schema_thread_instances_lost = 3). performance_schema.processlist then leaves sessions out, the exchange\'s check of the other sessions fails whatever the mutation, and the gate counts such mutants as killed: the database scores cannot be trusted. Give the server more Performance Schema threads (performance_schema_max_thread_instances) and run the gate again on a fresh server.' ] ],
			'a thread class lost'               => [ self::answers( [ 'b.example.com:3306' => [ 'Performance_schema_thread_classes_lost' => '1' ] ] ), [ 'b.example.com:3306: The Performance Schema of the database server could not instrument every thread during the run (Performance_schema_thread_classes_lost = 1). performance_schema.processlist then leaves sessions out, the exchange\'s check of the other sessions fails whatever the mutation, and the gate counts such mutants as killed: the database scores cannot be trusted. Give the server more Performance Schema threads (performance_schema_max_thread_instances) and run the gate again on a fresh server.' ] ],
			'a counter that is no number'       => [ self::answers( [ 'c.example.com:3306' => [ 'Performance_schema_thread_instances_lost' => '-1' ] ] ), [ 'c.example.com:3306: The database server reports no readable Performance_schema_thread_instances_lost, so it is unknown whether its Performance Schema listed every session: the database scores cannot be trusted.' ] ],
			'the Performance Schema off'        => [ self::answers( [ 'a.example.com:3306' => [ 'performance_schema' => '0' ] ] ), [ 'a.example.com:3306: The Performance Schema of the database server is off, so the exchange cannot see the other sessions and its tests fail whatever the mutation: the database scores cannot be trusted.' ] ],
		];
	}

	/**
	 * @param array<string, array<string, string>|null> $answers  What the servers answer.
	 * @param list<string>                              $problems What the check says.
	 */
	#[DataProvider( 'after_answers' )]
	public function test_the_scores_stand_only_if_every_server_ran_through_the_gate( array $answers, array $problems ): void {
		self::assertSame( $problems, DatabaseServers::after( array_keys( $answers ), $answers, 3600 ) );
	}

	public function test_a_counter_that_a_server_does_not_report_fails_the_scores(): void {
		$answers = self::answers();
		unset( $answers['b.example.com:3306']['Performance_schema_thread_classes_lost'] );
		self::assertSame( [ 'b.example.com:3306: The database server reports no readable Performance_schema_thread_classes_lost, so it is unknown whether its Performance Schema listed every session: the database scores cannot be trusted.' ], DatabaseServers::after( array_keys( $answers ), $answers, 3600 ) );
		// A server that answered the other questions but not those about its Performance Schema.
		$answers                       = self::answers();
		$answers['c.example.com:3306'] = array_diff_key( (array) $answers['c.example.com:3306'], array_flip( [ 'performance_schema', 'Performance_schema_thread_instances_lost', 'Performance_schema_thread_classes_lost' ] ) );
		self::assertSame( [ 'c.example.com:3306: The database server cannot be asked about its Performance Schema, so it is unknown whether that listed every session: the database scores cannot be trusted.' ], DatabaseServers::after( array_keys( $answers ), $answers, 3600 ) );
	}

	/**
	 * The script as bin/database-servers.sh runs it: its arguments, what the servers answer (null
	 * for no answers file), the exit status, and text that it prints.
	 *
	 * @return array<string, array{list<string>, array<string, array<string, string>|null>|null, int, string}>
	 */
	public static function checks(): array {
		return [
			'before, on servers of their own'    => [ [ 'before' ], self::answers(), 0, 'Database servers of the gate, one per Infection thread: 3, each a server of its own: a.example.com:3306 (8.4.13, server_uuid 00000000-0000-0000-0000-000000000000), b.example.com:3306 (8.4.13, server_uuid 00000000-0000-0000-0000-000000000001), c.example.com:3306 (8.4.13, server_uuid 00000000-0000-0000-0000-000000000002).' ],
			'before, one server named twice'     => [ [ 'before' ], self::answers( [ 'b.example.com:3306' => [ 'server_uuid' => '00000000-0000-0000-0000-000000000000' ] ] ), 1, '::error::a.example.com:3306 and b.example.com:3306 are one database server' ],
			'before, a server not answering'     => [ [ 'before' ], [ 'a.example.com:3306' => null ] + self::answers(), 1, '::error::The database server a.example.com:3306 does not answer' ],
			'after, a minute after the start'    => [ [ 'after', (string) ( time() - 60 ) ], self::answers(), 0, 'Every database server of the gate ran through it without a restart (' ],
			'after, a restart'                   => [ [ 'after', (string) ( time() - 9000 ) ], self::answers(), 1, '::error::The database server a.example.com:3306 has run for 7200 seconds, but the gate started 9' ],
			'after, servers that do not answer'  => [ [ 'after', (string) time() ], null, 1, '::error::The database server a.example.com:3306 does not answer after the gate' ],
			'no mode'                            => [ [], self::answers(), 1, '::error::Usage: php bin/check-database-servers.php before, or after with the Unix time at which the gate started.' ],
			'after without a start'              => [ [ 'after' ], self::answers(), 1, '::error::Usage:' ],
			'after with a start that is no time' => [ [ 'after', 'soon' ], self::answers(), 1, '::error::Usage:' ],
		];
	}

	/**
	 * @param list<string>                                   $arguments The arguments.
	 * @param array<string, array<string, string>|null>|null $answers   What the servers answer.
	 * @param int                                            $expected  The exit status.
	 * @param string                                         $printed   Text that it prints.
	 */
	#[DataProvider( 'checks' )]
	public function test_the_check_script_reports_what_the_servers_answer( array $arguments, ?array $answers, int $expected, string $printed ): void {
		$scratch = TempDir::create( 'database-servers' );
		try {
			$file = $scratch . '/answers.json';
			file_put_contents( $file, (string) json_encode( $answers ?? [] ) );
			[ $status, $output ] = self::command( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/check-database-servers.php', ...$arguments, '--answers', $file ], [ DatabaseServers::VARIABLE => 'a.example.com:3306 b.example.com:3306 c.example.com:3306' ] );
			self::assertSame( $expected, $status, $output );
			self::assertStringContainsString( $printed, $output );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_the_check_script_asks_the_servers_themselves(): void {
		// Without --answers the script loads the database suite's connection and, through it,
		// source files that end a request silently unless ABSPATH is defined.
		[ $status, $output ] = self::command( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/check-database-servers.php', 'before' ], [ DatabaseServers::VARIABLE => 'down.invalid:3306' ] );
		self::assertSame( 1, $status, $output );
		self::assertSame( "::error::The database server down.invalid:3306 does not answer with the account of BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD.\n", $output );
	}

	/**
	 * Runs of bin/database-servers.sh with stand-ins for the check script and the command: the
	 * variables for the stand-ins, the arguments, the exit status and the lines that the
	 * stand-ins log, "<started>" for the start time.
	 *
	 * @return array<string, array{array<string, string>, list<string>, int, list<string>}>
	 */
	public static function wrapped_runs(): array {
		$before = 'check before hosts=a.example.com:3306 b.example.com:3306 c.example.com:3306';
		$after  = 'check after <started> hosts=a.example.com:3306 b.example.com:3306 c.example.com:3306';
		$ran    = 'command --no-progress --threads=3 host=a.example.com:3306 token=unset';
		return [
			'one thread per server, the first for the initial run' => [ [], [ '--no-progress' ], 0, [ $before, $ran, $after ] ],
			'the command\'s status passes on'              => [ [ 'FAKE_STATUS' => '3' ], [ '--no-progress' ], 3, [ $before, $ran, $after ] ],
			'a failed check before keeps the command from running' => [ [ 'FAKE_BEFORE' => '1' ], [ '--no-progress' ], 1, [ $before ] ],
			'a failed check after fails a passing command' => [ [ 'FAKE_AFTER' => '1' ], [ '--no-progress' ], 1, [ $before, $ran, $after ] ],
			'a failed check after fails a failing one too' => [
				[
					'FAKE_STATUS' => '3',
					'FAKE_AFTER'  => '1',
				],
				[ '--no-progress' ],
				1,
				[ $before, $ran, $after ],
			],
			// The list as DatabaseServers reads it: every word, across lines too.
			'servers named on several lines'               => [ [ DatabaseServers::VARIABLE => "a.example.com:3306\n\tb.example.com:3306 \nc.example.com:3306\n" ], [ '--no-progress' ], 0, [ $before, $ran, $after ] ],
			'threads as an argument'                       => [ [], [ '--threads=4' ], 1, [] ],
			'threads as a short option'                    => [ [], [ '-j4' ], 1, [] ],
			'threads as a separate value'                  => [ [], [ '--threads', '4' ], 1, [] ],
		];
	}

	/**
	 * The script runs the command with --threads set to the number of servers, the first server
	 * for Infection's initial test run and no TEST_TOKEN of its own, between a check before and a
	 * check after it, and fails when either check fails.
	 *
	 * @param array<string, string> $environment Variables for the stand-ins.
	 * @param list<string>          $arguments   Arguments after the command.
	 * @param int                   $expected    The exit status.
	 * @param list<string>          $logged      What the stand-ins log.
	 */
	#[DataProvider( 'wrapped_runs' )]
	public function test_the_wrapper_runs_one_thread_per_server_between_two_checks( array $environment, array $arguments, int $expected, array $logged ): void {
		$root = TempDir::create( 'database-servers' );
		try {
			mkdir( $root . '/bin' );
			copy( dirname( __DIR__, 3 ) . '/bin/database-servers.sh', $root . '/bin/database-servers.sh' );
			file_put_contents( $root . '/bin/check-database-servers.php', '<?php file_put_contents( (string) getenv( "FAKE_LOG" ), "check " . implode( " ", array_slice( $argv, 1 ) ) . " hosts=" . preg_replace( "/\\s+/", " ", trim( (string) getenv( "BFR_TEST_MYSQL_HOSTS" ) ) ) . "\n", FILE_APPEND ); exit( (int) getenv( "before" === ( $argv[1] ?? "" ) ? "FAKE_BEFORE" : "FAKE_AFTER" ) );' );
			file_put_contents( $root . '/command.sh', "#!/usr/bin/env bash\necho \"command \$* host=\${BFR_TEST_MYSQL_HOST:-} token=\${TEST_TOKEN-unset}\" >> \"\$FAKE_LOG\"\nexit \"\${FAKE_STATUS:-0}\"\n" );
			chmod( $root . '/command.sh', 0755 );
			[ $status, $output ] = self::command(
				[ 'bash', $root . '/bin/database-servers.sh', $root . '/command.sh', ...$arguments ],
				$environment + [
					DatabaseServers::VARIABLE => 'a.example.com:3306 b.example.com:3306 c.example.com:3306',
					'BFR_TEST_MYSQL_HOST'     => 'shared.example.com:3306',
					DatabaseServers::TOKEN    => '7',
					'FAKE_LOG'                => $root . '/log',
				]
			);
			self::assertSame( $expected, $status, $output );
			$lines = is_file( $root . '/log' ) ? (array) file( $root . '/log', FILE_IGNORE_NEW_LINES ) : [];
			self::assertSame( $logged, array_map( static fn ( $line ): string => (string) preg_replace( '/^check after [0-9]{10,} /', 'check after <started> ', (string) $line ), $lines ), $output );
			if ( [] === $logged ) {
				self::assertStringContainsString( '::error::' . $arguments[0] . ': the database gate runs one Infection thread per server that BFR_TEST_MYSQL_HOSTS names, so the number of threads is not an argument.', $output );
			}
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_the_database_gate_runs_through_its_servers(): void {
		$root     = dirname( __DIR__, 3 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $composer );
		$commands = array_values( array_filter( (array) ( $composer['scripts']['mutation:database'] ?? [] ), static fn ( $command ): bool => ! str_starts_with( (string) $command, 'Composer\\' ) ) );
		self::assertSame( [ 'bin/database-servers.sh bin/mutation-gate.sh --configuration=infection-database.json5.dist --with-uncovered --only-covering-test-cases' ], $commands, 'The servers set the number of threads, and the script checks them before and after the gate.' );
		$phpunit = (string) file_get_contents( $root . '/tests/Mutation/database/phpunit.xml.dist' );
		self::assertStringContainsString( 'bootstrap="bootstrap.php"', $phpunit );
		self::assertStringContainsString( 'DatabaseServers::choose(', (string) file_get_contents( $root . '/tests/Mutation/database/bootstrap.php' ) );
	}

	/**
	 * Every server of the workflow is a service of the job with the image of the database job in
	 * ci.yml, by digest, with its data on tmpfs, and BFR_TEST_MYSQL_HOSTS names each of them once,
	 * in the order of their ports; the database suite before the gate runs against the first.
	 */
	public function test_the_workflow_gives_each_thread_a_server_of_its_own(): void {
		$root     = dirname( __DIR__, 3 );
		$workflow = (string) file_get_contents( $root . '/.github/workflows/database-mutation.yml' );
		self::assertSame( 1, preg_match( '/^ +image: (mysql:8\.4\.\d+@sha256:[0-9a-f]{64})$/m', (string) file_get_contents( $root . '/.github/workflows/ci.yml' ), $image ) );
		self::assertSame( 1, preg_match( "/^    services:\n((?:(?: {6}.*)?\n)+)/m", $workflow, $services ) );
		preg_match_all( "/^      (database-(\d+)):\n((?: {8}.*\n)+)/m", $services[1], $blocks, PREG_SET_ORDER );
		self::assertGreaterThanOrEqual( 2, count( $blocks ) );
		$hosts    = [];
		$template = null;
		foreach ( $blocks as $number => [ , $name, $index, $body ] ) {
			self::assertSame( (string) ( $number + 1 ), $index, 'The services are numbered from 1.' );
			self::assertSame( 1, preg_match( '/^ +- (\d+):3306$/m', $body, $port ), $name );
			$shape      = (string) preg_replace( '/^( +- )\d+(:3306)$/m', '$1PORT$2', $body );
			$template ??= $shape;
			self::assertSame( $template, $shape, $name . ' differs from database-1 in more than its port.' );
			self::assertStringContainsString( 'image: ' . $image[1] . "\n", $body, $name );
			self::assertStringContainsString( '--tmpfs /var/lib/mysql', $body, $name );
			$hosts[] = '127.0.0.1:' . $port[1];
		}
		self::assertSame( count( $hosts ), count( array_unique( $hosts ) ), 'Each service has a port of its own.' );
		self::assertSame( 1, preg_match( '/^      BFR_TEST_MYSQL_HOSTS: (.+)$/m', $workflow, $listed ) );
		self::assertSame( $hosts, DatabaseServers::hosts( $listed[1] ) );
		self::assertSame( 1, preg_match( '/^      BFR_TEST_MYSQL_HOST: (.+)$/m', $workflow, $first ) );
		self::assertSame( $hosts[0], $first[1] );
	}

	/**
	 * A stand-in for a session on a server: it answers each query for the session list with the
	 * next list, records every statement, and refuses KILL for a session that has gone.
	 *
	 * @param list<list<array<string, string|null>>> $lists What the server lists, query by query.
	 */
	private static function server( array $lists ): Connection {
		return new class( $lists ) implements Connection {
			/** @var list<string> */
			public array $sent = [];

			/** @param list<list<array<string, string|null>>> $lists */
			public function __construct( private array $lists ) {
			}

			public function rows( string $sql ): array {
				$this->sent[] = $sql;
				if ( str_starts_with( $sql, 'SELECT ID,USER,DB,COMMAND FROM performance_schema.processlist' ) ) {
					if ( [] === $this->lists ) {
						throw new DatabaseFailed( 'Query failed: SELECT command denied to user.' );
					}
					return array_shift( $this->lists );
				}
				if ( 'KILL 9' === $sql ) {
					throw new DatabaseFailed( 'Query failed: Unknown thread id: 9' );
				}
				return [];
			}
		};
	}

	/**
	 * Runs a command with variables added to this process's environment.
	 *
	 * @param list<string>          $command     The command and its arguments.
	 * @param array<string, string> $environment Variables for it.
	 * @return array{int, string} The exit status and the output.
	 */
	private static function command( array $command, array $environment ): array {
		$process = proc_open(
			$command,
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			null,
			$environment + [ 'PATH' => dirname( PHP_BINARY ) . PATH_SEPARATOR . getenv( 'PATH' ) ] + getenv()
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $process ), $output ];
	}
}
