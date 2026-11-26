<?php
/**
 * One database server per process of the database mutation gate.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use Throwable;

/**
 * Infection runs the mutant processes of the database gate in parallel, and their tests look at
 * the whole server: the exchange compares the sessions of its account with every session of the
 * server, and one test lowers the server's global max_allowed_packet for a moment. On one shared
 * server a test could fail through what another process did at that moment, and its mutant
 * counted as killed (docs/04 §5.6). So each process has a server of its own.
 *
 * BFR_TEST_MYSQL_HOSTS names the servers, one per Infection thread, as host:port separated by
 * spaces, with the account of BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD on each.
 * bin/database-servers.sh runs as many threads as it names and checks the servers before and after
 * the run (bin/check-database-servers.php). Infection numbers its threads from 1 and gives each
 * mutant process the number of its thread in TEST_TOKEN; a thread runs one process at a time,
 * and the next one only after the last has ended. The bootstrap of the gate
 * (tests/Mutation/database/bootstrap.php) points BFR_TEST_MYSQL_HOST, through which the database
 * suite connects, at the server of the thread, for the process and for every process it starts.
 * A process whose thread has no server gets none: its tests reach no server and skip, and the
 * gate fails on the line that the process leaves in its report (MutationProcess::problem()).
 *
 * A process that Infection stopped at its timeout can leave its server changed: sessions of the
 * programs it started, which outlive it, and the lowered max_allowed_packet of a test it was in.
 * So a mutant process first ends every other session on its server and sets max_allowed_packet
 * back to the server's default. A test that PHPUnit runs in a process of its own does neither,
 * as its mutant process still holds its sessions.
 */
final class DatabaseServers {

	/** The servers of the gate, one per Infection thread: host:port, separated by spaces. */
	public const VARIABLE = 'BFR_TEST_MYSQL_HOSTS';

	/** The variable in which Infection gives a mutant process the number of its thread. */
	public const TOKEN = 'TEST_TOKEN';

	/** The variable of the server that the database suite connects to (MysqliConnection). */
	public const HOST = 'BFR_TEST_MYSQL_HOST';

	/** The host of a process whose thread has no server: a name that never resolves (RFC 6761). */
	public const NONE = 'no-server-of-its-own.invalid';

	/** How long the sessions that an earlier process left may take to end, in seconds. */
	private const ENDING = 10.0;

