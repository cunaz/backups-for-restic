<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Continuity;
use BackupsForRestic\Database\Restore\PluginList;
use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Tests\Support\ActivePlugins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list of active plugins of a generation, only in the form WordPress writes, and what an
 * exchange must keep working: the administrator's capabilities and this plugin's path.
 */
final class PluginListTest extends TestCase {

	private const string OWN = 'backups-for-restic/backups-for-restic.php';

	public function test_reads_a_list_in_its_stored_order_also_with_the_gaps_of_deactivation(): void {
		self::assertSame( [ 'b/b.php', 'a/a.php' ], PluginList::parse( serialize( [ 'b/b.php', 'a/a.php' ] ) )->entries );
		self::assertSame(
			[ 'a.php', 'c/c.php' ],
			PluginList::parse(
				serialize(
					[
						0 => 'a.php',
						2 => 'c/c.php',
					]
				)
			)->entries
		);
		self::assertSame( [], PluginList::parse( 'a:0:{}' )->entries );
		self::assertSame( [ 'hello.php', 'é/plugin.php', '../x.php' ], PluginList::parse( serialize( [ 'hello.php', 'é/plugin.php', '../x.php' ] ) )->entries );
	}

	/** @return iterable<string, array{string|null}> */
	public static function invalid(): iterable {
		yield 'absent' => [ null ];
		yield 'not serialized' => [ 'hello.php' ];
		yield 'an empty value' => [ '' ];
		yield 'a string' => [ serialize( 'hello.php' ) ];
		yield 'an object' => [ serialize( new \stdClass() ) ];
		yield 'a string key' => [ serialize( [ 'hello' => 'hello.php' ] ) ];
		yield 'an integer entry' => [ serialize( [ 7 ] ) ];
		yield 'a boolean entry' => [ serialize( [ true ] ) ];
		yield 'a null entry' => [ serialize( [ null ] ) ];
		yield 'a nested list' => [ serialize( [ [ 'hello.php' ] ] ) ];
		yield 'a repeated entry' => [ serialize( [ 'hello.php', 'akismet/akismet.php', 'hello.php' ] ) ];
		yield 'an empty entry' => [ serialize( [ '' ] ) ];
		yield 'an entry with a line break' => [ serialize( [ "hello.php\n" ] ) ];
		yield 'an entry with a NUL' => [ serialize( [ "hello.php\0.txt" ] ) ];
		yield 'an entry that is not UTF-8' => [ serialize( [ "h\xe9llo.php" ] ) ];
		yield 'an entry that is too long' => [ serialize( [ str_repeat( 'p', PluginList::MAX_ENTRY - 3 ) . '.php' ] ) ];
	}

	#[DataProvider( 'invalid' )]
	public function test_refuses_a_list_that_wordpress_does_not_write( ?string $stored ): void {
		try {
			PluginList::parse( $stored );
			self::fail( 'A list that WordPress does not write was read.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_plugins_invalid', $refused->context()['code'] ?? null );
		}
	}

	public function test_a_list_and_an_entry_are_read_up_to_their_bounds(): void {
		$longest = str_repeat( 'p', PluginList::MAX_ENTRY - 4 ) . '.php';
		self::assertSame( [ $longest ], PluginList::parse( serialize( [ $longest ] ) )->entries );
		$entries = [];
		$length  = strlen( serialize( $entries ) );
		for ( $i = 0; $length < PluginList::MAX_BYTES; ++$i ) {
			$entries[] = sprintf( '%05d/', $i ) . str_repeat( 'p', 900 ) . '.php';
			$length    = strlen( serialize( $entries ) );
		}
		array_pop( $entries );
		// Lengthen the last entry while one more byte still fits.
		$last   = '';
		$length = strlen( serialize( [ ...$entries, 'x' ] ) );
		while ( $length <= PluginList::MAX_BYTES ) {
			$last  .= 'x';
			$length = strlen( serialize( [ ...$entries, $last . 'x' ] ) );
		}
		$stored = serialize( [ ...$entries, $last ] );
		self::assertSame( PluginList::MAX_BYTES, strlen( $stored ) );
		self::assertCount( count( $entries ) + 1, PluginList::parse( $stored )->entries );
		try {
			PluginList::parse( serialize( [ ...$entries, $last . 'x' ] ) );
			self::fail( 'A list beyond the bound was read.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame(
				[
					'code'  => 'database_plugins_too_large',
					'bytes' => PluginList::MAX_BYTES + 1,
					'limit' => PluginList::MAX_BYTES,
				],
				$refused->context()
			);
		}
	}

	public function test_a_length_instead_of_a_value_is_too_large_or_nothing_that_can_be_read(): void {
		foreach ( [
			PluginList::MAX_BYTES + 1 => 'database_plugins_too_large',
			PluginList::MAX_BYTES     => 'database_plugins_invalid',
		] as $bytes => $code ) {
			try {
				PluginList::parse( $bytes );
				self::fail( 'A length was read as a list.' );
			} catch ( DatabaseFailed $refused ) {
				self::assertSame( $code, $refused->context()['code'] ?? null );
			}
		}
	}

