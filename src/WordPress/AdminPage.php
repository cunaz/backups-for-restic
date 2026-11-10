<?php
/**
 * The Backups admin page.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One admin page with tabs (ADR 0012): the server renders the frame, plain JavaScript on
 * core's wp-api-fetch fills it from the REST API. Without JavaScript the page says so; every
 * action needs the REST API anyway.
 */
final class AdminPage {

	public const string SLUG = 'backups-for-restic';

	private const array TABS = [ 'dashboard', 'snapshots', 'settings', 'environment', 'logs' ];

	/**
	 * Registers the menu and assets.
	 *
	 * @param Container $container Services.
	 */
	public static function register( Container $container ): void {
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				/** This filter is documented in src/WordPress/RestRoutes.php */
				$capability = apply_filters( 'backups_for_restic_capability', 'manage_options' );
				add_menu_page(
					__( 'Backups', 'backups-for-restic' ),
					__( 'Backups', 'backups-for-restic' ),
					is_string( $capability ) ? $capability : 'manage_options',
					self::SLUG,
					static function () use ( $container ): void {
						self::render( $container );
					},
					'dashicons-backup',
					80
				);
			}
		);
		add_action(
			'admin_notices',
			static function () use ( $container ): void {
				self::render_check_alert( $container );
			}
		);
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ) use ( $container ): void {
				if ( 'toplevel_page_' . self::SLUG === $hook ) {
					self::enqueue( $container );
				}
			}
		);
	}

	/**
	 * The persistent notice of a check that found damage (ADR 0022), on every admin screen
	 * except the plugin's own page, whose dashboard lists the findings. It stays until a check
	 * finds no errors.
	 *
	 * @param Container $container Services.
	 */
	private static function render_check_alert( Container $container ): void {
		$screen = get_current_screen();
		if ( ! RestRoutes::allowed() || ( null !== $screen && 'toplevel_page_' . self::SLUG === $screen->id ) ) {
			return;
		}
		if ( [] === $container->state()->get( 'check_alert' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Backups: the repository check found damage.', 'backups-for-restic' ),
			esc_html__( 'Some snapshots may not restore completely.', 'backups-for-restic' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Show the findings', 'backups-for-restic' )
		);
	}

	/**
	 * Loads script, styles and translations.
	 *
	 * @param Container $container Services.
	 */
	private static function enqueue( Container $container ): void {
		$base = plugin_dir_url( $container->plugin_file );
		wp_enqueue_style( 'backups-for-restic-admin', $base . 'assets/css/admin.css', [], Plugin::VERSION );
		wp_enqueue_script(
			'backups-for-restic-admin',
			$base . 'assets/js/admin.js',
			[ 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ],
			Plugin::VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_set_script_translations( 'backups-for-restic-admin', 'backups-for-restic' );
		wp_add_inline_script(
			'backups-for-restic-admin',
			'window.backupsForRestic = ' . wp_json_encode(
				[
					'setupCompleted' => $container->settings()->bool( 'setup.completed' ),
					'pageUrl'        => admin_url( 'admin.php?page=' . self::SLUG ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the frame of the page.
	 *
	 * @param Container $container Services.
	 */
	private static function render( Container $container ): void {
		$setup = $container->settings()->bool( 'setup.completed' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of the visible tab.
		$requested = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tab       = in_array( $requested, self::TABS, true ) ? $requested : 'dashboard';
		$labels    = [
			'dashboard'   => __( 'Dashboard', 'backups-for-restic' ),
			'snapshots'   => __( 'Snapshots', 'backups-for-restic' ),
			'settings'    => __( 'Settings', 'backups-for-restic' ),
			'environment' => __( 'Environment', 'backups-for-restic' ),
			'logs'        => __( 'Logs', 'backups-for-restic' ),
		];
		?>
		<div class="wrap backups-for-restic">
			<h1><?php esc_html_e( 'Backups', 'backups-for-restic' ); ?></h1>
			<?php if ( $setup ) : ?>
				<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Backup pages', 'backups-for-restic' ); ?>">
					<?php foreach ( $labels as $id => $label ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $id ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
			<noscript>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'This page needs JavaScript.', 'backups-for-restic' ); ?></p></div>
			</noscript>
			<div id="backups-for-restic-app" data-view="<?php echo esc_attr( $setup ? $tab : 'setup' ); ?>" aria-live="polite">
				<p class="backups-for-restic-loading"><?php esc_html_e( 'Loading…', 'backups-for-restic' ); ?></p>
			</div>
		</div>
		<?php
	}
}
