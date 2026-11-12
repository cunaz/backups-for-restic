<?php
/**
 * Destination filesystem rules for snapshot names.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Unix retains raw filename bytes. Windows additionally refuses device names and aliases.
 */
final class Names {

	/**
	 * Checks one component before it is joined to a local destination.
	 *
	 * @param string $name Raw snapshot name.
	 * @param bool   $windows Whether the destination uses Windows filename rules.
	 * @throws FormatException If this component cannot safely name a destination file.
	 */
	public static function validate( string $name, bool $windows = DIRECTORY_SEPARATOR === '\\' ): void {
		if ( '' === $name || '.' === $name || '..' === $name || str_contains( $name, '/' ) || str_contains( $name, "\0" ) ) {
			throw new FormatException( 'The snapshot contains an unsafe file name.' );
		}
		if ( ! $windows ) {
			if ( strlen( $name ) > 255 ) {
				throw new FormatException( 'A snapshot name exceeds the destination component limit.' );
			}
			return;
		}
		if ( ! mb_check_encoding( $name, 'UTF-8' ) || strpbrk( $name, '<>:"\\|?*~' ) !== false || 1 === preg_match( '/[\x01-\x1f]/', $name ) || str_ends_with( $name, '.' ) || str_ends_with( $name, ' ' ) ) {
			throw new FormatException( 'A snapshot name cannot be represented safely on Windows.' );
		}
		if ( strlen( mb_convert_encoding( $name, 'UTF-16LE', 'UTF-8' ) ) > 510 || 1 === preg_match( '/\A(?:CON|CONIN\$|CONOUT\$|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])(?:\.|\z)/iu', $name ) ) {
			throw new FormatException( 'A snapshot name exceeds the Windows component limit or names a reserved device.' );
		}
	}

	/**
	 * Returns a conservative collision key for names on a case-insensitive destination.
	 *
	 * @param string $name Previously validated name.
	 * @param bool   $windows Destination filename rules.
	 * @return string Collision key, retaining bytes on Unix.
	 */
	public static function key( string $name, bool $windows = DIRECTORY_SEPARATOR === '\\' ): string {
		return $windows ? mb_convert_case( $name, MB_CASE_FOLD, 'UTF-8' ) : $name;
	}
}
