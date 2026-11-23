<?php
/**
 * The plugins that WordPress loads, as the options table stores them.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * The option active_plugins of one database generation: each plugin by its path below the
 * plugins directory, in the order WordPress loads them. Only the form that WordPress writes is
 * accepted: an array of distinct paths with integer keys, which activate_plugin() appends to
 * and deactivate_plugins() leaves with gaps. A path has to be UTF-8 without control characters.
 */
final readonly class PluginList {

	/** Longest stored list that is read, in bytes. */
	public const int MAX_BYTES = 262144;

	/** Longest path of one plugin, in bytes. */
	public const int MAX_ENTRY = 1024;

	/**
	 * Holds a checked list.
	 *
	 * @param list<string> $entries Plugin paths in their stored order.
	 */
	private function __construct( public array $entries ) {
	}

	/**
	 * Reads a stored list.
	 *
	 * @param string|int|null $stored Value of active_plugins; its length in bytes if longer than MAX_BYTES; null if absent or ambiguous.
	 * @return self List.
	 * @throws DatabaseFailed With the code database_plugins_too_large if the value is longer than MAX_BYTES, database_plugins_invalid if it is not one that WordPress writes.
	 */
	public static function parse( string|int|null $stored ): self {
		$bytes = is_string( $stored ) ? strlen( $stored ) : $stored;
		if ( null !== $bytes && $bytes > self::MAX_BYTES ) {
			DatabaseFailed::raise(
				'The stored list of active plugins is longer than the review reads.',
				[
					'code'  => 'database_plugins_too_large',
					'bytes' => $bytes,
					'limit' => self::MAX_BYTES,
				]
			);
		}
		try {
			$value = is_string( $stored ) ? SerializedValue::decode( $stored, 1 ) : null;
		} catch ( FormatException $error ) {
			DatabaseFailed::raise( 'The stored list of active plugins is not one that WordPress writes.', [ 'code' => 'database_plugins_invalid' ], $error );
		}
		if ( ! is_array( $value ) ) {
			DatabaseFailed::raise( 'The stored list of active plugins is absent or not an array.', [ 'code' => 'database_plugins_invalid' ] );
		}
		$entries = [];
		$seen    = [];
		foreach ( $value as $key => $entry ) {
			if ( ! is_int( $key ) || ! is_string( $entry ) || ! self::entry( $entry ) || isset( $seen[ $entry ] ) ) {
				DatabaseFailed::raise( 'The stored list of active plugins has an entry that WordPress does not write.', [ 'code' => 'database_plugins_invalid' ] );
			}
			$seen[ $entry ] = true;
			$entries[]      = $entry;
		}
		return new self( $entries );
	}

	/**
	 * A list without plugins: what the comparison starts from when the running site's list cannot
	 * be read, so that every plugin of the other generation is listed.
	 *
	 * @return self Empty list.
	 */
	public static function none(): self {
		return new self( [] );
	}

	/**
	 * Whether a plugin path is one that this review reads, shows and writes.
	 *
	 * @param string $entry Path below the plugins directory.
	 * @return bool Whether it is UTF-8 of 1 to MAX_ENTRY bytes without control characters.
	 */
	public static function entry( string $entry ): bool {
		return strlen( $entry ) <= self::MAX_ENTRY && 1 === preg_match( '/\A[^\x00-\x1F\x7F]+\z/u', $entry );
	}

	/**
	 * Whether the list holds a plugin.
	 *
	 * @param string $plugin Plugin path.
	 * @return bool Whether WordPress would load it, if its file exists.
	 */
	public function has( string $plugin ): bool {
		return in_array( $plugin, $this->entries, true );
	}

	/**
	 * The list with a plugin at its end, as it is when the list has it already. Each list that
	 * this class holds can be read back: renumbering never lengthens a list that parse() read, and
	 * a list that the entry would take beyond MAX_BYTES is refused here, before anything writes it.
	 *
	 * @param string $plugin A path that entry() accepts.
	 * @return self List.
	 * @throws DatabaseFailed With the code database_plugins_too_large if the list with the entry would be longer than MAX_BYTES.
	 */
	public function with( string $plugin ): self {
		if ( $this->has( $plugin ) ) {
			return $this;
		}
		$list  = new self( [ ...$this->entries, $plugin ] );
		$bytes = strlen( $list->stored() );
		if ( $bytes > self::MAX_BYTES ) {
			DatabaseFailed::raise(
				'With this plugin added, the list of active plugins would be longer than the review reads.',
				[
					'code'  => 'database_plugins_too_large',
					'bytes' => $bytes,
					'limit' => self::MAX_BYTES,
				]
			);
		}
		return $list;
	}

	/**
	 * The plugins of this list that another one lacks.
	 *
	 * @param self $other List to compare with.
	 * @return list<string> Plugin paths, in this list's order.
	 */
	public function without( self $other ): array {
		return array_values( array_diff( $this->entries, $other->entries ) );
	}

	/**
	 * The list as serialize() writes it, with keys from zero, for the options table.
	 *
	 * @return string Stored value.
	 */
	public function stored(): string {
		$elements = '';
		foreach ( $this->entries as $index => $entry ) {
			$elements .= 'i:' . $index . ';s:' . strlen( $entry ) . ':"' . $entry . '";';
		}
		return 'a:' . count( $this->entries ) . ':{' . $elements . '}';
	}
}
