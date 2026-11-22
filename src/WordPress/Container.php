<?php
/**
 * Composition root.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

// Before the imports, unlike in the other files: Plugin Check looks for this line only near
// the top of a file, and this file imports more classes than fit there.
defined( 'ABSPATH' ) || exit;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Backup\SitePaths;
use BackupsForRestic\Connection\ConnectionTester;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\RepositoryRecord;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Database\Dump\DatabaseDump;
use BackupsForRestic\Database\Restore\MysqliConnection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\Restore\DatabasePreview;
use BackupsForRestic\Doctor\Doctor;
use BackupsForRestic\Doctor\Environment;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\MemoryBudget;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Engine\TreeCache;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Jobs\Backup\BackupServices;
use BackupsForRestic\Jobs\Backup\BackupSteps;
use BackupsForRestic\Jobs\JobSteps;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\Apply\DatabaseGeneration;
use BackupsForRestic\Jobs\Maintenance\MaintenanceServices;
use BackupsForRestic\Jobs\Maintenance\MaintenanceSteps;
use BackupsForRestic\Jobs\Restore\RestoreServices;
use BackupsForRestic\Jobs\Restore\RestoreSteps;
use BackupsForRestic\Jobs\Maintenance\RetentionGuard;
use BackupsForRestic\Jobs\Maintenance\RetentionSettings;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Notifications\Notifier;
use BackupsForRestic\Plugin;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Restore\SnapshotBrowser;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Secrets\ConstantOverrideStore;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Secrets\SecretStore;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\BrowserProbe;
use BackupsForRestic\Storage\CacheDirectory;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SchemaVersion;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Support\SystemClock;

/**
 * Builds every service from WordPress' constants, options and filters, once per request.
 * This is the only place that knows how the WordPress-free core is wired together.
 */
final class Container {

	/** The eight salt constants of wp-config.php that protect the stored secrets (ADR 0006). */
	/** Seconds the Doctor waits for the repository server. */
	private const int PROBE_SECONDS = 10;

	public const array SALT_CONSTANTS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

	/**
	 * Settings, loaded once per request.
	 *
	 * @var ?Settings
	 */
	private ?Settings $settings = null;

	/**
	 * Secret store with constant overrides.
	 *
	 * @var ?SecretStore
	 */
	private ?SecretStore $secrets = null;

	/**
	 * The encrypted option store.
	 *
	 * @var ?EncryptedOptionStore
	 */
	private ?EncryptedOptionStore $stored_secrets = null;

	/**
	 * Private directory.
	 *
	 * @var ?PrivateDirectory
	 */
	private ?PrivateDirectory $private_dir = null;

	/**
	 * Redactor with the current secrets.
	 *
	 * @var ?Redactor
	 */
	private ?Redactor $redactor = null;

	/**
	 * Lock for changes to the state, shared by everything in this request.
	 *
	 * @var FileLock|null
	 */
	private ?FileLock $state_lock = null;

	/**
	 * Randomness.
	 *
	 * @var ?RandomSource
	 */
	private ?RandomSource $random = null;

	/**
	 * Creates the container.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public function __construct( public readonly string $plugin_file ) {
	}

	/**
	 * Options table.
	 *
	 * @return WpOptions Options.
	 */
	public function options(): WpOptions {
		return new WpOptions();
	}

	/**
	 * The layout version of the stored options.
	 *
	 * @return SchemaVersion Version record.
	 */
	public function schema_version(): SchemaVersion {
		return new SchemaVersion( $this->options() );
	}

	/**
	 * Settings store with the wp-config.php overrides.
	 *
	 * @return SettingsStore Store.
	 */
	public function settings_store(): SettingsStore {
		return new SettingsStore( $this->options(), self::constants( array_values( Schema::CONSTANTS ) ), $this->state_lock() );
	}

	/**
	 * Current settings.
	 *
	 * @return Settings Settings.
	 */
	public function settings(): Settings {
		return $this->settings ??= $this->settings_store()->load();
	}

