<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Exception\FormatException;

final class ManifestTest extends TestCase {

	/** @return array<string, mixed> */
	private static function data(): array {
		return [
			'format'    => 'backups-for-restic-dump/1',
			'created'   => '2026-11-12T00:00:00Z',
			'server'    => '8.4.0',
			'charset'   => 'utf8mb4',
			'time_zone' => '+02:00',
			'prefix'    => 'wp_',
			'tables'    => [
				[
					'name'  => 'wp_posts',
					'file'  => '010-wp_posts.sql',
					'rows'  => 123,
					'bytes' => 4096,
				],
			],
			'views'     => [ 'wp_titles' ],
		];
	}

	public function test_complete_metadata_keeps_counts_and_binds_resumption_to_its_bytes(): void {
		$json     = json_encode( self::data(), JSON_THROW_ON_ERROR );
		$manifest = Manifest::parse( $json, 'wp_' );
		self::assertSame( self::data()['tables'], $manifest->tables );
		self::assertSame( [ 'wp_titles' ], $manifest->views );
		self::assertSame( '+02:00', $manifest->time_zone );
		self::assertSame( hash( 'sha256', $json ), $manifest->fingerprint );
	}

	/** @return iterable<string, array{string}> */
	public static function rejected(): iterable {
		foreach ( [
			'format'    => 'backups-for-restic-dump/2',
			'created'   => 'yesterday',
			'charset'   => 'latin1',
			'time_zone' => "+00:00'; DROP TABLE wp_users; --",
			'prefix'    => 'other_',
			'tables'    => [
				[
					'name'  => 'wp_posts',
					'file'  => '../wp-config.php',
					'rows'  => 1,
					'bytes' => 1,
				],
			],
			'views'     => [ 'other_view' ],
		] as $field => $value ) {
			yield $field => [ json_encode( array_replace( self::data(), [ $field => $value ] ), JSON_THROW_ON_ERROR ) ];
		}
		foreach ( [
			'missing row count'  => [ 'rows' => null ],
			'negative rows'      => [ 'rows' => -1 ],
			'missing byte count' => [ 'bytes' => null ],
			'zero bytes'         => [ 'bytes' => 0 ],
			'foreign table'      => [
				'name' => 'other_posts',
				'file' => '010-other_posts.sql',
			],
			'qualified table'    => [
				'name' => 'wp_other.table',
				'file' => '010-wp_other%2Etable.sql',
			],
		] as $name => $change ) {
			$data              = self::data();
			$data['tables'][0] = array_replace( $data['tables'][0], $change );
			yield $name => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
		}
		$data             = self::data();
		$data['tables'][] = $data['tables'][0];
		yield 'duplicate tables' => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
		$data            = self::data();
		$data['views'][] = 'wp_posts';
		yield 'view collides with table' => [ json_encode( $data, JSON_THROW_ON_ERROR ) ];
	}

	#[DataProvider( 'rejected' )]
	public function test_partial_or_misdirected_inventory_is_refused( string $json ): void {
		$this->expectException( FormatException::class );
		Manifest::parse( $json, 'wp_' );
	}

