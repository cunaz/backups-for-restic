<?php
/**
 * Early WordPress boot policy while a live file job is changing the installation.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress requires .maintenance before loading extensions. This file is reached from
 * the job-owned marker and must work without the plugin autoloader or database.
 */
final class FileMaintenanceGate {

	/** The plugin's WP-CLI command. CliCommand registers it under this name. */
	public const string COMMAND = 'backups-for-restic';

	/**
	 * Called by the marker. Only the controller's requests may boot before cleanup.
	 *
	 * @param string $id Active job ID.
	 * @param string $grant SHA-256 of the opaque control token, empty for CLI jobs.
	 * @param string $plugin Plugin basename.
	 * @param string $api Absolute namespace URL as computed by this installation.
	 * @param string $admin Absolute admin.php URL of the plugin.
	 * @param int    $updated Last successful heartbeat of the marker.
	 * @return float Timestamp for core's maintenance check; zero admits this request.
	 */
	public static function stamp( string $id, string $grant, string $plugin, string $api, string $admin, int $updated ): float {
		// An expired marker must relinquish the cron queue and extension bootstrap
		// as well as allowing normal public requests through core's grace period.
		if ( time() - $updated >= 600 ) {
			return (float) $updated;
		}
		if ( defined( 'WP_CLI' ) && true === WP_CLI ) {
			// WP-CLI switches core's maintenance check off for every command, so a command
			// other than this plugin's boots as during core's own maintenance: with the
			// site's plugins and options, even when it defines DOING_CRON early.
			$admitted = self::own_command( $GLOBALS['argv'] ?? null );
		} else {
			$admitted = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || self::admitted( $id, $grant, $api, $admin, $_SERVER, $_GET, $_POST, $_COOKIE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- This controls early boot only. WordPress verifies the REST nonce at the route.
		}
		if ( ! $admitted ) {
			// A float still expires under core's ten-minute grace period. Core reserves
			// integer timestamps for its extension-editor scrape workflow.
			return (float) $updated;
		}
		// The controller must boot even half-way through replacing an active extension.
		// The filters affect this request only; the installed site's options stay intact.
		add_filter( 'pre_option_active_plugins', static fn (): array => [ $plugin ], PHP_INT_MAX );
		// update_option() compares a new list with this substitute, so activate_plugin(),
		// deactivate_plugins() or the REST plugins endpoint would store a list derived
		// from it. The stored list stays as it is.
		add_filter( 'pre_update_option_active_plugins', static fn ( mixed $value, mixed $old_value ): mixed => $old_value, PHP_INT_MAX, 2 );
		add_filter( 'template_directory', static fn (): string => __DIR__, PHP_INT_MAX );
		add_filter( 'stylesheet_directory', static fn (): string => __DIR__, PHP_INT_MAX );
		// Other extensions did not register their callbacks. Leave their due events
		// scheduled for the first normal cron boot after the job ends. A request that is
		// not a cron request can still run cron, for example with ALTERNATE_WP_CRON.
		add_filter( 'pre_get_ready_cron_jobs', self::ready_events( ... ), PHP_INT_MAX );
		return 0.0;
	}

	/**
	 * Releases only this plugin's due worker/heartbeat events during maintenance.
	 *
	 * @return array<int,array<mixed>> Due events by time.
	 */
	public static function ready_events(): array {
		$ready = [];
		foreach ( _get_cron_array() as $when => $hooks ) {
			if ( $when > microtime( true ) ) {
				break;
			}
			$ours = array_intersect_key(
				$hooks,
				[
					'backups_for_restic_tick'      => true,
					'backups_for_restic_heartbeat' => true,
				]
			);
			if ( [] !== $ours ) {
				$ready[ $when ] = $ours;
			}
		}
		return $ready;
	}

	/**
	 * Recognises this plugin's WP-CLI command before WordPress loads, from the same
	 * arguments and by the same rules as WP-CLI's own parser: the script name and a
	 * leading alias such as "@staging" are not part of the command, and every word that
	 * starts with "--" and a name is a parameter (--path=…, --url=…, --user=…,
	 * --skip-plugins, --skip-themes=…, --allow-root, --no-color), wherever it stands.
	 * The first other word names the command.
	 *
	 * @param mixed $argv Process arguments as WP-CLI reads them, $GLOBALS['argv'].
	 * @return bool Whether the command is this plugin's.
	 */
	public static function own_command( mixed $argv ): bool {
		if ( ! is_array( $argv ) || ! array_is_list( $argv ) ) {
			return false;
		}
		$words = array_slice( $argv, 1 );
		// WP_CLI\Configurator::ALIAS_REGEX.
		if ( isset( $words[0] ) && is_string( $words[0] ) && 1 === preg_match( '#^@[A-Za-z0-9-_\.\-]+$#', $words[0] ) ) {
			array_shift( $words );
		}
		foreach ( $words as $word ) {
			if ( ! is_string( $word ) ) {
				return false;
			}
			// WP_CLI\Configurator::extract_assoc() reads --name, --no-name and --name=value
			// as parameters; "--" alone and "--=value" are positional.
			if ( 1 !== preg_match( '/\A--[^=]/', $word ) ) {
				return self::COMMAND === $word;
			}
		}
		return false;
	}

	/**
	 * Checks the token and exact request before WordPress boot. Route authorization and
	 * the logged-in user's REST nonce remain the responsibility of WordPress afterwards.
	 *
	 * @param string                 $id Active job.
	 * @param string                 $grant Stored SHA-256 of the secret.
	 * @param string                 $api Configured REST namespace URL.
	 * @param string                 $admin Configured admin.php URL.
	 * @param array<array-key,mixed> $server Server environment.
	 * @param array<array-key,mixed> $query Parsed query.
	 * @param array<array-key,mixed> $body Parsed form body.
	 * @param array<array-key,mixed> $cookies Cookies.
	 * @return bool Whether to let this request reach the ordinary authenticated route.
	 */
	public static function admitted( string $id, string $grant, string $api, string $admin, array $server, array $query, array $body, array $cookies ): bool {
		$token  = $server['HTTP_X_BACKUPS_FOR_RESTIC_CONTROL'] ?? $cookies['bfr_file_control'] ?? null;
		$uri    = $server['REQUEST_URI'] ?? null;
		$method = $server['REQUEST_METHOD'] ?? null;
		// WordPress takes rest_route from a form body before the one in the address or the
		// path, so a body could send an admitted address to any other route.
		if ( ! is_string( $token ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $token ) || ! hash_equals( $grant, hash( 'sha256', $token ) ) || ! is_string( $uri ) || ! is_string( $method ) || isset( $query['_method'] ) || isset( $server['HTTP_X_HTTP_METHOD_OVERRIDE'] ) || isset( $body['rest_route'] ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() loads after the maintenance gate.
		$path = parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}
		if ( 'GET' === $method && $path === parse_url( $admin, PHP_URL_PATH ) && 'backups-for-restic' === ( $query['page'] ?? null ) && ! isset( $query['rest_route'] ) ) {
			return true;
		}
		$base = parse_url( $api, PHP_URL_PATH );
		if ( ! is_string( $base ) ) {
			return false;
		}
		$settings = parse_url( $api, PHP_URL_QUERY );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( is_string( $settings ) ) {
			parse_str( $settings, $configured );
			$prefix = $configured['rest_route'] ?? null;
			$route  = $query['rest_route'] ?? null;
			$front  = $path === $base || ( str_ends_with( $base, '/index.php' ) && $path === substr( $base, 0, -strlen( 'index.php' ) ) );
			if ( ! $front || ! is_string( $prefix ) || ! is_string( $route ) || ! str_starts_with( $route, rtrim( $prefix, '/' ) . '/' ) ) {
				return false;
			}
			$route = substr( $route, strlen( rtrim( $prefix, '/' ) ) );
		} else {
			if ( isset( $query['rest_route'] ) || ! str_starts_with( $path, rtrim( $base, '/' ) . '/' ) ) {
				return false;
			}
			$route = substr( $path, strlen( rtrim( $base, '/' ) ) );
		}
		return 'GET' === $method ? in_array( $route, [ '/status', '/restores', '/undo', '/jobs/' . $id, '/jobs/' . $id . '/log' ], true ) : 'POST' === $method && '/jobs/' . $id . '/cancel' === $route;
	}
}
