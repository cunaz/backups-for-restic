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
			$user     = str_replace( "'", "''", substr( $account, 0, $separator ) );
			$host     = str_replace( "'", "''", substr( $account, $separator + 1 ) );
			$filter   = 'GRANTEE=0x' . bin2hex( "'$user'@'$host'" );
			$mode     = $connection->rows( "SHOW VARIABLES LIKE 'partial_revokes'" );
			$patterns = [] === $mode || 'OFF' === ( $mode[0]['Value'] ?? '' );
			$schema   = $patterns
				? 'CAST(DATABASE() AS BINARY) LIKE CAST(TABLE_SCHEMA AS BINARY) ESCAPE 0x5c'
				: 'CAST(DATABASE() AS BINARY)=CAST(TABLE_SCHEMA AS BINARY)';
			$rows     = $connection->rows( 'SELECT TABLE_SCHEMA,PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE ' . $schema . ' AND ' . $filter );
			$sets     = [];
			foreach ( $rows as $row ) {
				if ( is_string( $row['TABLE_SCHEMA'] ?? null ) && is_string( $row['PRIVILEGE_TYPE'] ?? null ) ) {
					$sets[ $row['TABLE_SCHEMA'] ][] = $row['PRIVILEGE_TYPE'];
				}
			}
			// Multiple schema patterns can match, but MySQL honors only one of them.
			// Only a privilege shared by every possible match is safe to rely on.
			$sets   = array_values( $sets );
			$grants = array_shift( $sets ) ?? [];
			foreach ( $sets as $set ) {
				$grants = array_intersect( $grants, $set );
			}
			if ( $patterns ) {
				$global = $connection->rows( 'SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE ' . $filter );
				$grants = array_merge( $grants, array_filter( array_column( $global, 'PRIVILEGE_TYPE' ), 'is_string' ) );
			}
			return array_values( array_unique( $grants ) );
		} catch ( DatabaseFailed ) {
			return [];
		}
	}
}
