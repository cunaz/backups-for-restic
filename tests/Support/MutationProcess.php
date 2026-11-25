<?php
/**
 * Gives a mutant process PHP's plain file wrapper back, and checks that durable writes work there.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Infection runs each mutant in its own PHPUnit process. Before the bootstrap of the mutation
 * suites runs, that process replaces PHP's file:// wrapper with a user-space wrapper, which
 * serves the mutated copy when the autoloader includes the original file. fsync() fails on every
 * stream that wrapper opens ("Can't fsync this stream!"). So every test that writes a job
 * document or a journal record durably would fail whatever the mutation is, and each mutant it
 * covers would count as killed.
 *
 * tests/Mutation/bootstrap.php therefore loads every class that a gate can mutate while the
 * wrapper is in place, so that the mutated class is the one in memory. It then restores the
 * plain wrapper for everything the tests do. Errors lean towards a lower score: a class first
 * loaded after the restore is the original, and its mutants escape. Nothing here fails the
 * process, because Infection counts a process that fails as a detected mutant.
 *
 * Silence fails the gate instead: before it loads anything, every mutant process reports in with
 * a file of its own, and bin/mutation-gate.sh fails the gate when fewer processes reported in
 * than Infection ran tests for. When durable writes still fail after the restore, or the
 * user-space wrapper is back when the process ends, a line goes into the process's report, or
 * into a marker file when the report cannot take it, and the script fails the gate on that too.
 */
final class MutationProcess {

	/**
	 * Every source directory of every gate (infection*.json5.dist), relative to the repository.
	 * tests/Unit/Tooling/MutationGatesTest.php fails when a gate names a directory that is not
	 * covered here.
	 */
	public const DIRECTORIES = [
		'src/Crypto',
		'src/Database/Restore',
		'src/Engine/Forget',
		'src/Format',
		'src/Jobs/Apply',
		'src/Restore',
		'src/Storage/Zip',
	];

	/** The environment variable that names the marker file; bin/mutation-gate.sh sets it. */
	public const MARKER_VARIABLE = 'BFR_MUTATION_CANARY';

	/** The variable that names the directory of the reports; bin/mutation-gate.sh sets it. */
	public const REPORT_VARIABLE = 'BFR_MUTATION_REPORTS';

	/**
	 * Run by the bootstrap of the mutation suites, after tests/bootstrap.php. Throws nothing.
	 *
	 * @param string $root The repository's root directory.
	 */
	public static function prepare( string $root ): void {
		$markers = self::markers( $root );
		if ( self::intercepted() ) {
			$report = self::report_in( getenv( self::REPORT_VARIABLE ) );
			if ( null !== $report ) {
				array_unshift( $markers, $report );
			}
			try {
				self::preload( $root, self::DIRECTORIES );
			} catch ( \CompileError $error ) {
				// The mutated copy does not compile. Keep the wrapper, as without this bootstrap:
				// each test that reaches the class meets the same error when the autoloader
				// includes the file again, and Infection reports a syntax error.
				return;
			} catch ( Throwable $error ) {
				self::leave( $markers, 'The classes under mutation could not be loaded: ' . $error->getMessage() );
			}
			stream_wrapper_restore( 'file' );
		}
		self::canary( $markers );
		register_shutdown_function( [ self::class, 'finish' ], $markers );
	}

