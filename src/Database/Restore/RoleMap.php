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
 * with each stored capability granted or denied, as WP_User::has_cap() reads it; a capability
 * that a role does not store is absent. Only the form that WordPress writes is accepted: role
 * names mapped to exactly a display name and a map of capability names to scalars. A name has to
 * be UTF-8 without control characters.
 *
 * @phpstan-type Change array{role:string,change:'added'|'removed'|'changed',added:list<string>,removed:list<string>,denials_added:list<string>,denials_removed:list<string>}
 */
final readonly class RoleMap {

	/**
	 * Longest stored role map that is read, in bytes. WordPress's own five roles take about
	 * 3 KiB. At this bound the exchange's checks under its write barrier took about 50 ms more
	 * than with WordPress's own roles (ADR 0043).
	 */
	public const int MAX_BYTES = 1048576;

	/** Longest stored list of an account's own roles and capabilities that is read, in bytes. */
	public const int MAX_OWN_BYTES = 65536;

	/** Longest role or capability name, in bytes. */
	public const int MAX_NAME = 1024;

	/**
	 * Holds a checked role map.
	 *
	 * @param array<array-key,array<array-key,bool>> $roles Each role's stored capabilities, granted or denied.
	 */
	private function __construct( public array $roles ) {
	}

	/**
	 * Reads a stored role map.
	 *
	 * @param string|int|null $stored Value of {prefix}user_roles; its length in bytes if longer than MAX_BYTES; null if absent or ambiguous.
	 * @return self Role map.
	 * @throws DatabaseFailed With the code database_roles_too_large if the value is longer than MAX_BYTES, database_roles_invalid if it is not one that WordPress writes.
	 */
	public static function parse( string|int|null $stored ): self {
		$code  = 'database_roles_invalid';
		$roles = [];
		foreach ( self::decode( $stored, self::MAX_BYTES, 3, $code, 'database_roles_too_large' ) as $role => $definition ) {
			if ( ! self::name( $role ) || ! is_array( $definition ) || 2 !== count( $definition ) || ! is_string( $definition['name'] ?? null ) || ! is_array( $definition['capabilities'] ?? null ) ) {
				DatabaseFailed::raise( 'A stored WordPress role is not one that WordPress writes.', [ 'code' => $code ] );
			}
			$roles[ $role ] = self::grants( $definition['capabilities'], $code );
		}
		return new self( $roles );
	}

	/**
	 * A role map without roles: what the comparison starts from when the running site's role map
	 * cannot be read, so that every role of the other generation is listed.
	 *
	 * @return self Empty role map.
	 */
	public static function none(): self {
		return new self( [] );
	}

	/**
	 * Reads an account's own roles and capabilities, the user meta {prefix}capabilities.
	 *
	 * @param string|int|null $stored Stored value; its length in bytes if longer than MAX_OWN_BYTES; null if absent or stored more than once.
	 * @return array<array-key,bool> Role and capability names, granted or not, in their stored order.
	 * @throws DatabaseFailed With the code database_admin_capabilities_unreadable if the value is absent, ambiguous, too long or not one that WordPress writes.
	 */
	public static function own( string|int|null $stored ): array {
		$code = 'database_admin_capabilities_unreadable';
		return self::grants( self::decode( $stored, self::MAX_OWN_BYTES, 1, $code, $code ), $code );
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
	 * The roles that differ between this map and the one an exchange brings live, by role name
	 * in byte order. A role that only one map has is added or removed. A role in both is listed
	 * if it grants or denies other capabilities afterwards: a stored denial matters for an account
	 * with several roles, where a later role's denial takes away what an earlier one grants.
	 *
	 * @param self $next The role map that becomes live.
	 * @return list<Change> Each role with the capabilities granted only afterwards (added) and only now (removed), and the denials stored only afterwards and only now, in byte order.
	 */
	public function changes( self $next ): array {
		$names = array_map( strval( ... ), array_keys( $this->roles + $next->roles ) );
		sort( $names, SORT_STRING );
		$changes = [];
		foreach ( $names as $name ) {
			$now    = $this->roles[ $name ] ?? [];
			$after  = $next->roles[ $name ] ?? [];
			$lists  = [
				'added'           => self::only( $after, $now, true ),
				'removed'         => self::only( $now, $after, true ),
				'denials_added'   => self::only( $after, $now, false ),
				'denials_removed' => self::only( $now, $after, false ),
			];
			$change = match ( true ) {
				! isset( $this->roles[ $name ] ) => 'added',
				! isset( $next->roles[ $name ] ) => 'removed',
				default                          => 'changed',
			};
			if ( 'changed' !== $change || [] !== array_merge( ...array_values( $lists ) ) ) {
				$changes[] = [
					'role'   => $name,
					'change' => $change,
				] + $lists;
			}
		}
		return $changes;
	}

	/**
	 * The capabilities that one role stores with a value and the other does not.
	 *
	 * @param array<array-key,bool> $these Capabilities of the role on one side.
	 * @param array<array-key,bool> $those Capabilities of the role on the other side.
	 * @param bool                  $granted Whether to compare grants or denials.
	 * @return list<string> Names, in byte order.
	 */
	private static function only( array $these, array $those, bool $granted ): array {
		$names = [];
		foreach ( $these as $name => $value ) {
			if ( $granted === $value && $granted !== ( $those[ $name ] ?? null ) ) {
				$names[] = (string) $name;
			}
		}
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
	 * @param string|int|null $stored Stored value, its length if it was too long to read, or null.
	 * @param int             $limit Longest value read, in bytes.
	 * @param int             $depth Deepest nesting.
	 * @param string          $code Refusal code for a value that is absent or not an array as WordPress writes it.
	 * @param string          $large Refusal code for a value longer than the limit.
	 * @return array<array-key,mixed> Array.
	 * @throws DatabaseFailed With one of the codes.
	 */
	private static function decode( string|int|null $stored, int $limit, int $depth, string $code, string $large ): array {
		$bytes = is_string( $stored ) ? strlen( $stored ) : $stored;
		if ( null !== $bytes && $bytes > $limit ) {
			DatabaseFailed::raise(
				'A stored WordPress value is longer than the review reads.',
				[
					'code'  => $large,
					'bytes' => $bytes,
					'limit' => $limit,
				]
			);
		}
		try {
			$value = is_string( $stored ) ? SerializedValue::decode( $stored, $depth ) : null;
		} catch ( FormatException $error ) {
			DatabaseFailed::raise( 'A stored WordPress value is not one that WordPress writes.', [ 'code' => $code ], $error );
		}
		if ( ! is_array( $value ) ) {
			DatabaseFailed::raise( 'A stored WordPress value is absent or not an array.', [ 'code' => $code ] );
		}
		return $value;
	}
}
