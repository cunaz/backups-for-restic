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
 * Copies table rows inside SQL. No session token or password hash enters PHP or a job
 * document. Three serialized values are read into PHP, bounded and by SerializedValue, never
 * by unserialize(): a generation's role map and list of active plugins, and the approving
 * administrator's own roles and capabilities. The caller holds the write barrier throughout
 * validation, the copy and RENAME, and always copies into its verified private targets.
 */
final readonly class WordPressState {

	public const array TABLES = [ 'options', 'users', 'usermeta' ];

	/**
	 * Options of the running installation that the other generation receives: the site address,
	 * the WordPress database version that both generations already share, and the lock of a
	 * WP-Cron request, which may be the one that runs the exchange. Roles, active plugins and
	 * scheduled events stay those of the generation that becomes live (ADR 0043, decision 5).
	 */
	public const array OPTIONS = [ 'home', 'siteurl', 'db_version', '_transient_doing_cron', '_transient_timeout_doing_cron' ];

	/** Prefixes of this plugin's own options and transients, which the other generation receives. */
	public const array PLUGIN_OPTIONS = [ 'backups_for_restic_', '_transient_backups_for_restic_', '_transient_timeout_backups_for_restic_' ];

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
	 * Binds the approving credential, its database-backed authority and the live list of
	 * active plugins, which the review compared with the other generation, without reading any
	 * password hash or serialized value into PHP. Recheck this under the write barrier in case
	 * another request changed the user or the site after WordPress checked user_can().
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
		$caps    = $this->connection->rows( 'SELECT SHA2(meta_value,256) AS f FROM ' . $meta . ' WHERE user_id=' . $admin . ' AND meta_key=' . Session::literal( $prefix . 'capabilities' ) . ' ORDER BY umeta_id LIMIT 2' );
		$roles   = $this->connection->rows( 'SELECT SHA2(option_value,256) AS f FROM ' . $options . ' WHERE option_name=' . Session::literal( $prefix . 'user_roles' ) )[0]['f'] ?? '';
		$plugins = $this->connection->rows( 'SELECT SHA2(option_value,256) AS f FROM ' . $options . ' WHERE option_name=' . Session::literal( 'active_plugins' ) )[0]['f'] ?? '';
		if ( ! is_string( $user ) ) {
			DatabaseFailed::raise( 'The approving administrator has no local identity.', [ 'code' => 'database_admin_collision' ] );
		}
		if ( 1 !== count( $caps ) || ! is_string( $caps[0]['f'] ?? null ) ) {
			DatabaseFailed::raise( 'The approving administrator\'s own roles and capabilities are absent or stored more than once.', [ 'code' => 'database_admin_capabilities_unreadable' ] );
		}
		return self::bound( $user, $caps[0]['f'], $roles, $plugins );
	}

	/**
	 * The authority from its parts. Versioned: an authority from before the live list of active
	 * plugins was bound, the SHA-256 of the user's, the roles' and the capabilities' fingerprints
	 * alone, never matches, so an exchange confirmed before that stops.
	 *
	 * @param string $user Fingerprint of the user row.
	 * @param string $capabilities Fingerprint of the account's own roles and capabilities.
	 * @param string $roles Fingerprint of the live role map, empty if it is absent.
	 * @param string $plugins Fingerprint of the live list of active plugins, empty if it is absent.
	 * @return string SHA-256.
	 */
	private static function bound( string $user, string $capabilities, string $roles, string $plugins ): string {
		return hash( 'sha256', 'backups-for-restic-authority/2:' . $user . $roles . $capabilities . $plugins );
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
		// The running site's role map does not matter here: the exchange discards it.
		$caps = $this->connection->rows( 'SELECT 1 AS present FROM ' . $meta . ' WHERE user_id=' . $admin . ' AND meta_key=' . Session::literal( $prefix . 'capabilities' ) . " AND meta_value<>'' LIMIT 1" );
		if ( [] === $caps ) {
			DatabaseFailed::raise( 'The confirming administrator has no record of their own roles and capabilities to keep.', [ 'code' => 'database_admin_capabilities_unreadable' ] );
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
	 * Reads one option of a generation's options table. A value longer than the limit is not
	 * transferred; its length is returned instead.
	 *
	 * @param string $table Live or verified private options table.
	 * @param string $name Option name.
	 * @param int    $limit Longest value read, in bytes.
	 * @return string|int|null Stored value; its length in bytes if longer than the limit; null if the table holds no such option or more than one.
	 */
	public function option( string $table, string $name, int $limit ): string|int|null {
		return self::bounded( $this->connection->rows( 'SELECT LENGTH(option_value) AS stored_bytes,IF(LENGTH(option_value)<=' . $limit . ',option_value,NULL) AS stored_value FROM ' . SqlFormatter::identifier( $table ) . ' WHERE option_name=' . Session::literal( $name ) . ' LIMIT 2' ) );
	}

	/**
	 * Reads the approving administrator's own roles and capabilities from the live user meta,
	 * which the exchange keeps.
	 *
	 * @param string $prefix Current WordPress table prefix.
	 * @param int    $admin Confirmed WordPress user ID.
	 * @return string|int|null Stored value; its length in bytes if longer than RoleMap reads; null if absent or stored more than once.
	 */
	public function capabilities( string $prefix, int $admin ): string|int|null {
		return self::bounded( $this->connection->rows( 'SELECT LENGTH(meta_value) AS stored_bytes,IF(LENGTH(meta_value)<=' . RoleMap::MAX_OWN_BYTES . ',meta_value,NULL) AS stored_value FROM ' . SqlFormatter::identifier( $prefix . 'usermeta' ) . ' WHERE user_id=' . $admin . ' AND meta_key=' . Session::literal( $prefix . 'capabilities' ) . ' LIMIT 2' ) );
	}

	/**
	 * The value of exactly one row, or its length when it was too long to transfer.
	 *
	 * @param list<array<string,string|null>> $rows Rows with stored_bytes and stored_value.
	 * @return string|int|null Value, length, or null for no row, several rows or a NULL value.
	 */
	private static function bounded( array $rows ): string|int|null {
		if ( 1 !== count( $rows ) ) {
			return null;
		}
		$bytes = $rows[0]['stored_bytes'] ?? null;
		return $rows[0]['stored_value'] ?? ( null === $bytes ? null : (int) $bytes );
	}

	/**
	 * The capabilities that the approving administrator would lack once a generation is live:
	 * their own roles and capabilities, which the exchange keeps, under that generation's roles.
	 *
	 * @param string       $prefix Current WordPress table prefix.
	 * @param string       $options Options table of the generation that becomes live.
	 * @param int          $admin Confirmed WordPress user ID.
	 * @param list<string> $required Capabilities that a database restore needs.
	 * @return list<string> Missing capabilities.
	 * @throws DatabaseFailed With the code database_roles_invalid or database_admin_capabilities if a stored value is not one that WordPress writes.
	 */
	public function missing( string $prefix, string $options, int $admin, array $required ): array {
		$roles = RoleMap::parse( $this->option( $options, $prefix . 'user_roles', RoleMap::MAX_BYTES ) );
		return $roles->missing( RoleMap::own( $this->capabilities( $prefix, $admin ) ), $required );
	}

	/**
	 * Adds this plugin at the end of the list of active plugins in a private options table, if
	 * the list lacks it, and commits. The caller holds a write lock on that one private table and
	 * has verified its identity; the live tables stay unlocked.
	 *
	 * @param string $table Verified private options table.
	 * @param string $plugin This plugin's path, one that PluginList::entry() accepts.
	 * @return bool Whether the entry was added.
	 * @throws DatabaseFailed With the code database_plugins_invalid if the list is not one that WordPress writes; without one if the write fails.
	 */
	public function activate( string $table, string $plugin ): bool {
		$list = PluginList::parse( $this->option( $table, 'active_plugins', PluginList::MAX_BYTES ) );
		if ( $list->has( $plugin ) ) {
			return false;
		}
		$changed = $this->connection->execute( 'UPDATE ' . SqlFormatter::identifier( $table ) . ' SET option_value=' . Session::literal( $list->with( $plugin )->stored() ) . ' WHERE option_name=' . Session::literal( 'active_plugins' ) );
		if ( 1 !== $changed ) {
			throw new DatabaseFailed( 'This plugin could not be added to the active plugins of the private generation.' );
		}
		$this->connection->execute( 'COMMIT' );
		return true;
	}

	/**
	 * Refreshes state inside the caller's transaction. Copy named fields verbatim, allocate
	 * fresh option/meta row IDs, and preserve the administrator's primary user ID. The roles,
	 * the active plugins and the scheduled events of the generation that becomes live stay.
	 * Under its roles the approving administrator must keep every capability that a database
	 * restore needs, and its list of active plugins must hold this plugin.
	 *
	 * @param string               $prefix Current WordPress table prefix.
	 * @param array<string,string> $targets Logical names mapped to private destination tables.
	 * @param int                  $admin Confirmed WordPress user ID.
	 * @param Continuity           $continuity Capabilities the administrator keeps and this plugin's path.
	 * @throws DatabaseFailed If a required source row is missing, the generation would leave the administrator or this plugin without what they need, or the whole copy fails.
	 */
	public function copy( string $prefix, array $targets, int $admin, Continuity $continuity ): void {
		$this->check( $prefix, $targets, $admin );
		$target = $targets[ $prefix . 'options' ];
		if ( [] !== $this->missing( $prefix, $target, $admin, $continuity->capabilities ) ) {
			DatabaseFailed::raise( 'Under the roles of the generation that the exchange brings live, the approving administrator would lack a capability that a database restore needs.', [ 'code' => 'database_admin_capabilities' ] );
		}
		if ( null !== $continuity->plugin && ! PluginList::parse( $this->option( $target, 'active_plugins', PluginList::MAX_BYTES ) )->has( $continuity->plugin ) ) {
			DatabaseFailed::raise( 'The active plugins of the generation that the exchange brings live lack this plugin.', [ 'code' => 'database_plugins_invalid' ] );
		}
		$names   = array_map( Session::literal( ... ), self::OPTIONS );
		$options = 'option_name IN (' . implode( ',', $names ) . ')';
		foreach ( self::PLUGIN_OPTIONS as $namespace ) {
			$options .= ' OR CAST(LEFT(option_name,' . strlen( $namespace ) . ') AS BINARY)=' . Session::literal( $namespace );
		}
		$this->replace( $prefix . 'options', $target, [ 'option_name', 'option_value', 'autoload' ], $options );
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