	/**
	 * Saves what an administrator changed, on the settings page or with WP-CLI.
	 *
	 * @param array<string, mixed> $changes New values by dotted path.
	 * @return Settings The saved settings.
	 * @throws ValidationFailed If a value is invalid; nothing is saved then.
	 */
	public function save_settings( array $changes ): Settings {
		$store = $this->settings_store();
		$mode  = array_key_exists( 'retention.mode', $changes ) ? $store->load( true )->string( 'retention.mode' ) : null;
		$saved = $store->update( $changes );
		// Every path is one of the schema here: the update refuses any other.
		foreach ( array_keys( $changes ) as $path ) {
			if ( str_starts_with( $path, 'retention.' ) ) {
				// The cached list of this site's snapshots previews the policy from before the change.
				delete_transient( 'backups_for_restic_snapshots' );
				break;
			}
		}
		if ( null !== $mode && $saved->string( 'retention.mode' ) !== $mode ) {
			// An administrator chose the mode: that answers the proposal and the switch notice.
			$this->state()->set( 'retention_proposal', [] );
			$this->state()->set( 'retention_switch', [] );
		}
		$this->refresh();
		return $saved;
	}

	/**
	 * Forgets cached settings after an update.
	 */
	public function refresh(): void {
		$this->settings = null;
		$this->redactor = null;
	}

	/**
	 * Secrets, with wp-config.php constants winning over stored values.
	 *
	 * @return SecretStore Store.
	 */
	public function secrets(): SecretStore {
		$constants = [];
		foreach ( SecretName::cases() as $name ) {
			if ( null !== $name->constant() ) {
				$constants[] = $name->constant();
			}
		}
		return $this->secrets ??= new ConstantOverrideStore( $this->stored_secrets(), self::constants( $constants ) );
	}

	/**
	 * The encrypted option store itself, below the constants of wp-config.php.
	 *
	 * @return EncryptedOptionStore Store.
	 */
	public function stored_secrets(): EncryptedOptionStore {
		$material = '';
		foreach ( self::SALT_CONSTANTS as $constant ) {
			$material .= self::constant_string( $constant );
		}
		return $this->stored_secrets ??= new EncryptedOptionStore( $this->options(), new Secret( $material ), $this->random(), $this->state_lock() );
	}

	/**
	 * Randomness.
	 *
	 * @return RandomSource Source.
	 */
	public function random(): RandomSource {
		return $this->random ??= new SystemRandom();
	}

	/**
	 * Time.
	 *
	 * @return Clock Clock.
	 */
	public function clock(): Clock {
		return new SystemClock();
	}

	/**
	 * Private directory: the constant, a filter, then wp-content or uploads (ADR 0007).
	 *
	 * @return PrivateDirectory Directory.
	 */
	public function private_dir(): PrivateDirectory {
		if ( null === $this->private_dir ) {
			$configured = defined( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) ? self::constant_string( 'BACKUPS_FOR_RESTIC_PRIVATE_DIR' ) : null;
			/**
			 * Filters the private working directory of the backup plugin.
			 *
			 * @param string|null $configured Absolute path, or null for the default location.
			 */
			$configured        = apply_filters( 'backups_for_restic_private_dir', $configured );
			$uploads           = wp_upload_dir( null, false );
			$this->private_dir = PrivateDirectory::locate( is_string( $configured ) ? $configured : null, WP_CONTENT_DIR, $uploads['basedir'] );
		}
		return $this->private_dir;
	}

	/**
	 * Removes every known secret from logs and job files: the passwords, among them a
	 * repository password that was entered and is not confirmed yet, and the REST user name and
	 * password together as the Authorization header of repository requests carries them.
	 *
	 * @return Redactor Redactor.
	 */
	public function redactor(): Redactor {
		if ( null === $this->redactor ) {
			$this->redactor = new Redactor();
			$username       = $this->settings()->string( 'repository.rest_username' );
			foreach ( [ SecretName::RepositoryPassword, SecretName::RepositoryPasswordCandidate, SecretName::RestPassword ] as $name ) {
				try {
					$secret = $this->secrets()->get( $name );
				} catch ( SecretsUndecryptable ) {
					$secret = null;
				}
				if ( null !== $secret ) {
					$this->redactor->add( $secret );
				}
				if ( null !== $secret && SecretName::RestPassword === $name && '' !== $username ) {
					$this->redactor->add_credentials( $username, $secret );
				}
			}
		}
		return $this->redactor;
	}

	/**
	 * Jobs.
	 *
	 * @return JobStore Store.
	 */
	public function jobs(): JobStore {
		$this->private_dir()->ensure();
		return new JobStore( $this->private_dir()->subdirectory( 'jobs' ), $this->redactor(), $this->random() );
	}

