<?php
/**
 * Port to the site's database.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only queries for the dump. WordPress\WpdbConnection adapts $wpdb; tests use mysqli.
 * Values come back as strings or null, exactly as the server sends them.
 */
interface Connection {

	/**
	 * Runs a query and returns all rows.
	 *
	 * @param string $sql Query; identifiers are quoted by the caller.
	 * @return list<array<string, string|null>> Rows keyed by column name.
	 * @throws DatabaseFailed If the query fails.
	 */
	public function rows( string $sql ): array;
}
