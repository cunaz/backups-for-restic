<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\MutationProcess;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mutation job that skips a pull request must not skip one that changes a file the job reads
 * (docs/04 §5.6): the `watched` patterns of the two jobs in ci.yml and the `paths` filter of
 * database-mutation.yml are compared with the files that each job's steps, gates, PHPUnit
 * configurations and preloaded classes name. Code that the tests reach beyond that is not
 * derived here; the lists name it by hand, wider than needed.
 */
final class MutationJobScopeTest extends TestCase {

	/**
	 * Each job: its workflow, its id, and how a pull request selects it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function jobs(): array {
		return [
			'core gate in ci.yml'                    => [ '.github/workflows/ci.yml', 'mutation' ],
			'restore gates in ci.yml'                => [ '.github/workflows/ci.yml', 'restore-mutation' ],
			'database gate in database-mutation.yml' => [ '.github/workflows/database-mutation.yml', 'mutation' ],
		];
	}

	#[DataProvider( 'jobs' )]
	public function test_a_change_to_any_file_the_job_reads_runs_it( string $workflow, string $job ): void {
		$root  = dirname( __DIR__, 3 );
		$text  = (string) file_get_contents( $root . '/' . $workflow );
		$block = self::job( $text, $job );
		$runs  = self::selector( $text, $block );
		$read  = self::read( $root, $workflow, $block );
		self::assertNotEmpty( $read );
		$missed = array_values( array_filter( $read, static fn ( string $path ): bool => ! $runs( $path ) ) );
		self::assertSame( [], $missed, $workflow . ' does not run job ' . $job . ' for a pull request that changes these files.' );
	}

	public function test_a_file_outside_the_lists_does_not_run_the_jobs(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( self::jobs() as [ $workflow, $job ] ) {
			$text = (string) file_get_contents( $root . '/' . $workflow );
			$runs = self::selector( $text, self::job( $text, $job ) );
			self::assertFalse( $runs( 'docs/04-development-and-quality.md' ), $workflow . ' ' . $job );
			self::assertTrue( $runs( $workflow ), $workflow . ' ' . $job );
		}
	}

	/**
	 * The text of one job of a workflow.
	 */
	private static function job( string $workflow, string $job ): string {
		self::assertSame( 1, preg_match( '/^  ' . preg_quote( $job, '/' ) . ":\n((?:(?:    .*)?\n)+)/m", $workflow, $match ), 'No job ' . $job . '.' );
		return $match[1];
	}

