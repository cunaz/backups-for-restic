<?php
/**
 * The settings model.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every setting with its type, default and constraints, declared once
 * (docs/02-architecture.md §11). Secrets are not settings; they live in the SecretStore.
 */
final class Schema {

	/**
	 * Settings that a wp-config.php constant can override, by dotted path.
	 */
	/**
	 * Beginnings of the paths that an administrator may change after the setup, on the
	 * settings page and with WP-CLI. Everything else is written by the setup and the jobs.
	 */
	public const array EDITABLE = [ 'schedule.', 'retention.', 'backup.', 'notifications.', 'advanced.' ];

	/**
	 * Whether a setting may be changed after the setup.
	 *
	 * @param string $path Dotted path.
	 * @return bool True if the settings page and WP-CLI may change it.
	 */
	public static function editable( string $path ): bool {
		foreach ( self::EDITABLE as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public const array CONSTANTS = [
		'repository.url'           => 'BACKUPS_FOR_RESTIC_REPOSITORY_URL',
		'repository.rest_username' => 'BACKUPS_FOR_RESTIC_REST_USERNAME',
	];

	/**
	 * The retention floor of the secure defaults (docs/03-security-and-threat-model.md §13):
	 * keep-within is at least one day, or keep-last is at least 1. Keep-within counts back
	 * from the newest snapshot and protects what lies in its window from the rules that count
	 * snapshots (threat T-12); a window of a few hours holds little more than that newest one.
	 *
	 * @param string $within Keep-within duration such as 2d or 1y6m.
	 * @param int    $last   Number of newest snapshots that are always kept.
	 * @return bool True if the floor holds.
	 */
	public static function retention_floor( string $within, int $last ): bool {
		if ( $last >= 1 ) {
			return true;
		}
		preg_match_all( '/(\d+)([ymdh])/', $within, $parts, PREG_SET_ORDER );
		foreach ( $parts as [ , $number, $unit ] ) {
			if ( (int) $number >= ( 'h' === $unit ? 24 : 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * All fields by dotted path.
	 *
	 * @return array<string, Field> Fields.
	 */
	public static function fields(): array {
		return [
			'repository.server'                       => Field::choice( 'rest-server', [ 'rest-server', 'borgbase' ] ),
			'repository.url'                          => Field::text(),
			'repository.rest_username'                => Field::text( '', 255 ),
			'repository.ca_certificate'               => Field::choice( 'none', [ 'none', 'uploaded' ] ),
			'repository.append_only'                  => Field::choice( 'unknown', [ 'yes', 'no', 'unknown' ] ),
			'repository.format_version'               => Field::integer( 1, 1, 2 ),
			'repository.id'                           => Field::text( '', 64 ),
			'schedule.backup.frequency'               => Field::choice( 'daily', [ 'manual', 'twice_daily', 'daily', 'weekly' ] ),
			'schedule.backup.weekday'                 => Field::integer( 0, 0, 6 ),
			'schedule.backup.hour'                    => Field::integer( 3, 0, 23 ),
			'schedule.backup.minute'                  => Field::integer( 17, 0, 59 ),
			'schedule.prune.frequency'                => Field::choice( 'weekly', [ 'manual', 'weekly', 'monthly' ] ),
			'schedule.prune.weekday'                  => Field::integer( 0, 0, 6 ),
			'schedule.prune.hour'                     => Field::integer( 4, 0, 23 ),
			'schedule.check.frequency'                => Field::choice( 'monthly', [ 'manual', 'weekly', 'monthly' ] ),
			'schedule.check.day'                      => Field::integer( 1, 1, 28 ),
			'schedule.check.hour'                     => Field::integer( 5, 0, 23 ),
			'schedule.check.read_data_subset_percent' => Field::integer( 2, 0, 100 ),
			'retention.mode'                          => Field::choice( 'plugin', [ 'plugin', 'external' ] ),
			'retention.keep.last'                     => Field::integer( 2, 0, 10000 ),
			'retention.keep.daily'                    => Field::integer( 7, 0, 10000 ),
			'retention.keep.weekly'                   => Field::integer( 4, 0, 10000 ),
			'retention.keep.monthly'                  => Field::integer( 6, 0, 10000 ),
			'retention.keep.yearly'                   => Field::integer( 0, 0, 10000 ),
			'retention.keep.within'                   => Field::duration( '2d' ),
			'retention.run_forget_after_backup'       => Field::boolean( true ),
			'backup.extra_roots'                      => Field::list(),
			'backup.excludes'                         => Field::list(),
			'backup.exclude_caches'                   => Field::boolean( true ),
			'backup.database.enabled'                 => Field::boolean( true ),
			'backup.database.table_scope'             => Field::choice( 'prefix', [ 'prefix', 'all' ] ),
			'backup.database.exclude_tables'          => Field::list( 64 ),
			'notifications.email.on_failure'          => Field::boolean( true ),
			'notifications.email.on_stale'            => Field::boolean( true ),
			'notifications.email.stale_after_hours'   => Field::integer( 36, 1, 720 ),
			'notifications.email.recipients'          => Field::emails(),
			'advanced.tick_budget_seconds'            => Field::integer( 20, 5, 300 ),
			'advanced.pack_size_mib'                  => Field::integer( 16, 4, 64 ),
			'advanced.upload_timeout_seconds'         => Field::integer( 60, 10, 600 ),
			'advanced.cache_limit_mib'                => Field::integer( 256, 16, 4096 ),
			'advanced.cpu_throttle'                   => Field::choice( 'normal', [ 'normal', 'gentle' ] ),
			'advanced.loopback_chaining'              => Field::choice( 'auto', [ 'auto', 'off' ] ),
			'advanced.keep_jobs'                      => Field::integer( 30, 5, 500 ),
			'setup.completed'                         => Field::boolean( false ),
			'setup.recovery_kit_acknowledged_at'      => Field::text( '', 64 ),
		];
	}
}
