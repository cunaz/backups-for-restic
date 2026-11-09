<?php
/**
 * WP-CLI commands.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;

defined( 'ABSPATH' ) || exit;

/**
 * Manages backups from the command line (docs/02-architecture.md §13.3). Optional: every
 * command has an equivalent in the admin area.
 */
final class CliCommand {

	/**
	 * Registers the command.
	 *
	 * @param Container $container Services.
	 */
	public static function register( Container $container ): void {
		\WP_CLI::add_command( 'backups-for-restic', new self( $container ) );
	}

	/**
	 * Creates the command.
	 *
	 * @param Container $container Services.
	 */
	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Checks whether this server can run backups.
	 */
	public function doctor(): void {
		$blocked = false;
		foreach ( $this->container->doctor( true ) as $check ) {
			$texts   = Messages::check( $check );
			$blocked = $blocked || 'critical' === $check->status;
			\WP_CLI::line( sprintf( '[%s] %s %s', $check->status, $texts['label'], $texts['description'] ) );
		}
		if ( $blocked ) {
			\WP_CLI::error( __( 'This server cannot run backups yet.', 'backups-for-restic' ) );
		}
		\WP_CLI::success( __( 'This server can run backups.', 'backups-for-restic' ) );
	}

	/**
	 * Starts a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the backup in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function backup( array $args, array $assoc_args ): void {
		try {
			$job = $this->container->jobs()->create( JobType::Backup, 'cli', time() );
		} catch ( JobConflict ) {
			\WP_CLI::error( Messages::job_error( 'job_conflict' )['message'] );
		}
		/* translators: %s: job ID. */
		\WP_CLI::line( sprintf( __( 'Job %s created.', 'backups-for-restic' ), $job->id ) );
		if ( isset( $assoc_args['wait'] ) ) {
			$this->run_until_idle( 300.0 );
			$this->report( $job->id );
			return;
		}
		Cron::chain();
	}

	/**
	 * Advances the active job.
	 *
	 * ## OPTIONS
	 *
	 * [--until-idle]
	 * : Keep going until no job is left.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function tick( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['until-idle'] ) ) {
			$this->run_until_idle( $this->container->tick_budget() );
			return;
		}
		$report = Cron::tick( $this->container, null, false );
		\WP_CLI::line( $report->status . ( null === $report->job ? '' : ' ' . $report->job->id . ' ' . $report->job->state->value . ' ' . $report->job->step ) );
	}

	/**
	 * Shows the last backup and the active job.
	 */
	public function status(): void {
		$last   = $this->container->state()->get( 'last_backup' );
		$active = $this->container->jobs()->active();
		\WP_CLI::line( sprintf( '%s: %s', __( 'Last backup', 'backups-for-restic' ), is_int( $last['finished'] ?? null ) ? gmdate( 'Y-m-d H:i:s', $last['finished'] ) . ' UTC' : '-' ) );
		\WP_CLI::line( sprintf( '%s: %s', __( 'Active job', 'backups-for-restic' ), null === $active ? '-' : $active->id . ' (' . Messages::step( $active->step ) . ')' ) );
	}

	/**
	 * Lists the snapshots in the repository.
	 */
	public function snapshots(): void {
		try {
			$repository = $this->container->access()->open_cached();
			if ( null === $repository ) {
				\WP_CLI::error( Messages::job_error( 'configuration' )['message'] );
			}
			foreach ( $repository->snapshots() as $id => $snapshot ) {
				\WP_CLI::line( sprintf( '%s  %s  %s', substr( $id, 0, 8 ), $snapshot->time, $snapshot->hostname ) );
			}
		} catch ( PluginException $e ) {
			\WP_CLI::error( Messages::job_error( JobError::from_throwable( $e )->code )['message'] );
		}
	}

	/**
	 * Ticks until no job is left.
	 *
	 * @param float $budget Seconds per tick.
	 */
	private function run_until_idle( float $budget ): void {
		do {
			$report = $this->container->runner()->tick( $budget );
			if ( null !== $report->job ) {
				\WP_CLI::line( $report->job->state->value . ' ' . Messages::step( $report->job->step ) );
			}
			if ( 'waiting' === $report->status || 'busy' === $report->status ) {
				sleep( 5 );
			}
		} while ( $report->has_more_work() );
	}

	/**
	 * Prints how a job ended.
	 *
	 * @param string $id Job ID.
	 */
	private function report( string $id ): void {
		$job = $this->container->jobs()->get( $id );
		if ( null === $job || null !== $job->error ) {
			\WP_CLI::error( Messages::job_error( $job?->error->code ?? 'internal' )['message'] );
		}
		/* translators: %s: snapshot ID. */
		\WP_CLI::success( sprintf( __( 'Backup finished: snapshot %s.', 'backups-for-restic' ), substr( (string) ( $job->result['snapshot'] ?? '' ), 0, 8 ) ) );
	}
}
