<?php
/**
 * Per-job checkpoints for live file verification.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/** Checks small files directly and checkpoints a large verification under its private owner. */
final class FileVerification {

	/**
	 * Deadline of the current operation.
	 *
	 * @var float
	 */
	public float $deadline = PHP_FLOAT_MAX;

	/**
	 * Creates a verifier. Different previews, mutations and undo runs have their own scope.
	 *
	 * @param string $directory Private per-entry directory.
	 * @param string $scope Current job ID or an entry's legacy scope.
	 */
	public function __construct( private readonly string $directory, private readonly string $scope ) {
	}

	/**
	 * Reads a reviewed regular file. A completed comparison is reused in this job only
	 * while the exact inode and full metadata remain stable.
	 *
	 * @param string $path Canonical regular file.
	 * @param string $expected Expected digest.
	 * @param int    $links Expected hard links.
	 * @return bool Match.
	 * @throws FileWorkPending If verification needs another tick.
	 * @throws StorageFailed If a checkpoint is invalid.
	 */
	public function matches( string $path, string $expected, int $links ): bool {
		clearstatcache( true, $path );
		$stat = is_file( $path ) && ! is_link( $path ) && realpath( $path ) === $path ? lstat( $path ) : false;
		if ( false === $stat || $stat['nlink'] !== $links ) {
			return false;
		}
		if ( ! ChunkDigest::valid( $expected ) ) {
			return hash_file( 'sha256', $path ) === $expected;
		}
		$file   = $this->directory . '/verify-' . hash( 'sha256', $this->scope . "\0" . $path . "\0" . $expected . "\0" . $links ) . '.json';
		$cursor = [];
		$large  = $stat['size'] > ChunkDigest::BLOCK;
		if ( $large && is_file( $file ) ) {
			$text   = ! is_link( $file ) ? file_get_contents( $file, false, null, 0, 4096 ) : false;
			$stored = is_string( $text ) ? json_decode( $text, true ) : null;
			if ( ! is_array( $stored ) || ! is_string( $stored['identity'] ?? null ) || ! is_int( $stored['offset'] ?? null ) || ! is_string( $stored['chain'] ?? null ) ) {
				throw new StorageFailed( 'A private incremental file verification checkpoint is invalid.' );
			}
			if ( ApplyManifest::identity( $path ) === $stored['identity'] ) {
				if ( true === ( $stored['stable'] ?? null ) && $stored['offset'] === $stat['size'] && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $stored['chain'] ) ) {
					return hash_equals( $expected, 'c1:' . $stored['chain'] );
				}
				$cursor = [
					'identity' => $stored['identity'],
					'offset'   => $stored['offset'],
					'chain'    => $stored['chain'],
					'stable'   => true === ( $stored['stable'] ?? null ),
				];
			}
		}
		$digest = ChunkDigest::advance( $path, $this->deadline, $cursor, $links );
		if ( $large && ( null === $digest || true === ( $cursor['stable'] ?? null ) ) ) {
			Files::write_atomic( $file, json_encode( $cursor, JSON_THROW_ON_ERROR ) );
		}
		if ( null === $digest ) {
			throw new FileWorkPending();
		}
		return hash_equals( $expected, $digest );
	}
}