	/**
	 * Leaves this process's report in the directory that bin/mutation-gate.sh names: a new file
	 * whose one line says that the process ran this bootstrap under Infection's interceptor. The
	 * file is new, so a directory that cannot take a file stops the report as it would stop a
	 * marker, and the script then finds the process missing.
	 *
	 * @param string|false $directory The directory, or false when the variable is not set.
	 * @return string|null The report, which then takes the canary's lines first, or null.
	 */
	public static function report_in( string|false $directory ): ?string {
		if ( false === $directory || '' === $directory ) {
			return null;
		}
		$path   = $directory . '/report-' . getmypid() . '-' . uniqid( '', true );
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			return null;
		}
		$line    = 'Process ' . getmypid() . " reported in.\n";
		$written = @fwrite( $handle, $line );
		fclose( $handle );
		return strlen( $line ) === $written ? $path : null;
	}

	/**
	 * Whether file:// streams of this process go through a user-space wrapper.
	 */
	public static function intercepted(): bool {
		$probe = @fopen( __FILE__, 'rb' );
		if ( false === $probe ) {
			return false;
		}
		$wrapper = stream_get_meta_data( $probe )['wrapper_type'] ?? '';
		fclose( $probe );
		return 'plainfile' !== $wrapper;
	}

	/**
	 * Loads every class, interface, trait and enum below the directories through the autoloader.
	 *
	 * @param string       $root        The repository's root directory.
	 * @param list<string> $directories Directories relative to the root, below src/.
	 * @return list<string> The names whose file declared nothing of that name.
	 */
	public static function preload( string $root, array $directories ): array {
		$missing = [];
		foreach ( self::names( $root, $directories ) as $name ) {
			if ( ! class_exists( $name ) && ! interface_exists( $name ) && ! trait_exists( $name ) && ! enum_exists( $name ) ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}

	/**
	 * The PSR-4 names of the PHP files below the directories.
	 *
	 * @param string       $root        The repository's root directory.
	 * @param list<string> $directories Directories relative to the root, below src/.
	 * @return list<string>
	 */
	public static function names( string $root, array $directories ): array {
		$names = [];
		foreach ( $directories as $directory ) {
			if ( ! is_dir( $root . '/' . $directory ) ) {
				continue;
			}
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = str_replace( '\\', '/', (string) $file );
				if ( str_ends_with( $path, '.php' ) ) {
					$names[] = 'BackupsForRestic\\' . str_replace( '/', '\\', substr( $path, strlen( $root . '/src/' ), -4 ) );
				}
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Writes, flushes and fsyncs a temporary file, as Storage\Files::write_durable() does, and
	 * leaves a marker when that fails or the stream is not a plain file.
	 *
	 * @param list<string> $markers The marker files, see markers().
	 * @return string|null Why durable writes fail, or null when they work.
	 */
	public static function canary( array $markers ): ?string {
		$problem = null;
		$path    = tempnam( sys_get_temp_dir(), 'bfr-canary-' );
		$handle  = false === $path ? false : @fopen( $path, 'wb' );
		if ( false === $handle ) {
			$problem = 'No temporary file could be opened for the fsync check.';
		} else {
			$wrapper = stream_get_meta_data( $handle )['wrapper_type'] ?? '';
			$synced  = false !== @fwrite( $handle, 'canary' ) && @fflush( $handle ) && @fsync( $handle );
			fclose( $handle );
			if ( 'plainfile' !== $wrapper ) {
				$problem = 'Files still open through the ' . $wrapper . ' stream wrapper after the restore.';
			} elseif ( ! $synced ) {
				$problem = 'fsync() failed on a plain file in ' . sys_get_temp_dir() . '.';
			}
		}
		if ( is_string( $path ) ) {
			@unlink( $path );
		}
		if ( null !== $problem ) {
			self::leave( $markers, $problem );
		}
		return $problem;
	}

	/**
	 * Run when the process ends: leaves a marker when file:// is a user-space wrapper again, for
	 * example because the interceptor was enabled after this bootstrap.
	 *
	 * @param list<string> $markers The marker files, see markers().
	 * @return bool Whether the plain wrapper was still in place.
	 */
	public static function finish( array $markers ): bool {
		if ( ! self::intercepted() ) {
			return true;
		}
		self::leave( $markers, 'file:// was a user-space stream wrapper when the process ended.' );
		return false;
	}

	/**
	 * The marker files, in the order they are tried: the path in BFR_MUTATION_CANARY, and
	 * .tools/mutation-canary. bin/mutation-gate.sh puts the first under TMPDIR, which is tmpfs in
	 * CI, and the second is on the disk of the checkout, so that a full file system leaves the
	 * other one. The script checks both before Infection starts and fails on either.
	 *
	 * @param string $root The repository's root directory.
	 * @return list<string>
	 */
	public static function markers( string $root ): array {
		$fallback = $root . '/.tools/mutation-canary';
		$path     = getenv( self::MARKER_VARIABLE );
		return is_string( $path ) && '' !== $path && $fallback !== $path ? [ $path, $fallback ] : [ $fallback ];
	}

	/**
	 * Appends one line to the first file that takes it: the process's report, then the markers.
	 *
	 * @param list<string> $markers The process's report and the marker files, see markers().
	 * @param string       $problem What went wrong.
	 * @return bool Whether a marker took the line.
	 */
	private static function leave( array $markers, string $problem ): bool {
		$line = 'Process ' . getmypid() . ': ' . $problem . "\n";
		foreach ( $markers as $marker ) {
			if ( false !== @file_put_contents( $marker, $line, FILE_APPEND ) ) {
				return true;
			}
		}
		// Neither file takes the line. The process must still not fail, because Infection would
		// count its mutant as killed; standard error at least reaches Infection's log.
		@fwrite( STDERR, 'Mutation canary, no marker could be written: ' . $line );
		return false;
	}
}
