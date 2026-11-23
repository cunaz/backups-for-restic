<?php
/**
 * The dump's reads on the site's own connection.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Runs each query of the dump with utf8mb4 as the character set of the connection and of
 * its results, the time zone +00:00, the largest sort length, no row limit and a short wait
 * for locks (TableSource::LOCK_WAIT_SECONDS), on a connection that
 * WordPress and other plugins use in the same request (ADR 0021). Rows and definitions are
 * read the same whatever DB_CHARSET or the session's time zone is.
 *
 * One statement saves the site's settings in user variables and makes the dump's; another
 * restores them right after the query, also when it failed, so that no other code ever runs
 * under the dump's settings. A connection that was replaced in between has no saved values,
 * so restoring them fails instead of going unnoticed.
 *
 * Two read-backs show that the statements reach the session that has the dump's settings.
 * Before the first query, a value is set and read back by statements without a table: a
 * database drop-in that sends consecutive statements to different connections fails there.
 * Before the first read of each table, prove() reads that value back, with the dump's
 * settings, through a statement that names the table: a drop-in that sends a table's
 * statements to another connection than the others, for example to a replica, fails there,
 * before its definition or rows are read. Both fail with database_dump_session. Not shown is
 * a drop-in that sends one statement of a table elsewhere than the one before it, or the
 * definitions of views, which are read without such a statement.
 */
final class SharedConnection implements Connection {

	/** Session variables the dump sets, and the user variables that keep the site's values. */
	private const array SAVED = [
		'character_set_client'  => '@bfr_dump_client',
		'character_set_results' => '@bfr_dump_results',
		'collation_connection'  => '@bfr_dump_collation',
		'time_zone'             => '@bfr_dump_time_zone',
		'max_sort_length'       => '@bfr_dump_sort_length',
		'sql_select_limit'      => '@bfr_dump_select_limit',
		'lock_wait_timeout'     => '@bfr_dump_lock_wait',
	];

	/**
	 * Whether the connection was seen to keep a session value between two statements.
	 *
	 * @var bool
	 */
	private bool $probed = false;

	/**
	 * Tables whose statements were shown to reach the session with the dump's settings.
	 *
	 * @var array<string, true>
	 */
	private array $proven = [];

	/**
	 * Wraps the site's connection.
	 *
	 * @param Connection  $connection Connection that other code shares.
	 * @param string|null $time_zone  Time zone for the dump's reads, or null to keep the session's; see DatabaseDump.
	 */
	public function __construct( private readonly Connection $connection, private readonly ?string $time_zone = '+00:00' ) {
	}

	/**
	 * Runs a query under the dump's settings and restores the site's afterwards.
	 *
	 * @param string $sql Query.
	 * @return list<array<string, string|null>> Rows.
	 * @throws DatabaseFailed If the query fails or the settings cannot be made or restored.
	 */
	public function rows( string $sql ): array {
		if ( ! $this->probed ) {
			$this->probe();
		}
		$this->connection->rows( $this->dump_settings() );
		try {
			$rows = $this->connection->rows( $sql );
		} catch ( \Throwable $error ) {
			try {
				$this->connection->rows( self::site_settings() );
			} catch ( \Throwable ) {
				// The query's own error is the one to report; the request ends with it.
				throw $error;
			}
			throw $error;
		}
		$this->connection->rows( self::site_settings() );
		return $rows;
	}

	/**
	 * Saves the site's values and makes the dump's in one statement.
	 *
	 * @return string SET statement.
	 */
	private function dump_settings(): string {
		$saves = [];
		foreach ( self::SAVED as $variable => $user ) {
			$saves[] = $user . ' = @@SESSION.' . $variable;
		}
		$zone = null === $this->time_zone ? '' : ', time_zone = ' . self::literal( $this->time_zone );
		return 'SET ' . implode( ', ', $saves ) . ', SESSION character_set_client = utf8mb4, character_set_results = utf8mb4, character_set_connection = utf8mb4' . $zone . ', max_sort_length = 8388608, sql_select_limit = 18446744073709551615, lock_wait_timeout = ' . TableSource::LOCK_WAIT_SECONDS;
	}

