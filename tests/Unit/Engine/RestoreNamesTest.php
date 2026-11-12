<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Exception\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestoreNamesTest extends TestCase {

	/** @return iterable<string, array{string, bool}> */
	public static function invalid(): iterable {
		foreach ( [ '', '.', '..', 'a/b', "nul\0", str_repeat( 'x', 256 ) ] as $i => $name ) {
			yield 'unix-' . $i => [ $name, false ];
			yield 'windows-' . $i => [ $name, true ];
		}
		foreach ( [ 'a\\b', 'a:b', 'file:stream', 'a?b', 'a*b', 'a|b', 'a<b', 'a>b', 'a"b', "a\tb", 'trailing.', 'trailing ', 'CON', 'nul.txt', 'NUL.tar.gz', 'COM1', 'lpt9.backup', 'COM¹.txt', 'LPT²', 'CONIN$', 'conout$', 'CONIN$.txt', 'conout$.log', 'LONGFI~1.TXT', 'custom~alias', "raw\xff" ] as $i => $name ) {
			yield 'windows-only-' . $i => [ $name, true ];
		}
	}

	#[DataProvider( 'invalid' )]
	public function test_names_that_escape_or_alias_the_destination_are_refused( string $name, bool $windows ): void {
		$this->expectException( FormatException::class );
		Names::validate( $name, $windows );
	}

	public function test_unix_preserves_names_that_are_ordinary_on_that_filesystem(): void {
		foreach ( [ '0', "latin1-\xe9", 'quote"name', 'a\\b', 'a:b', 'NUL.txt' ] as $name ) {
			Names::validate( $name, false );
			self::assertSame( $name, Names::key( $name, false ) );
		}
	}

	public function test_windows_keeps_unicode_and_detects_case_collisions(): void {
		foreach ( [ '.htaccess', 'COM10', 'convenient.txt', '日本語.txt', 'ümlaut.txt' ] as $name ) {
			Names::validate( $name, true );
		}
		self::assertSame( Names::key( 'ÜMLAUT.TXT', true ), Names::key( 'ümlaut.txt', true ) );
		self::assertNotSame( Names::key( 'Case', false ), Names::key( 'case', false ) );
	}
}
