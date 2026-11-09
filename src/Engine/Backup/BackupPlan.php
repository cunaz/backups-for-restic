<?php
/**
 * What a backup covers.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Backup;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Paths, exclusions, tags and virtual files of a backup.
 *
 * The plan is rebuilt from the settings in every request, so it may hold closures; only the
 * normalized paths end up in the checkpoint.
 */
final class BackupPlan {

	/**
	 * Distinct, non-nested absolute paths.
	 *
	 * @var list<string>
	 */
	public readonly array $paths;

	/**
	 * Creates a plan.
	 *
	 * @param list<string>                               $paths           Absolute paths to back up.
	 * @param list<string>                               $tags            Snapshot tags.
	 * @param (\Closure(string, bool): bool)|null        $exclude         Returns true for paths to skip (path, is directory).
	 * @param (\Closure(string): list<VirtualFile>)|null $virtual_entries Virtual files by absolute directory path.
	 * @throws InvalidArgument If a path is not absolute.
	 */
	public function __construct(
		array $paths,
		public readonly array $tags = [],
		private readonly ?\Closure $exclude = null,
		private readonly ?\Closure $virtual_entries = null
	) {
		$this->paths = self::normalize( $paths );
	}

	/**
	 * Whether a path is excluded.
	 *
	 * @param string $path   Absolute path.
	 * @param bool   $is_dir Whether it is a directory.
	 * @return bool True to skip.
	 */
	public function excluded( string $path, bool $is_dir ): bool {
		return null !== $this->exclude && ( $this->exclude )( $path, $is_dir );
	}

	/**
	 * Returns virtual files for a directory.
	 *
	 * @param string $directory Absolute directory path.
	 * @return array<string, VirtualFile> Files by name.
	 */
	public function virtual_entries( string $directory ): array {
		$files = [];
		foreach ( null === $this->virtual_entries ? [] : ( $this->virtual_entries )( $directory ) as $file ) {
			$files[ $file->name ] = $file;
		}
		return $files;
	}

	/**
	 * Normalizes paths: absolute, without "." and "..", deduplicated, nested paths removed, sorted.
	 *
	 * @param list<string> $paths Paths.
	 * @return list<string> Normalized paths.
	 * @throws InvalidArgument If a path is relative or empty.
	 */
	public static function normalize( array $paths ): array {
		$clean = [];
		foreach ( $paths as $path ) {
			if ( ! str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) ) {
				throw new InvalidArgument( 'Backup paths must be absolute.' );
			}
			$parts = [];
			foreach ( explode( '/', $path ) as $part ) {
				if ( '' === $part || '.' === $part ) {
					continue;
				}
				if ( '..' === $part ) {
					array_pop( $parts );
					continue;
				}
				$parts[] = $part;
			}
			if ( [] === $parts ) {
				throw new InvalidArgument( 'The file system root cannot be backed up.' );
			}
			$clean[] = '/' . implode( '/', $parts );
		}

		sort( $clean, SORT_STRING );
		$result = [];
		foreach ( array_unique( $clean ) as $path ) {
			$last = end( $result );
			if ( false === $last || ! str_starts_with( $path . '/', $last . '/' ) ) {
				$result[] = $path;
			}
		}
		return $result;
	}
}
