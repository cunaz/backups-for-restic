<?php
/**
 * A checked database manifest and its canonical private staging directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Engine\Restore\Files;
use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/** Rehearsal and final import open the exact same private snapshot files. */
final readonly class StagedDatabase {

	/**
	 * Identifies the source verified for this request.
	 *
	 * @param string   $directory Canonical dump directory.
	 * @param Manifest $manifest Validated table inventory.
	 */
	private function __construct( public string $directory, public Manifest $manifest ) {
	}

	/**
	 * Resolves a snapshot directory without following symlinks within staging.
	 *
	 * @param string $files Canonical verified staging files/ directory.
	 * @param string $path Absolute snapshot path of the database directory.
	 * @param string $prefix Current WordPress table prefix.
	 * @return self Checked source.
	 * @throws FormatException If the directory or manifest cannot be safely opened.
	 */
	public static function open( string $files, string $path, string $prefix ): self {
		foreach ( Path::parts( $path ) as $part ) {
			Names::validate( $part );
		}
		$directory = $files . $path;
		clearstatcache( true );
		if ( '' === $files || realpath( $directory ) !== $directory || ! is_dir( $directory ) ) {
			FormatException::raise( 'Select the complete database directory of the snapshot to preview its tables.', [ 'code' => 'database_preview_missing' ] );
		}
		$handle = Files::open( $directory . '/manifest.json' );
		try {
			$json = stream_get_contents( $handle, Manifest::MAX_BYTES + 1 );
			if ( false === $json ) {
				throw new FormatException( 'The staged database manifest could not be read.' );
			}
			return new self( $directory, Manifest::parse( $json, $prefix ) );
		} finally {
			fclose( $handle );
		}
	}
}