	/**
	 * What finished restores left in the private directory, and its removal (ADR 0040).
	 *
	 * @return Staging Staging directories.
	 */
	public function staging(): Staging {
		return new Staging( $this->private_dir()->subdirectory( 'restores' ), $this->jobs(), $this->clock() );
	}

	/**
	 * The three destination roots belong to this installation. A snapshot's manifest and
	 * its extra backup roots cannot grant write access to a different location.
	 *
	 * @param bool        $include_config Explicitly include the live configuration file.
	 * @param string|null $restore ID of the original restore with its verified layout binding.
	 * @throws InvalidArgument If the restore metadata required for file apply has expired.
	 * @return ApplyPlan Read-only planner.
	 */
	public function apply_plan( bool $include_config = false, ?string $restore = null ): ApplyPlan {
		$site   = $this->site();
		$layout = null;
		if ( null !== $restore ) {
			$job = $this->jobs()->get( $restore );
			if ( null === $job ) {
				throw new InvalidArgument( 'The restore job metadata needed to verify live file destinations is unavailable.' );
			}
			$layout = SiteLayout::from_job( $job, $site->content_dir, $site->uploads_dir );
		}
		// Core, configuration and drop-ins execute before the worker can isolate active
		// extensions. Their restoration requires a controller outside this installation.
		$bootstrap = [
			$site->abspath . '/wp-admin',
			$site->abspath . '/wp-includes',
			defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : $site->content_dir . '/mu-plugins',
			dirname( $site->abspath ) . '/wp-config.php',
		];
		foreach ( [ 'index.php', 'wp-blog-header.php', 'wp-settings.php', 'wp-load.php', 'wp-config.php', 'wp-cron.php', 'wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-comments-post.php', 'wp-links-opml.php', 'wp-mail.php', 'wp-trackback.php', 'xmlrpc.php', '.htaccess', '.user.ini', 'web.config', 'php.ini' ] as $name ) {
			$bootstrap[] = $site->abspath . '/' . $name;
		}
		foreach ( [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'fatal-error-handler.php', 'php-error.php', 'maintenance.php', 'sunrise.php', 'install.php' ] as $name ) {
			$bootstrap[] = $site->content_dir . '/' . $name;
		}
		return new ApplyPlan(
			[ $site->abspath, $site->content_dir, $site->uploads_dir ],
			[ dirname( $this->plugin_file ), $this->private_dir()->path, $site->abspath . '/.maintenance' ],
			[ $site->abspath . '/wp-config.php', dirname( $site->abspath ) . '/wp-config.php' ],
			$include_config,
			$layout,
			$bootstrap
		);
	}

	/**
	 * Builds the guarded apply and undo job service.
	 *
	 * @return FileChanges File change service.
	 */
	public function file_changes(): FileChanges {
		return new FileChanges(
			$this->jobs(),
			$this->staging(),
			$this->clock(),
			$this->settings()->int( 'restore.staging_expiry_hours' ),
			$this->apply_plan( ... ),
			$this->file_maintenance(),
			static fn (): bool => wp_is_file_mod_allowed( 'backups_for_restic_apply' )
		);
	}

	/**
	 * Owns the live marker for this WordPress installation.
	 *
	 * @return FileMaintenance Marker manager.
	 */
	private function file_maintenance(): FileMaintenance {
		return new FileMaintenance( $this->site()->abspath, plugin_basename( $this->plugin_file ), rest_url( RestRoutes::REST_NAMESPACE ), admin_url( 'admin.php' ) );
	}

	/**
	 * Builds confirmed database jobs without opening SQL on unrelated runner requests.
	 * During uninstall the supplied store and directory identify a previous private path.
	 *
	 * @param PrivateDirectory|null $directory Private job directory for retained generations.
	 * @param JobStore|null         $store Corresponding validated store.
	 * @return DatabaseChanges Reviewed database operations.
	 */
	public function database_changes( ?PrivateDirectory $directory = null, ?JobStore $store = null ): DatabaseChanges {
		$directory ??= $this->private_dir();
		$store     ??= $this->jobs();
		return new DatabaseChanges(
			$store,
			new Staging( $directory->subdirectory( 'restores' ), $store, $this->clock() ),
			$this->clock(),
			$this->random(),
			$this->settings()->int( 'restore.staging_expiry_hours' ),
			self::wpdb()->prefix,
			$this->database_connection( ... ),
			static function ( int $user ): array {
				$blockers = [];
				if ( ! RestRoutes::database_user_allowed( $user ) ) {
					$blockers[] = 'database_account';
				}
				$wpdb = self::wpdb();
				if ( is_multisite() || \wpdb::class !== $wpdb::class || $wpdb->users !== $wpdb->prefix . 'users' || $wpdb->usermeta !== $wpdb->prefix . 'usermeta' || file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
					$blockers[] = 'wordpress_environment';
				}
				if ( wp_using_ext_object_cache() || file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
					$blockers[] = 'persistent_object_cache';
				}
				return $blockers;
			},
			$this->file_maintenance(),
			static function (): void {
				if ( ! wp_cache_flush() ) {
					throw new DatabaseFailed( 'The WordPress object cache could not be refreshed after the exchange.' );
				}
				Cron::ensure_heartbeat();
			}
		);
	}