	public function test_how_the_dump_read_each_table_is_kept_by_ordinal_and_absent_from_an_earlier_manifest(): void {
		$read             = [
			'key'               => [ 'object_id', 'term_taxonomy_id' ],
			'snapshot'          => false,
			'restarts'          => 1,
			'largest_statement' => 4000,
		];
		$data             = self::data();
		$data['tables'][] = [
			'name'  => 'wp_term_relationships',
			'file'  => '010-wp_term_relationships.sql',
			'rows'  => 2,
			'bytes' => 8192,
		] + $read;
		$manifest         = Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
		self::assertSame( [ null, $read ], $manifest->reads );
		// The other fields of the table stay what they were.
		self::assertSame( array_slice( $data['tables'][1], 0, 4 ), $manifest->tables[1] );
		$data['tables'][1]['key'] = [];
		self::assertSame( [], Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' )->reads[1]['key'] ?? null );
	}

	/** @return iterable<string, array{array<string,mixed>}> */
	public static function invalid_reads(): iterable {
		$read = [
			'key'               => [ 'id' ],
			'snapshot'          => true,
			'restarts'          => 0,
			'largest_statement' => 100,
		];
		foreach ( array_keys( $read ) as $field ) {
			$partial = $read;
			unset( $partial[ $field ] );
			yield 'without ' . $field => [ $partial ];
		}
		yield 'a key that is no list' => [ [ 'key' => [ 'a' => 'id' ] ] + $read ];
		yield 'a key of a number' => [ [ 'key' => [ 1 ] ] + $read ];
		yield 'a key with an empty name' => [ [ 'key' => [ '' ] ] + $read ];
		yield 'a key with an empty name before a valid one' => [ [ 'key' => [ '', 'id' ] ] + $read ];
		yield 'a key with an empty name after a valid one' => [ [ 'key' => [ 'id', '' ] ] + $read ];
		yield 'a key that is a name' => [ [ 'key' => 'id' ] + $read ];
		yield 'a snapshot of a number' => [ [ 'snapshot' => 1 ] + $read ];
		yield 'negative restarts' => [ [ 'restarts' => -1 ] + $read ];
		yield 'restarts as text' => [ [ 'restarts' => '0' ] + $read ];
		yield 'a negative statement' => [ [ 'largest_statement' => -1 ] + $read ];
		yield 'a statement as a number with a fraction' => [ [ 'largest_statement' => 1.5 ] + $read ];
	}

	/** @param array<string,mixed> $read */
	#[DataProvider( 'invalid_reads' )]
	public function test_a_record_of_how_a_table_was_read_is_complete_and_of_its_kinds_or_refused( array $read ): void {
		$data              = self::data();
		$data['tables'][0] = $data['tables'][0] + $read;
		try {
			Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
			self::fail( 'An incomplete or invalid record of how the dump read a table was accepted.' );
		} catch ( FormatException $refused ) {
			self::assertSame( 'database_restore_format', $refused->context()['code'] ?? null );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function names_in_other_scripts(): iterable {
		yield 'Cyrillic' => [ 'wp_архив' ];
		yield 'Chinese' => [ 'wp_内容' ];
		yield 'Arabic' => [ 'wp_مقالات' ];
		yield 'Hebrew' => [ 'wp_שלום' ];
		yield 'Devanagari with its vowel signs' => [ 'wp_कथा_सूची' ];
		yield 'Thai with its marks' => [ 'wp_ข้อมูล' ];
		yield 'a combining accent' => [ "wp_e\u{301}te\u{301}" ];
		yield 'Latin-1 letters' => [ 'wp_åtgärd_ß' ];
		yield 'a space inside, digits and ASCII punctuation' => [ 'wp_my table-2$(x)' ];
	}

	#[DataProvider( 'names_in_other_scripts' )]
	public function test_a_table_or_view_named_in_another_script_is_restored( string $name ): void {
		$data                      = self::data();
		$data['tables']            = [
			[
				'name'  => $name,
				'file'  => '010-' . rawurlencode( $name ) . '.sql',
				'rows'  => 1,
				'bytes' => 100,
			],
		];
		$data['tables'][0]['file'] = ( new \BackupsForRestic\Database\Dump\Table( $name, '', [], null, [] ) )->file();
		$data['views']             = [ $name . '_view' ];
		$manifest                  = Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
		self::assertSame( $name, $manifest->tables[0]['name'] );
		self::assertSame( [ $name . '_view' ], $manifest->views );
		self::assertTrue( Manifest::printable( $name ) );
	}

	/** @return iterable<string, array{string, string}> */
	public static function names_that_do_not_print_as_themselves(): iterable {
		$characters = [
			'right-to-left override'      => [ "\u{202E}", '\\u202e' ],
			'left-to-right isolate'       => [ "\u{2066}", '\\u2066' ],
			'right-to-left mark'          => [ "\u{200F}", '\\u200f' ],
			'Arabic letter mark'          => [ "\u{061C}", '\\u061c' ],
			'zero-width space'            => [ "\u{200B}", '\\u200b' ],
			'zero-width joiner'           => [ "\u{200D}", '\\u200d' ],
			'zero-width no-break space'   => [ "\u{FEFF}", '\\ufeff' ],
			'word joiner'                 => [ "\u{2060}", '\\u2060' ],
			'soft hyphen'                 => [ "\u{00AD}", '\\u00ad' ],
			'Mongolian vowel separator'   => [ "\u{180E}", '\\u180e' ],
			'next line, a C1 control'     => [ "\u{0085}", '\\u0085' ],
			'control sequence introducer' => [ "\u{009B}", '\\u009b' ],
			'no-break space'              => [ "\u{00A0}", '\\u00a0' ],
			'ideographic space'           => [ "\u{3000}", '\\u3000' ],
			'line separator'              => [ "\u{2028}", '\\u2028' ],
			'paragraph separator'         => [ "\u{2029}", '\\u2029' ],
			'combining grapheme joiner'   => [ "\u{034F}", '\\u034f' ],
			'variation selector 16'       => [ "\u{FE0F}", '\\ufe0f' ],
			'Hangul filler'               => [ "\u{3164}", '\\u3164' ],
			'halfwidth Hangul filler'     => [ "\u{FFA0}", '\\uffa0' ],
			'Khmer inherent vowel'        => [ "\u{17B4}", '\\u17b4' ],
			'private use'                 => [ "\u{E000}", '\\ue000' ],
			'unassigned'                  => [ "\u{0378}", '\\u0378' ],
			'noncharacter'                => [ "\u{FFFE}", '\\ufffe' ],
		];
		foreach ( $characters as $kind => [ $character, $escape ] ) {
			yield $kind => [ 'wp_po' . $character . 'sts', '"wp_po' . $escape . 'sts"' ];
		}
		// MySQL allows no character beyond the Basic Multilingual Plane in an identifier, and
		// no space at the end of a table name.
		yield 'beyond the Basic Multilingual Plane' => [ "wp_\u{10400}", "\"wp_\u{10400}\"" ];
		yield 'a tag character' => [ "wp_posts\u{E0041}", '"wp_posts\\U000e0041"' ];
		yield 'a space at the end' => [ 'wp_posts ', '"wp_posts "' ];
	}

	#[DataProvider( 'names_that_do_not_print_as_themselves' )]
	public function test_a_table_whose_name_does_not_print_as_itself_is_refused_with_its_name_escaped( string $name, string $escaped ): void {
		$data                      = self::data();
		$data['tables'][0]['name'] = $name;
		$data['tables'][0]['file'] = ( new \BackupsForRestic\Database\Dump\Table( $name, '', [], null, [] ) )->file();
		try {
			Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
			self::fail( 'A table name that does not print as itself was accepted.' );
		} catch ( FormatException $refused ) {
			self::assertSame( 'database_restore_name', $refused->context()['code'] ?? null );
			self::assertSame( $escaped, $refused->context()['table'] ?? null );
			self::assertStringNotContainsString( $name, $refused->getMessage() );
		}
		self::assertFalse( Manifest::printable( $name ) );
	}

	public function test_a_view_whose_name_does_not_print_as_itself_is_refused_too(): void {
		$data          = self::data();
		$data['views'] = [ "wp_ti\u{200B}tles" ];
		try {
			Manifest::parse( json_encode( $data, JSON_THROW_ON_ERROR ), 'wp_' );
			self::fail( 'A view name that does not print as itself was accepted.' );
		} catch ( FormatException $refused ) {
			self::assertSame( 'database_restore_name', $refused->context()['code'] ?? null );
			self::assertSame( '"wp_ti\\u200btles"', $refused->context()['table'] ?? null );
		}
	}

	public function test_an_escaped_name_holds_printable_characters_only(): void {
		foreach ( [ "wp_a\u{202E}b", "wp_\u{FE0F}\u{3164}", 'wp_"quoted\\' ] as $name ) {
			$quoted = Manifest::quoted( $name );
			self::assertMatchesRegularExpression( '/\A"[\x20-\x7e]*"\z/', $quoted, $name );
		}
		self::assertSame( '"wp_\\"quoted\\\\"', Manifest::quoted( 'wp_"quoted\\' ) );
	}
}
