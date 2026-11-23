<?php
/**
 * Boots like WordPress up to wp_maintenance(), for FileMaintenanceTest: wp-settings.php has
 * loaded load.php and plugin.php by then, but no database, no options, no cron functions
 * and no plugin autoloader. The marker is required the way wp_is_maintenance_mode()
 * requires it. Afterwards the hooks that it added are applied the way core applies them
 * later in the same request, against a fixed list of active plugins and a fixed cron array.
 *
 * Usage: php boot.php <request.json>. Prints the outcome as JSON.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

$request = json_decode( (string) file_get_contents( $argv[1] ), true, 32, JSON_THROW_ON_ERROR );

define( 'ABSPATH', $request['root'] . '/' );
if ( $request['cli'] ) {
	define( 'WP_CLI', true );
}
if ( $request['doing_cron'] ) {
	define( 'DOING_CRON', true );
}
$GLOBALS['argv'] = $request['argv'];
$_SERVER         = array_merge( $_SERVER, $request['server'] );
$_GET            = $request['get'];
$_POST           = $request['post'];
$_COOKIE         = $request['cookies'];
$hooks           = [];

/**
 * The part of WP_Hook that the gate relies on: priorities, then the order of addition.
 */
function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['hooks'][ $hook_name ][ $priority ][] = [ $callback, $accepted_args ];
	return true;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$callbacks = $GLOBALS['hooks'][ $hook_name ] ?? [];
	ksort( $callbacks );
	foreach ( $callbacks as $callbacks_at_priority ) {
		foreach ( $callbacks_at_priority as [ $callback, $accepted_args ] ) {
			$value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted_args ) );
		}
	}
	return $value;
}

function wp_doing_cron(): bool {
	return apply_filters( 'wp_doing_cron', defined( 'DOING_CRON' ) && DOING_CRON );
}

$upgrading = null;
require ABSPATH . '.maintenance';

// cron.php loads after the maintenance check.
if ( ! function_exists( '_get_cron_array' ) ) {
	function _get_cron_array(): array {
		return $GLOBALS['request']['cron'];
	}
}

/**
 * get_option( 'active_plugins' ).
 */
function active_plugins(): mixed {
	$pre = apply_filters( 'pre_option_active_plugins', false, 'active_plugins', false );
	return false === $pre ? $GLOBALS['request']['active_plugins'] : $pre;
}

/**
 * Whether update_option( 'active_plugins', $value ) reaches the database.
 */
function stores( mixed $value ): bool {
	$old_value = active_plugins();
	$value     = apply_filters( 'pre_update_option_active_plugins', $value, $old_value, 'active_plugins' );
	$value     = apply_filters( 'pre_update_option', $value, 'active_plugins', $old_value );
	return $value !== $old_value && serialize( $value ) !== serialize( $old_value );
}

// What activate_plugin() and deactivate_plugins() store; the REST plugins endpoint calls them.
$activated   = active_plugins();
$activated[] = 'another/another.php';
sort( $activated );
$deactivated = array_values( array_diff( active_plugins(), [ 'backups-for-restic/backups-for-restic.php' ] ) );

echo json_encode(
	[
		'upgrading' => $upgrading,
		'hooks'     => array_keys( $hooks ),
		'plugins'   => active_plugins(),
		'stores'    => [
			'activate_plugin'    => stores( $activated ),
			'deactivate_plugins' => stores( $deactivated ),
			'update_option'      => stores( [] ),
		],
		'ready'     => apply_filters( 'pre_get_ready_cron_jobs', null ),
		'theme'     => apply_filters( 'template_directory', '/themes/site' ),
	],
	JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
);