	/**
	 * The cache directory.
	 *
	 * @return CacheDirectory Cache.
	 */
	public function cache(): CacheDirectory {
		return new CacheDirectory( $this->private_dir()->subdirectory( 'cache' ) );
	}

	/**
	 * Remembered results.
	 *
	 * @return SiteState State.
	 */
	public function state(): SiteState {
		return new SiteState( $this->options(), $this->state_lock() );
	}

	/**
	 * The lock for changes to the remembered results and to the notifications. One object for
	 * the request: a change that leads to another one takes the lock only once.
	 *
	 * @return FileLock Lock.
	 */
	private function state_lock(): FileLock {
		return $this->state_lock ??= $this->private_lock( 'state.lock' );
	}

	/**
	 * Claims the configured directory before a writer creates its lock file.
	 * Reading options and constructing services leaves the filesystem untouched.
	 *
	 * @param string $name Lock filename.
	 * @return FileLock Lock.
	 */
	private function private_lock( string $name ): FileLock {
		return new FileLock(
			function () use ( $name ): string {
				$directory = $this->private_dir();
				$directory->ensure();
				return $directory->path . '/' . $name;
			}
		);
	}

	/**
	 * HTTP client for the repository server, with the uploaded CA certificate if any.
	 *
	 * @param int|null $timeout Seconds per request; null for the setting that the jobs use.
	 * @return HttpClient Client.
	 */
	public function http( ?int $timeout = null ): HttpClient {
		// A missing configured trust bundle makes the HTTPS request fail.
		$ca = 'uploaded' === $this->settings()->string( 'repository.ca_certificate' ) ? $this->private_dir()->path . '/ca.pem' : null;
		return new WpHttpClient( $timeout ?? $this->settings()->int( 'advanced.upload_timeout_seconds' ), $ca );
	}

	/**
	 * Repository access.
	 *
	 * @return RepositoryAccess Access.
	 */
	public function access(): RepositoryAccess {
		return new RepositoryAccess( $this->settings(), $this->secrets(), $this->http(), $this->random(), $this->user_agent() );
	}

	/**
	 * The snapshot browser of the REST routes and the command line. Its reader shares the
	 * index and tree cache of the jobs and takes a repository lock of its own for every read.
	 * This site's snapshots are those with its host name and the two tags that the retention
	 * selects them by (MaintenanceServices::retention_tags()). The browser gets the policy of
	 * the next forget, so that the list of this site's snapshots previews what it removes.
	 *
	 * @return SnapshotBrowser|null Browser, or null if the repository has to be unlocked first.
	 */
	public function snapshot_browser(): ?SnapshotBrowser {
		$repository = $this->access()->open_cached();
		if ( null === $repository ) {
			return null;
		}
		$this->private_dir()->ensure();
		// A request has PHP's memory limit as a job has: an index that does not fit is refused, not fatal.
		$repository->limit_memory( MemoryBudget::from_ini( ini_get( 'memory_limit' ) ) );
		$site   = $this->site();
		$cache  = $this->cache()->path . '/' . $repository->config()->id;
		$limit  = $this->settings()->int( 'advanced.cache_limit_mib' ) * 1048576;
		$reader = new Reader( $repository, $this->identity(), $this->clock(), new IndexCache( $cache, $limit ), new TreeCache( $cache, $limit ) );
		return new SnapshotBrowser( $reader, $site->hostname(), [ PlanFactory::TAG, $site->site_tag() ], RetentionSettings::preview( $this->settings() ), $this->clock() );
	}

