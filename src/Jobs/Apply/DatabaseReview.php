<?php
/**
 * Read-only review of a rehearsed database and its current destination.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\PluginList;
use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Database\Restore\Session;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\StagedDatabase;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * The public preview never contains row values, private table names or SQL, with the names of
 * roles, capabilities and plugins as the one exception: it says how the two generations differ
 * in what accounts may do and in which plugins WordPress loads. Its digest covers the actual
 * source transcript, the reviewed physical targets and those differences, independently of
 * estimated counts, which change during ordinary WordPress activity.
 *
 * @phpstan-import-type Change from RoleMap
 * @phpstan-type Entry array{table:string,action:string}
 * @phpstan-type Listing array{entries:list<string>,more:int}
 * @phpstan-type Plugins array{entries:list<array{plugin:string,file:bool}>,more:int}
 * @phpstan-type Role array{role:string,change:string,added:Listing,removed:Listing,denials_added:Listing,denials_removed:Listing}
 * @phpstan-type Roles array{entries:list<Role>,more:int,readable:bool}
 * @phpstan-type PluginChanges array{deactivated:Plugins,activated:Plugins,readable:bool}
 * @phpstan-type State array{kept:list<string>,restored:list<string>,plugin:array{entry:string|null,added:bool},plugins:PluginChanges,roles:Roles,missing_capabilities:list<string>}
 * @phpstan-type Preview array{restore:string,digest:string,applicable:bool,entries:list<Entry>,blockers:list<string>,missing_privileges:list<string>,kept:list<string>,restored:list<string>,plugin:array{entry:string|null,added:bool},plugins:PluginChanges,roles:Roles,missing_capabilities:list<string>}
 * @phpstan-type Target array{database:string,uuid:string,ids:array<string,string>,schemas:array<string,string>}
 */