	public function test_without_the_running_sites_list_every_plugin_of_the_other_generation_starts(): void {
		$after = PluginList::parse( serialize( [ 'b.php', 'a/a.php' ] ) );
		self::assertSame( [], PluginList::none()->entries );
		self::assertSame( [ 'b.php', 'a/a.php' ], $after->without( PluginList::none() ) );
		self::assertSame( [], PluginList::none()->without( $after ) );
	}

	public function test_adds_this_plugin_at_the_end_once_and_stores_the_list_as_serialize_does(): void {
		$list  = PluginList::parse(
			serialize(
				[
					2 => 'akismet/akismet.php',
					5 => 'é/plugin.php',
				]
			)
		);
		$added = $list->with( self::OWN );
		self::assertFalse( $list->has( self::OWN ) );
		self::assertTrue( $added->has( self::OWN ) );
		self::assertSame( [ 'akismet/akismet.php', 'é/plugin.php', self::OWN ], $added->entries );
		self::assertSame( $added, $added->with( self::OWN ) );
		self::assertSame( serialize( $added->entries ), $added->stored() );
		self::assertSame( $added->entries, unserialize( $added->stored() ) );
		self::assertSame( $added->entries, PluginList::parse( $added->stored() )->entries );
		self::assertSame( 'a:0:{}', PluginList::parse( 'a:0:{}' )->stored() );
	}

	public function test_this_plugin_is_added_only_while_the_list_stays_within_the_bound(): void {
		// Its entry adds 55 bytes to a list of 100 to 998 entries: i:N;s:41:"…"; with the count as long as before.
		$fits = PluginList::parse( serialize( ActivePlugins::of_length( PluginList::MAX_BYTES - 55 ) ) );
		self::assertSame( PluginList::MAX_BYTES, strlen( $fits->with( self::OWN )->stored() ) );
		$over = PluginList::parse( serialize( ActivePlugins::of_length( PluginList::MAX_BYTES - 54 ) ) );
		try {
			$over->with( self::OWN );
			self::fail( 'This plugin was added to a list that would be too long to read back.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame(
				[
					'code'  => 'database_plugins_too_large',
					'bytes' => PluginList::MAX_BYTES + 1,
					'limit' => PluginList::MAX_BYTES,
				],
				$refused->context()
			);
		}
		// A list that holds this plugin already stays as it is, up to the bound.
		$full = PluginList::parse( serialize( [ ...ActivePlugins::of_length( PluginList::MAX_BYTES - 55 ), self::OWN ] ) );
		self::assertSame( $full, $full->with( self::OWN ) );
	}

	public function test_without_lists_the_entries_that_the_other_list_lacks_in_this_order(): void {
		$now   = PluginList::parse( serialize( [ 'c.php', 'a.php', 'b.php', self::OWN ] ) );
		$after = PluginList::parse( serialize( [ 'b.php', self::OWN, 'd.php' ] ) );
		self::assertSame( [ 'c.php', 'a.php' ], $now->without( $after ) );
		self::assertSame( [ 'd.php' ], $after->without( $now ) );
		self::assertSame( [], $now->without( $now ) );
	}

	public function test_continuity_names_valid_capabilities_once_and_a_valid_path(): void {
		$continuity = Continuity::of( [ 'manage_options', 'update_core', 'manage_options' ], self::OWN );
		self::assertSame( [ 'manage_options', 'update_core' ], $continuity->capabilities );
		self::assertSame( self::OWN, $continuity->plugin );
		self::assertNull( Continuity::of( [ 'manage_options' ], null )->plugin );
		self::assertSame( [ '5' ], Continuity::of( [ '5' ], null )->capabilities );
		$invalid = [
			'no list'               => [ 'manage_options', null ],
			'no capability'         => [ [], null ],
			'a map'                 => [ [ 'a' => 'manage_options' ], null ],
			'a number'              => [ [ 5 ], null ],
			'an empty name'         => [ [ '' ], null ],
			'a name with a newline' => [ [ "manage_options\n" ], null ],
			'a long name'           => [ [ str_repeat( 'c', RoleMap::MAX_NAME + 1 ) ], null ],
			'an empty path'         => [ [ 'manage_options' ], '' ],
			'a path with a NUL'     => [ [ 'manage_options' ], "a\0.php" ],
		];
		foreach ( $invalid as $case => [ $capabilities, $plugin ] ) {
			try {
				Continuity::of( $capabilities, $plugin );
				self::fail( 'Continuity accepted ' . $case . '.' );
			} catch ( InvalidArgument ) {
				self::assertTrue( true );
			}
		}
	}
}
