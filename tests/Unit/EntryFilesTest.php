<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two files that WordPress loads before the plugin's version check has run: the main
 * file, and uninstall.php when the plugin is deleted. On a PHP older than 8.3 they have to
 * be read at all, so that the check can refuse: a syntax error would stop with a fatal
 * error instead, or, in uninstall.php, not get to the refusal. WordPress 6.7 runs on PHP
 * 7.2.24 and newer, so nothing newer than PHP 7.2 may appear in them.
 */
final class EntryFilesTest extends TestCase {

	/**
	 * Tokens of syntax that PHP 7.2 cannot parse, with the version that brought it.
	 */
	private const array NEWER_TOKENS = [
		'T_FN'                       => '7.4',
		'T_COALESCE_EQUAL'           => '7.4',
		'T_MATCH'                    => '8.0',
		'T_NULLSAFE_OBJECT_OPERATOR' => '8.0',
		'T_ATTRIBUTE'                => '8.0',
		'T_ENUM'                     => '8.1',
		'T_READONLY'                 => '8.1',
	];

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function files(): iterable {
		yield 'backups-for-restic.php' => [ 'backups-for-restic.php' ];
		yield 'uninstall.php' => [ 'uninstall.php' ];
	}

	/**
	 * The tokens of a file without whitespace and comments.
	 *
	 * @return list<array{int, string, int}|string>
	 */
	private static function tokens( string $file ): array {
		$tokens = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ) );
		return array_values( array_filter( $tokens, static fn ( array|string $token ): bool => ! is_array( $token ) || ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) );
	}

	#[DataProvider( 'files' )]
	public function test_the_file_uses_no_syntax_newer_than_php_7_2( string $file ): void {
		$tokens = self::tokens( $file );
		foreach ( $tokens as $index => $token ) {
			$line = is_array( $token ) ? $token[2] : 0;
			if ( is_array( $token ) ) {
				$name = token_name( $token[0] );
				self::assertArrayNotHasKey( $name, self::NEWER_TOKENS, sprintf( '%s:%d uses %s, which needs PHP %s.', $file, $line, $name, self::NEWER_TOKENS[ $name ] ?? '' ) );
				if ( in_array( $token[0], [ T_LNUMBER, T_DNUMBER ], true ) ) {
					self::assertStringNotContainsString( '_', $token[1], sprintf( '%s:%d: a numeric separator needs PHP 7.4.', $file, $line ) );
				}
				if ( T_CATCH === $token[0] ) {
					// catch ( Name $variable ): a catch without a variable needs PHP 8.0.
					$closing = $index + 1;
					while ( isset( $tokens[ $closing ] ) && ')' !== $tokens[ $closing ] ) {
						++$closing;
					}
					$variable = $tokens[ $closing - 1 ] ?? null;
					self::assertTrue( is_array( $variable ) && T_VARIABLE === $variable[0], sprintf( '%s:%d: a catch without a variable needs PHP 8.0.', $file, $line ) );
				}
			}
			$next = $tokens[ $index + 1 ] ?? null;
			self::assertFalse( ',' === $token && ')' === $next, sprintf( '%s: a trailing comma in an argument list needs PHP 7.3.', $file ) );
		}
	}

	#[DataProvider( 'files' )]
	public function test_the_version_check_comes_before_the_code_that_needs_php_8_3( string $file ): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		$check  = strpos( $source, "if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {" );
		$load   = strpos( $source, "require_once __DIR__ . '/src/autoload.php';" );

		self::assertNotFalse( $check, $file );
		self::assertNotFalse( $load, $file );
		self::assertLessThan( $load, $check, $file );
	}

	public function test_uninstalling_on_an_older_php_refuses_instead_of_returning(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$start  = (int) strpos( $source, "if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {" );
		$branch = substr( $source, $start, (int) strpos( $source, "\n}\n", $start ) - $start );

		// WordPress deletes the plugin when this file returns; its data would stay behind.
		self::assertStringContainsString( 'wp_die(', $branch );
		self::assertStringNotContainsString( 'return', $branch );
	}
}