final readonly class DatabaseReview {

	/**
	 * What every exchange keeps from the running site (WordPressState::copy()): the site
	 * address, the WordPress database version that both generations share, the lock of a
	 * WP-Cron request, this plugin's settings, state and transients, and the approving
	 * administrator's account with all of its user meta, active sessions included.
	 */
	public const array KEPT = [ 'site_address', 'database_version', 'cron_lock', 'plugin_data', 'administrator' ];

	/** What comes with the generation that becomes live, the backup or for an undo the earlier one. */
	public const array RESTORED = [ 'roles', 'active_plugins', 'scheduled_events' ];

	/** Entries of each list that a review shows; it counts the rest. */
	public const int LIST_LIMIT = 50;

	/**
	 * Bound of the report before JSON decoding: the metadata of at most 128 tables, and the
	 * backup's role map and list of active plugins, at most 1 MiB and 256 KiB, base64-encoded.
	 */
	private const int MAX_REPORT = 3145728;

	/**
	 * Creates a read-only reviewer for this installation.
	 *
	 * @param JobStore     $jobs Original job documents.
	 * @param Staging      $staging Checked private files.
	 * @param int          $hours Staging TTL.
	 * @param string       $prefix Current WordPress prefix.
	 * @param ExchangeSite $site This plugin's path, the capabilities a restore needs and the plugin file check.
	 */
	public function __construct( private JobStore $jobs, private Staging $staging, private int $hours, private string $prefix, private ExchangeSite $site ) {
	}

	/**
	 * Reads a successful rehearsal and binds it to fresh live identities and definitions.
	 *
	 * @param Connection   $connection Current single database session.
	 * @param string       $restore Original restore job.
	 * @param int          $user Confirming WordPress user ID.
	 * @param list<string> $policy Site-specific blockers.
	 * @param bool         $retained Whether a queued change pins the source.
	 * @return array{preview:Preview,source:StagedDatabase,path:string,content:string,authority:string,target:Target,capabilities:list<string>}
	 * @throws JobConflict If the report or staging lacks its original verified binding, or the rehearsal predates the comparison of roles and plugins.
	 */
	public function read( Connection $connection, string $restore, int $user, array $policy, bool $retained ): array {
		$job   = $this->jobs->get( $restore );
		$sha   = $job?->result['database_report_sha256'] ?? '';
		$files = $this->staging->files_directory( $restore, $this->hours, $retained );
		if ( null === $job || JobType::Restore !== $job->type || JobState::Succeeded !== $job->state || ! is_string( $sha ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $sha ) || null === $files ) {
			JobConflict::raise( 'Stage and rehearse the complete database before applying it.', [ 'code' => 'database_review_required' ] );
		}
		$handle = $this->staging->open_download( $restore, $this->hours, 'database_report', '', $retained );
		if ( null === $handle ) {
			JobConflict::raise( 'The verified rehearsal report is unavailable.', [ 'code' => 'database_review_required' ] );
		}
		try {
			$json = stream_get_contents( $handle, self::MAX_REPORT + 1 );
		} finally {
			fclose( $handle );
		}
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_REPORT || ! hash_equals( $sha, hash( 'sha256', $json ) ) ) {
			JobConflict::raise( 'The saved rehearsal report changed or exceeds the live review limit.', [ 'code' => 'database_review_required' ] );
		}
		$report = json_decode( $json, true, 32 );
		if ( ! is_array( $report ) || ! is_string( $report['source_directory'] ?? null ) || ! is_string( $report['content_digest'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $report['content_digest'] ) || ! is_array( $report['findings'] ?? null ) || $this->prefix !== ( $report['prefix'] ?? null ) || ( $job->data['snapshot'] ?? null ) !== ( $report['snapshot'] ?? null ) ) {
			JobConflict::raise( 'This rehearsal does not bind the verified SQL content to the reviewed snapshot.', [ 'code' => 'database_review_required' ] );
		}
		$stored = $report['wordpress_state'] ?? null;
		if ( ! is_array( $stored ) || ! self::encoded( $stored, 'user_roles' ) || ! self::encoded( $stored, 'active_plugins' ) ) {
			JobConflict::raise( 'This rehearsal was made before the review compared the roles and active plugins of the backup with the site.', [ 'code' => 'database_review_required' ] );
		}
		$source = StagedDatabase::open( $files, $report['source_directory'], $this->prefix );
		if ( $source->manifest->fingerprint !== ( $report['manifest_sha256'] ?? null ) ) {
			JobConflict::raise( 'The source database manifest changed after rehearsal.', [ 'code' => 'database_exchange_changed' ] );
		}
		$names      = array_column( $source->manifest->tables, 'name' );
		$review     = ( new SwapCatalog( $connection ) )->review( $names );
		$inspection = $review['inspection'];
		$blockers   = [ ...$policy, ...$inspection['blockers'] ];
		// Keep only source findings from the rehearsal. The live catalog may have changed.
		foreach ( $report['findings'] as $finding ) {
			if ( is_array( $finding ) && is_string( $finding['code'] ?? null ) && str_starts_with( $finding['code'], 'snapshot_' ) ) {
				$blockers[] = $finding['code'];
			}
		}
		$rows = $connection->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND CAST(LEFT(TABLE_NAME,' . strlen( $this->prefix ) . ') AS BINARY)=' . Session::literal( $this->prefix ) . ' ORDER BY TABLE_NAME LIMIT ' . ( Manifest::MAX_TABLES + 1 ) );
		if ( count( $rows ) > Manifest::MAX_TABLES ) {
			$blockers[] = 'table_count';
		}
		$live    = array_fill_keys( array_values( array_filter( array_column( $rows, 'TABLE_NAME' ), 'is_string' ) ), true );
		$present = true;
		foreach ( WordPressState::TABLES as $suffix ) {
			$name = $this->prefix . $suffix;
			if ( ! isset( $live[ $name ] ) || ! in_array( $name, $names, true ) ) {
				$blockers[] = 'wordpress_tables';
			}
			$present = $present && isset( $live[ $name ] );
		}
		$entries = [];
		foreach ( $names as $name ) {
			$entries[] = [
				'table'  => $name,
				'action' => isset( $live[ $name ] ) ? 'replace' : 'create',
			];
			unset( $live[ $name ] );
		}
		foreach ( array_keys( $live ) as $name ) {
			$entries[] = [
				'table'  => $name,
				'action' => 'keep',
			];
		}
		// Without the live options and user tables there is nothing to compare, and nothing to read.
		$facts     = $present ? $this->state( $connection, $user, self::decoded( $stored['user_roles'] ), self::decoded( $stored['active_plugins'] ) ) : [
			'state'        => self::blank( $this->site->plugin ),
			'blockers'     => [],
			'capabilities' => [],
		];
		$blockers  = [ ...$blockers, ...$facts['blockers'] ];
		$authority = '';
		if ( [] === $blockers ) {
			try {
				$authority = ( new WordPressState( $connection ) )->authority( $this->prefix, $user );
			} catch ( DatabaseFailed $refused ) {
				$code       = $refused->context()['code'] ?? null;
				$blockers[] = is_string( $code ) ? $code : 'database_admin_collision';
			}
		}
		$target = [
			'database' => $inspection['facts']['database'],
			'uuid'     => $inspection['facts']['uuid'],
			'ids'      => $inspection['ids'],
			'schemas'  => $review['schemas'],
		];
		return [
			'preview'      => self::summary( $restore, $user, $entries, $blockers, $inspection['missing_privileges'], [ 'apply', $sha, $target, $authority, $facts['capabilities'] ], $facts['state'] ),
			'source'       => $source,
			'authority'    => $authority,
			'path'         => $report['source_directory'],
			'content'      => $report['content_digest'],
			'target'       => $target,
			'capabilities' => $facts['capabilities'],
		];
	}

	/**
	 * What the exchange keeps from the running site, what it takes from the generation that
	 * becomes live, and how the two differ: the plugins that WordPress loads now and would not
	 * afterwards and the other way round, each with whether its file is on the site, and the
	 * roles whose stored capabilities differ, granted or denied. The approving administrator
	 * keeps their own roles and capabilities, so these, under the other generation's roles, must
	 * still give every capability that a database restore needs.
	 *
	 * The values of the generation that becomes live and the administrator's own record have to
	 * be readable, and so does that generation's list of active plugins with this plugin's entry
	 * added; otherwise the review refuses with a code that names the value. The running
	 * site's role map and list of active plugins are read for the comparison only, because the
	 * exchange discards them: if one cannot be read, the review says so (readable: false) and
	 * lists everything of the other generation instead.
	 *
	 * @param Connection      $connection Current session.
	 * @param int             $user Approving administrator.
	 * @param string|int|null $roles Stored role map of the generation that becomes live, its length if too long, or null.
	 * @param string|int|null $plugins Stored list of active plugins of that generation, its length if too long, or null.
	 * @return array{state:State,blockers:list<string>,capabilities:list<string>} Facts for the review, refusal codes and the capabilities checked.
	 */
	public function state( Connection $connection, int $user, string|int|null $roles, string|int|null $plugins ): array {
		$live    = new WordPressState( $connection );
		$options = $this->prefix . 'options';
		$current = [
			'roles'   => $live->option( $options, $this->prefix . 'user_roles', RoleMap::MAX_BYTES ),
			'plugins' => $live->option( $options, 'active_plugins', PluginList::MAX_BYTES ),
			'own'     => $live->capabilities( $this->prefix, $user ),
		];
		$state   = self::blank( $this->site->plugin );
		try {
			$continuity = $this->site->continuity();
		} catch ( InvalidArgument ) {
			// No account can hold a capability without a valid name.
			return [
				'state'        => $state,
				'blockers'     => [ 'database_account' ],
				'capabilities' => [],
			];
		}
		$blockers = [];
		$next     = self::required( static fn (): RoleMap => RoleMap::parse( $roles ), $blockers );
		$own      = self::required( static fn (): array => RoleMap::own( $current['own'] ), $blockers );
		$after    = self::required( static fn (): PluginList => PluginList::parse( $plugins ), $blockers );
		$plugin   = $continuity->plugin;
		if ( null !== $after && null !== $plugin ) {
			$state['plugin']['added'] = ! $after->has( $plugin );
			// The exchange adds this plugin's entry, and the list has to stay one that it reads.
			$list  = $after;
			$after = self::required( static fn (): PluginList => $list->with( $plugin ), $blockers );
		}
		// The running site's values: a refusal only means that they cannot be compared.
		$now_roles                    = self::optional( static fn (): RoleMap => RoleMap::parse( $current['roles'] ) );
		$now_plugins                  = self::optional( static fn (): PluginList => PluginList::parse( $current['plugins'] ) );
		$state['roles']['readable']   = null !== $now_roles;
		$state['plugins']['readable'] = null !== $now_plugins;
		if ( null !== $next && null !== $own ) {
			$state['missing_capabilities'] = $next->missing( $own, $continuity->capabilities );
			if ( [] !== $state['missing_capabilities'] ) {
				$blockers[] = 'database_admin_capabilities';
			}
		}
		if ( null !== $next ) {
			$state['roles'] = self::bounded( ( $now_roles ?? RoleMap::none() )->changes( $next ), self::role( ... ) ) + [ 'readable' => null !== $now_roles ];
		}
		if ( null !== $after ) {
			// This plugin runs this review, so it is active now even where the list cannot be read.
			$now              = $now_plugins ?? ( null === $plugin ? PluginList::none() : PluginList::none()->with( $plugin ) );
			$file             = fn ( string $path ): array => [
				'plugin' => $path,
				'file'   => true === ( $this->site->installed )( $path ),
			];
			$state['plugins'] = [
				'deactivated' => self::bounded( $now->without( $after ), $file ),
				'activated'   => self::bounded( $after->without( $now ), $file ),
				'readable'    => null !== $now_plugins,
			];
		}
		return [
			'state'        => $state,
			'blockers'     => $blockers,
			'capabilities' => $continuity->capabilities,
		];
	}

	/**
	 * Reads a value that the exchange needs; a refusal becomes a blocker with its code.
	 *
	 * @template T
	 * @param \Closure():T $read Reader that refuses with a coded DatabaseFailed.
	 * @param list<string> $blockers Refusal codes, extended.
	 * @return T|null The value, or null if it was refused.
	 */
	private static function required( \Closure $read, array &$blockers ): mixed {
		try {
			return $read();
		} catch ( DatabaseFailed $refused ) {
			$code       = $refused->context()['code'] ?? null;
			$blockers[] = is_string( $code ) ? $code : 'database_exchange_failed';
			return null;
		}
	}

	/**
	 * Reads a value that only the comparison needs.
	 *
	 * @template T
	 * @param \Closure():T $read Reader that refuses with DatabaseFailed.
	 * @return T|null The value, or null if it cannot be read.
	 */
	private static function optional( \Closure $read ): mixed {
		try {
			return $read();
		} catch ( DatabaseFailed ) {
			return null;
		}
	}

	/**
	 * The facts of a review that could not compare the two generations.
	 *
	 * @param string|null $plugin This plugin's path in active_plugins.
	 * @return State What every exchange keeps and takes, without differences.
	 */
	public static function blank( ?string $plugin ): array {
		$none = [
			'entries' => [],
			'more'    => 0,
		];
		return [
			'kept'                 => self::KEPT,
			'restored'             => self::RESTORED,
			'plugin'               => [
				'entry' => $plugin,
				'added' => false,
			],
			'plugins'              => [
				'deactivated' => $none,
				'activated'   => $none,
				'readable'    => true,
			],
			'roles'                => $none + [ 'readable' => true ],
			'missing_capabilities' => [],
		];
	}

	/**
	 * Binds one visible plan and its private physical inputs to its confirming user. Version 2
	 * of the digest covers what the exchange keeps and how the generations differ, so no review
	 * from before it can be confirmed.
	 *
	 * @param string       $restore Restore identifier.
	 * @param int          $user Current user ID.
	 * @param list<Entry>  $entries Table-level operations.
	 * @param list<string> $blockers Refusal codes.
	 * @param list<string> $missing Missing privileges.
	 * @param array<mixed> $binding Private source and destination bindings.
	 * @param State        $state What is kept, what is taken and the differences.
	 * @return Preview Review for the administrator.
	 */
	public static function summary( string $restore, int $user, array $entries, array $blockers, array $missing, array $binding, array $state ): array {
		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );
		sort( $missing, SORT_STRING );
		return [
			'restore'            => $restore,
			'digest'             => hash( 'sha256', json_encode( [ 2, $restore, $user, $entries, $blockers, $missing, $binding, $state ], JSON_THROW_ON_ERROR ) ),
			'applicable'         => $user > 0 && [] === $blockers && [] !== $entries,
			'entries'            => $entries,
			'blockers'           => $blockers,
			'missing_privileges' => $missing,
		] + $state;
	}

	/**
	 * The first entries of a list for the review, and how many more there are.
	 *
	 * @template T
	 * @template U
	 * @param list<T>       $items Whole list.
	 * @param \Closure(T):U $shown What the review shows of one entry.
	 * @return array{entries:list<U>,more:int} Shown entries and the count of the rest.
	 */
	private static function bounded( array $items, \Closure $shown ): array {
		return [
			'entries' => array_map( $shown, array_slice( $items, 0, self::LIST_LIMIT ) ),
			'more'    => max( 0, count( $items ) - self::LIST_LIMIT ),
		];
	}

	/**
	 * One role that differs, with bounded lists of capabilities.
	 *
	 * @param Change $change Difference.
	 * @return Role Shown difference.
	 */
	private static function role( array $change ): array {
		$name = static fn ( string $capability ): string => $capability;
		return [
			'role'            => $change['role'],
			'change'          => $change['change'],
			'added'           => self::bounded( $change['added'], $name ),
			'removed'         => self::bounded( $change['removed'], $name ),
			'denials_added'   => self::bounded( $change['denials_added'], $name ),
			'denials_removed' => self::bounded( $change['denials_removed'], $name ),
		];
	}

	/**
	 * Whether the report has a field that holds a stored value, the length of one too long to
	 * read, or null, which says that the rehearsal found none. A report from before the
	 * comparison lacks the field.
	 *
	 * @param array<mixed> $state The report's wordpress_state.
	 * @param string       $field Field name.
	 * @return bool Whether the field is present and null, a string or a positive integer.
	 */
	private static function encoded( array $state, string $field ): bool {
		if ( ! array_key_exists( $field, $state ) ) {
			return false;
		}
		$value = $state[ $field ];
		return null === $value || is_string( $value ) || ( is_int( $value ) && $value > 0 );
	}

	/**
	 * Decodes a stored value of the report.
	 *
	 * @param mixed $value Base64 text, the length of a value too long to read, or null.
	 * @return string|int|null Stored value or length, or null if there is none or it does not decode.
	 */
	private static function decoded( mixed $value ): string|int|null {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		try {
			return Bytes::base64_decode( $value );
		} catch ( FormatException ) {
			return null;
		}
	}
}
