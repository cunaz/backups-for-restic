<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every sort in the tests and the development scripts says how it compares. With its default
 * flag, sort() compares two numeric strings as numbers and every other pair as strings. A short
 * hexadecimal ID such as 053e4943 or 7278e184 is a number to PHP, so such a comparison is not
 * consistent and the result depends on the order of the input: CliTest failed on that once.
 * SORT_STRING sorts IDs, names and paths by their bytes, SORT_NUMERIC sorts numbers.
 *
 * The check reads tokens, not types. It therefore asks every call of sort(), rsort(),
 * asort(), arsort(), ksort(), krsort() and array_multisort() for a flag, also in PHP code
 * that a string holds for a site or a process to run, and it cannot tell whether the flag
 * fits the values. It leaves usort() and its kind and assertEqualsCanonicalizing() alone:
 * their <=> is right for numbers and objects, and only the values show whether it compares
 * strings. tests/Fixtures is not read, as PHPCS does not read it: its stand-ins copy
 * WordPress, which sorts the priorities of hooks as numbers.
 */
final class SortFlagsTest extends TestCase {

	/** The functions that take a flag for how they compare, by lowercase name. */
	private const array FUNCTIONS = [
		'sort'            => true,
		'rsort'           => true,
		'asort'           => true,
		'arsort'          => true,
		'ksort'           => true,
		'krsort'          => true,
		'array_multisort' => true,
	];

	/** The flags that say how array_multisort() compares the array before them. */
	private const array TYPE_FLAGS = [ 'SORT_REGULAR', 'SORT_NUMERIC', 'SORT_STRING', 'SORT_LOCALE_STRING', 'SORT_NATURAL' ];

	/**
	 * The flags of array_multisort() that do not say how it compares: the direction, and
	 * SORT_FLAG_CASE, which changes a type flag beside it and alone means SORT_REGULAR.
	 */
	private const array ORDER_FLAGS = [ 'SORT_ASC', 'SORT_DESC', 'SORT_FLAG_CASE' ];

	/** The escapes of a single-quoted string. */
	private const array SINGLE_QUOTED = [
		'\\\\' => '\\',
		"\\'"  => "'",
	];

	/** The escapes of a double-quoted string that code in it needs: a line break stays two characters. */
	private const array DOUBLE_QUOTED = [
		'\\\\' => '\\',
		'\\"'  => '"',
		'\\$'  => '$',
	];

	public function test_every_sort_of_the_tests_and_scripts_names_how_it_compares(): void {
		$root  = dirname( __DIR__, 3 );
		$found = [];
		foreach ( self::files( $root ) as $path ) {
			foreach ( self::unflagged( (string) file_get_contents( $root . '/' . $path ) ) as $line ) {
				$found[] = $path . ':' . $line;
			}
		}
		self::assertSame( [], $found, 'Name the flag: SORT_STRING for IDs, names and paths, SORT_NUMERIC for numbers.' );
	}

