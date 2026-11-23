<?php
/**
 * Lists of active plugins of a chosen stored length, for tests of the bounds.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/** Distinct plugin paths as WordPress stores them in the option active_plugins. */
final class ActivePlugins {

	/**
	 * Plugin paths whose list, as serialize() stores it, is exactly the given number of bytes long.
	 *
	 * @param int $bytes Length of the stored list, at least 128.
	 * @return list<string> Distinct paths of at most 1,000 bytes, none of them this plugin's.
	 */
	public static function of_length( int $bytes ): array {
		$entries = [];
		// With one more, empty path: a path of n bytes, 100 to 999, then adds n + 2 bytes.
		$rest = $bytes - strlen( serialize( [ '' ] ) );
		while ( $rest > 1001 ) {
			$entries[] = self::path( count( $entries ), min( 1000, $rest - 515 ) );
			$rest      = $bytes - strlen( serialize( [ ...$entries, '' ] ) );
		}
		if ( $rest < 102 ) {
			throw new \LogicException( 'No list of active plugins is ' . $bytes . ' bytes long here.' );
		}
		$entries[] = self::path( count( $entries ), $rest - 2 );
		return $entries;
	}

	/**
	 * A path of the given length, distinct by its number.
	 *
	 * @param int $number Position in the list.
	 * @param int $length Length in bytes, at least 20.
	 */
	private static function path( int $number, int $length ): string {
		$directory = sprintf( 'plugin-%05d/', $number );
		return $directory . str_repeat( 'p', $length - strlen( $directory ) - 4 ) . '.php';
	}
}
