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
				$problem  = MutationProcess::canary( $marker );
				$finished = MutationProcess::finish( $marker );
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
			self::assertNull( MutationProcess::canary( $marker ) );
			self::assertTrue( MutationProcess::finish( $marker ) );
			self::assertFileDoesNotExist( $marker );
		} finally {
			UserSpaceFiles::disable();
			TempDir::remove( $scratch );
		}
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
