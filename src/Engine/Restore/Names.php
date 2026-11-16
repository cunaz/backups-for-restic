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
	 * Checks what makes a name unsafe in any tree, whether or not the entry is restored. The
	 * limits of the destination are left to validate(): an entry that is never written cannot
	 * exceed them.
	 *
	 * @param string $name Raw snapshot name.
	 * @throws FormatException If this component cannot be one path component.
	 */
	public static function structure( string $name ): void {
		if ( '' === $name || '.' === $name || '..' === $name || str_contains( $name, '/' ) || str_contains( $name, "\0" ) ) {
			throw new FormatException( 'The snapshot contains an unsafe file name.' );
		}
	}

	/**
	 * Checks one component before it is joined to a local destination.
	 *
	 * @param string $name Raw snapshot name.
	 * @param bool   $windows Whether the destination uses Windows filename rules.
	 * @throws FormatException If this component cannot safely name a destination file.
	 */
	public static function validate( string $name, bool $windows = DIRECTORY_SEPARATOR === '\\' ): void {
		self::structure( $name );
		if ( ! $windows ) {
			if ( strlen( $name ) > 255 ) {
				throw new FormatException( 'A snapshot name exceeds the destination component limit.' );
			}
			return;
		}
		if ( ! mb_check_encoding( $name, 'UTF-8' ) || strpbrk( $name, '<>:"\\|?*~' ) !== false || 1 === preg_match( '/[\x01-\x1f]/', $name ) || str_starts_with( $name, ' ' ) || str_ends_with( $name, '.' ) || str_ends_with( $name, ' ' ) ) {
			throw new FormatException( 'A snapshot name cannot be represented safely on Windows.' );
		}
		if ( strlen( mb_convert_encoding( $name, 'UTF-16LE', 'UTF-8' ) ) > 510 || 1 === preg_match( '/\A(?:CON|CONIN\$|CONOUT\$|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³]) *(?:\.|\z)/iu', $name ) ) {
			throw new FormatException( 'A snapshot name exceeds the Windows component limit or names a reserved device.' );
		}
	}

	/**
	 * Returns a collision key for names on a case-insensitive destination. Windows compares
	 * names one character at a time, so the key uses simple case folding: full folding would
	 * equate a sharp s with "ss" and refuse two names that NTFS stores side by side.
	 *
	 * @param string $name Previously validated name.
	 * @param bool   $windows Destination filename rules.
	 * @return string Collision key, retaining bytes on Unix.
	 */
	public static function key( string $name, bool $windows = DIRECTORY_SEPARATOR === '\\' ): string {
		return $windows ? mb_convert_case( $name, MB_CASE_FOLD_SIMPLE, 'UTF-8' ) : $name;
	}
}
