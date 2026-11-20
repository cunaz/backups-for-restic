<?php
/**
 * Database port for atomic restore batches.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * A single server session, with no automatic reconnect, retry or query rewriting.
 * Losing the session must fail: retrying a statement on a new connection would lose the
 * transaction that commits imported rows together with their progress. Errors must not
 * contain SQL, database values or the server's unredacted message.
 */
interface Connection extends \BackupsForRestic\Database\Dump\Connection {

	/**
	 * Executes exactly one statement once, refusing warnings as well as errors.
	 *
	 * @param string $sql Importer's checked statement.
	 * @return int Affected rows, for checking the complete batch and its progress write.
	 * @throws DatabaseFailed If the server refuses, warns, or loses its session.
	 */
	public function execute( string $sql ): int;
}