	/**
	 * Whether a change to a path selects the job: the watched pattern of its scope step, or else
	 * the workflow's paths filter for pull requests.
	 *
	 * @return callable(string): bool
	 */
	private static function selector( string $workflow, string $job ): callable {
		if ( 1 === preg_match( "/^ +watched: '([^']+)'$/m", $job, $match ) ) {
			$pattern = '#' . str_replace( '#', '\#', $match[1] ) . '#';
			return static fn ( string $path ): bool => 1 === preg_match( $pattern, $path );
		}
		self::assertSame( 1, preg_match( "/^  pull_request:\n(?:    #.*\n)*    paths:\n((?:      - '[^']+'\n)+)/m", $workflow, $match ), 'Neither a watched pattern nor a paths filter.' );
		preg_match_all( "/- '([^']+)'/", $match[1], $globs );
		$patterns = array_map(
			static fn ( string $glob ): string => '#^' . strtr(
				preg_quote( $glob, '#' ),
				[
					'\*\*' => '.*',
					'\*'   => '[^/]*',
				]
			) . '$#',
			$globs[1]
		);
		return static function ( string $path ) use ( $patterns ): bool {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $path ) ) {
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * The files that the job reads: the workflow, its local actions, the scripts, skip lists and
	 * PHPUnit configurations its steps name, Composer's files, and for every gate it runs the
	 * configuration, the source directories, the PHPUnit configuration with its bootstrap and
	 * test directories, and the classes that every mutant process loads first. A directory
	 * stands for a file in it.
	 *
	 * @return list<string>
	 */
	private static function read( string $root, string $workflow, string $job ): array {
		$paths = [ $workflow, 'composer.json', 'composer.lock', 'bin/mutation-gate.sh', 'tests/bootstrap.php', 'tests/Support/MutationProcess.php' ];
		preg_match_all( '#uses: \./(\.github/actions/[\w-]+)#', $job, $actions );
		foreach ( $actions[1] as $action ) {
			$paths[] = $action . '/action.yml';
		}
		$steps = (string) preg_replace( '/^ +(?:watched|label): .*$/m', '', $job );
		preg_match_all( '#(?<![\w/])((?:bin|tests/Skips)/[\w.-]+)#', $steps, $files );
		array_push( $paths, ...$files[1] );
		if ( 1 === preg_match( '#vendor/bin/phpunit(?![^\n]*(?:-c |--configuration))#', $job ) ) {
			$paths[] = 'phpunit.xml.dist';
		}
		foreach ( self::gates( $root, $job ) as $gate ) {
			$paths[] = $gate;
			$config  = self::json5( $root . '/' . $gate );
			foreach ( (array) ( $config['source']['directories'] ?? [] ) as $directory ) {
				$paths[] = rtrim( (string) $directory, '/' ) . '/Any.php';
			}
			$config_dir = (string) ( $config['phpUnit']['configDir'] ?? '' );
			$paths[]    = $config_dir . '/phpunit.xml.dist';
			$phpunit    = new DOMDocument();
			self::assertTrue( @$phpunit->load( $root . '/' . $config_dir . '/phpunit.xml.dist' ) );
			$xpath = new DOMXPath( $phpunit );
			foreach ( [ 'string(/phpunit/@bootstrap)', '//testsuite/directory', '//testsuite/file' ] as $query ) {
				$found = $xpath->evaluate( $query );
				$names = is_string( $found ) ? [ $found ] : array_map( static fn ( \DOMNode $node ): string => (string) $xpath->evaluate( 'string(.)', $node ), false === $found ? [] : iterator_to_array( $found ) );
				foreach ( $names as $name ) {
					$relative = (string) realpath( $root . '/' . $config_dir . '/' . trim( $name ) );
					self::assertNotSame( '', $relative, $gate . ': ' . $name );
					$paths[] = substr( $relative, strlen( (string) realpath( $root ) ) + 1 ) . ( is_dir( $relative ) ? '/AnyTest.php' : '' );
				}
			}
		}
		foreach ( self::commands( $root, $job ) as $command ) {
			preg_match_all( '#(?<![\w/])(bin/[\w.-]+)#', $command, $named );
			array_push( $paths, ...$named[1] );
		}
		foreach ( MutationProcess::DIRECTORIES as $directory ) {
			$paths[] = $directory . '/Any.php';
		}
		$paths = array_values( array_unique( $paths ) );
		sort( $paths );
		return $paths;
	}

	/**
	 * The gate configurations that the job's Composer scripts use.
	 *
	 * @return list<string>
	 */
	private static function gates( string $root, string $job ): array {
		$gates = [];
		foreach ( self::commands( $root, $job ) as $command ) {
			self::assertStringContainsString( 'bin/mutation-gate.sh', $command );
			$gates[] = 1 === preg_match( '/--configuration=(\S+)/', $command, $match ) ? $match[1] : 'infection.json5.dist';
		}
		return $gates;
	}

	/**
	 * The commands of the Composer scripts that the job runs, one string per script.
	 *
	 * @return list<string>
	 */
	private static function commands( string $root, string $job ): array {
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $composer );
		$scripts = [];
		preg_match_all( '#composer "?(mutation(?::[\w$]+)?)"?#', $job, $calls );
		foreach ( $calls[1] as $call ) {
			if ( str_contains( $call, '$GATE' ) ) {
				preg_match_all( '#\{ gate: (\w+),#', $job, $matrix );
				self::assertNotEmpty( $matrix[1], 'No matrix of gates.' );
				foreach ( $matrix[1] as $gate ) {
					$scripts[] = 'mutation:' . $gate;
				}
			} else {
				$scripts[] = $call;
			}
		}
		self::assertNotEmpty( $scripts, 'The job runs no mutation gate.' );
		return array_map( static fn ( string $script ): string => implode( ' ', (array) ( $composer['scripts'][ $script ] ?? [] ) ), $scripts );
	}

	/**
	 * A gate configuration: JSON with comments on lines of their own.
	 *
	 * @return array<mixed>
	 */
	private static function json5( string $path ): array {
		$lines  = array_filter( (array) file( $path ), static fn ( $line ): bool => 1 !== preg_match( '#^\s*//#', (string) $line ) );
		$config = json_decode( implode( '', $lines ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $config );
		return $config;
	}
}
