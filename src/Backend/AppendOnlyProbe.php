<?php
/**
 * Side-effect-free append-only detection.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\Unauthorized;

defined( 'ABSPATH' ) || exit;

/**
 * Detects append-only mode by deleting a random snapshot name that does not exist (ADR 0010).
 *
 * The request changes nothing on any server. rest-server checks append-only before it touches
 * the file system and answers 403; otherwise it ignores the missing file and answers 200.
 */
final class AppendOnlyProbe {

	/**
	 * Probes the server.
	 *
	 * @param Backend      $backend Repository backend.
	 * @param RandomSource $random  Source of the random name.
	 * @return AppendOnlyStatus Result.
	 * @throws Unauthorized If the credentials are wrong.
	 */
	public static function detect( Backend $backend, RandomSource $random ): AppendOnlyStatus {
		try {
			$backend->delete( ObjectType::Snapshots, bin2hex( $random->bytes( 32 ) ) );
			return AppendOnlyStatus::DeletionsAllowed;
		} catch ( AppendOnlyRefused ) {
			return AppendOnlyStatus::AppendOnly;
		} catch ( NotFound ) {
			return AppendOnlyStatus::DeletionsAllowed;
		} catch ( Unauthorized $exception ) {
			throw $exception;
		} catch ( BackendException ) {
			return AppendOnlyStatus::Unknown;
		}
	}
}