	public function test_the_check_reads_the_tests_the_scripts_and_the_code_in_their_strings(): void {
		$files = self::files( dirname( __DIR__, 3 ) );
		self::assertGreaterThan( 150, count( $files ) );
		foreach ( [ 'bin/fuzz.php', 'tests/bootstrap.php', 'tests/Integration/HttpStreamingTest.php', 'tests/Unit/Tooling/SortFlagsTest.php' ] as $path ) {
			self::assertContains( $path, $files );
		}
		self::assertSame( [], array_values( array_filter( $files, static fn ( string $path ): bool => str_starts_with( $path, 'tests/Fixtures/' ) ) ) );

		$calls = (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/sort-flags/calls.php' );
		$lines = array_keys( array_filter( explode( "\n", $calls ), static fn ( string $line ): bool => str_ends_with( $line, '// unflagged' ) ) );
		self::assertCount( 20, $lines, 'The fixture still has its calls.' );
		self::assertSame( array_map( static fn ( int $index ): int => $index + 1, $lines ), self::unflagged( $calls ) );
	}

	/**
	 * The PHP files of the tests and of the development scripts, without the fixtures.
	 *
	 * @param string $root The repository.
	 * @return list<string> Paths below the repository, in order.
	 */
	private static function files( string $root ): array {
		$files = [];
		foreach ( [ 'tests', 'bin' ] as $directory ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$path = str_replace( '\\', '/', substr( (string) $file, strlen( $root ) + 1 ) );
				if ( str_ends_with( $path, '.php' ) && ! str_starts_with( $path, 'tests/Fixtures/' ) ) {
					$files[] = $path;
				}
			}
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * The lines of the calls that do not say how they compare, in a PHP file and in the PHP
	 * code that its strings hold.
	 *
	 * @param string $code   PHP code.
	 * @param int    $offset Lines before the first line of the code.
	 * @return list<int> Line numbers.
	 */
	private static function unflagged( string $code, int $offset = 0 ): array {
		$tokens = array_values( array_filter( PhpToken::tokenize( $code ), static fn ( PhpToken $token ): bool => ! $token->isIgnorable() ) );
		$found  = [];
		foreach ( $tokens as $index => $token ) {
			if ( $token->is( [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ] ) ) {
				$contents = self::contents( $token );
				// Prose with an apostrophe reads as a string that does not end, which PHP gives
				// back whole: only what is shorter than the code around it is read again.
				if ( strlen( $contents ) < strlen( $code ) - strlen( '<?php ' ) ) {
					array_push( $found, ...self::unflagged( '<?php ' . $contents, $offset + $token->line - 1 ) );
				}
				continue;
			}
			$name = strtolower( ltrim( $token->text, '\\' ) );
			if ( ! $token->is( [ T_STRING, T_NAME_FULLY_QUALIFIED ] ) || ! isset( self::FUNCTIONS[ $name ] ) || '(' !== ( $tokens[ $index + 1 ]->text ?? '' ) ) {
				continue;
			}
			if ( $index > 0 && $tokens[ $index - 1 ]->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ] ) ) {
				continue;
			}
			$arguments = self::arguments( $tokens, $index + 1 );
			// No arguments is prose that names the function; a spread or a callable cannot be told.
			if ( null === $arguments || [] === $arguments ) {
				continue;
			}
			if ( 'array_multisort' === $name ? ! self::each_array_flagged( $arguments ) : count( $arguments ) < 2 ) {
				$found[] = $offset + $token->line;
			}
		}
		return $found;
	}

	/**
	 * What a string token holds, with the escapes of quotes, backslashes and dollar signs
	 * resolved; an escaped line break stays two characters, so that lines keep their numbers.
	 */
	private static function contents( PhpToken $token ): string {
		if ( ! $token->is( T_CONSTANT_ENCAPSED_STRING ) ) {
			return $token->text;
		}
		$quoted  = ltrim( $token->text, 'bB' );
		$escapes = "'" === $quoted[0] ? self::SINGLE_QUOTED : self::DOUBLE_QUOTED;
		return strtr( substr( $quoted, 1, -1 ), $escapes );
	}

	/**
	 * The arguments of a call, each as its tokens.
	 *
	 * @param list<PhpToken> $tokens The tokens without whitespace and comments.
	 * @param int            $open   The index of the opening parenthesis.
	 * @return list<list<PhpToken>>|null Null for a spread, a first-class callable or a call that does not end.
	 */
	private static function arguments( array $tokens, int $open ): ?array {
		$arguments = [];
		$argument  = [];
		$depth     = 0;
		$count     = count( $tokens );
		for ( $index = $open + 1; $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( 0 === $depth && ( ')' === $token->text || ',' === $token->text ) ) {
				if ( [] !== $argument ) {
					$arguments[] = $argument;
				}
				if ( ')' === $token->text ) {
					return $arguments;
				}
				$argument = [];
				continue;
			}
			if ( 0 === $depth && $token->is( T_ELLIPSIS ) ) {
				return null;
			}
			if ( in_array( $token->text, [ '(', '[', '{' ], true ) || $token->is( [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE ] ) ) {
				++$depth;
			} elseif ( in_array( $token->text, [ ')', ']', '}' ], true ) ) {
				--$depth;
			}
			$argument[] = $token;
		}
		return null;
	}

	/**
	 * Whether each array that array_multisort() sorts is followed by a flag that says how it
	 * compares, before the next array.
	 *
	 * @param list<list<PhpToken>> $arguments The arguments.
	 */
	private static function each_array_flagged( array $arguments ): bool {
		$flagged = true;
		foreach ( $arguments as $argument ) {
			$names = array_map( static fn ( PhpToken $token ): string => ltrim( $token->text, '\\' ), array_filter( $argument, static fn ( PhpToken $token ): bool => ! in_array( $token->text, [ '|', '(', ')' ], true ) ) );
			// An argument is a flag when it names flags and nothing else: $groups[ SORT_STRING ]
			// names one too, and is an array to sort.
			$flags = [] === array_diff( $names, self::TYPE_FLAGS, self::ORDER_FLAGS );
			if ( $flags && [] !== array_intersect( $names, self::TYPE_FLAGS ) ) {
				$flagged = true;
			} elseif ( $flags ) {
				continue;
			} elseif ( ! $flagged ) {
				return false;
			} else {
				$flagged = false;
			}
		}
		return $flagged;
	}
}
