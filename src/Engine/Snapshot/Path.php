<?php
/**
 * Paths inside a snapshot, independent of the current filesystem.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Snapshot;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot paths use raw name bytes and forward slashes. They never name local files.
 */
final class Path {

	/**
	 * Splits an absolute snapshot path without normalizing away ambiguous components.
	 *
	 * @param string $path Absolute path; a trailing slash is allowed.
	 * @return list<string> Raw components, empty for the root.
	 * @throws InvalidArgument If the path is relative, too long or contains an unsafe component.
	 */
	public static function parts( string $path ): array {
		if ( ! str_starts_with( $path, '/' ) || strlen( $path ) > 4096 || str_contains( $path, "\0" ) ) {
			throw new InvalidArgument( 'Use an absolute path inside the snapshot, at most 4096 bytes long.' );
		}
		if ( '/' === $path ) {
			return [];
		}
		$parts = explode( '/', substr( str_ends_with( $path, '/' ) ? substr( $path, 0, -1 ) : $path, 1 ) );
		if ( count( $parts ) > 256 ) {
			throw new InvalidArgument( 'The snapshot path has more than 256 components.' );
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				throw new InvalidArgument( 'The snapshot path contains an empty, dot or parent component.' );
			}
		}
		return $parts;
	}
}