	/**
	 * Restores the saved values and forgets them. collation_connection also restores the
	 * character set of the connection.
	 *
	 * @return string SET statement.
	 */
	private static function site_settings(): string {
		$restores = [];
		$clears   = [];
		foreach ( self::SAVED as $variable => $user ) {
			$restores[] = $variable . ' = ' . $user;
			$clears[]   = $user . ' = NULL';
		}
		return 'SET SESSION ' . implode( ', ', $restores ) . ', ' . implode( ', ', $clears );
	}

	/**
	 * Checks that a value set by one statement is seen by the next.
	 *
	 * @throws DatabaseFailed If the connection does not keep session values.
	 */
	private function probe(): void {
		$token = self::literal( 'bfr-' . getmypid() . '-' . hrtime( true ) );
		$this->connection->rows( 'SET @bfr_dump_probe = ' . $token );
		// An explicit limit, so that no sql_select_limit of the session applies.
		$seen = $this->connection->rows( 'SELECT HEX(@bfr_dump_probe) AS probe LIMIT 1' )[0]['probe'] ?? null;
		$this->connection->rows( 'SET @bfr_dump_probe = NULL' );
		if ( '0x' . strtolower( (string) $seen ) !== $token ) {
			DatabaseFailed::raise( 'The database connection does not keep the session settings of the dump between statements.', [ 'code' => 'database_dump_session' ] );
		}
		$this->probed = true;
	}

	/**
	 * Shows, before the first read of a table, that a statement that names the table reaches
	 * the session with the dump's settings: within those settings it reads back a value that a
	 * statement without a table set, and the settings themselves.
	 *
	 * @param string $table Table name.
	 * @throws DatabaseFailed If the statement reaches another session or one without the dump's settings.
	 */
	public function prove( string $table ): void {
		if ( isset( $this->proven[ $table ] ) ) {
			return;
		}
		if ( ! $this->probed ) {
			$this->probe();
		}
		$token = self::literal( 'bfr-' . getmypid() . '-' . hrtime( true ) );
		$this->connection->rows( 'SET @bfr_dump_probe = ' . $token );
		try {
			// One row whatever the table holds, and none of its rows read.
			$seen = $this->rows( 'SELECT HEX(@bfr_dump_probe) AS probe, @@SESSION.character_set_results AS results, @@SESSION.time_zone AS zone, @@SESSION.max_sort_length AS sort_length, @@SESSION.sql_select_limit AS select_limit, @@SESSION.lock_wait_timeout AS lock_wait FROM ' . SqlFormatter::identifier( $table ) . ' RIGHT JOIN (SELECT 1) AS bfr_dump_one ON FALSE LIMIT 1' )[0] ?? [];
		} finally {
			$this->connection->rows( 'SET @bfr_dump_probe = NULL' );
		}
		$expected = [
			'probe'        => $token,
			'results'      => 'utf8mb4',
			'zone'         => $this->time_zone ?? ( $seen['zone'] ?? null ),
			'sort_length'  => '8388608',
			'select_limit' => '18446744073709551615',
			'lock_wait'    => (string) TableSource::LOCK_WAIT_SECONDS,
		];
		$actual   = [ 'probe' => '0x' . strtolower( $seen['probe'] ?? '' ) ] + array_intersect_key( $seen, $expected );
		ksort( $expected );
		ksort( $actual );
		if ( $expected !== $actual ) {
			DatabaseFailed::raise(
				'The database connection reads a table on a session without the session settings of the dump.',
				[
					'table' => $table,
					'code'  => 'database_dump_session',
				]
			);
		}
		$this->proven[ $table ] = true;
	}

	/**
	 * Quotes a setting independently of the session's escaping mode.
	 *
	 * @param string $value Value.
	 * @return string Hex literal.
	 */
	private static function literal( string $value ): string {
		return '0x' . bin2hex( $value );
	}
}
