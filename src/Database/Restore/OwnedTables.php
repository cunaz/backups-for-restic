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
use BackupsForRestic\Exception\JobConflict;

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
	 * @param string     $site_prefix Table prefix from the validated manifest.
	 * @param string     $fingerprint SHA-256 of the validated manifest.
	 * @param string     $token Persisted random 128-bit lower-case hexadecimal token.
	 * @throws FormatException If the caller's ownership token is not valid.
	 */
	public function __construct( private Connection $connection, string $site_prefix, string $fingerprint, string $token ) {
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $token ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $fingerprint ) ) {
			throw new FormatException( 'The database restore ownership token is invalid.' );
		}
		$this->prefix = ( str_starts_with( $site_prefix, '_' ) ? 'bfr_r_' : '_bfr_r_' ) . $token;
		$this->owner  = 'backups-for-restic-restore/1:' . $token . ':' . $fingerprint;
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
	 * @param bool   $cleanup Leave a foreign collision untouched during cleanup.
	 * @return bool Whether an owned InnoDB table already exists.
	 * @throws DatabaseFailed If an existing object is not this import's private table.
	 */
	public function exists( string $name, bool $cleanup = false ): bool {
		if ( ! str_starts_with( $name, $this->prefix . '_' ) ) {
			throw new DatabaseFailed( 'A database restore ownership check names an unexpected object.' );
		}
		$rows = $this->connection->rows( 'SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . Session::literal( $name ) );
		if ( [] === $rows ) {
			return false;
		}
		if ( 1 !== count( $rows ) || ( $rows[0]['TABLE_TYPE'] ?? null ) !== 'BASE TABLE' || strtoupper( $rows[0]['ENGINE'] ?? '' ) !== 'INNODB' || ( $rows[0]['TABLE_COMMENT'] ?? null ) !== $this->owner ) {
			if ( $cleanup ) {
				return false;
			}
			throw new DatabaseFailed( 'A database restore name is occupied by a table that this import does not own.' );
		}
		$create = $this->connection->rows( 'SHOW CREATE TABLE ' . SqlFormatter::identifier( $name ) );
		$sql    = $create[0]['Create Table'] ?? '';
		if ( ! str_starts_with( $sql, 'CREATE TABLE ' . SqlFormatter::identifier( $name ) . ' (' ) || ! str_contains( $sql, "COMMENT='" . $this->owner . "'" ) ) {
			throw new DatabaseFailed( 'A database restore table is hidden by another session object.' );
		}
		return true;
	}

	/**
	 * Checks a bounded sequence of generated names, then drops the progress ledger last.
	 * A killed request may repeat drops from its previous cursor. No listing or repository
	 * filename selects a destination.
	 *
	 * @param int   $count Table count of the original, validated inventory.
	 * @param int   $cursor First ordinal to inspect. The ledger's ordinal is $count.
	 * @param float $deadline Wall-clock deadline.
	 * @return int Next ordinal. Cleanup is complete when it exceeds $count.
	 * @throws FormatException If the cursor or table count is invalid.
	 */
	public function clean( int $count, int $cursor, float $deadline ): int {
		if ( $count < 0 || $count > Manifest::MAX_TABLES || $cursor < 0 || $cursor > $count + 1 ) {
			throw new FormatException( 'The database cleanup cursor is invalid.' );
		}
		return $this->locked(
			null,
			function () use ( $count, $cursor, $deadline ): int {
				$end = min( $count + 1, $cursor + 16 );
				while ( $cursor < $end ) {
					$name = $cursor === $count ? $this->progress() : $this->shadow( $cursor );
					if ( $this->exists( $name, true ) ) {
						$this->connection->execute( 'DROP TABLE ' . SqlFormatter::identifier( $name ) );
					}
					++$cursor;
					if ( microtime( true ) >= $deadline ) {
						break;
					}
				}
				return $cursor;
			}
		);
	}

	/**
	 * Serializes import and cleanup on one normalized, non-reconnecting SQL session.
	 *
	 * @template T
	 * @param Manifest|null $manifest Validated inventory, or null when only dropping tables.
	 * @param \Closure(): T $work Protected operations.
	 * @return T Result.
	 * @throws \Throwable If the work or session cleanup fails.
	 */
	public function locked( ?Manifest $manifest, \Closure $work ): mixed {
		$session = new Session( $this->connection, $manifest );
		$lock    = Session::literal( substr( 'bfr-restore-' . hash( 'sha256', $this->progress() ), 0, 64 ) );
		$locked  = false;
		$failed  = false;
		try {
			$acquired = $this->connection->rows( 'SELECT GET_LOCK(' . $lock . ', 0) AS acquired' );
			if ( ( $acquired[0]['acquired'] ?? null ) !== '1' ) {
				throw new JobConflict( 'Another request is working on this staged database.' );
			}
			$locked = true;
			return $work();
		} catch ( \Throwable $failure ) {
			$failed = true;
			throw $failure;
		} finally {
			$error = null;
			try {
				// Roll back before releasing the lock, including after a failed COMMIT.
				$session->close();
			} catch ( \Throwable $failure ) {
				$error = $failure;
			}
			if ( $locked ) {
				try {
					$released = $this->connection->rows( 'SELECT RELEASE_LOCK(' . $lock . ') AS released' );
					if ( ( $released[0]['released'] ?? null ) !== '1' ) {
						throw new DatabaseFailed( 'The database import lock could not be released.' );
					}
				} catch ( \Throwable $failure ) {
					$error ??= $failure;
				}
			}
			if ( ! $failed && null !== $error ) {
				throw $error;
			}
		}
	}
}
