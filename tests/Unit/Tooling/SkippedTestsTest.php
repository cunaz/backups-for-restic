<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\SkippedTests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The check that keeps a CI job from turning green while its tests skip. */
final class SkippedTestsTest extends TestCase {

	private const string CLASS_NAME = 'BackupsForRestic\\Tests\\Database\\DatabaseExchangeTest';

	/**
	 * A JUnit log as PHPUnit 12 writes it, with one test class.
	 *
	 * @param array<string, bool> $tests   Test name => skipped.
	 * @param string              $another The testsuite element of another class, if any.
	 */
	private static function junit( array $tests, string $another = '' ): string {
		$cases = '';
		foreach ( $tests as $name => $skipped ) {
			$cases .= '<testcase name="' . htmlspecialchars( $name ) . '" class="' . self::CLASS_NAME . '" classname="BackupsForRestic.Tests.Database.DatabaseExchangeTest" assertions="0" time="0.1">'
				. ( $skipped ? '<skipped/>' : '' ) . '</testcase>';
		}
		$skipped = count( array_filter( $tests ) );
		return '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="database" tests="' . count( $tests ) . '" skipped="' . $skipped . '">'
			. '<testsuite name="' . self::CLASS_NAME . '" file="/tests/Database/DatabaseExchangeTest.php" tests="' . count( $tests ) . '" skipped="' . $skipped . '">'
			. $cases . '</testsuite>' . $another . '</testsuite></testsuites>';
	}

	public function test_the_expected_skips_pass_and_comments_and_blank_lines_are_ignored(): void {
		$junit = self::junit(
			[
				'test_rename_runs'                 => false,
				'test_mysql_only_exchange'         => true,
				'test_with_data with data set "a"' => true,
			]
		);
		$list  = "# MySQL-only by design\n\n" . self::CLASS_NAME . "::test_mysql_only_exchange  # the exchange\n"
			. self::CLASS_NAME . "::test_with_data with data set \"a\"\n";
		self::assertSame( [], SkippedTests::differences( $junit, $list ) );
	}

	public function test_a_skip_that_is_not_on_the_list_fails(): void {
		$junit = self::junit(
			[
				'test_dump'   => true,
				'test_import' => true,
			]
		);
		self::assertSame(
			[
				'Skipped, but not on the list: ' . self::CLASS_NAME . '::test_dump',
				'Skipped, but not on the list: ' . self::CLASS_NAME . '::test_import',
			],
			SkippedTests::differences( $junit, "# No skip is expected on MySQL.\n" )
		);
	}

	public function test_a_listed_test_that_ran_or_is_gone_fails(): void {
		$junit = self::junit( [ 'test_mysql_only_exchange' => false ] );
		$list  = self::CLASS_NAME . "::test_mysql_only_exchange\n" . self::CLASS_NAME . "::test_renamed_since\n";
		self::assertSame(
			[
				'On the list, but it ran: ' . self::CLASS_NAME . '::test_mysql_only_exchange',
				'On the list, but not in the log (renamed or removed?): ' . self::CLASS_NAME . '::test_renamed_since',
			],
			SkippedTests::differences( $junit, $list )
		);
	}

	public function test_a_class_that_skips_in_set_up_before_class_fails_although_the_log_names_none_of_its_tests(): void {
		// PHPUnit writes only the counts of such a class, and its failOnSkipped does not count it.
		$dump  = '<testsuite name="BackupsForRestic\\Tests\\Database\\DatabaseDumpTest" file="/tests/Database/DatabaseDumpTest.php" tests="3" assertions="0" errors="0" failures="0" skipped="3" time="0"/>';
		$junit = self::junit( [ 'test_rename_runs' => false ], $dump );
		self::assertSame( [ 'BackupsForRestic\\Tests\\Database\\DatabaseDumpTest (3 tests)' ], SkippedTests::read( $junit )['classes'] );
		self::assertSame(
			[ 'Skipped as a whole class, which no list can expect: BackupsForRestic\\Tests\\Database\\DatabaseDumpTest (3 tests)' ],
			SkippedTests::differences( $junit, '' )
		);
	}

	public function test_a_run_without_tests_fails(): void {
		self::assertSame(
			[ 'The log holds no test: the run selected nothing or did not start.' ],
			SkippedTests::differences( '<?xml version="1.0"?><testsuites/>', '' )
		);
	}

	public function test_an_unreadable_log_is_an_error(): void {
		$this->expectException( RuntimeException::class );
		SkippedTests::read( 'PHPUnit crashed before it wrote the log' );
	}

	public function test_a_test_with_a_data_set_keeps_its_name(): void {
		$run = SkippedTests::read( self::junit( [ 'test_case with data set #0' => true ] ) );
		self::assertSame( [ self::CLASS_NAME . '::test_case with data set #0' ], $run['skipped'] );
		self::assertSame( $run['skipped'], $run['tests'] );
	}

	public function test_a_numbered_data_set_can_be_expected_and_comments_need_a_space_after_the_hash(): void {
		$junit = self::junit(
			[
				'test_case with data set #0' => true,
				'test_case with data set #1' => false,
			]
		);
		$list  = "# Data sets that PHPUnit numbers.\n"
			. self::CLASS_NAME . "::test_case with data set #0 # the first one only\n"
			. '#' . self::CLASS_NAME . "::test_case with data set #1\n";
		self::assertSame( [ self::CLASS_NAME . '::test_case with data set #0' ], SkippedTests::expected( $list ) );
		self::assertSame( [], SkippedTests::differences( $junit, $list ) );
	}
}
