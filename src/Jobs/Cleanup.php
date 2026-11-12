<?php
/**
 * Independent cleanup actions.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A failed cleanup action must not skip the other actions needed to end a job.
 */
final class Cleanup {

	/**
	 * Runs every action in order, then reports the first failure to the job runner.
	 *
	 * @param \Closure(): mixed ...$actions Independent cleanup actions.
	 * @throws \Throwable The first failure, after all actions have been attempted.
	 */
	public static function run( \Closure ...$actions ): void {
		$failure = null;
		foreach ( $actions as $action ) {
			try {
				$action();
			} catch ( \Throwable $exception ) {
				$failure ??= $exception;
			}
		}
		if ( null !== $failure ) {
			throw $failure;
		}
	}
}
