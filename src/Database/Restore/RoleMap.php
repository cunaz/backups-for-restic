<?php
/**
 * WordPress roles as the options table stores them, and what they let an account do.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * The role map of one database generation, the option {prefix}user_roles that WP_Roles reads,
 * with each capability reduced to granted or not, as WP_User::has_cap() reads it. Only the form
 * that WordPress writes is accepted: role names mapped to exactly a display name and a map of
 * capability names to scalars. A name has to be UTF-8 without control characters.
 */
final readonly class RoleMap {

	/** Longest stored role map that is read, in bytes. WordPress's own five roles take about 6 KiB. */
	public const int MAX_BYTES = 524288;

	/** Longest stored list of an account's own roles and capabilities that is read, in bytes. */
	public const int MAX_OWN_BYTES = 65536;

	/** Longest role or capability name, in bytes. */
	public const int MAX_NAME = 1024;

	/**
	 * Holds a checked role map.
	 *
	 * @param array<array-key,array<array-key,bool>> $roles Each role's capabilities, granted or not.
	 */
	private function __construct( public array $roles ) {
	}

	/**
	 * Reads a stored role map.
	 *
	 * @param string|null $stored Value of {prefix}user_roles, or null if absent or too long to read.
	 * @return self Role map.
	 * @throws DatabaseFailed With the code database_roles_invalid if the value is not one that WordPress writes.
	 */
	public static function parse( ?string $stored ): self {
		$code  = 'database_roles_invalid';
		$roles = [];
		foreach ( self::decode( $stored, self::MAX_BYTES, 3, $code ) as $role => $definition ) {
			if ( ! self::name( $role ) || ! is_array( $definition ) || 2 !== count( $definition ) || ! is_string( $definition['name'] ?? null ) || ! is_array( $definition['capabilities'] ?? null ) ) {
				DatabaseFailed::raise( 'A stored WordPress role is not one that WordPress writes.', [ 'code' => $code ] );
			}
			$roles[ $role ] = self::grants( $definition['capabilities'], $code );
		}
		return new self( $roles );
	}

	/**
	 * Reads an account's own roles and capabilities, the user meta {prefix}capabilities.
	 *
	 * @param string|null $stored Stored value, or null if absent, ambiguous or too long to read.
	 * @return array<array-key,bool> Role and capability names, granted or not, in their stored order.
	 * @throws DatabaseFailed With the code database_admin_capabilities if the value is not one that WordPress writes.
	 */
	public static function own( ?string $stored ): array {
		return self::grants( self::decode( $stored, self::MAX_OWN_BYTES, 1, 'database_admin_capabilities' ), 'database_admin_capabilities' );
	}

	/**
	 * Whether a role or capability name is one that this review reads and shows.
	 *
	 * @param int|string $name Array key or capability.
	 * @return bool Whether it is UTF-8 of 1 to MAX_NAME bytes without control characters.
	 */
	public static function name( int|string $name ): bool {
		$name = (string) $name;
		return strlen( $name ) <= self::MAX_NAME && 1 === preg_match( '/\A[^\x00-\x1F\x7F]+\z/u', $name );
	}

	/**
	 * The capabilities from a list that an account would lack under this role map. The account
	 * has what WP_User::get_role_caps() builds: the capabilities of every role that its own list
	 * names, whatever the value there, merged in that list's order, then its own list over them.
	 * The filters user_has_cap and map_meta_cap are not applied, so only what is stored counts.
	 *
	 * @param array<array-key,bool> $own The account's own roles and capabilities.
	 * @param list<string>          $required Capabilities to check.
	 * @return list<string> Those that the account would not have, in the order asked.
	 */
	public function missing( array $own, array $required ): array {
		$granted = [];
		foreach ( array_keys( $own ) as $name ) {
			if ( isset( $this->roles[ $name ] ) ) {
				// array_merge() as WordPress uses it, renumbering integer keys.
				$granted = array_merge( $granted, $this->roles[ $name ] );
			}
		}
		$granted = array_merge( $granted, $own );
		return array_values( array_filter( $required, static fn ( string $capability ): bool => true !== ( $granted[ $capability ] ?? false ) ) );
	}

	/**
	 * The roles whose capabilities differ between this map and the one an exchange brings live,
	 * by role name in byte order. A role that only one map has is added or removed; a role in
	 * both is listed only if it grants other capabilities afterwards.
	 *
	 * @param self $next The role map that becomes live.
	 * @return list<array{role:string,change:'added'|'removed'|'changed',added:list<string>,removed:list<string>}> Each role with the capabilities granted only afterwards and only now, in byte order.
	 */
	public function changes( self $next ): array {
		$names = array_map( strval( ... ), array_keys( $this->roles + $next->roles ) );
		sort( $names, SORT_STRING );
		$changes = [];
		foreach ( $names as $name ) {
			$now     = self::granted( $this->roles[ $name ] ?? [] );
			$after   = self::granted( $next->roles[ $name ] ?? [] );
			$added   = array_values( array_diff( $after, $now ) );
			$removed = array_values( array_diff( $now, $after ) );
			$change  = match ( true ) {
				! isset( $this->roles[ $name ] ) => 'added',
				! isset( $next->roles[ $name ] ) => 'removed',
				default                          => 'changed',
			};
			if ( 'changed' !== $change || [] !== $added || [] !== $removed ) {
				$changes[] = [
					'role'    => $name,
					'change'  => $change,
					'added'   => $added,
					'removed' => $removed,
				];
			}
		}
		return $changes;
	}

	/**
	 * The names of the granted capabilities.
	 *
	 * @param array<array-key,bool> $capabilities Capabilities, granted or not.
	 * @return list<string> Granted ones, in byte order.
	 */
	private static function granted( array $capabilities ): array {
		$names = array_map( strval( ... ), array_keys( array_filter( $capabilities ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Reduces capability values to granted or not, as empty() does in WP_User::has_cap(). The
	 * values are null, booleans, integers or strings: the decoder reads no other scalar and
	 * allows no deeper nesting here, and of these, empty() holds exactly for the ones listed.
	 *
	 * @param array<array-key,mixed> $capabilities Stored names and values.
	 * @param string                 $code Refusal code.
	 * @return array<array-key,bool> Names and whether each is granted.
	 * @throws DatabaseFailed With the code if a name is not one that this review reads.
	 */
	private static function grants( array $capabilities, string $code ): array {
		$grants = [];
		foreach ( $capabilities as $name => $value ) {
			if ( ! self::name( $name ) ) {
				DatabaseFailed::raise( 'A stored WordPress capability has a name that is empty, too long or not UTF-8 text.', [ 'code' => $code ] );
			}
			$grants[ $name ] = ! in_array( $value, [ null, false, 0, '', '0' ], true );
		}
		return $grants;
	}

	/**
	 * Decodes a bounded stored array.
	 *
	 * @param string|null $stored Stored value.
	 * @param int         $limit Longest value read, in bytes.
	 * @param int         $depth Deepest nesting.
	 * @param string      $code Refusal code.
	 * @return array<array-key,mixed> Array.
	 * @throws DatabaseFailed With the code if the value is absent, too long or not an array as WordPress writes it.
	 */
	private static function decode( ?string $stored, int $limit, int $depth, string $code ): array {
		try {
			$value = null === $stored || strlen( $stored ) > $limit ? null : SerializedValue::decode( $stored, $depth );
		} catch ( FormatException $error ) {
			DatabaseFailed::raise( 'A stored WordPress value is not one that WordPress writes.', [ 'code' => $code ], $error );
		}
		if ( ! is_array( $value ) ) {
			DatabaseFailed::raise( 'A stored WordPress value is absent, too long or not an array.', [ 'code' => $code ] );
		}
		return $value;
	}
}