	/**
	 * The servers that a value of BFR_TEST_MYSQL_HOSTS names, in its order.
	 *
	 * @param string|false $value The value, or false when the variable is not set.
	 * @return list<string>
	 */
	public static function hosts( string|false $value ): array {
		$hosts = false === $value ? [] : preg_split( '/\s+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		return false === $hosts ? [] : $hosts;
	}

	/**
	 * Run by the bootstrap of the database gate after MutationProcess::prepare(), with what that
	 * returned. Outside a parallel run, without TEST_TOKEN (Infection's initial test run, or
	 * PHPUnit alone), it changes nothing. Throws nothing and never fails the process, as Infection
	 * would count that as a detected mutant: what goes wrong goes into the process's report.
	 *
	 * @param string $root   The repository's root directory.
	 * @param bool   $mutant Whether this is a mutant process itself, not one of its isolated tests.
	 */
	public static function choose( string $root, bool $mutant ): void {
		try {
			$problem = self::own( getenv( self::TOKEN ), getenv( self::VARIABLE ), $mutant );
		} catch ( Throwable $error ) {
			$problem = 'The database server of this process could not be cleared: ' . $error->getMessage();
		}
		if ( null !== $problem ) {
			MutationProcess::problem( $root, $problem );
		}
	}

	/**
	 * Points the database suite at the server of this process's thread and, in a mutant process,
	 * clears that server of what an earlier process left.
	 *
	 * @param string|false $token  TEST_TOKEN, or false when it is not set.
	 * @param string|false $hosts  BFR_TEST_MYSQL_HOSTS, or false when it is not set.
	 * @param bool         $mutant Whether to clear the server.
	 * @return string|null What went wrong, or null.
	 */
	public static function own( string|false $token, string|false $hosts, bool $mutant ): ?string {
		if ( false === $token || '' === $token ) {
			return null;
		}
		$servers = self::hosts( $hosts );
		$host    = 1 === preg_match( '/\A[1-9][0-9]{0,5}\z/', $token ) ? ( $servers[ (int) $token - 1 ] ?? null ) : null;
		if ( null === $host ) {
			self::point( self::NONE );
			return sprintf( 'Thread %s has no database server of its own: %s names %d. The tests of this process reach no server.', $token, self::VARIABLE, count( $servers ) );
		}
		self::point( $host );
		return $mutant ? self::clear( $host ) : null;
	}

	/**
	 * Ends every session on the server but this one and the server's daemons, waits until they
	 * have gone, and sets the server's max_allowed_packet back to its default. The server belongs
	 * to this process's thread, so every other session is one that an earlier process left.
	 *
	 * @param string $host The server, which BFR_TEST_MYSQL_HOST names already.
	 * @return string|null What went wrong, or null.
	 * @throws \RuntimeException If the server refuses a statement.
	 */
	private static function clear( string $host ): ?string {
		$connection = MysqliConnection::connect();
		if ( null === $connection ) {
			return sprintf( 'The database server of this process, %s, does not answer: its tests skip.', $host );
		}
		try {
			$until = microtime( true ) + self::ENDING;
			while ( true ) {
				$left = $connection->rows( "SELECT ID,USER,DB,COMMAND FROM performance_schema.processlist WHERE ID<>CONNECTION_ID() AND COMMAND<>'Daemon'" );
				if ( [] === $left || microtime( true ) >= $until ) {
					break;
				}
				foreach ( $left as $session ) {
					// A session that ends meanwhile is unknown to KILL, which is what is wanted.
					$connection->mysqli->query( 'KILL ' . (int) $session['ID'] );
				}
				usleep( 50000 );
			}
			if ( [] !== $left ) {
				return sprintf( 'Sessions that an earlier process left on %s did not end within %d seconds: %s.', $host, (int) self::ENDING, (string) json_encode( $left ) );
			}
			$connection->execute( 'SET GLOBAL max_allowed_packet=DEFAULT' );
			return null;
		} finally {
			$connection->mysqli->close();
		}
	}

	/**
	 * Makes the host the server of the database suite, in this process and in the processes it
	 * starts: PHPUnit hands a test in a process of its own either this environment or one built
	 * from $_SERVER.
	 */
	private static function point( string $host ): void {
		putenv( self::HOST . '=' . $host );
		$_SERVER[ self::HOST ] = $host;
	}

	/**
	 * What a server answers to the checks of bin/check-database-servers.php: its identity, its
	 * version, whether its Performance Schema is on, how long it has run, and the Performance
	 * Schema's counters of threads it could not instrument.
	 *
	 * @param string $host The server, as host:port.
	 * @return array<string, string>|null Null when the server does not answer.
	 */
	public static function answer( string $host ): ?array {
		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = @new \mysqli( $host, MysqliConnection::environment( 'BFR_TEST_MYSQL_USER', 'root' ), MysqliConnection::environment( 'BFR_TEST_MYSQL_PASSWORD', '' ) );
		if ( 0 !== $mysqli->connect_errno ) {
			return null;
		}
		$connection = new MysqliConnection( $mysqli );
		try {
			$answer = [];
			foreach ( $connection->rows( 'SELECT @@GLOBAL.server_uuid AS server_uuid, VERSION() AS version' )[0] ?? [] as $name => $value ) {
				$answer[ $name ] = (string) $value;
			}
			foreach ( $connection->rows( "SHOW GLOBAL STATUS LIKE 'Uptime'" ) as $row ) {
				$answer['Uptime'] = (string) $row['Value'];
			}
			return $answer + PerformanceSchemaCheck::status( $connection );
		} catch ( Throwable ) {
			return null;
		} finally {
			$mysqli->close();
		}
	}

	/**
	 * What the answers say against running the gate on these servers: every server must answer,
	 * be a server of its own, run the version of the first and have its Performance Schema on.
	 *
	 * @param list<string>                       $hosts   The servers, as BFR_TEST_MYSQL_HOSTS names them.
	 * @param array<string, array<string, string>|null> $answers What each server answered.
	 * @return list<string> Nothing when the gate can run.
	 */
	public static function before( array $hosts, array $answers ): array {
		if ( [] === $hosts ) {
			return [ self::VARIABLE . ' names no database server. The database mutation gate runs each Infection thread against a server of its own: name one per thread, as host:port separated by spaces.' ];
		}
		$problems = [];
		$servers  = [];
		$version  = null;
		foreach ( $hosts as $host ) {
			$answer = $answers[ $host ] ?? null;
			if ( null === $answer ) {
				$problems[] = "The database server {$host} does not answer with the account of BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD.";
				continue;
			}
			$uuid = $answer['server_uuid'] ?? '';
			if ( '' === $uuid ) {
				$problems[] = "The database server {$host} reports no server_uuid, so it cannot be shown to be a server of its own.";
			} elseif ( isset( $servers[ $uuid ] ) ) {
				$problems[] = "{$servers[ $uuid ]} and {$host} are one database server (server_uuid {$uuid}): each thread needs a server of its own.";
			} else {
				$servers[ $uuid ] = $host;
			}
			$version ??= [ $host, $answer['version'] ?? '' ];
			if ( ( $answer['version'] ?? '' ) !== $version[1] ) {
				$problems[] = sprintf( 'The database server %s runs %s and %s runs %s: a mutant must meet the same server on every thread.', $host, $answer['version'] ?? 'no known version', $version[0], $version[1] );
			}
			if ( '1' !== ( $answer['performance_schema'] ?? '' ) ) {
				$problems[] = "The Performance Schema of the database server {$host} is off, so the exchange cannot see the other sessions and its tests fail whatever the mutation.";
			}
		}
		return $problems;
	}

	/**
	 * What the answers after the gate say against its scores: every server must still answer,
	 * must have run for at least the seconds since the gate started, so that no restart cut off
	 * the tests that ran on it or reset its counters, and its Performance Schema must have
	 * instrumented every thread (PerformanceSchemaCheck).
	 *
	 * @param list<string>                       $hosts   The servers, as BFR_TEST_MYSQL_HOSTS names them.
	 * @param array<string, array<string, string>|null> $answers What each server answered.
	 * @param int                                $seconds How long ago the gate started.
	 * @return list<string> Nothing when the scores stand.
	 */
	public static function after( array $hosts, array $answers, int $seconds ): array {
		if ( [] === $hosts ) {
			return [ self::VARIABLE . ' names no database server, so it is unknown which servers the gate used: the database scores cannot be trusted.' ];
		}
		$problems = [];
		foreach ( $hosts as $host ) {
			$answer = $answers[ $host ] ?? null;
			if ( null === $answer ) {
				$problems[] = "The database server {$host} does not answer after the gate, so it is unknown whether it ran through it: the database scores cannot be trusted.";
				continue;
			}
			$uptime = $answer['Uptime'] ?? '';
			if ( 1 !== preg_match( '/\A[0-9]{1,18}\z/', $uptime ) ) {
				$problems[] = "The database server {$host} reports no readable Uptime, so it is unknown whether it ran through the gate: the database scores cannot be trusted.";
			} elseif ( (int) $uptime < $seconds ) {
				$problems[] = "The database server {$host} has run for {$uptime} seconds, but the gate started {$seconds} seconds ago: the server restarted during the gate, the tests that ran on it then failed or skipped whatever the mutation, and the database scores cannot be trusted.";
			}
			foreach ( PerformanceSchemaCheck::problems( array_intersect_key( $answer, array_flip( [ 'performance_schema', ...PerformanceSchemaCheck::COUNTERS ] ) ) ) as $problem ) {
				$problems[] = $host . ': ' . $problem;
			}
		}
		return $problems;
	}
}