	/**
	 * Connection tester for the wizard.
	 *
	 * @param HttpClient|null $http Client to test with; null for the one the jobs use, with the uploaded CA certificate if any.
	 * @return ConnectionTester Tester.
	 */
	public function tester( ?HttpClient $http = null ): ConnectionTester {
		return new ConnectionTester( $http ?? $this->http(), $this->random(), $this->user_agent() );
	}

	/**
	 * Facts about the site.
	 *
	 * @return SitePaths Site.
	 */
	public function site(): SitePaths {
		$uploads = wp_upload_dir( null, false );
		return new SitePaths( rtrim( ABSPATH, '/' ), rtrim( WP_CONTENT_DIR, '/' ), rtrim( $uploads['basedir'], '/' ), home_url(), self::wpdb()->prefix, get_bloginfo( 'version' ), Plugin::VERSION );
	}

	/**
	 * WordPress' database object.
	 *
	 * @return \wpdb Database.
	 * @throws \LogicException If WordPress has not set it up.
	 */
	public static function wpdb(): \wpdb {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \LogicException( 'WordPress has no database connection.' );
		}
		return $wpdb;
	}

	/**
	 * Who writes snapshots: the site's host, and the PHP user.
	 *
	 * @return Identity Identity.
	 */
	public function identity(): Identity {
		$uid  = getmyuid();
		$gid  = getmygid();
		$user = function_exists( 'posix_getpwuid' ) && false !== $uid ? posix_getpwuid( $uid ) : false;
		return new Identity(
			$this->site()->hostname(),
			is_array( $user ) ? $user['name'] : get_current_user(),
			false === $uid ? 0 : $uid,
			false === $gid ? 0 : $gid,
			'backups-for-restic/' . Plugin::VERSION
		);
	}

	/**
	 * The plan of a backup job.
	 *
	 * @param Job $job Job.
	 * @return BackupPlan Plan.
	 */
	public function plan( Job $job ): BackupPlan {
		$wpdb     = self::wpdb();
		$settings = $this->settings();
		/**
		 * Filters additional directories to back up.
		 *
		 * @param list<string> $roots Absolute paths from the settings.
		 */
		$roots = apply_filters( 'backups_for_restic_backup_roots', $settings->list( 'backup.extra_roots' ) );
		/**
		 * Filters additional exclude patterns.
		 *
		 * @param list<string> $excludes Patterns.
		 */
		$excludes = apply_filters( 'backups_for_restic_backup_excludes', [] );
		$database = null;
		if ( $settings->bool( 'backup.database.enabled' ) ) {
			/**
			 * Filters the tables left out of the database dump.
			 *
			 * @param list<string> $tables Table names from the settings.
			 */
			$exclude = apply_filters( 'backups_for_restic_database_tables_excluded', $settings->list( 'backup.database.exclude_tables' ) );
			$prefix  = 'all' === $settings->string( 'backup.database.table_scope' ) ? '' : $wpdb->prefix;
			$exclude = self::strings( $exclude );
			if ( '' === $prefix ) {
				foreach ( $this->jobs()->database_owners() as $owner ) {
					array_push( $exclude, ...DatabaseGeneration::exclusions( $this->database_connection(), $owner ) );
				}
			}
			$dump     = new DatabaseDump( new WpdbConnection( $wpdb ), BackupServices::dump_state( $this->jobs(), $job ), $prefix, $exclude, $job->created_at );
			$database = $dump->files( ... );
		}
		return ( new PlanFactory( $this->site(), $settings, $this->private_dir(), self::strings( $roots ), self::strings( $excludes ), $database, $job->id, $job->created_at ) )->plan();
	}

	/**
	 * The job runner with the backup, maintenance and restore steps.
	 *
	 * @return Runner Runner.
	 */
	public function runner(): Runner {
		$jobs        = $this->jobs();
		$services    = new BackupServices(
			$this->settings(),
			$this->access(),
			$this->private_dir(),
			$jobs,
			$this->state(),
			$this->clock(),
			$this->random(),
			$this->identity(),
			$this->plan( ... ),
			$this->exposure(),
			function ( Config $config ): void {
				if ( $this->repository_record()->opened( $config ) ) {
					// The plan of this very tick records the format version, and the snapshot list is another repository's.
					$this->refresh();
					delete_transient( 'backups_for_restic_snapshots' );
				}
			}
		);
		$backup      = new BackupSteps( $services );
		$maintenance = new MaintenanceSteps( new MaintenanceServices( $services, $this->switch_retention( ... ), $this->site()->site_tag() ), $backup );
		$restore     = new RestoreSteps( new RestoreServices( $services, $this->database_preview( ... ) ), $backup );
		return new Runner( $jobs, $this->runner_lock(), new JobSteps( $backup, $maintenance, $restore, $this->file_changes(), $this->database_changes( $this->private_dir(), $jobs ) ), $this->clock(), $this->random(), new JobEvents( $this ) );
	}

	/**
	 * Uses the WordPress connection without automatic retries during transactions.
	 *
	 * @return DatabasePreview Preview importer.
	 * @throws DatabaseFailed If the site's database adapter does not expose a mysqli handle.
	 */
	private function database_preview(): DatabasePreview {
		return new DatabasePreview( $this->database_connection(), self::wpdb()->prefix );
	}

	/**
	 * Obtains the single WordPress connection without its query retry machinery. After a
	 * lost reply the restore port closes the session; the next caller then gets a new
	 * WordPress connection, and nothing continues the old session's transaction or locks.
	 *
	 * @return MysqliConnection Current SQL session.
	 * @throws DatabaseFailed If a drop-in does not expose a usable mysqli handle.
	 */
	private function database_connection(): MysqliConnection {
		$wpdb   = self::wpdb();
		$handle = $wpdb->__get( 'dbh' );
		if ( $handle instanceof \mysqli && self::closed( $handle ) ) {
			if ( ! $wpdb->db_connect( false ) ) {
				DatabaseFailed::raise( 'The database connection could not be opened again.', [ 'code' => 'database_restore_busy' ] );
			}
			$handle = $wpdb->__get( 'dbh' );
		}
		if ( ! $handle instanceof \mysqli ) {
			throw new DatabaseFailed( 'Database preview requires the WordPress mysqli connection.' );
		}
		return new MysqliConnection( $handle );
	}

	/**
	 * Whether a mysqli handle was closed or its last call lost the server. PHP refuses every
	 * property of a closed handle, and mysqli never reconnects one that lost its session.
	 *
	 * @param \mysqli $handle WordPress connection.
	 * @return bool True if closed or gone.
	 */
	private static function closed( \mysqli $handle ): bool {
		try {
			return in_array( $handle->errno, [ 1153, 2006, 2013, 2055 ], true );
		} catch ( \Error ) {
			return true;
		}
	}

	/**
	 * Retries preview cleanup whose job reached a terminal state after a dropped connection
	 * or refused DROP. An uninstall may also finish an interrupted active preview. Each
	 * committed cursor is saved while the runner lock is held. Staging is unnecessary.
	 *
	 * @param bool                  $uninstall Include active restores during uninstall.
	 * @param float                 $deadline End of this request's cleanup budget.
	 * @param PrivateDirectory|null $directory An earlier working directory during uninstall.
	 * @return bool Whether cleanup finished under the runner lock.
	 */
	public function clean_database_previews( bool $uninstall, float $deadline, ?PrivateDirectory $directory = null ): bool {
		$directory ??= $this->private_dir();
		if ( ! $directory->owns_contents() || ! is_dir( $directory->subdirectory( 'jobs' ) ) ) {
			return true;
		}
		$lock = new RunnerLock( $directory->path . '/runner.lock' );
		if ( ! $lock->acquire() ) {
			return false;
		}
		try {
			$store = new JobStore( $directory->subdirectory( 'jobs' ), $this->redactor(), $this->random() );
			if ( $uninstall ) {
				foreach ( $store->maintenance_jobs() as $job ) {
					$this->file_maintenance()->release( $job->id, $store->directory( $job ) );
				}
			}
			foreach ( $store->pending_database_cleanups( $uninstall ) as $job ) {
				do {
					$done = $this->database_preview()->clean( $job, $deadline );
					$store->save( $job );
					if ( microtime( true ) >= $deadline ) {
						return false;
					}
				} while ( ! $done );
			}
			return $this->database_changes( $directory, $store )->expire( $uninstall, $deadline );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Which repository the plugin works with.
	 *
	 * @return RepositoryRecord Record.
	 */
	public function repository_record(): RepositoryRecord {
		return new RepositoryRecord( $this->settings_store(), $this->state(), $this->clock() );
	}

	/**
	 * The lock that keeps runners, and the heartbeat's notifications, apart.
	 *
	 * @return RunnerLock Lock.
	 */
	public function runner_lock(): RunnerLock {
		$this->private_dir()->ensure();
		return new RunnerLock( $this->private_dir()->path . '/runner.lock' );
	}

	/**
	 * Saves the retention mode after an automatic switch (ADR 0010).
	 *
	 * @param string $mode plugin or external.
	 */
	public function switch_retention( string $mode ): void {
		$this->settings_store()->update( [ 'retention.mode' => $mode ] );
		$this->refresh();
		// The cached list of this site's snapshots previews the mode from before the switch.
		delete_transient( 'backups_for_restic_snapshots' );
	}

	/**
	 * The retention modes, for the heartbeat's probe.
	 *
	 * @return RetentionGuard Guard.
	 */
	public function retention_guard(): RetentionGuard {
		return new RetentionGuard( $this->state(), $this->settings()->string( 'retention.mode' ), $this->switch_retention( ... ), $this->clock(), RetentionGuard::repository( $this->settings() ) );
	}

	/**
	 * Whether the server is append-only: the latest conclusive probe of this repository, else
	 * the wizard's answer.
	 *
	 * @return string yes, no or unknown.
	 */
	public function append_only(): string {
		return $this->retention_guard()->append_only( $this->settings()->string( 'repository.append_only' ) );
	}

	/**
	 * E-mail notifications.
	 *
	 * @return Notifier Notifier.
	 */
	public function notifier(): Notifier {
		return new Notifier( $this->options(), $this->state(), $this->settings(), new WpMailer( $this->settings() ), $this->state_lock(), $this->private_lock( 'notify.lock' ) );
	}

	/**
	 * HTTP probe of the private directory.
	 *
	 * @return WpExposureCheck Check.
	 */
	public function exposure(): WpExposureCheck {
		return new WpExposureCheck( $this->private_dir(), $this->state(), $this->random(), $this->browser_probe() );
	}

	/**
	 * The probe of the private directory from the administrator's browser.
	 *
	 * @return BrowserProbe Probe.
	 */
	public function browser_probe(): BrowserProbe {
		return new BrowserProbe( $this->private_dir(), $this->state(), $this->random(), $this->clock() );
	}

	/**
	 * Seconds one tick may spend: the setting, capped ten seconds below max_execution_time,
	 * and halved in the gentle CPU mode.
	 *
	 * @param float|null $requested Shorter budget, for example for admin polling.
	 * @return float Seconds.
	 */
	public function tick_budget( ?float $requested = null ): float {
		$budget = (float) $this->settings()->int( 'advanced.tick_budget_seconds' );
		if ( 'gentle' === $this->settings()->string( 'advanced.cpu_throttle' ) ) {
			$budget /= 2;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 5.0, $limit - 10.0 ) );
		}
		return null === $requested ? $budget : min( $budget, $requested );
	}

	/**
	 * Collects the environment for the Doctor.
	 *
	 * @param bool $fresh Re-run the HTTP probes instead of using cached answers.
	 * @return Environment Environment.
	 */
	public function environment( bool $fresh = false ): Environment {
		$missing = [];
		foreach ( [ 'openssl', 'json', 'mbstring', 'hash' ] as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}
		$salts = 0;
		foreach ( self::SALT_CONSTANTS as $constant ) {
			$value  = self::constant_string( $constant );
			$salts += '' !== $value && 'put your unique phrase here' !== $value ? 1 : 0;
		}
		$directory       = $this->private_dir();
		$directory_ready = false;
		$inside_web_root = true;
		try {
			$directory->ensure();
			$directory_ready = true;
			$probe           = $this->exposure();
			$inside_web_root = ! $probe->outside_web_root();
			$exposed         = $probe->exposed( $fresh );
		} catch ( \Throwable ) {
			$exposed = null;
		}
		return new Environment(
			$missing,
			function_exists( 'zstd_uncompress' ),
			self::bytes( ini_get( 'memory_limit' ) ),
			(int) ini_get( 'max_execution_time' ),
			$directory_ready ? $directory->free_space() : null,
			$exposed,
			$inside_web_root,
			$salts,
			defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' ),
			Cron::loopback_works( $fresh ),
			$this->access()->passwords_readable(),
			$this->settings()->int( 'repository.format_version' ),
			self::jit_enabled(),
			$this->repository_reached( $fresh ),
			$directory_ready
		);
	}

	/**
	 * Whether PHP's JIT compiler is at work in this request.
	 *
	 * OPcache says so itself where it may be asked. Where it may not, the settings are read:
	 * a mode of four digits whose last one is 0 compiles nothing, and on the command line
	 * OPcache is off unless it is switched on for it.
	 *
	 * @return bool True if the JIT compiles code of this request.
	 */
	private static function jit_enabled(): bool {
		if ( ! extension_loaded( 'Zend OPcache' ) ) {
			return false;
		}
		$status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns where opcache.restrict_api forbids the question; the settings answer it then.
		if ( is_array( $status ) && is_array( $status['jit'] ?? null ) && is_bool( $status['jit']['on'] ?? null ) ) {
			return $status['jit']['on'];
		}
		$mode = strtolower( trim( (string) ini_get( 'opcache.jit' ) ) );
		return filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN )
			&& ( 'cli' !== PHP_SAPI || filter_var( ini_get( 'opcache.enable_cli' ), FILTER_VALIDATE_BOOLEAN ) )
			&& ! in_array( $mode, [ '', '0', 'off', 'no', 'false', 'disable' ], true )
			&& 1 !== preg_match( '/\A\d{3}0\z/', $mode )
			&& self::bytes( (string) ini_get( 'opcache.jit_buffer_size' ) ) > 0;
	}

	/**
	 * Whether the repository server answers, cached for a day like the other probes. Any
	 * answer counts, also a refusal of the credentials or an error of the server: the question
	 * is whether this host lets the request out at all.
	 *
	 * The probe is one request with a short time limit of its own. With the time limit and
	 * the repetitions of the jobs, the page that is to explain an outage would wait minutes
	 * for it. What was found is kept for the address it was found for.
	 *
	 * @param bool $fresh Ask the server now.
	 * @return bool|null True if it answered, false if not, null if no repository is configured or none was asked yet.
	 */
	private function repository_reached( bool $fresh ): ?bool {
		$url = $this->settings()->string( 'repository.url' );
		if ( '' === $url ) {
			return null;
		}
		if ( ! $fresh ) {
			$cached = get_transient( 'backups_for_restic_reachable' );
			return is_array( $cached ) && $url === ( $cached['url'] ?? null ) && is_bool( $cached['reached'] ?? null ) ? $cached['reached'] : null;
		}
		try {
			$this->access()->probe_backend( $this->http( self::PROBE_SECONDS ) )->size( ObjectType::Config, 'config' );
			$reached = true;
		} catch ( SecretsUndecryptable | InvalidArgument ) {
			// Nothing was sent: the password cannot be read, or the address is none to ask.
			return null;
		} catch ( BackendException $e ) {
			// A refusal of the credentials is an answer, and so is every other status; without
			// one the request did not get there.
			$reached = $e instanceof Unauthorized || isset( $e->context()['status'] );
		} catch ( PluginException ) {
			$reached = true;
		}
		set_transient(
			'backups_for_restic_reachable',
			[
				'url'     => $url,
				'reached' => $reached,
			],
			DAY_IN_SECONDS
		);
		return $reached;
	}

	/**
	 * Runs the Doctor.
	 *
	 * @param bool $fresh Re-run the HTTP probes.
	 * @return list<\BackupsForRestic\Doctor\Check> Results.
	 */
	public function doctor( bool $fresh = false ): array {
		return Doctor::check( $this->environment( $fresh ) );
	}

	/**
	 * User-Agent of repository requests.
	 *
	 * @return string User-Agent.
	 */
	public function user_agent(): string {
		return 'backups-for-restic/' . Plugin::VERSION;
	}

	/**
	 * Values of defined constants.
	 *
	 * @param list<string> $names Constant names.
	 * @return array<string, string> Values by name.
	 */
	private static function constants( array $names ): array {
		$values = [];
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$values[ $name ] = self::constant_string( $name );
			}
		}
		return $values;
	}

	/**
	 * A constant's value as text.
	 *
	 * @param string $name Constant name.
	 * @return string Value, or an empty string if undefined or not scalar.
	 */
	public static function constant_string( string $name ): string {
		$value = defined( $name ) ? constant( $name ) : null;
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Keeps the strings of a filtered value.
	 *
	 * @param mixed $value Filter result.
	 * @return list<string> Strings.
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * Parses a php.ini size.
	 *
	 * @param string $value Value such as 128M.
	 * @return int Bytes, or -1 for unlimited.
	 */
	public static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		return wp_convert_hr_to_bytes( $value );
	}
}
