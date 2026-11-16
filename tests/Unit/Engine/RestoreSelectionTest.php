<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Engine\Restore\Selection;
use BackupsForRestic\Exception\InvalidArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestoreSelectionTest extends TestCase {

	public function test_duplicate_and_nested_paths_are_restored_once_without_flattening(): void {
		$selection = new Selection( [ '/site/a/file', '/site/a/', '/site/a', '/site/a-b', '/site/a/next', '/else/file' ] );
		self::assertSame( [ '/else/file', '/site/a', '/site/a-b' ], $selection->paths );
		self::assertTrue( $selection->visits( '/site' ) );
		self::assertTrue( $selection->visits( '/site/a/child' ) );
		self::assertTrue( $selection->visits( '/else/file' ) );
		self::assertFalse( $selection->visits( '/else/file-other' ) );
		self::assertFalse( $selection->visits( '/site/another' ) );
	}

	public function test_root_covers_everything_but_does_not_hide_invalid_input(): void {
		self::assertSame( [ '/' ], ( new Selection( [ '/site', '/' ] ) )->paths );
		$this->expectException( InvalidArgument::class );
		new Selection( [ '/', '/site/../secret' ] );
	}

	public function test_raw_name_bytes_are_preserved(): void {
		$path = "/raw\xff/0";
		self::assertSame( [ $path ], ( new Selection( [ $path ] ) )->paths );
	}

	/** @return iterable<string, array{list<string>}> */
	public static function invalid(): iterable {
		yield 'empty' => [ [] ];
		yield 'too many' => [ array_fill( 0, Selection::MAX_PATHS + 1, '/a' ) ];
		foreach ( [ '', 'relative', '//', '/a//b', '/a/./b', '/a/..', "/a\0", '/' . str_repeat( 'x', 4096 ), '/' . implode( '/', array_fill( 0, 257, 'a' ) ) ] as $i => $path ) {
			yield (string) $i => [ [ $path ] ];
		}
	}

	#[DataProvider( 'invalid' )]
	public function test_invalid_paths_cannot_start_a_restore( array $paths ): void {
		$this->expectException( InvalidArgument::class );
		new Selection( $paths );
	}
}
