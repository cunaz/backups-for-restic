<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\MutationProcess;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\UserSpaceFiles;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mutation gates measure what they claim: in each mutant process the tests run with PHP's
 * own file wrapper and the mutated class in memory (tests/Support/MutationProcess.php), and a
 * process where that fails turns the gate red instead of counting its mutant as killed.
 */
final class MutationGatesTest extends TestCase {

	/**
	 * The gate configurations.
	 *
	 * @return array<string, array{string}>
	 */
	public static function gates(): array {
		$gates = [];
		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/infection*.json5.dist' ) as $path ) {
			$gates[ basename( (string) $path ) ] = [ basename( (string) $path ) ];
		}
		return $gates;
	}

	#[DataProvider( 'gates' )]
	public function test_the_bootstrap_of_each_gate_preloads_every_directory_it_mutates( string $gate ): void {
		$root   = dirname( __DIR__, 3 );
		$config = self::json5( $root . '/' . $gate );

		$config_dir = $config['phpUnit']['configDir'] ?? '';
		self::assertIsString( $config_dir );
		$phpunit = new DOMDocument();
		self::assertTrue( @$phpunit->load( $root . '/' . $config_dir . '/phpunit.xml.dist' ), $gate . ': no phpunit.xml.dist in ' . $config_dir . '.' );
		$xpath     = new DOMXPath( $phpunit );
		$bootstrap = realpath( $root . '/' . $config_dir . '/' . $xpath->evaluate( 'string(/phpunit/@bootstrap)' ) );
		self::assertSame( realpath( $root . '/tests/Mutation/bootstrap.php' ), $bootstrap, $gate . ' runs its tests without tests/Mutation/bootstrap.php.' );
		self::assertStringContainsString( 'MutationProcess::prepare(', (string) file_get_contents( (string) $bootstrap ) );

		$options = $config['testFrameworkOptions'] ?? '';
		self::assertIsString( $options );
		self::assertSame( 1, preg_match( '/--testsuite=(\S+)/', $options, $suite ), $gate . ' selects no test suite.' );
		$suites = $xpath->query( '/phpunit/testsuites/testsuite[@name="' . $suite[1] . '"]' );
		self::assertSame( 1, false === $suites ? 0 : $suites->length, $gate . ' selects the test suite ' . $suite[1] . ', which ' . $config_dir . '/phpunit.xml.dist does not define.' );

		$directories = $config['source']['directories'] ?? [];
		self::assertIsArray( $directories );
		self::assertNotEmpty( $directories );
		self::assertSame(
			[],
			self::uncovered( $directories, MutationProcess::DIRECTORIES ),
			$gate . ' mutates code that the bootstrap does not load before it restores the file wrapper. Add the directory to MutationProcess::DIRECTORIES, or every mutant there escapes.'
		);
	}

	public function test_a_mutated_directory_outside_the_preload_is_reported(): void {
		self::assertSame( [ 'src/Backend' ], self::uncovered( [ 'src/Restore', 'src/Backend', 'src/Restore/Planner/' ], MutationProcess::DIRECTORIES ) );
		self::assertSame( [ 'src', 'src/RestoreLater' ], self::uncovered( [ 'src', './src/Restore', 'src/RestoreLater' ], [ 'src/Restore' ] ) );
	}

	public function test_every_gate_runs_through_the_script_that_checks_the_canary(): void {
		$root     = dirname( __DIR__, 3 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $composer );
		self::assertIsArray( $composer['scripts'] ?? null );
		$configured = [];
		foreach ( $composer['scripts'] as $name => $commands ) {
			foreach ( (array) $commands as $command ) {
				self::assertIsString( $command );
				self::assertStringNotContainsString( '.tools/bin/infection', $command, 'composer ' . $name . ' runs Infection without bin/mutation-gate.sh, which fails the gate when the canary fires.' );
				if ( str_starts_with( $command, 'bin/mutation-gate.sh ' ) ) {
					$configured[] = 1 === preg_match( '/--configuration=(\S+)/', $command, $match ) ? $match[1] : 'infection.json5.dist';
				}
			}
		}
		sort( $configured );
		$gates = array_keys( self::gates() );
		sort( $gates );
		self::assertSame( $gates, $configured, 'Each gate has one composer script.' );
		$workflows = (array) glob( $root . '/.github/workflows/*.yml' );
		self::assertNotEmpty( $workflows );
		foreach ( $workflows as $workflow ) {
			self::assertStringNotContainsString( '.tools/bin/infection', (string) file_get_contents( (string) $workflow ), basename( (string) $workflow ) . ' runs Infection without the canary check: use the composer scripts.' );
		}
	}

	/**
	 * A mutant whose copy compiles, and one whose copy does not.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function mutants(): array {
		return [
			'the mutated copy compiles'       => [ 'public const MUTATED = true;', true ],
			'the mutated copy does not parse' => [ 'public const MUTATED = ;', false ],
		];
	}

	/**
	 * A mutant process as Infection starts it, with UserSpaceFiles in place of its interceptor:
	 * the wrapper that serves the mutated copy first, then the bootstrap of the mutation suites.
	 */
	#[DataProvider( 'mutants' )]
	public function test_a_mutant_process_runs_its_tests_with_the_mutated_class_and_the_plain_wrapper( string $mutation, bool $compiles ): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'mutation-process' );
		try {
			$original = $root . '/src/Restore/ChunkDigest.php';
			$mutant   = $scratch . '/ChunkDigest.php';
			$source   = (string) file_get_contents( $original );
			self::assertStringContainsString( 'final class ChunkDigest {', $source );
			file_put_contents( $mutant, str_replace( 'final class ChunkDigest {', 'final class ChunkDigest { ' . $mutation, $source ) );
			$result = self::process( $scratch, $root, $original, $mutant );

			self::assertNull( $result['marker'], 'The canary fired.' );
			if ( ! $compiles ) {
				// As without the bootstrap: the wrapper stays, and a test that loads the class meets
				// the ParseError that Infection reports as a syntax error.
				self::assertTrue( $result['intercepted'] );
				self::assertSame( 'ParseError', $result['loaded'] );
				return;
			}
			self::assertFalse( $result['intercepted'], 'The plain file wrapper is back.' );
			self::assertSame( 'mutated', $result['loaded'], 'The class in memory is the mutated copy.' );
			self::assertTrue( $result['synced'], 'fsync() works in the tests.' );
			self::assertSame( [], $result['missing'], 'A file below MutationProcess::DIRECTORIES declares nothing of its PSR-4 name.' );
			self::assertSame( '', $result['output'], 'Loading the classes printed something.' );
			self::assertSame( [ [], [], [] ], [ $result['constants'], $result['functions'], $result['globals'] ], 'Loading the classes defined constants, functions or global variables.' );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_the_canary_fires_when_files_still_open_through_a_user_space_wrapper(): void {
		$scratch = TempDir::create( 'mutation-canary' );
		$marker  = $scratch . '/marker';
		try {
			UserSpaceFiles::enable();
			try {
				$problem  = MutationProcess::canary( [ $marker ] );
				$finished = MutationProcess::finish( [ $marker ] );
			} finally {
				UserSpaceFiles::disable();
			}
			self::assertSame( 'Files still open through the user-space stream wrapper after the restore.', $problem );
			self::assertFalse( $finished );
			self::assertSame(
				[
					'Process ' . getmypid() . ': Files still open through the user-space stream wrapper after the restore.',
					'Process ' . getmypid() . ': file:// was a user-space stream wrapper when the process ended.',
				],
				file( $marker, FILE_IGNORE_NEW_LINES )
			);

			unlink( $marker );
			self::assertNull( MutationProcess::canary( [ $marker ] ) );
			self::assertTrue( MutationProcess::finish( [ $marker ] ) );
			self::assertFileDoesNotExist( $marker );
		} finally {
			UserSpaceFiles::disable();
			TempDir::remove( $scratch );
		}
	}

	public function test_a_canary_whose_first_marker_cannot_be_written_reports_in_the_second(): void {
		$scratch  = TempDir::create( 'mutation-canary' );
		$missing  = $scratch . '/no-such-directory/marker';
		$fallback = $scratch . '/fallback';
		try {
			UserSpaceFiles::enable();
			try {
				$problem = MutationProcess::canary( [ $missing, $fallback ] );
			} finally {
				UserSpaceFiles::disable();
			}
			self::assertNotNull( $problem );
			self::assertFileDoesNotExist( $missing );
			self::assertSame( [ 'Process ' . getmypid() . ': ' . $problem ], file( $fallback, FILE_IGNORE_NEW_LINES ) );
		} finally {
			UserSpaceFiles::disable();
			TempDir::remove( $scratch );
		}
	}

	public function test_the_markers_are_the_variable_and_the_fallback_of_the_checkout(): void {
		$previous = getenv( MutationProcess::MARKER_VARIABLE );
		try {
			putenv( MutationProcess::MARKER_VARIABLE . '=/tmp/gate/marker' );
			self::assertSame( [ '/tmp/gate/marker', '/repo/.tools/mutation-canary' ], MutationProcess::markers( '/repo' ) );
			putenv( MutationProcess::MARKER_VARIABLE );
			self::assertSame( [ '/repo/.tools/mutation-canary' ], MutationProcess::markers( '/repo' ) );
		} finally {
			putenv( false === $previous ? MutationProcess::MARKER_VARIABLE : MutationProcess::MARKER_VARIABLE . '=' . $previous );
		}
	}

	/**
	 * What bin/mutation-gate.sh does after an Infection run, with a stand-in for Infection.
	 *
	 * @return array<string, array{array<string, string>, int, string}>
	 */
	public static function gate_runs(): array {
		return [
			'a clean run passes and passes the arguments on' => [ [], 0, 'infection --threads=2 --no-progress' ],
			'Infection fails and nothing was marked'   => [ [ 'FAKE_STATUS' => '3' ], 3, 'infection --threads=2' ],
			'Infection fails and a process marked the variable' => [
				[
					'FAKE_STATUS' => '3',
					'FAKE_MARK'   => 'variable',
				],
				1,
				'::error::Mutant processes that could not write durably: 2.',
			],
			'a process could write only the fallback'  => [ [ 'FAKE_MARK' => 'fallback' ], 1, 'Process 7: fsync() failed' ],
			'every process that ran tests reported in' => [ [], 0, 'Mutant processes that reported in: 6 of the 6 that Infection ran tests for.' ],
			'more reports than processes pass'         => [ [ 'FAKE_REPORTS' => '7' ], 0, 'reported in: 7 of the 6' ],
			'one process did not report in'            => [ [ 'FAKE_REPORTS' => '5' ], 1, '::error::Only 5 of the 6 mutant processes that Infection ran reported in; 1 did not run' ],
			'no process reported in'                   => [ [ 'FAKE_REPORTS' => '0' ], 1, '::error::Only 0 of the 6 mutant processes' ],
			'a report holds a line of the canary'      => [ [ 'FAKE_MARK' => 'report' ], 1, 'Process 9: fsync() failed' ],
			'the summary lacks a count'                => [ [ 'FAKE_SUMMARY' => 'partial' ], 1, '::error::Infection\'s summary ./.tools/infection/summary.log has no line "Errored: N"' ],
			'Infection wrote no summary'               => [ [ 'FAKE_SUMMARY' => 'none' ], 1, '::error::Infection wrote no summary to ./.tools/infection/summary.log' ],
			'Infection failed and wrote no summary'    => [
				[
					'FAKE_SUMMARY' => 'none',
					'FAKE_STATUS'  => '3',
				],
				3,
				'::error::Infection exited with 3 and wrote no summary',
			],
		];
	}

	/**
	 * Infection's exit status passes through, and a marker fails the gate after a failed run too.
	 *
	 * @param array<string, string> $environment What the stand-in for Infection does.
	 * @param int                   $expected    The script's exit status.
	 * @param string                $printed     Text it prints.
	 */
	#[DataProvider( 'gate_runs' )]
	public function test_the_gate_script_reports_infection_and_the_markers( array $environment, int $expected, string $printed ): void {
		$root = self::gate_checkout();
		try {
			[ $status, $output ] = self::gate( $root, $environment );
			self::assertSame( $expected, $status, $output );
			self::assertStringContainsString( $printed, $output );
			self::assertFileDoesNotExist( $root . '/.tools/mutation-canary' );
		} finally {
			chmod( $root . '/.tools', 0700 );
			TempDir::remove( $root );
		}
	}

	public function test_the_gate_script_refuses_to_start_when_a_marker_cannot_be_written(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'File permissions do not bind root.' );
		}
		$root = self::gate_checkout();
		try {
			chmod( $root . '/.tools', 0500 );
			[ $status, $output ] = self::gate( $root, [] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( '::error::The canary marker ' . $root . '/.tools/mutation-canary cannot be written', $output );
			self::assertStringNotContainsString( 'infection --threads=2', $output, 'Infection did not start.' );
		} finally {
			chmod( $root . '/.tools', 0700 );
			TempDir::remove( $root );
		}
	}

	public function test_a_summary_left_by_an_earlier_run_is_not_read(): void {
		$root = self::gate_checkout();
		try {
			mkdir( $root . '/.tools/infection' );
			file_put_contents( $root . '/.tools/infection/summary.log', "Killed by Test Framework: 0\nErrored: 0\nSyntax Errors: 0\nEscaped: 0\nTimed Out: 0\n" );
			[ $status, $output ] = self::gate( $root, [ 'FAKE_SUMMARY' => 'none' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( '::error::Infection wrote no summary', $output );
		} finally {
			TempDir::remove( $root );
		}
	}

	public function test_the_gate_reads_the_summary_of_the_configuration_it_is_given(): void {
		$root = self::gate_checkout();
		try {
			file_put_contents( $root . '/infection-other.json5.dist', "{\n    // \"summary\": \"commented/out.log\"\n    \"logs\": {\n        \"summary\": \".tools/infection/summary.log\"\n    }\n}\n" );
			unlink( $root . '/infection.json5.dist' );
			[ $status, $output ] = self::gate( $root, [], [ '--configuration=infection-other.json5.dist' ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( 'reported in: 6 of the 6', $output );

			file_put_contents( $root . '/infection-other.json5.dist', "{\n    \"logs\": {\n        \"text\": \"x.log\"\n    }\n}\n" );
			[ $status, $output ] = self::gate( $root, [], [ '--configuration', 'infection-other.json5.dist' ] );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( '::error::The Infection configuration infection-other.json5.dist names no summary log', $output );
			self::assertStringNotContainsString( 'infection --threads=2', $output, 'Infection did not start.' );
		} finally {
			TempDir::remove( $root );
		}
	}

	/**
	 * A real process whose canary fires while both markers cannot be written: when its report
	 * cannot be written either, it is missing; when the place turned unwritable only after it
	 * reported in, its report holds the canary's line. Either fails the gate.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function unwritable_markers(): array {
		return [
			'unwritable before the process reported in' => [ 'before', '::error::Only 5 of the 6 mutant processes that Infection ran reported in' ],
			'unwritable after the process reported in'  => [ 'after', 'Files still open through the user-space stream wrapper after the restore.' ],
		];
	}

	#[DataProvider( 'unwritable_markers' )]
	public function test_a_process_whose_markers_cannot_be_written_still_fails_the_gate( string $lock, string $printed ): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'File permissions do not bind root.' );
		}
		$root  = self::gate_checkout();
		$child = $root . '/child.php';
		file_put_contents(
			$child,
			'<?php
			require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . ';
			use BackupsForRestic\Tests\Support\MutationProcess;
			use BackupsForRestic\Tests\Support\UserSpaceFiles;
			$root    = (string) getenv( "FAKE_ROOT" );
			$markers = MutationProcess::markers( $root );
			$report  = MutationProcess::report_in( getenv( MutationProcess::REPORT_VARIABLE ) );
			if ( null !== $report ) {
				array_unshift( $markers, $report );
			}
			if ( "after" === getenv( "FAKE_LOCK" ) ) {
				chmod( (string) getenv( MutationProcess::REPORT_VARIABLE ), 0500 );
				chmod( $root . "/.tools", 0500 );
			}
			UserSpaceFiles::enable();
			MutationProcess::canary( $markers );
			UserSpaceFiles::disable();
			'
		);
		try {
			[ $status, $output ] = self::gate(
				$root,
				[
					'FAKE_REPORTS' => '5',
					'FAKE_CHILD'   => $child,
					'FAKE_PHP'     => PHP_BINARY,
					'FAKE_ROOT'    => $root,
					'FAKE_LOCK'    => $lock,
				]
			);
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( $printed, $output );
			self::assertFileDoesNotExist( $root . '/.tools/mutation-canary' );
		} finally {
			self::writable( $root );
			TempDir::remove( $root );
		}
	}

	/**
	 * Makes every directory below the root writable again, so that it can be removed.
	 */
	private static function writable( string $root ): void {
		chmod( $root, 0700 );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				chmod( $entry->getPathname(), 0700 );
			}
		}
	}

	/**
	 * A copy of bin/mutation-gate.sh in a temporary checkout, with a stand-in for Infection that
	 * prints its arguments, writes the markers that FAKE_MARK names, leaves FAKE_REPORTS reports
	 * (6), writes the summary that FAKE_SUMMARY names (6 processes, 3 mutants not covered), locks
	 * the reports and .tools when FAKE_LOCK is "before", runs FAKE_CHILD with FAKE_PHP, and exits
	 * with FAKE_STATUS.
	 */
	private static function gate_checkout(): string {
		$root = TempDir::create( 'mutation-gate' );
		mkdir( $root . '/bin' );
		mkdir( $root . '/.tools/bin', 0700, true );
		copy( dirname( __DIR__, 3 ) . '/bin/mutation-gate.sh', $root . '/bin/mutation-gate.sh' );
		file_put_contents( $root . '/infection.json5.dist', "{\n    \"logs\": {\n        \"text\": \".tools/infection/infection.log\",\n        \"summary\": \".tools/infection/summary.log\"\n    }\n}\n" );
		file_put_contents(
			$root . '/.tools/bin/infection',
			<<<'BASH'
			#!/usr/bin/env bash
			echo "infection $*"
			tools="$(dirname "$0")/.."
			case "${FAKE_MARK:-}" in
				variable) printf 'Process 7: fsync() failed\nProcess 7: still user-space\nProcess 8: fsync() failed\n' >> "$BFR_MUTATION_CANARY" ;;
				fallback) printf 'Process 7: fsync() failed\n' >> "$tools/mutation-canary" ;;
				report) printf 'Process 9 reported in.\nProcess 9: fsync() failed\n' > "$BFR_MUTATION_REPORTS/report-9-x" ;;
			esac
			for ((n = 0; n < ${FAKE_REPORTS:-6}; n++)); do
				printf 'Process %d reported in.\n' "$((100 + n))" > "$BFR_MUTATION_REPORTS/report-$((100 + n))-x"
			done
			mkdir -p "$tools/infection"
			case "${FAKE_SUMMARY:-full}" in
				full) printf 'Total: 9\n\nKilled by Test Framework: 3\nKilled by Static Analysis: 0\nErrored: 0\nSyntax Errors: 1\nEscaped: 1\nTimed Out: 1\nSkipped: 0\nIgnored: 0\nNot Covered: 3\n' > "$tools/infection/summary.log" ;;
				partial) printf 'Total: 9\n\nKilled by Test Framework: 3\n' > "$tools/infection/summary.log" ;;
			esac
			if [[ "${FAKE_LOCK:-}" == before ]]; then
				chmod 0500 "$BFR_MUTATION_REPORTS" "$tools"
			fi
			if [[ -n "${FAKE_CHILD:-}" ]]; then
				"$FAKE_PHP" "$FAKE_CHILD"
			fi
			exit "${FAKE_STATUS:-0}"
			BASH
		);
		chmod( $root . '/bin/mutation-gate.sh', 0755 );
		chmod( $root . '/.tools/bin/infection', 0755 );
		return $root;
	}

	/**
	 * Runs the copied script with the arguments and --threads=2 --no-progress.
	 *
	 * @param array<string, string> $environment Variables for the stand-in.
	 * @param list<string>          $arguments   Arguments before the others.
	 * @return array{int, string} The exit status and the output.
	 */
	private static function gate( string $root, array $environment, array $arguments = [] ): array {
		$process = proc_open(
			array_merge( [ 'bash', $root . '/bin/mutation-gate.sh' ], $arguments, [ '--threads=2', '--no-progress' ] ),
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			$root,
			$environment + [ 'TMPDIR' => $root ] + getenv()
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $process ), $output ];
	}

	/**
	 * Runs the bootstrap of the mutation suites in a new PHP process whose file wrapper serves
	 * the mutant for the original, and returns what that process found.
	 *
	 * @return array<string, mixed>
	 */
	private static function process( string $scratch, string $root, string $original, string $mutant ): array {
		$script = $scratch . '/process.php';
		file_put_contents(
			$script,
			<<<'PHP'
			<?php
			declare( strict_types=1 );
			[ , $root, $original, $mutant, $marker ] = $argv;
			require $root . '/tests/Support/UserSpaceFiles.php';
			\BackupsForRestic\Tests\Support\UserSpaceFiles::enable( $original, $mutant );
			require $root . '/tests/bootstrap.php';
			$constants = array_keys( get_defined_constants( true )['user'] ?? [] );
			$functions = get_defined_functions()['user'];
			$globals   = array_keys( $GLOBALS );
			ob_start();
			require $root . '/tests/Mutation/bootstrap.php';
			$output = (string) ob_get_clean();
			$result = [
				'intercepted' => \BackupsForRestic\Tests\Support\MutationProcess::intercepted(),
				'output'      => $output,
				'constants'   => array_values( array_diff( array_keys( get_defined_constants( true )['user'] ?? [] ), $constants ) ),
				'functions'   => array_values( array_diff( get_defined_functions()['user'], $functions ) ),
				'globals'     => array_values( array_diff( array_keys( $GLOBALS ), $globals, [ 'globals', 'output' ] ) ),
			];
			try {
				$result['loaded'] = defined( \BackupsForRestic\Restore\ChunkDigest::class . '::MUTATED' ) ? 'mutated' : 'original';
				$result['missing'] = \BackupsForRestic\Tests\Support\MutationProcess::preload( $root, \BackupsForRestic\Tests\Support\MutationProcess::DIRECTORIES );
			} catch ( \ParseError $error ) {
				$result['loaded'] = 'ParseError';
			}
			$file = tempnam( sys_get_temp_dir(), 'bfr-synced-' );
			$handle = fopen( (string) $file, 'wb' );
			$result['synced'] = false !== $handle && @fsync( $handle );
			false === $handle || fclose( $handle );
			unlink( (string) $file );
			register_shutdown_function(
				static function () use ( $result, $marker ): void {
					$result['marker'] = is_file( $marker ) ? file_get_contents( $marker ) : null;
					echo json_encode( $result, JSON_THROW_ON_ERROR );
				}
			);
			PHP
		);
		$marker  = $scratch . '/marker';
		$process = proc_open(
			[ PHP_BINARY, $script, $root, $original, $mutant, $marker ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'file', $scratch . '/stderr', 'w' ],
			],
			$pipes,
			null,
			[ MutationProcess::MARKER_VARIABLE => $marker ] + getenv()
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$status = proc_close( $process );
		self::assertSame( 0, $status, $output . file_get_contents( $scratch . '/stderr' ) );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );
		return $result;
	}

	/**
	 * The directories that are neither one of the preloaded ones nor below one.
	 *
	 * @param array<mixed> $directories Directories relative to the repository.
	 * @param list<string> $preloaded   MutationProcess::DIRECTORIES.
	 * @return list<string>
	 */
	private static function uncovered( array $directories, array $preloaded ): array {
		$uncovered = [];
		foreach ( $directories as $directory ) {
			$directory = rtrim( (string) preg_replace( '#^\./#', '', (string) $directory ), '/' );
			$covered   = false;
			foreach ( $preloaded as $root ) {
				$covered = $covered || $directory === $root || str_starts_with( $directory, $root . '/' );
			}
			if ( ! $covered ) {
				$uncovered[] = $directory;
			}
		}
		return $uncovered;
	}

	/**
	 * A gate configuration: JSON with comments on lines of their own.
	 *
	 * @return array<mixed>
	 */
	private static function json5( string $path ): array {
		$lines  = array_filter( (array) file( $path ), static fn( $line ): bool => 1 !== preg_match( '#^\s*//#', (string) $line ) );
		$config = json_decode( implode( '', $lines ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $config );
		return $config;
	}
}
