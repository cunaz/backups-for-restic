<?php
/**
 * Names and ownership checks for restore's private tables.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * The random token must be persisted by the job before its first tick. No name from the
 * repository can select an object for cleanup or mutation. Ownership survives killed DDL.
 */
final readonly class OwnedTables {

	/**
	 * Generated namespace, always outside the site's table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Expected table comment, binding token, schema version and manifest.
	 *
	 * @var string
	 */
	public string $owner;

	/**
	 * Names one restore's objects.
	 *
	 * @param Connection $connection Non-reconnecting database session.
	 * @param Manifest   $manifest Validated dump inventory.
	 * @param string     $token Persisted random 128-bit lower-case hexadecimal token.
	 * @throws FormatException If the caller's ownership token is not valid.
	 */
	public function __construct( private Connection $connection, Manifest $manifest, string $token ) {
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $token ) ) {
			throw new FormatException( 'The database restore ownership token is invalid.' );
		}
		$this->prefix = ( str_starts_with( $manifest->prefix, '_' ) ? 'bfr_r_' : '_bfr_r_' ) . $token;
		$this->owner  = 'backups-for-restic-restore/1:' . $token . ':' . $manifest->fingerprint;
	}

	/**
	 * Returns the private progress table name.
	 *
	 * @return string Generated name, outside the site's prefix.
	 */
	public function progress(): string {
		return $this->prefix . '_p';
	}

	/**
	 * Returns a short deterministic name for one shadow table.
	 *
	 * @param int $number Table ordinal.
	 * @return string Generated name, at most 45 characters.
	 * @throws FormatException If the ordinal is outside the inventory limit.
	 */
	public function shadow( int $number ): string {
		if ( $number < 0 || $number >= Manifest::MAX_TABLES ) {
			throw new FormatException( 'The database shadow ordinal is invalid.' );
		}
		return $this->prefix . '_t' . $number;
	}

	/**
	 * Refuses foreign objects and unexpected temporary-table overrides before any mutation.
	 *
	 * @param string $name One of this object's generated names.
	 * @return bool Whether an owned InnoDB table already exists.
	 * @throws DatabaseFailed If an existing object is not this import's private table.
	 */
	public function exists( string $name ): bool {
		if ( ! str_starts_with( $name, $this->prefix . '_' ) ) {
			throw new DatabaseFailed( 'A database restore ownership check names an unexpected object.' );
		}
		$rows = $this->connection->rows( 'SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . Session::literal( $name ) );
		if ( [] === $rows ) {
			return false;
		}
		if ( 1 !== count( $rows ) || ( $rows[0]['TABLE_TYPE'] ?? null ) !== 'BASE TABLE' || strtoupper( $rows[0]['ENGINE'] ?? '' ) !== 'INNODB' || ( $rows[0]['TABLE_COMMENT'] ?? null ) !== $this->owner ) {
			throw new DatabaseFailed( 'A database restore name is occupied by a table that this import does not own.' );
		}
		$create = $this->connection->rows( 'SHOW CREATE TABLE ' . SqlFormatter::identifier( $name ) );
		$sql    = $create[0]['Create Table'] ?? '';
		if ( ! str_starts_with( $sql, 'CREATE TABLE ' . SqlFormatter::identifier( $name ) . ' (' ) || ! str_contains( $sql, "COMMENT='" . $this->owner . "'" ) ) {
			throw new DatabaseFailed( 'A database restore table is hidden by another session object.' );
		}
		return true;
	}
}
