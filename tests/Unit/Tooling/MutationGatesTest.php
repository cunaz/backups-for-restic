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
			self::assertCount( 1, $result['reports'] );
			$statements = preg_replace( '/^Process [0-9]+ /m', '', $result['reports'][0] );
			if ( ! $compiles ) {
				// As without the bootstrap: the wrapper stays, and a test that loads the class meets
				// the ParseError that Infection reports as a syntax error. The report says so.
				self::assertTrue( $result['intercepted'] );
				self::assertSame( 'ParseError', $result['loaded'] );
				self::assertMatchesRegularExpression( '/\Areported in\.\ndoes not compile: syntax error, [^\n]+\n\z/', (string) $statements );
				return;
			}
			self::assertSame( "reported in.\ncan write durably.\nended with PHP's own file wrapper.\n", $statements );
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

	public function test_a_copy_that_fails_with_a_compile_fatal_error_says_that_the_process_ended_while_loading(): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'mutation-process' );
		try {
			$original = $root . '/src/Restore/ChunkDigest.php';
			$mutant   = $scratch . '/ChunkDigest.php';
			// As the mutant of ZstdFrame.php:72 that the core gate counts as errored: PHP refuses
			// an unparenthesized nested ternary in a function with a fatal error, not with a
			// ParseError, and the process ends while the classes load.
			file_put_contents( $mutant, str_replace( 'final class ChunkDigest {', 'final class ChunkDigest { public static function mutated( bool $a, bool $b ): int { return $a ? 1 : $b ? 2 : 3; }', (string) file_get_contents( $original ) ) );
			$result = self::process( $scratch, $root, $original, $mutant, null, true );
			self::assertCount( 1, $result['reports'] );
			self::assertMatchesRegularExpression( '/\AProcess ([0-9]+) reported in\.\nProcess \1 ended while the classes loaded: Unparenthesized `a \? b : c \? d : e` is not supported[^\n]*\n\z/', $result['reports'][0] );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_a_process_that_ends_with_a_fatal_error_outside_a_test_says_so(): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'mutation-process' );
		try {
			$original = $root . '/src/Restore/ChunkDigest.php';
			$mutant   = $scratch . '/ChunkDigest.php';
			copy( $original, $mutant );
			// The process exits with 255, and Infection counts it as errored, not among the killed
			// and escaped mutants whose end line the gate counts.
			$result = self::process( $scratch, $root, $original, $mutant, null, true, 'outside' );
			self::assertSame( 255, $result['status'] );
			self::assertCount( 1, $result['reports'] );
			self::assertMatchesRegularExpression( "/\\AProcess ([0-9]+) reported in\\.\\nProcess \\1 can write durably\\.\\nProcess \\1 ended with PHP's own file wrapper after a fatal error outside a test: Uncaught Error: Call to undefined function [^\\n]*\\n\\z/", $result['reports'][0] );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_a_process_that_ends_with_a_fatal_error_in_a_test_says_so(): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'mutation-process' );
		try {
			$original = $root . '/src/Restore/ChunkDigest.php';
			$mutant   = $scratch . '/ChunkDigest.php';
			copy( $original, $mutant );
			// As three killed mutants of the ZIP gate did: the memory ran out while PHPUnit ran a
			// test, PHPUnit's shutdown function ended the process with status 2, and Infection
			// counted the mutant as killed, so its end line counts.
			$result = self::process( $scratch, $root, $original, $mutant, null, true, 'test' );
			self::assertSame( 2, $result['status'] );
			self::assertCount( 1, $result['reports'] );
			self::assertMatchesRegularExpression( "/\\AProcess ([0-9]+) reported in\\.\\nProcess \\1 can write durably\\.\\nProcess \\1 ended with PHP's own file wrapper after a fatal error in a test: Allowed memory size of [0-9]+ bytes exhausted[^\\n]*\\n\\z/", $result['reports'][0] );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	/**
	 * A mutant process reports in once and passes its report on; a test that PHPUnit runs in a
	 * process of its own inherits that and reports nothing, so that its report could not stand
	 * in for one that is missing.
	 */
	public function test_a_mutant_process_reports_in_once_and_its_isolated_tests_do_not(): void {
		$root    = (string) realpath( dirname( __DIR__, 3 ) );
		$scratch = TempDir::create( 'mutation-process' );
		try {
			$original = $root . '/src/Restore/ChunkDigest.php';
			$mutant   = $scratch . '/ChunkDigest.php';
			copy( $original, $mutant );

			$parent = self::process( $scratch, $root, $original, $mutant );
			self::assertCount( 1, $parent['reports'] );
			self::assertMatchesRegularExpression( "/\\AProcess ([0-9]+) reported in\\.\\nProcess \\1 can write durably\\.\\nProcess \\1 ended with PHP's own file wrapper\\.\\n\\z/", $parent['reports'][0] );
			self::assertIsString( $parent['reported'] );
			self::assertStringStartsWith( $scratch . '/reports/report-', $parent['reported'], 'The process passes its report on.' );

			$child = self::process( $scratch, $root, $original, $mutant, $parent['reported'] );
			self::assertCount( 1, $child['reports'], 'The isolated test did not report again.' );
			self::assertSame( $parent['reports'], $child['reports'], 'Nor did it state anything in its mutant process\'s report.' );
			self::assertSame( $parent['reported'], $child['reported'] );

			$orphan = self::process( $scratch, $root, $original, $mutant, '-' );
			self::assertCount( 1, $orphan['reports'], 'Nor does it when its mutant process could not report.' );
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
			'Infection fails and nothing was marked'     => [ [ 'FAKE_STATUS' => '3' ], 3, 'infection --threads=2' ],
			'Infection fails and a process marked the variable' => [
				[
					'FAKE_STATUS' => '3',
					'FAKE_MARK'   => 'variable',
				],
				1,
				'::error::Mutant processes that could not write durably: 2.',
			],
			'a process could write only the fallback'    => [ [ 'FAKE_MARK' => 'fallback' ], 1, 'Process 7: fsync() failed' ],
			'every process stated what it must'          => [ [], 0, "Mutant processes: 6 ran tests, 4 of them to their end; 6 reported in, 6 could write durably or did not compile (1 caught, 1 counted as syntax errors, 0 ended while the classes loaded), 4 ended with PHP's own file wrapper (needed: 4), 0 of them after a fatal error in a test, and 0 more after a fatal error outside a test, which do not count." ],
			'more reports than processes pass'           => [
				[
					'FAKE_REPORTS' => '7',
					'FAKE_PASSES'  => '6',
				],
				0,
				'7 reported in, 7 could write durably',
			],
			'one process did not report in'              => [ [ 'FAKE_REPORTS' => '5' ], 1, '::error::1 of the 6 mutant processes that Infection ran did not report in' ],
			'no process reported in'                     => [ [ 'FAKE_REPORTS' => '0' ], 1, '::error::6 of the 6 mutant processes that Infection ran did not report in' ],
			'a process did not say it can write durably' => [ [ 'FAKE_PASSES' => '4' ], 1, '::error::1 of the 6 mutant processes did not state that they could write durably' ],
			'a killed mutant did not say how it ended'   => [ [ 'FAKE_ENDS' => '3' ], 1, "::error::1 of the 4 mutant processes that ran to their end did not state that PHP's own file wrapper was still in place" ],
			'a timed-out mutant needs no end line'       => self::statements( '1 0 0 0 1', 'pass,end;pass', 0, "1 ended with PHP's own file wrapper (needed: 1)" ),
			// CodeRabbit's example: a copy that ends the process while the classes load is errored,
			// and must not excuse a killed mutant that did not say how it ended.
			'a fatal compile error excuses no end line'  => self::statements( '2 1 0 0 0', 'pass,end;pass;loading', 1, '::error::1 of the 2 mutant processes that ran to their end did not state' ),
			// The mirror case: a syntax error, whose copy the bootstrap caught, excuses no killed
			// mutant either. Here the killed one met an intercepted wrapper at its end while its
			// report and both markers could not be written, so it left nothing at all.
			'a syntax error excuses no end line'         => self::statements( '1 0 1 0 0', 'pass;caught', 1, '::error::1 of the 1 mutant processes that ran to their end did not state' ),
			'a caught compile error counted as killed'   => self::statements( '3 0 0 1 0', 'pass,end;pass,end;caught;pass,end', 0, "(1 caught, 0 counted as syntax errors, 0 ended while the classes loaded), 3 ended with PHP's own file wrapper (needed: 3)" ),
			'a caught compile error counted as a syntax error' => self::statements( '2 0 1 1 0', 'pass,end;pass,end;caught;pass,end', 0, "(1 caught, 1 counted as syntax errors, 0 ended while the classes loaded), 3 ended with PHP's own file wrapper (needed: 3)" ),
			// A fatal error outside a test ends the process with 255, and Infection counts it as
			// errored: its end line must not stand in for a killed mutant's.
			'a fatal error outside a test excuses no end line' => self::statements( '2 1 0 0 0', 'pass,end;pass;pass,fatal-outside', 1, '::error::1 of the 2 mutant processes that ran to their end did not state' ),
			'a fatal error outside a test, of an errored process' => self::statements( '2 1 0 0 0', 'pass,end;pass,end;pass,fatal-outside', 0, "2 ended with PHP's own file wrapper (needed: 2), 0 of them after a fatal error in a test, and 1 more after a fatal error outside a test" ),
			// A fatal error in a test ends the process with PHPUnit's status 2, and Infection counts
			// it as killed, as with three mutants of the ZIP gate whose memory ran out.
			'a fatal error in a test is an end line'     => self::statements( '2 0 0 0 0', 'pass,end;pass,fatal-in-test', 0, "2 ended with PHP's own file wrapper (needed: 2), 1 of them after a fatal error in a test, and 0 more" ),
			'a copy that does not compile, counted as killed' => [
				[
					'FAKE_SUMMARY' => 'killed-compile',
					'FAKE_ENDS'    => '4',
				],
				0,
				"Mutant processes: 6 ran tests, 5 of them to their end; 6 reported in, 6 could write durably or did not compile (1 caught, 0 counted as syntax errors, 0 ended while the classes loaded), 4 ended with PHP's own file wrapper (needed: 4), 0 of them after a fatal error in a test, and 0 more after a fatal error outside a test, which do not count.",
			],
			'the summary lacks a count'                  => [ [ 'FAKE_SUMMARY' => 'partial' ], 1, '::error::Infection\'s summary ./.tools/infection/summary.log has no line "Errored: N"' ],
			'Infection wrote no summary'                 => [ [ 'FAKE_SUMMARY' => 'none' ], 1, '::error::Infection wrote no summary to ./.tools/infection/summary.log' ],
			'Infection failed and wrote no summary'      => [
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
	 * A run of the gate script with reports that hold the named statements and a summary with
	 * the counts "killed errored syntax escaped timed-out".
	 *
	 * @return array{array<string, string>, int, string}
	 */
	private static function statements( string $counts, string $lines, int $status, string $printed ): array {
		return [
			[
				'FAKE_REPORTS' => '0',
				'FAKE_SUMMARY' => 'counts',
				'FAKE_COUNTS'  => $counts,
				'FAKE_LINES'   => $lines,
			],
			$status,
			$printed,
		];
	}

	/**
	 * Runs whose mutant processes timed out, against the floors 80 % and 84 %: the counts "killed
	 * errored syntax escaped timed-out not-covered", Infection's exit status, the script's, and
	 * the annotation that it prints, if any.
	 *
	 * @return array<string, array{string, string, int, string}>
	 */
	public static function timed_out_runs(): array {
		return [
			// 10 of 100 timed out: both scores lie between 85 % and 95 %.
			'both floors met without the timed-out mutants' => [ '85 0 0 5 10 0', '0', 0, '::notice::10 of the 100 mutant processes timed out, more than 5 percent, and Infection counts a timed-out mutant as detected: MSI between 85.00 % and 95.00 %, covered code MSI between 85.00 % and 95.00 %, against the floors 80.00 % and 84.00 %. Counted as not detected, the timed-out mutants still leave both scores at their floors, so the run counts.' ],
			"Infection's status passes on"         => [ '85 0 0 5 10 0', '3', 3, '::notice::10 of the 100 mutant processes timed out, more than 5 percent' ],
			// 7 not covered: MSI 79.44 % to 88.79 %, covered code MSI 85 % to 95 %.
			'the MSI floor met only with them'     => [ '85 0 0 5 10 7', '0', 1, '::error::10 of the 100 mutant processes timed out, more than 5 percent, and Infection counts a timed-out mutant as detected: MSI between 79.44 % and 88.79 %, covered code MSI between 85.00 % and 95.00 %, against the floors 80.00 % and 84.00 %. Counted as not detected, the timed-out mutants leave a score below its floor, so the score cannot be trusted on this machine: run the gate again on a faster one.' ],
			// Both scores between 82 % and 92 %.
			'the covered floor met only with them' => [ '82 0 0 8 10 0', '0', 1, '::error::10 of the 100 mutant processes timed out, more than 5 percent, and Infection counts a timed-out mutant as detected: MSI between 82.00 % and 92.00 %, covered code MSI between 82.00 % and 92.00 %, against the floors 80.00 % and 84.00 %.' ],
			// Infection's verdict stands, although 80 % covered without the timed-out mutants is below 84 %.
			'exactly 5 percent timed out'          => [ '80 0 0 15 5 0', '0', 0, '' ],
			'1 of 40 timed out'                    => [ '29 0 0 10 1 0', '0', 0, '' ],
		];
	}

	/**
	 * Above 5 percent of timed-out mutant processes Infection's score is an upper bound, as it
	 * counts a timeout as detected: the database gate on a slow runner timed out 414 of 4,081.
	 * The run then counts only if the score with the timed-out mutants counted as not detected
	 * meets both floors as well. Up to 5 percent Infection's verdict stands: healthy runs of the
	 * six gates timed out 0.1 to 1.8 percent.
	 *
	 * @param string $counts     The summary's counts.
	 * @param string $infection  Infection's exit status.
	 * @param int    $expected   The script's exit status.
	 * @param string $annotation What the script prints about the bounds, or "" for nothing.
	 */
	#[DataProvider( 'timed_out_runs' )]
	public function test_the_gate_script_bounds_the_score_when_many_mutant_processes_timed_out( string $counts, string $infection, int $expected, string $annotation ): void {
		$numbers = array_map( 'intval', explode( ' ', $counts ) );
		$root    = self::gate_checkout( "    \"minMsi\": 80,\n    \"minCoveredMsi\": 84,\n" );
		try {
			[ $status, $output ] = self::gate( $root, self::timed_out( $counts, $infection ) );
			self::assertSame( $expected, $status, $output );
			self::assertStringContainsString( ( $numbers[0] + $numbers[3] ) . " ended with PHP's own file wrapper (needed: " . ( $numbers[0] + $numbers[3] ) . ')', $output );
			if ( '' === $annotation ) {
				self::assertStringNotContainsString( '::notice::', $output );
				self::assertStringNotContainsString( '::error::', $output );
			} else {
				self::assertStringContainsString( $annotation, $output );
				self::assertStringNotContainsString( str_starts_with( $annotation, '::notice::' ) ? '::error::' : '::notice::', $output );
			}
		} finally {
			TempDir::remove( $root );
		}
	}

	/**
	 * Floors as the script reads them: the configuration's lines, the arguments, what the script
	 * prints, and whether Infection started. The run has 10 timed-out mutants of 100, so both
	 * scores lie between 85 % and 95 %.
	 *
	 * @return array<string, array{string, list<string>, string, bool}>
	 */
	public static function floor_readings(): array {
		return [
			'no floors'                        => [ '', [], '::error::The floors of infection.json5.dist cannot be read (minMsi "", minCoveredMsi "")', false ],
			'no covered floor'                 => [ "    \"minMsi\": 80,\n", [], '::error::The floors of infection.json5.dist cannot be read (minMsi "80", minCoveredMsi "")', false ],
			'a floor with three decimals'      => [ "    \"minMsi\": 80.125,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "80.125", minCoveredMsi "84")', false ],
			'a floor in a comment'             => [ "    // \"minMsi\": 80,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "", minCoveredMsi "84")', false ],
			'a floor in exponent notation'     => [ "    \"minMsi\": 8.6e1,\n    \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'a floor in hexadecimal'           => [ "    \"minMsi\": 80,\n    \"minCoveredMsi\": 0x54,\n", [], '::error::The floors of infection.json5.dist cannot be read (minMsi "", minCoveredMsi "")', false ],
			'a floor with a sign'              => [ "    \"minMsi\": +80,\n    \"minCoveredMsi\": 84,\n", [], 'infection.json5.dist is not JSON once its comments are taken out (Syntax error), so its minMsi cannot be read.', false ],
			'a floor without quotes'           => [ "    minMsi: 80,\n    \"minCoveredMsi\": 84,\n", [], 'infection.json5.dist is not JSON once its comments are taken out (Syntax error), so its minCoveredMsi cannot be read.', false ],
			'a floor as a string'              => [ "    \"minMsi\": \"80\",\n    \"minCoveredMsi\": 84,\n", [], '(minMsi ""80"", minCoveredMsi "84")', false ],
			'a floor that is no number'        => [ "    \"minMsi\": 80,\n    \"minCoveredMsi\": null,\n", [], '(minMsi "80", minCoveredMsi "null")', false ],
			'a floor below zero'               => [ "    \"minMsi\": -1,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "-1", minCoveredMsi "84")', false ],
			'a floor above a hundred'          => [ "    \"minMsi\": 80,\n    \"minCoveredMsi\": 100.5,\n", [], '(minMsi "80", minCoveredMsi "100.5")', false ],
			'a floor set twice'                => [ "    \"minMsi\": 80,\n    \"minMsi\": 8,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "set 2 times", minCoveredMsi "84")', false ],
			'a floor set twice on one line'    => [ "    \"minMsi\": 80, \"minMsi\": 8,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "set 2 times", minCoveredMsi "84")', false ],
			'a floor set again with escapes'   => [ "    \"minMsi\": 80,\n    \"min\\u004dsi\": 8,\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "set 2 times", minCoveredMsi "84")', false ],
			'a floor set again in an object'   => [ "    \"minMsi\": 80,\n    \"mutators\": { \"minMsi\": 8 },\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "set 2 times", minCoveredMsi "84")', false ],
			'a floor whose name has escapes'   => [ "    \"min\\u004dsi\": 86,\n    \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'a floor named in a string'        => [ "    \"testFrameworkOptions\": \"\\\"minMsi\\\": 8\",\n    \"minMsi\": 86,\n    \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'a floor in a block comment'       => [ "    /*\n    \"minMsi\": 80,\n    */\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "", minCoveredMsi "84")', false ],
			'a floor in another object'        => [ "    \"mutators\": { \"minMsi\": 80 },\n    \"minCoveredMsi\": 84,\n", [], '(minMsi "", minCoveredMsi "84")', false ],
			'floors beside comments'           => [ "    // \"minMsi\": 98,\n    \"minMsi\": 86, // measured\n    \"minCoveredMsi\": 84/* \"minCoveredMsi\": 99 */,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'floors after a comment sign'      => [ "    \"testFrameworkOptions\": \"--filter=//x /* y \\\" z\",\n    \"minMsi\": 86,\n    \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'floors on one line'               => [ "    \"minMsi\": 86, \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'a floor on the line after a name' => [ "    \"minMsi\":\n    86.0,\n    \"minCoveredMsi\": 84,\n", [], 'against the floors 86.00 % and 84.00 %', true ],
			'floors on the command line'       => [ '', [ '--min-msi=86', '--min-covered-msi', '84.5' ], 'against the floors 86.00 % and 84.50 %', true ],
			'the command line before the file' => [ "    \"minMsi\": 80,\n    \"minCoveredMsi\": 84,\n", [ '--min-msi=86' ], 'against the floors 86.00 % and 84.00 %', true ],
		];
	}

	/**
	 * The script reads the floors before Infection starts, as Infection does: from --min-msi and
	 * --min-covered-msi, else from the configuration, which it reads as JSON once the comments
	 * are taken out. When it cannot, also because a floor is set twice or is no percentage, the
	 * gate fails, as a run whose timeouts make its score an upper bound could not be held to
	 * them.
	 *
	 * @param string       $floors  Lines of the configuration with the floors.
	 * @param list<string> $options Arguments of the script.
	 * @param string       $printed What the script prints.
	 * @param bool         $started Whether Infection started.
	 */
	#[DataProvider( 'floor_readings' )]
	public function test_the_gate_script_reads_the_floors_before_infection_starts( string $floors, array $options, string $printed, bool $started ): void {
		$root = self::gate_checkout( $floors );
		try {
			[ $status, $output ] = self::gate( $root, self::timed_out( '85 0 0 5 10 0', '0' ), $options );
			self::assertSame( 1, $status, $output );
			self::assertStringContainsString( $printed, $output );
			if ( $started ) {
				self::assertStringContainsString( implode( ' ', array_merge( [ 'infection' ], $options, [ '--threads=2' ] ) ), $output );
			} else {
				self::assertStringNotContainsString( 'infection --threads=2', $output, 'Infection did not start.' );
			}
		} finally {
			TempDir::remove( $root );
		}
	}

	/**
	 * The script reads the floors of each gate's configuration as they stand in the file. The
	 * run has 10 timed-out mutants of 100, so the script prints the floors it holds the run to.
	 */
	#[DataProvider( 'gates' )]
	public function test_the_gate_script_reads_the_floors_of_each_gate( string $gate ): void {
		$text = (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $gate );
		self::assertSame( 1, preg_match( '/^    "minMsi": (\d+),$/m', $text, $msi ), $gate );
		self::assertSame( 1, preg_match( '/^    "minCoveredMsi": (\d+),$/m', $text, $covered ), $gate );
		self::assertSame( 1, preg_match( '~^        "summary": "\.tools/([a-z-]+)/summary\.log",$~m', $text, $logs ), $gate );
		$root = self::gate_checkout();
		try {
			file_put_contents( $root . '/' . $gate, $text );
			if ( 'infection' !== $logs[1] ) {
				symlink( 'infection', $root . '/.tools/' . $logs[1] );
			}
			[ , $output ] = self::gate( $root, self::timed_out( '85 0 0 5 10 0', '0' ), [ '--configuration=' . $gate ] );
			self::assertStringContainsString( 'against the floors ' . $msi[1] . '.00 % and ' . $covered[1] . '.00 %', $output );
		} finally {
			TempDir::remove( $root );
		}
	}

	/**
	 * The stand-in's variables for a summary of the counts "killed errored syntax escaped
	 * timed-out not-covered", with the reports that the processes leave: a pass and an end line
	 * for the killed and escaped mutants, a pass for the timed-out ones.
	 *
	 * @return array<string, string>
	 */
	private static function timed_out( string $counts, string $infection ): array {
		$numbers = array_map( 'intval', explode( ' ', $counts ) );
		return [
			'FAKE_REPORTS' => '0',
			'FAKE_SUMMARY' => 'counts',
			'FAKE_COUNTS'  => $counts,
			'FAKE_LINES'   => implode( ';', array_merge( array_fill( 0, $numbers[0] + $numbers[3], 'pass,end' ), array_fill( 0, $numbers[4], 'pass' ) ) ),
			'FAKE_STATUS'  => $infection,
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
			file_put_contents( $root . '/infection-other.json5.dist', "{\n    // \"summary\": \"commented/out.log\"\n    \"minMsi\": 0,\n    \"minCoveredMsi\": 0,\n    \"logs\": {\n        \"summary\": \".tools/infection/summary.log\"\n    }\n}\n" );
			unlink( $root . '/infection.json5.dist' );
			[ $status, $output ] = self::gate( $root, [], [ '--configuration=infection-other.json5.dist' ] );
			self::assertSame( 0, $status, $output );
			self::assertStringContainsString( '6 reported in, 6 could write durably', $output );

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
	 * reported in, its report holds the canary's line; and when the report turned unwritable as
	 * well, it lacks the line that says the process can write durably. Each fails the gate.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function unwritable_markers(): array {
		return [
			'unwritable before the process reported in' => [ 'before', '::error::1 of the 6 mutant processes that Infection ran did not report in' ],
			'unwritable after the process reported in'  => [ 'after', 'Files still open through the user-space stream wrapper after the restore.' ],
			'the report unwritable too after that'      => [ 'after-report', '::error::1 of the 6 mutant processes did not state that they could write durably' ],
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
			if ( str_starts_with( (string) getenv( "FAKE_LOCK" ), "after" ) ) {
				chmod( (string) getenv( MutationProcess::REPORT_VARIABLE ), 0500 );
				chmod( $root . "/.tools", 0500 );
			}
			if ( "after-report" === getenv( "FAKE_LOCK" ) && null !== $report ) {
				chmod( $report, 0400 );
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
					'FAKE_PASSES'  => '4',
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
	 * prints its arguments, writes the markers that FAKE_MARK names, and leaves FAKE_REPORTS
	 * reports (6): the first FAKE_PASSES (5) say they can write durably, the next FAKE_UNCOMPILED
	 * (1) that their copy does not compile, and the first FAKE_ENDS (4) how they ended. It writes
	 * the summary that FAKE_SUMMARY names (full: 3 killed, 1 escaped, 1 timed out, 1 syntax error,
	 * 3 not covered; killed-compile: the syntax error counted as killed; counts: FAKE_COUNTS, as
	 * "killed errored syntax escaped timed-out [not-covered]"). FAKE_LINES adds reports whose
	 * statements it names, one report per ";" (pass, caught, loading, end, fatal-in-test,
	 * fatal-outside). The stand-in locks the reports and .tools when FAKE_LOCK is "before", runs
	 * FAKE_CHILD with FAKE_PHP, and exits with FAKE_STATUS. The configuration has the floors given,
	 * by default 0 and 0, which any lower bound meets.
	 *
	 * @param string $floors Lines of the configuration with the floors.
	 */
	private static function gate_checkout( string $floors = "    \"minMsi\": 0,\n    \"minCoveredMsi\": 0,\n" ): string {
		$root = TempDir::create( 'mutation-gate' );
		mkdir( $root . '/bin' );
		mkdir( $root . '/.tools/bin', 0700, true );
		copy( dirname( __DIR__, 3 ) . '/bin/mutation-gate.sh', $root . '/bin/mutation-gate.sh' );
		file_put_contents( $root . '/infection.json5.dist', "{\n" . $floors . "    \"logs\": {\n        \"text\": \".tools/infection/infection.log\",\n        \"summary\": \".tools/infection/summary.log\"\n    }\n}\n" );
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
			passes=${FAKE_PASSES:-5}
			for ((n = 0; n < ${FAKE_REPORTS:-6}; n++)); do
				{
					printf 'Process %d reported in.\n' "$((100 + n))"
					if ((n < passes)); then
						printf 'Process %d can write durably.\n' "$((100 + n))"
					elif ((n < passes + ${FAKE_UNCOMPILED:-1})); then
						printf 'Process %d does not compile: syntax error, unexpected token ";"\n' "$((100 + n))"
					fi
					if ((n < ${FAKE_ENDS:-4})); then
						printf "Process %d ended with PHP's own file wrapper.\n" "$((100 + n))"
					fi
				} > "$BFR_MUTATION_REPORTS/report-$((100 + n))-x"
			done
			n=0
			IFS=';' read -ra specs <<< "${FAKE_LINES:-}"
			for spec in "${specs[@]}"; do
				n=$((n + 1))
				{
					printf 'Process %d reported in.\n' "$((200 + n))"
					IFS=',' read -ra parts <<< "$spec"
					for part in "${parts[@]}"; do
						case "$part" in
							pass) printf 'Process %d can write durably.\n' "$((200 + n))" ;;
							caught) printf 'Process %d does not compile: syntax error, unexpected token "==="\n' "$((200 + n))" ;;
							loading) printf 'Process %d ended while the classes loaded: Unparenthesized `a ? b : c ? d : e` is not supported\n' "$((200 + n))" ;;
							end) printf "Process %d ended with PHP's own file wrapper.\n" "$((200 + n))" ;;
							fatal-in-test) printf "Process %d ended with PHP's own file wrapper after a fatal error in a test: Allowed memory size of 134217728 bytes exhausted\n" "$((200 + n))" ;;
							fatal-outside) printf "Process %d ended with PHP's own file wrapper after a fatal error outside a test: Allowed memory size of 134217728 bytes exhausted\n" "$((200 + n))" ;;
						esac
					done
				} > "$BFR_MUTATION_REPORTS/report-$((200 + n))-x"
			done
			mkdir -p "$tools/infection"
			case "${FAKE_SUMMARY:-full}" in
				full) printf 'Total: 9\n\nKilled by Test Framework: 3\nKilled by Static Analysis: 0\nErrored: 0\nSyntax Errors: 1\nEscaped: 1\nTimed Out: 1\nSkipped: 0\nIgnored: 0\nNot Covered: 3\n' > "$tools/infection/summary.log" ;;
				killed-compile) printf 'Total: 9\n\nKilled by Test Framework: 4\nKilled by Static Analysis: 0\nErrored: 0\nSyntax Errors: 0\nEscaped: 1\nTimed Out: 1\nSkipped: 0\nIgnored: 0\nNot Covered: 3\n' > "$tools/infection/summary.log" ;;
				partial) printf 'Total: 9\n\nKilled by Test Framework: 3\n' > "$tools/infection/summary.log" ;;
				counts)
					read -r killed errored syntax escaped timed uncovered <<< "$FAKE_COUNTS"
					printf 'Total: %d\n\nKilled by Test Framework: %d\nKilled by Static Analysis: 0\nErrored: %d\nSyntax Errors: %d\nEscaped: %d\nTimed Out: %d\nSkipped: 0\nIgnored: 0\nNot Covered: %d\n' "$((killed + errored + syntax + escaped + timed + ${uncovered:-0}))" "$killed" "$errored" "$syntax" "$escaped" "$timed" "${uncovered:-0}" > "$tools/infection/summary.log"
					;;
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
			$environment + [
				'TMPDIR' => $root,
				'PATH'   => dirname( PHP_BINARY ) . PATH_SEPARATOR . getenv( 'PATH' ),
			] + getenv()
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
	private static function process( string $scratch, string $root, string $original, string $mutant, ?string $inherited = null, bool $dies = false, string $fatal = '' ): array {
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
				'reported'    => getenv( \BackupsForRestic\Tests\Support\MutationProcess::REPORTED_VARIABLE ),
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
			if ( 'outside' === getenv( 'BFR_TEST_FATAL_AT_END' ) ) {
				bfr_no_such_function_ends_this_process();
			}
			if ( 'test' === getenv( 'BFR_TEST_FATAL_AT_END' ) ) {
				// As PHPUnit's TestCase::run() does around each test.
				\PHPUnit\Runner\ShutdownHandler::setMessage( 'Fatal error: Premature end of PHP process when running a test.' );
				ini_set( 'memory_limit', (string) ( memory_get_usage() + 4194304 ) );
				$result['memory'] = str_repeat( 'x', 8388608 );
			}
			PHP
		);
		$marker  = $scratch . '/marker';
		$reports = $scratch . '/reports';
		is_dir( $reports ) || mkdir( $reports );
		$variables = [
			MutationProcess::MARKER_VARIABLE => $marker,
			MutationProcess::REPORT_VARIABLE => $reports,
		];
		if ( null !== $inherited ) {
			$variables[ MutationProcess::REPORTED_VARIABLE ] = $inherited;
		}
		if ( '' !== $fatal ) {
			$variables['BFR_TEST_FATAL_AT_END'] = $fatal;
		}
		$environment = getenv();
		unset( $environment[ MutationProcess::REPORTED_VARIABLE ] );
		$process = proc_open(
			[ PHP_BINARY, $script, $root, $original, $mutant, $marker ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'file', $scratch . '/stderr', 'w' ],
			],
			$pipes,
			null,
			$variables + $environment
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$status  = proc_close( $process );
		$reports = array_map( static fn ( string $file ): string => (string) file_get_contents( $file ), (array) glob( $reports . '/report-*' ) );
		if ( $dies ) {
			self::assertNotSame( 0, $status, 'The process ended with an error.' );
			return [
				'reports' => $reports,
				'status'  => $status,
			];
		}
		self::assertSame( 0, $status, $output . file_get_contents( $scratch . '/stderr' ) );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );
		$result['reports'] = $reports;
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
