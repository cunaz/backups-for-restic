<?php
/**
 * Releases a run's lock without resuming its work.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * The positive integer pid is a stable ownership field of every run checkpoint, independent
 * of its version. Cleanup needs that number, not the run's plan, index or working files.
 * The recorded lock ID is deliberately ignored: only authenticated locks belonging to the
 * current host and that run number may be removed (ADR 0032).
 */
final class CheckpointLock {

	/**
	 * Releases every repository lock belonging to a checkpoint's run.
	 *
	 * @param Repository $repository Open repository.
	 * @param string     $file       Private checkpoint file.
	 * @param Identity   $identity   The site's lock identity.
	 * @param Clock      $clock      Time source.
	 * @throws StorageFailed If the checkpoint does not identify its owner unambiguously.
	 */
	public static function release( Repository $repository, string $file, Identity $identity, Clock $clock ): void {
		$json = file_get_contents( $file );
		if ( false === $json ) {
			StorageFailed::raise( 'The run checkpoint could not be read for cleanup.', [ 'file' => basename( $file ) ] );
		}
		try {
			$state = GoJson::decode_object( $json );
		} catch ( FormatException ) {
			StorageFailed::raise( 'The run checkpoint does not identify its lock owner.', [ 'file' => basename( $file ) ] );
		}
		$pid = $state['pid'] ?? null;
		if ( ! is_int( $pid ) || $pid < 1 || $pid > 2147483647 ) {
			StorageFailed::raise( 'The run checkpoint does not identify its lock owner.', [ 'file' => basename( $file ) ] );
		}
		( new Locking( $repository, $identity, $clock, $pid ) )->release( null );
	}
}
