<?php
/**
 * Composition root of the plugin.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic;

use BackupsForRestic\WordPress\AdminPage;
use BackupsForRestic\WordPress\CliCommand;
use BackupsForRestic\WordPress\Container;
use BackupsForRestic\WordPress\Cron;
use BackupsForRestic\WordPress\RestRoutes;
use BackupsForRestic\WordPress\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Multisite is refused until 1.1 (ADR 0026). Options that a
 * newer version of the plugin wrote keep this version inert (docs/02-architecture.md §18).
 */
final class Plugin {

	public const string VERSION = '0.1.0-dev';

	/**
	 * Registers the hooks the plugin needs.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function boot( string $plugin_file ): void {
		register_activation_hook( $plugin_file, [ self::class, 'activate' ] );
		register_deactivation_hook(
			$plugin_file,
			static function () use ( $plugin_file ): void {
				self::deactivate( $plugin_file );
			}
		);

		if ( is_multisite() ) {
			// Activated by other means (must-use plugin, WP-CLI): stay inert and say why.
			add_action( 'network_admin_notices', [ self::class, 'render_multisite_notice' ] );
			add_action( 'admin_notices', [ self::class, 'render_multisite_notice' ] );
			return;
		}

		$container = new Container( $plugin_file );
		$schema    = $container->schema_version();
		if ( ! $schema->upgrade() ) {
			// No hook may interpret settings whose layout is unknown or was not recorded.
			$notice = $schema->is_supported() ? 'render_schema_write_notice' : 'render_unsupported_data_notice';
			add_action( 'admin_notices', [ self::class, $notice ] );
			return;
		}

		Cron::register( $container );
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				( new RestRoutes( $container ) )->register();
			}
		);
		if ( is_admin() ) {
			AdminPage::register( $container );
		}
		SiteHealth::register( $container );
		if ( defined( 'WP_CLI' ) && true === constant( 'WP_CLI' ) ) {
			CliCommand::register( $container );
		}
	}

	/**
	 * Activation: refuse multisite, schedule the heartbeat.
	 */
	public static function activate(): void {
		self::refuse_multisite_activation();
		Cron::ensure_heartbeat();
	}

	/**
	 * Deactivation: remove the scheduled events and end the active job, so that its lock does
	 * not stay in the repository. Deactivation itself never fails over this: a lock that
	 * could not be released turns stale after half an hour, and what is left of the job is
	 * cleaned up when the plugin is activated again.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function deactivate( string $plugin_file ): void {
		Cron::unschedule();
		if ( is_multisite() ) {
			return;
		}
		try {
			$container = new Container( $plugin_file );
			if ( ! $container->schema_version()->is_supported() ) {
				return;
			}
			Cron::stop( $container );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Aborts activation on multisite, network-wide and per site (ADR 0026).
	 *
	 * Per-site activation is refused on purpose: a site administrator must never be able to
	 * back up or restore the whole network.
	 */
	public static function refuse_multisite_activation(): void {
		if ( ! is_multisite() ) {
			return;
		}

		wp_die(
			esc_html__( 'This plugin does not support multisite networks yet. Network support is planned for version 1.1.', 'backups-for-restic' ),
			esc_html__( 'Plugin activation refused', 'backups-for-restic' ),
			[ 'back_link' => true ]
		);
	}

	/**
	 * Tells administrators that the plugin is inert because it cannot read the options layout.
	 * Retention must not delete snapshots by settings it may misread.
	 */
	public static function render_unsupported_data_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The backup plugin is inactive because its settings have a newer or invalid layout number. Install a compatible plugin version or restore damaged settings from a verified backup. Do not lower or delete the layout number; no backups run until it can be read.', 'backups-for-restic' )
		);
	}

	/**
	 * Tells administrators that the layout number could not be recorded.
	 */
	public static function render_schema_write_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The backup plugin is inactive because the layout number of its settings could not be saved. Check that the database accepts writes; no backups run until then.', 'backups-for-restic' )
		);
	}

	/**
	 * Tells super administrators that the plugin is inert on this network.
	 */
	public static function render_multisite_notice(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The backup plugin is inactive because multisite networks are not supported yet. Please deactivate it.', 'backups-for-restic' )
		);
	}
}
