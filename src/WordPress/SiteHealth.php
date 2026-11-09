<?php
/**
 * Site Health tests and debug information.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Doctor\Check;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Adds backup freshness and the environment checks to Tools → Site Health
 * (docs/02-architecture.md §13.4).
 */
final class SiteHealth {

	/**
	 * Registers the filters.
	 *
	 * @param Container $container Services.
	 */
	public static function register( Container $container ): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ) use ( $container ): array {
				$direct                                   = is_array( $tests['direct'] ?? null ) ? $tests['direct'] : [];
				$direct['backups_for_restic_backup']      = [
					'label' => __( 'Backups', 'backups-for-restic' ),
					'test'  => static fn (): array => self::freshness( $container ),
				];
				$direct['backups_for_restic_environment'] = [
					'label' => __( 'Backup environment', 'backups-for-restic' ),
					'test'  => static fn (): array => self::environment( $container ),
				];
				$direct['backups_for_restic_check']       = [
					'label' => __( 'Backup repository check', 'backups-for-restic' ),
					'test'  => static fn (): array => self::repository_check( $container ),
				];
				$tests['direct']                          = $direct;
				return $tests;
			}
		);
		add_filter(
			'debug_information',
			static fn ( array $information ): array => self::debug( $container, $information )
		);
	}

	/**
	 * Whether a recent backup exists.
	 *
	 * @param Container $container Services.
	 * @return array<string, mixed> Site Health result.
	 */
	private static function freshness( Container $container ): array {
		$settings = $container->settings();
		$last     = $container->state()->get( 'last_backup' );
		$finished = is_int( $last['finished'] ?? null ) ? $last['finished'] : 0;
		$limit    = SchedulePolicy::stale_after( $settings );
		$since    = 0 === $finished ? (int) strtotime( $settings->string( 'setup.recovery_kit_acknowledged_at' ) ) : $finished;
		if ( ! $settings->bool( 'setup.completed' ) ) {
			return self::result( 'backups_for_restic_backup', 'recommended', __( 'Backups are not set up', 'backups-for-restic' ), __( 'Finish the setup wizard to back up files and database.', 'backups-for-restic' ) );
		}
		if ( null !== $limit && $since < time() - $limit ) {
			return self::result(
				'backups_for_restic_backup',
				'critical',
				__( 'The last backup is too old', 'backups-for-restic' ),
				0 === $finished
					? __( 'No backup has finished yet.', 'backups-for-restic' )
					/* translators: %s: human-readable time difference such as "3 days". */
					: sprintf( __( 'The last backup finished %s ago.', 'backups-for-restic' ), human_time_diff( $finished ) )
			);
		}
		return self::result(
			'backups_for_restic_backup',
			'good',
			__( 'Backups are recent', 'backups-for-restic' ),
			0 === $finished
				? ''
				/* translators: %s: human-readable time difference such as "5 hours". */
				: sprintf( __( 'The last backup finished %s ago.', 'backups-for-restic' ), human_time_diff( $finished ) )
		);
	}

	/**
	 * The result of the last repository check (ADR 0022).
	 *
	 * @param Container $container Services.
	 * @return array<string, mixed> Site Health result.
	 */
	private static function repository_check( Container $container ): array {
		$state = $container->state();
		$alert = $state->get( 'check_alert' );
		if ( [] !== $alert ) {
			$errors = Decoded::int( $alert['errors'] ?? null );
			return self::result(
				'backups_for_restic_check',
				'critical',
				__( 'The backup repository is damaged', 'backups-for-restic' ),
				sprintf(
					/* translators: 1: number of errors, 2: human-readable time difference such as "3 days". */
					_n( 'The repository check %2$s ago found %1$d error. Some snapshots may not restore completely; the backup dashboard lists the findings.', 'The repository check %2$s ago found %1$d errors. Some snapshots may not restore completely; the backup dashboard lists the findings.', $errors, 'backups-for-restic' ),
					$errors,
					human_time_diff( Decoded::int( $alert['at'] ?? null ) )
				)
			);
		}
		$finished = Decoded::int( $state->get( 'last_check' )['finished'] ?? null );
		return self::result(
			'backups_for_restic_check',
			'good',
			__( 'The backup repository passed its last check', 'backups-for-restic' ),
			0 === $finished
				? __( 'No check has run yet. The schedule checks the repository once a month by default.', 'backups-for-restic' )
				/* translators: %s: human-readable time difference such as "5 days". */
				: sprintf( __( 'The last check finished %s ago without errors.', 'backups-for-restic' ), human_time_diff( $finished ) )
		);
	}

	/**
	 * The Doctor's findings, from cached probes.
	 *
	 * @param Container $container Services.
	 * @return array<string, mixed> Site Health result.
	 */
	private static function environment( Container $container ): array {
		$checks = $container->doctor();
		$worst  = 'good';
		$lines  = [];
		foreach ( $checks as $check ) {
			if ( Check::GOOD === $check->status ) {
				continue;
			}
			$worst   = Check::CRITICAL === $check->status || Check::CRITICAL === $worst ? 'critical' : 'recommended';
			$texts   = Messages::check( $check );
			$lines[] = '<li><strong>' . esc_html( $texts['label'] ) . '</strong> ' . esc_html( $texts['description'] ) . '</li>';
		}
		return self::result(
			'backups_for_restic_environment',
			$worst,
			'good' === $worst ? __( 'The server can run backups', 'backups-for-restic' ) : __( 'The backup environment needs attention', 'backups-for-restic' ),
			[] === $lines ? '' : '<ul>' . implode( '', $lines ) . '</ul>',
			true
		);
	}

	/**
	 * Debug information without secrets.
	 *
	 * @param Container    $container   Services.
	 * @param array<mixed> $information Debug sections.
	 * @return array<mixed> Debug sections.
	 */
	private static function debug( Container $container, array $information ): array {
		$settings                          = $container->settings();
		$last                              = $container->state()->get( 'last_backup' );
		$fields                            = [
			'version'     => [
				'label' => __( 'Plugin version', 'backups-for-restic' ),
				'value' => \BackupsForRestic\Plugin::VERSION,
			],
			'server'      => [
				'label' => __( 'Repository host', 'backups-for-restic' ),
				'value' => (string) wp_parse_url( $settings->string( 'repository.url' ), PHP_URL_HOST ),
			],
			'format'      => [
				'label' => __( 'Repository format', 'backups-for-restic' ),
				'value' => $settings->int( 'repository.format_version' ),
			],
			'append_only' => [
				'label' => __( 'Append-only', 'backups-for-restic' ),
				'value' => $container->append_only(),
			],
			'private_dir' => [
				'label'   => __( 'Working directory', 'backups-for-restic' ),
				'value'   => $container->private_dir()->path,
				'private' => true,
			],
			'last_backup' => [
				'label' => __( 'Last backup', 'backups-for-restic' ),
				'value' => is_int( $last['finished'] ?? null ) ? gmdate( 'c', $last['finished'] ) : __( 'None', 'backups-for-restic' ),
			],
		];
		$information['backups-for-restic'] = [
			'label'  => __( 'Backups', 'backups-for-restic' ),
			'fields' => $fields,
		];
		return $information;
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $test        Test ID.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Label.
	 * @param string $description Description; HTML only when $html is true.
	 * @param bool   $html        Whether the description is escaped HTML already.
	 * @return array<string, mixed> Result.
	 */
	private static function result( string $test, string $status, string $label, string $description, bool $html = false ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Backups', 'backups-for-restic' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			],
			'description' => '' === $description ? '' : ( $html ? $description : '<p>' . esc_html( $description ) . '</p>' ),
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . AdminPage::SLUG ) ) . '">' . esc_html__( 'Open the backup dashboard', 'backups-for-restic' ) . '</a></p>',
			'test'        => $test,
		];
	}
}
