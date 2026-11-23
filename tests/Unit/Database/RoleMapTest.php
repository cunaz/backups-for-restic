<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Tests\Support\WordPressRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The role map of a generation: only the form WordPress writes, what it lets an account do as
 * WP_User builds it, and how two role maps differ.
 */
final class RoleMapTest extends TestCase {

	private const array REQUIRED = [ 'manage_options', 'edit_users', 'promote_users', 'update_core' ];

	/**
	 * A stored role map.
	 *
	 * @param array<array-key,mixed> $roles Roles.
	 */
	private static function map( array $roles ): RoleMap {
		return RoleMap::parse( serialize( $roles ) );
	}

	/**
	 * Expects a refusal with its code.
	 *
	 * @param \Closure():mixed $read Reader.
	 */
	private static function refused( string $code, \Closure $read ): void {
		try {
			$read();
			self::fail( 'A value that WordPress does not write was read.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( $code, $refused->context()['code'] ?? null, $refused->getMessage() );
		}
	}

	public function test_reads_the_roles_of_a_fresh_wordpress(): void {
		$roles = self::map( WordPressRoles::defaults() );
		self::assertSame( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], array_keys( $roles->roles ) );
		self::assertSame(
			[
				'read'    => true,
				'level_0' => true,
			],
			$roles->roles['subscriber']
		);
		self::assertCount( 61, $roles->roles['administrator'] );
	}

	public function test_a_capability_is_granted_where_empty_does_not_hold_for_its_value(): void {
		$values = [ true, false, null, 1, 0, -1, '1', '0', '', 'yes', '0.0', ' ' ];
		$stored = [];
		foreach ( $values as $number => $value ) {
			$stored[ 'cap' . $number ] = $value;
		}
		$roles = self::map(
			[
				'role' => [
					'name'         => 'Role',
					'capabilities' => $stored,
				],
			]
		);
		foreach ( $values as $number => $value ) {
			self::assertSame( ! empty( $value ), $roles->roles['role'][ 'cap' . $number ], var_export( $value, true ) );
		}
		self::assertSame( array_map( static fn ( mixed $value ): bool => ! empty( $value ), $stored ), RoleMap::own( serialize( $stored ) ) );
	}

	/** @return iterable<string, array{string|null}> */
	public static function invalid_maps(): iterable {
		$role = [
			'name'         => 'Role',
			'capabilities' => [ 'read' => true ],
		];
		yield 'absent' => [ null ];
		yield 'not serialized' => [ 'roles' ];
		yield 'a string' => [ serialize( 'roles' ) ];
		yield 'an object' => [ serialize( new \stdClass() ) ];
		yield 'a role that is no array' => [ serialize( [ 'role' => true ] ) ];
		yield 'a role without a name' => [ serialize( [ 'role' => [ 'capabilities' => [] ] ] ) ];
		yield 'a role without capabilities' => [ serialize( [ 'role' => [ 'name' => 'Role' ] ] ) ];
		yield 'a role with another field' => [
			serialize(
				[
					'role' => [
						...$role,
						'extra' => 1,
					],
				]
			),
		];
		yield 'a role with another field instead of its name' => [
			serialize(
				[
					'role' => [
						'title'        => 'Role',
						'capabilities' => [],
					],
				]
			),
		];
		yield 'a name that is no string' => [
			serialize(
				[
					'role' => [
						'name'         => 1,
						'capabilities' => [],
					],
				]
			),
		];
		yield 'capabilities that are no array' => [
			serialize(
				[
					'role' => [
						'name'         => 'Role',
						'capabilities' => 'read',
					],
				]
			),
		];
		yield 'a capability that is an array' => [
			serialize(
				[
					'role' => [
						'name'         => 'Role',
						'capabilities' => [ 'read' => [ true ] ],
					],
				]
			),
		];
		yield 'an empty role name' => [ serialize( [ '' => $role ] ) ];
		yield 'a role name with a line break' => [ serialize( [ "role\n" => $role ] ) ];
		yield 'a role name with a delete character' => [ serialize( [ "role\x7f" => $role ] ) ];
		yield 'a role name that is not UTF-8' => [ serialize( [ "r\xffle" => $role ] ) ];
		yield 'a role name that is too long' => [ serialize( [ str_repeat( 'r', RoleMap::MAX_NAME + 1 ) => $role ] ) ];
		yield 'a capability name with a NUL' => [
			serialize(
				[
					'role' => [
						'name'         => 'Role',
						'capabilities' => [ "read\0" => true ],
					],
				]
			),
		];
		yield 'an empty capability name' => [
			serialize(
				[
					'role' => [
						'name'         => 'Role',
						'capabilities' => [ '' => true ],
					],
				]
			),
		];
	}

