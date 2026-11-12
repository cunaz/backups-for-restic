<?php
/**
 * The site manifest stored in a snapshot.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Snapshot;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Descriptive metadata only. Applying it still requires the restore planner's validation.
 */
final class Manifest {

	public const string FORMAT     = 'backups-for-restic-site/1';
	public const int MAX_BYTES     = 1048576;
	public const int MAX_BLOBS     = 128;
	public const string TAG_PREFIX = 'backups-for-restic-manifest:';

	/**
	 * Records the manifest's snapshot path without relying on the restoring site's layout.
	 *
	 * @param string $path Absolute path inside the snapshot.
	 * @return string Snapshot tag, independent of retention grouping.
	 */
	public static function tag( string $path ): string {
		if ( [] === Path::parts( $path ) || str_ends_with( $path, '/' ) ) {
			throw new InvalidArgument( 'The manifest locator must name a file.' );
		}
		return self::TAG_PREFIX . Bytes::base64_encode( $path );
	}

	/**
	 * Reads the locator on snapshots made by versions that record it.
	 *
	 * @param list<string> $tags Snapshot tags.
	 * @return string|null Path, or null for older or non-plugin snapshots.
	 * @throws FormatException If the locator is ambiguous or malformed.
	 */
	public static function path( array $tags ): ?string {
		$path = null;
		foreach ( $tags as $tag ) {
			if ( ! str_starts_with( $tag, self::TAG_PREFIX ) ) {
				continue;
			}
			if ( null !== $path ) {
				throw new FormatException( 'The snapshot has more than one site manifest locator.' );
			}
			if ( strlen( $tag ) > strlen( self::TAG_PREFIX ) + 5464 ) {
				throw new FormatException( 'The snapshot site manifest locator is too long.' );
			}
			$path = Bytes::base64_decode( substr( $tag, strlen( self::TAG_PREFIX ) ) );
			try {
				if ( [] === Path::parts( $path ) || str_ends_with( $path, '/' ) ) {
					throw new InvalidArgument( 'The manifest locator must name a file.' );
				}
			} catch ( InvalidArgument $error ) {
				FormatException::raise( 'The snapshot site manifest locator is invalid.', [], $error );
			}
		}
		return $path;
	}

	/**
	 * Validates the schema before exposing metadata to restore planning or presentation.
	 *
	 * @param string $json Complete, verified file content.
	 * @return array<string, mixed> Manifest fields. Unknown fields are retained as data.
	 * @throws FormatException If required fields or the format are invalid.
	 */
	public static function decode( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new FormatException( 'The site manifest exceeds its one MiB limit.' );
		}
		$data = GoJson::decode_object( $json );
		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			throw new FormatException( 'The site manifest has an unsupported format.' );
		}
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new FormatException( 'The site manifest has an invalid field name.' );
			}
		}
		foreach ( [ 'created', 'home_url', 'abspath', 'content', 'uploads', 'prefix' ] as $key ) {
			if ( ! is_string( $data[ $key ] ?? null ) || str_contains( $data[ $key ], "\0" ) ) {
				FormatException::raise( 'A site manifest field is invalid.', [ 'field' => $key ] );
			}
		}
		Rfc3339::parse( $data['created'] );
		foreach ( [ 'roots', 'excludes' ] as $key ) {
			$value = $data[ $key ] ?? null;
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				FormatException::raise( 'A site manifest list is invalid.', [ 'field' => $key ] );
			}
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) || str_contains( $item, "\0" ) ) {
					FormatException::raise( 'A site manifest list is invalid.', [ 'field' => $key ] );
				}
			}
		}
		$versions = $data['versions'] ?? null;
		if ( ! is_array( $versions ) ) {
			throw new FormatException( 'The site manifest lacks version information.' );
		}
		foreach ( [ 'plugin', 'wordpress', 'php' ] as $key ) {
			if ( ! is_string( $versions[ $key ] ?? null ) ) {
				throw new FormatException( 'The site manifest version information is invalid.' );
			}
		}
		if ( ! is_bool( $data['database'] ?? null ) || ! is_array( $data['repository'] ?? null ) || ! in_array( $data['repository']['format'] ?? null, [ 1, 2 ], true ) ) {
			throw new FormatException( 'The site manifest database or repository information is invalid.' );
		}
		/**
		 * Field names were checked above.
		 *
		 * @var array<string, mixed> $data
		 */
		return $data;
	}
}
