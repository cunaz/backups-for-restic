<?php
/**
 * Direct grants that establish complete visibility of a site's database metadata.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/** An empty set means that this account's database-wide grants could not be established. */
final class Privileges {

	/**
	 * Looks only at this account's grants and at the current database. A table-level
	 * grant cannot establish complete trigger visibility across a site's table set.
	 * Per-schema restrictions on global grants are honored by leaving those grants out.
	 *
	 * @param Connection $connection Current SQL session.
	 * @return list<string> Verified database-wide grants.
	 */
	public static function database( Connection $connection ): array {
		try {
			$account   = $connection->rows( 'SELECT CURRENT_USER() AS account' )[0]['account'] ?? '';
			$separator = strrpos( $account, '@' );
			if ( false === $separator ) {
				return [];
			}
			$user   = str_replace( "'", "''", substr( $account, 0, $separator ) );
			$host   = str_replace( "'", "''", substr( $account, $separator + 1 ) );
			$filter = 'GRANTEE=0x' . bin2hex( "'$user'@'$host'" );
			$query  = 'SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=DATABASE() AND ' . $filter;
			$mode   = $connection->rows( "SHOW VARIABLES LIKE 'partial_revokes'" );
			if ( [] === $mode || 'OFF' === ( $mode[0]['Value'] ?? '' ) ) {
				$query .= ' UNION SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE ' . $filter;
			}
			return array_values( array_filter( array_column( $connection->rows( $query ), 'PRIVILEGE_TYPE' ), 'is_string' ) );
		} catch ( DatabaseFailed ) {
			return [];
		}
	}
}
