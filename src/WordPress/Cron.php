<?php
/**
 * Ticks and the heartbeat.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\TickReport;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Drives jobs with WP-Cron (ADR 0011): a tick event that the runner chains with loopback
 * requests while work remains, and a five-minute heartbeat that starts scheduled backups and
 * picks up stalled jobs.
 */
final class Cron {

	public const string TICK      = 'backups_for_restic_tick';
	public const string HEARTBEAT = 'backups_for_restic_heartbeat';
	public const string INTERVAL  = 'backups_for_restic_five_minutes';

	/**
	 * Registers the hooks.
	 *
	 * @param Container $container Services.
	 */
	public static function register( Container $container ): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The heartbeat runs every five minutes on purpose (ADR 0011).
		add_action(
			self::TICK,
			static function () use ( $container ): void {
				self::tick( $container );
			}
		);
		add_action(
			self::HEARTBEAT,
			static function () use ( $container ): void {
				self::heartbeat( $container );
			}
		);
		add_action( 'init', [ self::class, 'ensure_heartbeat' ] );
	}

	/**
	 * Adds the five-minute interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}> Schedules.
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::INTERVAL ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (backups)', 'backups-for-restic' ),
		];
		return $schedules;
	}

	/**
	 * Schedules the heartbeat if it is missing, for example after a migration.
	 */
	public static function ensure_heartbeat(): void {
		if ( false === wp_next_scheduled( self::HEARTBEAT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HEARTBEAT );
		}
	}

	/**
	 * Removes the scheduled events, on deactivation and uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HEARTBEAT );
		wp_clear_scheduled_hook( self::TICK );
	}

	/**
	 * Runs one tick and chains the next one while work remains.
	 *
	 * @param Container  $container Services.
	 * @param float|null $budget    Shorter budget, for example for admin polling.
	 * @param bool       $chain     Whether to start the next tick with a loopback request.
	 * @return TickReport What happened.
	 */
	public static function tick( Container $container, ?float $budget = null, bool $chain = true ): TickReport {
		wp_raise_memory_limit( 'backups_for_restic' );
		ignore_user_abort( true );
		$budget = $container->tick_budget( $budget );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) ceil( $budget ) + 30 );
		}
		$report = $container->runner()->tick( $budget );
		if ( $chain && $report->should_chain() && 'off' !== $container->settings()->string( 'advanced.loopback_chaining' ) ) {
			self::chain();
		}
		return $report;
	}

	/**
	 * Starts the next tick soon: schedules the tick event and requests wp-cron.php without
	 * waiting. Unlike spawn_cron(), this also works from inside a wp-cron.php request and more
	 * than once a minute; the running cron request notices the new lock holder and stops
	 * after the current event, as WordPress intends.
	 */
	public static function chain(): void {
		if ( false === wp_next_scheduled( self::TICK ) ) {
			wp_schedule_single_event( time(), self::TICK );
		}
		$key = sprintf( '%.22F', microtime( true ) );
		set_transient( 'doing_cron', $key );
		wp_remote_post(
			add_query_arg( 'doing_wp_cron', $key, site_url( 'wp-cron.php' ) ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for requests to the site itself.
			]
		);
	}

	/**
	 * Probes for append-only mode once a day, starts scheduled jobs, advances jobs whose chain
	 * broke, and sends the stale notice when it is due.
	 *
	 * @param Container $container Services.
	 */
	public static function heartbeat( Container $container ): void {
		if ( $container->settings()->bool( 'setup.completed' ) ) {
			self::exclusively( $container, static fn () => self::probe_append_only( $container, time() ) );
			self::start_due( $container, time() );
		}
		if ( null !== $container->jobs()->active() ) {
			self::tick( $container );
		}
		self::exclusively( $container, static fn () => $container->notifier()->heartbeat( time() ) );
	}

	/**
	 * Runs work under the runner lock: the probe and the notifier write the same state as a
	 * tick that reports a finished job, and must not race with it. When a tick holds the lock,
	 * the work waits for the next heartbeat.
	 *
	 * @param Container $container Services.
	 * @param \Closure  $work      Work.
	 */
	private static function exclusively( Container $container, \Closure $work ): void {
		$lock = $container->runner_lock();
		if ( ! $lock->acquire() ) {
			return;
		}
		try {
			$work();
		} finally {
			$lock->release();
		}
	}

	/**
	 * The daily append-only probe of ADR 0010. Forget and prune jobs probe as well, so this
	 * matters when neither runs: weekly backups, or retention mode external, where only the
	 * probe can notice that deletions became possible again. After an inconclusive answer it
	 * probes again an hour later.
	 *
	 * @param Container $container Services.
	 * @param int       $now       Unix time.
	 */
	private static function probe_append_only( Container $container, int $now ): void {
		$last     = $container->state()->get( 'append_only' );
		$interval = AppendOnlyStatus::Unknown->value === ( $last['status'] ?? null ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		if ( $now - Decoded::int( $last['checked_at'] ?? null ) < $interval ) {
			return;
		}
		try {
			$status = AppendOnlyProbe::detect( $container->access()->backend(), $container->random() );
		} catch ( PluginException ) {
			// Wrong credentials or no repository: the next job reports that with a hint.
			$status = AppendOnlyStatus::Unknown;
		}
		if ( $container->retention_guard()->detected( $status ) ) {
			$container->notifier()->retention_switched( $now );
		}
	}

	/**
	 * Starts the job that is due, if any: a scheduled backup first, then forget after a
	 * successful backup, then prune and check. One job at a time; whatever cannot start now
	 * starts at a later heartbeat. The first slot of each schedule after setup is only recorded.
	 *
	 * @param Container $container Services.
	 * @param int       $now       Unix time.
	 */
	private static function start_due( Container $container, int $now ): void {
		$settings = $container->settings();
		$state    = $container->state();
		$schedule = $state->get( 'schedule' );
		$zone     = wp_timezone();
		$plugin   = 'plugin' === $settings->string( 'retention.mode' );

		$slot    = SchedulePolicy::last_backup_slot( $settings, $zone, $now );
		$handled = Decoded::int( $schedule['backup_slot'] ?? null );
		if ( null !== $slot && $slot > $handled ) {
			// The first slot after setup is only recorded: the wizard ran the first backup.
			if ( 0 === $handled || self::start( $container, JobType::Backup, 'schedule', $now ) ) {
				$schedule['backup_slot'] = $slot;
				$state->set( 'schedule', $schedule );
			}
			return;
		}

		$last_backup = Decoded::string( $state->get( 'last_backup' )['job'] ?? null );
		if ( $plugin && $settings->bool( 'retention.run_forget_after_backup' ) && '' !== $last_backup && Decoded::string( $schedule['forget_for'] ?? null ) !== $last_backup ) {
			if ( self::start( $container, JobType::Forget, 'after_backup', $now ) ) {
				$schedule['forget_for'] = $last_backup;
				$state->set( 'schedule', $schedule );
			}
			return;
		}

		foreach ( [ JobType::Prune, JobType::Check ] as $type ) {
			if ( JobType::Prune === $type && ! $plugin ) {
				continue;
			}
			$key     = $type->value . '_slot';
			$slot    = SchedulePolicy::last_maintenance_slot( $settings, $zone, $now, $type->value );
			$handled = Decoded::int( $schedule[ $key ] ?? null );
			if ( null === $slot || $slot <= $handled ) {
				continue;
			}
			if ( 0 === $handled || self::start( $container, $type, 'schedule', $now ) ) {
				$schedule[ $key ] = $slot;
				$state->set( 'schedule', $schedule );
			}
			return;
		}
	}

	/**
	 * Creates a job unless another one is active.
	 *
	 * @param Container $container Services.
	 * @param JobType   $type      Job type.
	 * @param string    $trigger   Why the job starts.
	 * @param int       $now       Unix time.
	 * @return bool True if the job was created; false means the next heartbeat tries again.
	 */
	private static function start( Container $container, JobType $type, string $trigger, int $now ): bool {
		try {
			$container->jobs()->create( $type, $trigger, $now );
			return true;
		} catch ( JobConflict ) {
			return false;
		}
	}

	/**
	 * Whether the site can request its own wp-cron.php, cached for a day.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return bool|null True if it works, false if blocked, null if not yet known.
	 */
	public static function loopback_works( bool $fresh = false ): ?bool {
		$cached = get_transient( 'backups_for_restic_loopback' );
		if ( ! $fresh && in_array( $cached, [ 'yes', 'no' ], true ) ) {
			return 'yes' === $cached;
		}
		if ( ! $fresh ) {
			return null;
		}
		$response = wp_remote_get(
			site_url( 'wp-cron.php' ),
			[
				'timeout'   => 10,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for requests to the site itself.
			]
		);
		$works    = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		set_transient( 'backups_for_restic_loopback', $works ? 'yes' : 'no', DAY_IN_SECONDS );
		return $works;
	}
}
