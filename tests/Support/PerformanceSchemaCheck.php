<?php
/**
 * Whether the Performance Schema of the database gate's server instrumented every thread.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use Throwable;

/**
 * The exchange's check of the other sessions compares the server's Threads_connected with the
 * rows of performance_schema.processlist (Database\Restore\SwapCatalog::complete()). A connection
 * whose thread the Performance Schema could not instrument is missing from that list, so the
 * check fails whatever the mutation, the exchange test fails, and the database gate counts the
 * mutant as killed. The server counts such threads in Performance_schema_thread_instances_lost
 * and Performance_schema_thread_classes_lost, which start at zero with the server.
 * bin/check-performance-schema.php asks the gate's server after the gate (docs/04 §5.6).
 */
final class PerformanceSchemaCheck {

	/** The counters of threads that the Performance Schema could not instrument. */
	public const COUNTERS = [ 'Performance_schema_thread_instances_lost', 'Performance_schema_thread_classes_lost' ];

	/**
	 * The server's answer: whether the Performance Schema is on, and the counters.
	 *
	 * @param MysqliConnection|null $connection The gate's server, as the database suite connects.
	 * @return array<string, string> Nothing when the server cannot be asked.
	 */
	public static function status( ?MysqliConnection $connection ): array {
		if ( null === $connection ) {
			return [];
		}
		try {
			$status = [ 'performance_schema' => (string) ( $connection->rows( 'SELECT @@GLOBAL.performance_schema AS enabled' )[0]['enabled'] ?? '' ) ];
			foreach ( $connection->rows( "SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode( "','", self::COUNTERS ) . "')" ) as $row ) {
				$status[ (string) ( $row['Variable_name'] ?? '' ) ] = (string) ( $row['Value'] ?? '' );
			}
			return $status;
		} catch ( Throwable ) {
			return [];
		}
	}

	/**
	 * What the answer says against the gate's scores; nothing when it is clean.
	 *
	 * @param array<string, string> $status The server's answer.
	 * @return list<string>
	 */
	public static function problems( array $status ): array {
		if ( [] === $status ) {
			return [ 'The database server of the gate cannot be asked (BFR_TEST_MYSQL_HOST, _USER, _PASSWORD), so it is unknown whether its Performance Schema listed every session: the database scores cannot be trusted.' ];
		}
		if ( '1' !== ( $status['performance_schema'] ?? '' ) ) {
			return [ 'The Performance Schema of the database server is off, so the exchange cannot see the other sessions and its tests fail whatever the mutation: the database scores cannot be trusted.' ];
		}
		$problems = [];
		foreach ( self::COUNTERS as $counter ) {
			$value = $status[ $counter ] ?? '';
			if ( 1 !== preg_match( '/\A[0-9]{1,18}\z/', $value ) ) {
				$problems[] = "The database server reports no readable {$counter}, so it is unknown whether its Performance Schema listed every session: the database scores cannot be trusted.";
			} elseif ( '' !== ltrim( $value, '0' ) ) {
				$problems[] = "The Performance Schema of the database server could not instrument every thread during the run ({$counter} = {$value}). performance_schema.processlist then leaves sessions out, the exchange's check of the other sessions fails whatever the mutation, and the gate counts such mutants as killed: the database scores cannot be trusted. Give the server more Performance Schema threads (performance_schema_max_thread_instances) and run the gate again on a fresh server.";
			}
		}
		return $problems;
	}
}
