<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/check-performance-schema.php, which composer mutation:database runs after the gate: it
 * fails when the server's Performance Schema could not instrument every thread, because the
 * exchange's check of the other sessions then fails whatever the mutation (docs/04 §5.6).
 */
final class PerformanceSchemaCheckTest extends TestCase {

	/**
	 * The server's answer, the exit status, and text that the script prints.
	 *
	 * @return array<string, array{array<string, string>, int, string}>
	 */
	public static function answers(): array {
		$clean = [
			'performance_schema'                       => '1',
			'Performance_schema_thread_instances_lost' => '0',
			'Performance_schema_thread_classes_lost'   => '0',
		];
		return [
			'nothing lost'                => [ $clean, 0, 'lost no thread during the run: Performance_schema_thread_instances_lost 0, Performance_schema_thread_classes_lost 0.' ],
			'thread instances lost'       => [ [ 'Performance_schema_thread_instances_lost' => '3' ] + $clean, 1, '::error::The Performance Schema of the database server could not instrument every thread during the run (Performance_schema_thread_instances_lost = 3).' ],
			'a thread class lost'         => [ [ 'Performance_schema_thread_classes_lost' => '1' ] + $clean, 1, '(Performance_schema_thread_classes_lost = 1)' ],
			'a counter missing'           => [ array_diff_key( $clean, [ 'Performance_schema_thread_classes_lost' => '' ] ), 1, '::error::The database server reports no readable Performance_schema_thread_classes_lost' ],
			'a counter that is no number' => [ [ 'Performance_schema_thread_instances_lost' => '-1' ] + $clean, 1, 'no readable Performance_schema_thread_instances_lost' ],
			'the Performance Schema off'  => [ [ 'performance_schema' => '0' ] + $clean, 1, '::error::The Performance Schema of the database server is off' ],
			'no server to ask'            => [ [], 1, '::error::The database server of the gate cannot be asked' ],
		];
	}

	/**
	 * @param array<string, string> $answer   The server's answer.
	 * @param int                   $expected The exit status.
	 * @param string                $printed  Text the script prints.
	 */
	#[DataProvider( 'answers' )]
	public function test_the_check_fails_unless_every_thread_was_instrumented( array $answer, int $expected, string $printed ): void {
		$scratch = TempDir::create( 'performance-schema' );
		try {
			file_put_contents( $scratch . '/status.json', (string) json_encode( $answer ) );
			// Composer passes the gate's arguments on, as --no-progress here.
			[ $status, $output ] = self::check( [ '--no-progress', '--status', $scratch . '/status.json' ] );
			self::assertSame( $expected, $status, $output );
			self::assertStringContainsString( $printed, $output );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_an_unreachable_server_fails_the_check(): void {
		// Without --status the script loads the database suite's connection and, through it,
		// source files that end a request silently unless ABSPATH is defined.
		[ $status, $output ] = self::check( [ '--no-progress' ], [ 'BFR_TEST_MYSQL_HOST' => '127.0.0.1:9' ] );
		self::assertSame( 1, $status, $output );
		self::assertStringContainsString( '::error::The database server of the gate cannot be asked', $output );
	}

	public function test_the_database_gate_runs_the_check_after_infection(): void {
		$scripts = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/composer.json' ), true )['scripts']['mutation:database'] ?? [];
		$scripts = array_values( (array) $scripts );
		$gate    = array_keys( array_filter( $scripts, static fn ( $command ): bool => str_contains( (string) $command, 'bin/mutation-gate.sh' ) ) );
		$check   = array_keys( array_filter( $scripts, static fn ( $command ): bool => str_contains( (string) $command, 'bin/check-performance-schema.php' ) ) );
		self::assertCount( 1, $gate, 'composer mutation:database runs the gate once.' );
		self::assertCount( 1, $check, 'composer mutation:database checks the Performance Schema.' );
		self::assertGreaterThan( $gate[0], $check[0], 'The check runs after the gate, on the server it used.' );
	}

	/**
	 * Runs the script with the arguments.
	 *
	 * @param list<string>          $arguments   The arguments.
	 * @param array<string, string> $environment Variables for the script.
	 * @return array{int, string} The exit status and the output.
	 */
	private static function check( array $arguments, array $environment = [] ): array {
		$process = proc_open(
			array_merge( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/check-performance-schema.php' ], $arguments ),
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			null,
			$environment + getenv()
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $process ), $output ];
	}
}
