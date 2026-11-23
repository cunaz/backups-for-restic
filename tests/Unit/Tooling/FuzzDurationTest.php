<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The duration of the nightly fuzzing. The job's name states it and the release gate reads it
 * from there (docs/04 §7), so the workflow and bin/fuzz.php must run exactly that duration and
 * refuse what PHP would read as another one.
 */
final class FuzzDurationTest extends TestCase {

	/**
	 * The input, whether the step accepts it, and what it says.
	 *
	 * @return array<string, array{string, bool, string}>
	 */
	public static function inputs(): array {
		return [
			'the nightly duration'       => [ '120', true, 'bin/fuzz.php --seconds=120 --seed=7' ],
			'Arabic-Indic digits'        => [ "\u{0661}\u{0662}\u{0660}", false, '::error::The input seconds must be a whole number of seconds in ASCII digits' ],
			'a fraction'                 => [ '0.5', false, '::error::' ],
			'more than five digits'      => [ '123456', false, '::error::' ],
			'nothing after a manual run' => [ '', false, '::error::' ],
		];
	}

	#[DataProvider( 'inputs' )]
	public function test_the_workflow_runs_only_a_duration_in_ascii_digits( string $seconds, bool $runs, string $printed ): void {
		$scratch = TempDir::create( 'fuzz-duration' );
		try {
			$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/fuzz.yml' );
			self::assertSame( 1, preg_match( '/^      - name: Fuzz\n(?:        (?!run:).*\n)*        run: \|\n((?: {10}.*\n|\n)+)/m', $workflow, $match ), 'The Fuzz step has a run block.' );
			file_put_contents( $scratch . '/step.sh', (string) preg_replace( '/^ {10}/m', '', $match[1] ) );
			mkdir( $scratch . '/bin' );
			file_put_contents( $scratch . '/bin/php', "#!/usr/bin/env bash\necho \"\$*\"\n" );
			chmod( $scratch . '/bin/php', 0755 );
			$process = proc_open(
				[ 'bash', '--noprofile', '--norc', '-eo', 'pipefail', $scratch . '/step.sh' ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'redirect', 1 ],
				],
				$pipes,
				$scratch,
				[
					'PATH'          => $scratch . '/bin:' . getenv( 'PATH' ),
					'FUZZ_SECONDS'  => $seconds,
					'GITHUB_RUN_ID' => '7',
				]
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			self::assertSame( $runs ? 0 : 1, proc_close( $process ), $output );
			self::assertStringContainsString( $printed, $output );
			if ( ! $runs ) {
				self::assertStringNotContainsString( 'bin/fuzz.php', $output, 'The fuzzer did not start.' );
			}
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_the_fuzzer_refuses_a_duration_that_php_would_read_as_zero(): void {
		foreach ( [ "\u{0661}\u{0662}\u{0660}", 'two minutes', '1e3' ] as $seconds ) {
			$process = proc_open(
				[ PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/fuzz.php', '--seconds=' . $seconds, '--seed=1' ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'redirect', 1 ],
				],
				$pipes
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			self::assertSame( 2, proc_close( $process ), $seconds . ': ' . $output );
			self::assertStringContainsString( '--seconds needs a number of seconds in ASCII digits', $output );
		}
	}
}