	#[DataProvider( 'invalid_maps' )]
	public function test_refuses_a_role_map_that_wordpress_does_not_write( ?string $stored ): void {
		self::refused( 'database_roles_invalid', static fn (): RoleMap => RoleMap::parse( $stored ) );
	}

	public function test_names_have_bounded_length_and_no_control_characters(): void {
		self::assertTrue( RoleMap::name( str_repeat( 'é', RoleMap::MAX_NAME / 2 ) ) );
		self::assertFalse( RoleMap::name( str_repeat( 'é', RoleMap::MAX_NAME / 2 ) . 'x' ) );
		self::assertTrue( RoleMap::name( 5 ) );
		self::assertTrue( RoleMap::name( 'edit others posts' ) );
		self::assertFalse( RoleMap::name( '' ) );
		self::assertFalse( RoleMap::name( "\t" ) );
		self::assertFalse( RoleMap::name( "a\x1f" ) );
	}

	public function test_a_role_map_is_read_up_to_its_bound(): void {
		$stored = self::sized(
			RoleMap::MAX_BYTES,
			static fn ( array $capabilities ): string => serialize(
				[
					'role' => [
						'name'         => 'Role',
						'capabilities' => $capabilities,
					],
				]
			)
		);
		self::assertSame( RoleMap::MAX_BYTES, strlen( $stored ) );
		self::assertArrayHasKey( 'role', RoleMap::parse( $stored )->roles );
		self::refused( 'database_roles_invalid', static fn (): RoleMap => RoleMap::parse( $stored . ' ' ) );
		$own = self::sized( RoleMap::MAX_OWN_BYTES, static fn ( array $capabilities ): string => serialize( $capabilities ) );
		self::assertSame( RoleMap::MAX_OWN_BYTES, strlen( $own ) );
		self::assertNotSame( [], RoleMap::own( $own ) );
		self::refused( 'database_admin_capabilities', static fn (): array => RoleMap::own( $own . ' ' ) );
	}

	/**
	 * A stored value of exactly the given length: capabilities with long names, the last one
	 * shortened to fit.
	 *
	 * @param \Closure(array<string,bool>):string $stored Stores the capabilities.
	 */
	private static function sized( int $bytes, \Closure $stored ): string {
		$capabilities = [];
		$length       = strlen( $stored( $capabilities ) );
		for ( $i = 0; $length < $bytes; ++$i ) {
			$capabilities[ sprintf( 'c%05d', $i ) . str_repeat( 'x', 900 ) ] = true;
			$length = strlen( $stored( $capabilities ) );
		}
		// Shorten the last name until the value fits: each byte less shortens it by one.
		$name = (string) array_key_last( $capabilities );
		unset( $capabilities[ $name ] );
		$fitted = static fn ( string $last ): string => $stored(
			[
				...$capabilities,
				$last => true,
			]
		);
		$length = strlen( $fitted( $name ) );
		while ( $length > $bytes ) {
			$name   = substr( $name, 0, -1 );
			$length = strlen( $fitted( $name ) );
		}
		return $fitted( $name );
	}

	/** @return iterable<string, array{string|null}> */
	public static function invalid_own(): iterable {
		yield 'absent' => [ null ];
		yield 'a string' => [ serialize( 'administrator' ) ];
		yield 'nested' => [ serialize( [ 'administrator' => [ true ] ] ) ];
		yield 'an object' => [ serialize( [ 'administrator' => new \stdClass() ] ) ];
		yield 'a name that is not UTF-8' => [ serialize( [ "\xff" => true ] ) ];
	}

	#[DataProvider( 'invalid_own' )]
	public function test_refuses_an_account_capability_list_that_wordpress_does_not_write( ?string $stored ): void {
		self::refused( 'database_admin_capabilities', static fn (): array => RoleMap::own( $stored ) );
	}

