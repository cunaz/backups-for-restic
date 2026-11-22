<?php
/**
 * Compares the skipped tests of a PHPUnit run with the list that a CI job expects.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use DOMDocument;
use RuntimeException;

/**
 * A test class whose server or tool does not answer marks its tests skipped, and PHPUnit still
 * ends with status 0. A job in which some tests skip by design, such as the MySQL-only cases on
 * MariaDB, compares the skipped tests of its JUnit log with a list in tests/Skips: every skip
 * that is not on the list fails it, and so does a listed test that ran or is not in the log, so
 * that the list stays true (docs/04 §5).
 *
 * A class that skips in setUpBeforeClass() runs none of its tests. PHPUnit's failOnSkipped does
 * not count such a class, and the JUnit log names none of its tests, only the counts of the
 * class's testsuite element; this check reads those counts too.
 */
final class SkippedTests {

	/**
	 * What differs between the skips of a run and the expected ones.
	 *
	 * @param string $junit    JUnit XML as PHPUnit writes it with --log-junit.
	 * @param string $expected One test per line as Class::method, as the log names it; text after
	 *                         # is a comment.
	 * @return list<string> One line per difference; empty when the skips are the expected ones.
	 * @throws RuntimeException When the log cannot be read.
	 */
	public static function differences( string $junit, string $expected ): array {
		$run    = self::read( $junit );
		$wanted = self::expected( $expected );
		if ( 0 === $run['count'] ) {
			return [ 'The log holds no test: the run selected nothing or did not start.' ];
		}
		$problems = [];
		foreach ( $run['classes'] as $class ) {
			$problems[] = 'Skipped as a whole class, which no list can expect: ' . $class;
		}
		foreach ( $run['skipped'] as $test ) {
			if ( ! in_array( $test, $wanted, true ) ) {
				$problems[] = 'Skipped, but not on the list: ' . $test;
			}
		}
		foreach ( $wanted as $test ) {
			if ( ! in_array( $test, $run['tests'], true ) ) {
				$problems[] = 'On the list, but not in the log (renamed or removed?): ' . $test;
			} elseif ( ! in_array( $test, $run['skipped'], true ) ) {
				$problems[] = 'On the list, but it ran: ' . $test;
			}
		}
		return $problems;
	}

	/**
	 * The tests of a JUnit log, those of them that were skipped or marked incomplete, and the
	 * classes that skipped tests the log does not name.
	 *
	 * @param string $junit JUnit XML.
	 * @return array{count: int, tests: list<string>, skipped: list<string>, classes: list<string>}
	 *         The number of tests, the named tests, the named skipped tests, and each class with
	 *         unnamed skipped tests as "Class (N tests)".
	 * @throws RuntimeException When the XML cannot be parsed.
	 */
	public static function read( string $junit ): array {
		$document = new DOMDocument();
		if ( '' === trim( $junit ) || ! @$document->loadXML( $junit, LIBXML_NONET ) ) {
			throw new RuntimeException( 'The JUnit log is not XML.' );
		}
		$tests   = [];
		$skipped = [];
		foreach ( $document->getElementsByTagName( 'testcase' ) as $testcase ) {
			$name    = $testcase->getAttribute( 'class' ) . '::' . $testcase->getAttribute( 'name' );
			$tests[] = $name;
			// PHPUnit writes <skipped/> for a skipped and for an incomplete test.
			if ( $testcase->getElementsByTagName( 'skipped' )->length > 0 ) {
				$skipped[] = $name;
			}
		}
		$count   = count( $tests );
		$classes = [];
		foreach ( $document->getElementsByTagName( 'testsuite' ) as $suite ) {
			$count = max( $count, (int) $suite->getAttribute( 'tests' ) );
			// The testsuite element of a test class names its file; those of a configuration, a
			// test suite or a data provider do not.
			if ( ! $suite->hasAttribute( 'file' ) ) {
				continue;
			}
			$named = 0;
			foreach ( $suite->getElementsByTagName( 'testcase' ) as $testcase ) {
				if ( $testcase->getElementsByTagName( 'skipped' )->length > 0 ) {
					++$named;
				}
			}
			$unnamed = (int) $suite->getAttribute( 'skipped' ) - $named;
			if ( $unnamed > 0 ) {
				$classes[] = $suite->getAttribute( 'name' ) . ' (' . $unnamed . ' tests)';
			}
		}
		return [
			'count'   => $count,
			'tests'   => $tests,
			'skipped' => $skipped,
			'classes' => $classes,
		];
	}

	/**
	 * The tests on an expected list.
	 *
	 * @param string $content The file's content.
	 * @return list<string>
	 */
	public static function expected( string $content ): array {
		$tests = [];
		$lines = preg_split( '/\R/', $content );
		foreach ( false === $lines ? [] : $lines as $line ) {
			$line = trim( explode( '#', $line, 2 )[0] );
			if ( '' !== $line ) {
				$tests[] = $line;
			}
		}
		return array_values( array_unique( $tests ) );
	}
}
