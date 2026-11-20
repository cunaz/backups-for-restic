<?php
/**
 * Preserves the running WordPress site's identity and the confirmed administrator.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Copies table rows inside SQL. No option, session token, password hash or serialized
 * value enters PHP or a job document. The caller holds the write barrier throughout
 * validation, the copy and RENAME, and always copies into its verified private targets.
 */
final readonly class WordPressState {

	public const array TABLES = [ 'options', 'users', 'usermeta' ];

	/** Values from the running installation that affect reachability, credentials or jobs. */
	public const array OPTIONS = [ 'home', 'siteurl', 'active_plugins', 'cron', 'db_version', '_transient_doing_cron', '_transient_timeout_doing_cron' ];

	private const array COLUMNS = [
		'options'  => [ 'option_id', 'option_name', 'option_value', 'autoload' ],
		'users'    => [ 'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ],
		'usermeta' => [ 'umeta_id', 'user_id', 'meta_key', 'meta_value' ],
	];

	/**
	 * Creates a state preserver on the exchange connection.
	 *
	 * @param Connection $connection Non-reconnecting SQL session.
	 */
	public function __construct( private Connection $connection ) {
	}

	/**
	 * Binds the approving credential and its database-backed authority without reading any
	 * password hash or serialized permission value into PHP. Recheck this under the write
	 * barrier in case another request changed the user after WordPress checked user_can().
	 *
	 * @param string $prefix Validated target prefix.
	 * @param int    $admin Confirmed administrator.
	 * @return string SHA-256 of the SQL-generated row fingerprints.
	 * @throws DatabaseFailed If the administrative identity cannot be verified.
	 */
	public function authority( string $prefix, int $admin ): string {
		if ( $admin < 1 || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $prefix ) ) {
			throw new DatabaseFailed( 'The current database administrator cannot be identified.' );
		}
		$users   = SqlFormatter::identifier( $prefix . 'users' );
		$options = SqlFormatter::identifier( $prefix . 'options' );
		$meta    = SqlFormatter::identifier( $prefix . 'usermeta' );
		$user    = $this->connection->rows( 'SELECT SHA2(JSON_ARRAY(user_login,user_pass,user_email),256) AS f FROM ' . $users . ' WHERE ID=' . $admin )[0]['f'] ?? null;
		$roles   = $this->connection->rows( 'SELECT SHA2(option_value,256) AS f FROM ' . $options . ' WHERE option_name=' . Session::literal( $prefix . 'user_roles' ) )[0]['f'] ?? null;
		$caps    = $this->connection->rows( 'SELECT SHA2(meta_value,256) AS f FROM ' . $meta . ' WHERE user_id=' . $admin . ' AND meta_key=' . Session::literal( $prefix . 'capabilities' ) . ' ORDER BY umeta_id LIMIT 2' );
		if ( ! is_string( $user ) || ! is_string( $roles ) || 1 !== count( $caps ) || ! is_string( $caps[0]['f'] ?? null ) ) {
			DatabaseFailed::raise( 'The approving administrator has an ambiguous local identity or capabilities.', [ 'code' => 'database_admin_collision' ] );
		}
		return hash( 'sha256', $user . $roles . $caps[0]['f'] );
	}

	/**
	 * Requires ordinary WordPress layouts in both generations and checks the administrator
	 * has an unambiguous identity. Safe to call before confirmation and under the barrier.
	 *
	 * @param string               $prefix Current site prefix.
	 * @param array<string,string> $targets Logical site table names to verified private tables.
	 * @param int                  $admin Confirmed WordPress user ID.
	 * @throws DatabaseFailed If the site cannot safely preserve its current state.
	 */
	public function check( string $prefix, array $targets, int $admin ): void {
		if ( $admin < 1 || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $prefix ) ) {
			throw new DatabaseFailed( 'Database restore needs an identified site administrator and table prefix.' );
		}
		foreach ( self::TABLES as $suffix ) {
			$source = $prefix . $suffix;
			$target = $targets[ $source ] ?? '';
			if ( 1 !== preg_match( '/\A_?bfr_r_[0-9a-f]{32}_[bt][0-9]+\z/', $target ) || str_starts_with( $target, $prefix ) ) {
				throw new DatabaseFailed( 'The database exchange lacks an owned WordPress state table.' );
			}
			if ( $this->layout( $source, $suffix ) !== $this->layout( $target, $suffix ) ) {
				throw new DatabaseFailed( 'The two WordPress state tables have incompatible columns, collations or keys.' );
			}
		}
		$live = SqlFormatter::identifier( $prefix . 'users' );
		$user = SqlFormatter::identifier( $targets[ $prefix . 'users' ] );
		if ( [] === $this->connection->rows( 'SELECT 1 AS present FROM ' . $live . ' WHERE ID=' . $admin . " AND user_login<>'' AND user_email<>'' AND user_pass<>'' LIMIT 1" ) ) {
			throw new DatabaseFailed( 'The administrator who confirmed this database restore is no longer available.' );
		}
		// Qualify by the actual locked identifiers. Aliases require separately named
		// locks and would undermine the barrier holding these exact table names.
		$collision = $this->connection->rows( 'SELECT 1 AS collision FROM ' . $live . ' JOIN ' . $user . ' ON (' . $user . '.ID=' . $live . '.ID OR ' . $user . '.user_login=' . $live . '.user_login OR ' . $user . '.user_email=' . $live . '.user_email) WHERE ' . $live . '.ID=' . $admin . ' AND (' . $user . '.ID<>' . $live . '.ID OR CAST(' . $user . '.user_login AS BINARY)<>CAST(' . $live . '.user_login AS BINARY) OR CAST(' . $user . '.user_email AS BINARY)<>CAST(' . $live . '.user_email AS BINARY)) LIMIT 1' );
		if ( [] !== $collision ) {
			DatabaseFailed::raise( 'The restored users contain a conflicting ID, login or email for the confirming administrator.', [ 'code' => 'database_admin_collision' ] );
		}
		$options = SqlFormatter::identifier( $prefix . 'options' );
		$meta    = SqlFormatter::identifier( $prefix . 'usermeta' );
		$roles   = $this->connection->rows( 'SELECT 1 AS present FROM ' . $options . ' WHERE option_name=' . Session::literal( $prefix . 'user_roles' ) . " AND option_value<>'' LIMIT 1" );
		$caps    = $this->connection->rows( 'SELECT 1 AS present FROM ' . $meta . ' WHERE user_id=' . $admin . ' AND meta_key=' . Session::literal( $prefix . 'capabilities' ) . " AND meta_value<>'' LIMIT 1" );
		if ( [] === $roles || [] === $caps ) {
			throw new DatabaseFailed( 'The confirming administrator has no local WordPress roles and capabilities to preserve.' );
		}
		// Never carry state into an incompatible WordPress database schema.
		$target_options = SqlFormatter::identifier( $targets[ $prefix . 'options' ] );
		$matches        = $this->connection->rows( 'SELECT 1 AS same_version FROM ' . $options . ' JOIN ' . $target_options . ' ON ' . $target_options . '.option_name=' . $options . '.option_name AND CAST(' . $target_options . '.option_value AS BINARY)=CAST(' . $options . '.option_value AS BINARY) WHERE ' . $options . ".option_name='db_version' LIMIT 1" );
		if ( [] === $matches ) {
			DatabaseFailed::raise( 'The snapshot and running WordPress database schema versions differ.', [ 'code' => 'database_wordpress_version' ] );
		}
	}

	/**
	 * Compares the effective schema. MySQL SHOW CREATE may spell a column's implicit
	 * CHARACTER SET explicitly after importing the same definition and collation.
	 *
	 * @param string $table Existing permanent table.
	 * @param string $suffix Known WordPress table role.
	 * @return array{columns:list<array<string,string|null>>,indexes:list<array<string,string|null>>} Stable metadata.
	 * @throws DatabaseFailed If a protected table cannot be copied without losing values.
	 */
	private function layout( string $table, string $suffix ): array {
		$name    = SqlFormatter::identifier( $table );
		$columns = $this->connection->rows( 'SHOW FULL COLUMNS FROM ' . $name );
		if ( array_column( $columns, 'Field' ) !== self::COLUMNS[ $suffix ] ) {
			throw new DatabaseFailed( 'The database exchange requires the standard WordPress options and user table columns.' );
		}
		$fields = array_fill_keys( [ 'Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra' ], true );
		foreach ( $columns as &$column ) {
			if ( 1 === preg_match( '/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/', strtoupper( $column['Extra'] ?? '' ) ) ) {
				throw new DatabaseFailed( 'Generated WordPress state columns cannot be preserved safely.' );
			}
			$column = array_intersect_key( $column, $fields );
		}
		unset( $column );
		$fields = array_fill_keys( [ 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Sub_part', 'Index_type', 'Visible', 'Expression' ], true );
		$keys   = [];
		foreach ( $this->connection->rows( 'SHOW INDEX FROM ' . $name ) as $index ) {
			$keys[ $index['Key_name'] . ':' . $index['Seq_in_index'] ] = array_intersect_key( $index, $fields );
		}
		ksort( $keys, SORT_STRING );
		return [
			'columns' => $columns,
			'indexes' => array_values( $keys ),
		];
	}

	/**
	 * Refreshes state inside the caller's transaction. Copy named fields verbatim, allocate
	 * fresh option/meta row IDs, and preserve the administrator's primary user ID.
	 *
	 * @param string               $prefix Current WordPress table prefix.
	 * @param array<string,string> $targets Logical names mapped to private destination tables.
	 * @param int                  $admin Confirmed WordPress user ID.
	 * @throws DatabaseFailed If a required source row is missing or the whole copy fails.
	 */
	public function copy( string $prefix, array $targets, int $admin ): void {
		$this->check( $prefix, $targets, $admin );
		$names   = array_map( Session::literal( ... ), [ ...self::OPTIONS, $prefix . 'user_roles' ] );
		$options = 'option_name IN (' . implode( ',', $names ) . ')';
		foreach ( [ 'backups_for_restic_', '_transient_backups_for_restic_', '_transient_timeout_backups_for_restic_' ] as $namespace ) {
			$options .= ' OR CAST(LEFT(option_name,' . strlen( $namespace ) . ') AS BINARY)=' . Session::literal( $namespace );
		}
		$this->replace( $prefix . 'options', $targets[ $prefix . 'options' ], [ 'option_name', 'option_value', 'autoload' ], $options );
		$this->replace( $prefix . 'users', $targets[ $prefix . 'users' ], self::COLUMNS['users'], 'ID=' . $admin );
		$this->replace( $prefix . 'usermeta', $targets[ $prefix . 'usermeta' ], [ 'user_id', 'meta_key', 'meta_value' ], 'user_id=' . $admin );
	}

	/**
	 * Replaces a bounded set of protected rows without serializing them in application code.
	 *
	 * @param string       $source Live table.
	 * @param string       $target Verified private table.
	 * @param list<string> $columns Explicit ordinary columns.
	 * @param string       $where Generated selector.
	 * @throws DatabaseFailed If a protected row set exceeds the budget or did not copy fully.
	 */
	private function replace( string $source, string $target, array $columns, string $where ): void {
		$source = SqlFormatter::identifier( $source );
		$target = SqlFormatter::identifier( $target );
		$fields = implode( ',', array_map( SqlFormatter::identifier( ... ), $columns ) );
		$count  = 0;
		foreach ( [ $target, $source ] as $table ) {
			$found = $this->connection->rows( 'SELECT COUNT(*) AS n FROM (SELECT 1 FROM ' . $table . ' WHERE ' . $where . ' LIMIT 4097) protected_rows' )[0]['n'] ?? '';
			if ( ! ctype_digit( $found ) || (int) $found > 4096 ) {
				throw new DatabaseFailed( 'The protected WordPress rows exceed the database exchange budget.' );
			}
			$count = (int) $found;
		}
		$this->connection->execute( 'DELETE FROM ' . $target . ' WHERE ' . $where );
		$inserted = $this->connection->execute( 'INSERT INTO ' . $target . ' (' . $fields . ') SELECT ' . $fields . ' FROM ' . $source . ' WHERE ' . $where );
		if ( $inserted !== $count ) {
			throw new DatabaseFailed( 'The protected WordPress state was not copied completely.' );
		}
	}
}
