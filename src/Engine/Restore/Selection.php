<?php
/**
 * A bounded selection of absolute paths inside a snapshot.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Preserves original path bytes and removes overlapping selections, never flattening them.
 */
final readonly class Selection {

	public const int MAX_PATHS = 256;

	/**
	 * Canonical, disjoint paths in byte order.
	 *
	 * @var list<string>
	 */
	public array $paths;

	/**
	 * Validates every path before removing duplicates or redundant descendants.
	 *
	 * @param list<string> $paths Absolute snapshot paths; a trailing slash is accepted.
	 * @throws InvalidArgument If the selection is empty, excessive or ambiguous.
	 */
	public function __construct( array $paths ) {
		if ( [] === $paths || count( $paths ) > self::MAX_PATHS ) {
			throw new InvalidArgument( 'Select between one and 256 snapshot paths.' );
		}
		$normalized = [];
		foreach ( $paths as $path ) {
			if ( ! str_starts_with( $path, '/' ) || strlen( $path ) > 4096 || str_contains( $path, "\0" ) ) {
				throw new InvalidArgument( 'A restore selection must be an absolute snapshot path of at most 4096 bytes.' );
			}
			if ( '/' !== $path ) {
				$path  = str_ends_with( $path, '/' ) ? substr( $path, 0, -1 ) : $path;
				$parts = explode( '/', substr( $path, 1 ) );
				if ( count( $parts ) > 256 || [] !== array_intersect( $parts, [ '', '.', '..' ] ) ) {
					throw new InvalidArgument( 'A restore selection contains an invalid or excessively deep snapshot path.' );
				}
			}
			$normalized[] = $path;
		}
		sort( $normalized, SORT_STRING );
		$kept = [];
		foreach ( $normalized as $path ) {
			foreach ( $kept as $previous ) {
				if ( '/' === $previous || $path === $previous || str_starts_with( $path, $previous . '/' ) ) {
					continue 2;
				}
			}
			$kept[] = $path;
		}
		$this->paths = $kept;
	}

	/**
	 * Whether an entry is selected or is an ancestor needed to reach selected entries.
	 *
	 * @param string $path Absolute entry path assembled from validated snapshot names.
	 * @return bool Whether the traversal should visit this entry.
	 */
	public function visits( string $path ): bool {
		foreach ( $this->paths as $selected ) {
			if ( '/' === $selected || $path === $selected || str_starts_with( $path, $selected . '/' ) || str_starts_with( $selected, $path . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