	public function test_an_account_has_what_its_roles_and_own_capabilities_give_it(): void {
		$roles = self::map( WordPressRoles::defaults() );
		self::assertSame( [], $roles->missing( [ 'administrator' => true ], self::REQUIRED ) );
		self::assertSame( self::REQUIRED, $roles->missing( [ 'subscriber' => true ], self::REQUIRED ) );
		self::assertSame( self::REQUIRED, $roles->missing( [], self::REQUIRED ) );
		// WP_User::get_role_caps() takes every role its list names, whatever the value there.
		self::assertSame( [], $roles->missing( [ 'administrator' => false ], self::REQUIRED ) );
		// Its own entries come last and decide.
		self::assertSame(
			[ 'update_core' ],
			$roles->missing(
				[
					'administrator' => true,
					'update_core'   => false,
				],
				self::REQUIRED
			)
		);
		self::assertSame(
			[ 'edit_users', 'update_core' ],
			$roles->missing(
				[
					'editor'         => true,
					'manage_options' => true,
					'promote_users'  => true,
				],
				self::REQUIRED
			)
		);
		// A role that the map lacks gives nothing; the order of the answer is the order asked.
		self::assertSame(
			[ 'update_core', 'manage_options' ],
			$roles->missing(
				[
					'site_owner'    => true,
					'edit_users'    => true,
					'promote_users' => true,
				],
				[ 'update_core', 'manage_options', 'edit_users', 'promote_users' ]
			)
		);
		self::assertSame( [ 'backups' ], $roles->missing( [ 'administrator' => true ], [ 'backups', ...self::REQUIRED ] ) );
	}

	public function test_an_account_has_the_capabilities_of_all_its_roles(): void {
		$roles = self::map(
			[
				'options' => [
					'name'         => 'Options',
					'capabilities' => [ 'manage_options' => true ],
				],
				'core'    => [
					'name'         => 'Core',
					'capabilities' => [ 'update_core' => true ],
				],
			]
		);
		self::assertSame(
			[],
			$roles->missing(
				[
					'options' => true,
					'core'    => true,
				],
				[ 'manage_options', 'update_core' ]
			)
		);
		self::assertSame( [ 'update_core' ], $roles->missing( [ 'options' => true ], [ 'manage_options', 'update_core' ] ) );
	}

	public function test_a_later_role_of_the_account_overrides_an_earlier_one_as_in_wordpress(): void {
		$roles = self::map(
			[
				'grants' => [
					'name'         => 'Grants',
					'capabilities' => [ 'update_core' => true ],
				],
				'denies' => [
					'name'         => 'Denies',
					'capabilities' => [ 'update_core' => false ],
				],
			]
		);
		self::assertSame(
			[ 'update_core' ],
			$roles->missing(
				[
					'grants' => true,
					'denies' => true,
				],
				[ 'update_core' ]
			)
		);
		self::assertSame(
			[],
			$roles->missing(
				[
					'denies' => true,
					'grants' => true,
				],
				[ 'update_core' ]
			)
		);
	}

	public function test_the_changes_are_the_roles_whose_granted_capabilities_differ(): void {
		$live = self::map( WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ) );
		$next = self::map( WordPressRoles::defaults() );
		self::assertSame( [], $next->changes( $next ) );
		self::assertSame(
			[
				[
					'role'    => 'subscriber',
					'change'  => 'changed',
					'added'   => [],
					'removed' => [ 'edit_users', 'manage_options' ],
				],
			],
			$live->changes( $next )
		);
		self::assertSame(
			[
				[
					'role'    => 'subscriber',
					'change'  => 'changed',
					'added'   => [ 'edit_users', 'manage_options' ],
					'removed' => [],
				],
			],
			$next->changes( $live )
		);
	}

	public function test_a_role_of_only_one_generation_is_added_or_removed_in_name_order(): void {
		$now  = self::map(
			[
				'shop_manager' => [
					'name'         => 'Shop manager',
					'capabilities' => [
						'manage_woocommerce' => true,
						'read'               => true,
						'view_reports'       => false,
					],
				],
				'reader'       => [
					'name'         => 'Reader',
					'capabilities' => [ 'read' => true ],
				],
				'5'            => [
					'name'         => 'Five',
					'capabilities' => [ '7' => true ],
				],
			]
		);
		$next = self::map(
			[
				'reader'      => [
					'name'         => 'Renamed reader',
					'capabilities' => [
						'read'   => true,
						'unused' => false,
					],
				],
				'contributor' => [
					'name'         => 'Contributor',
					'capabilities' => [
						'read'       => true,
						'edit_posts' => true,
					],
				],
			]
		);
		self::assertSame(
			[
				[
					'role'    => '5',
					'change'  => 'removed',
					'added'   => [],
					'removed' => [ '7' ],
				],
				[
					'role'    => 'contributor',
					'change'  => 'added',
					'added'   => [ 'edit_posts', 'read' ],
					'removed' => [],
				],
				[
					'role'    => 'shop_manager',
					'change'  => 'removed',
					'added'   => [],
					'removed' => [ 'manage_woocommerce', 'read' ],
				],
			],
			$now->changes( $next )
		);
	}
}
