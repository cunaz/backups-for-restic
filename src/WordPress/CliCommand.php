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
	 * Removes this site's snapshots that the retention policy no longer keeps.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function forget( array $args, array $assoc_args ): void {
		$this->maintain( JobType::Forget, $assoc_args );
	}

	/**
	 * Deletes the packs that no snapshot uses.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function prune( array $args, array $assoc_args ): void {
		$this->maintain( JobType::Prune, $assoc_args );
	}

	/**
	 * Checks the repository and reads a share of its packs.
	 *
	 * ## OPTIONS
	 *
	 * [--read-data-subset=<percent>]
	 * : Share of packs to download and verify, 0 to 100. Default: the setting (2).
	 *
	 * [--wait]
	 * : Run the job in this process until it ends.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function check( array $args, array $assoc_args ): void {
		$data = [];
		if ( isset( $assoc_args['read-data-subset'] ) ) {
			$percent = filter_var(
				$assoc_args['read-data-subset'],
				FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => 0,
						'max_range' => 100,
					],
				]
			);
			if ( false === $percent ) {
				\WP_CLI::error( __( '--read-data-subset must be a number from 0 to 100.', 'backups-for-restic' ) );
			}
			$data['read_data_subset_percent'] = $percent;
		}
		$this->maintain( JobType::Check, $assoc_args, $data );
	}

	/**
	 * Creates a maintenance job and runs or chains it.
	 *
	 * @param JobType               $type       Forget, prune or check.
	 * @param array<string, string> $assoc_args Options.
	 * @param array<string, mixed>  $data       Job data.
	 */
	private function maintain( JobType $type, array $assoc_args, array $data = [] ): void {
		if ( JobType::Check !== $type && 'external' === $this->container->settings()->string( 'retention.mode' ) ) {
			\WP_CLI::error( Messages::job_error( 'retention_external' )['message'] );
		}
		try {
			$job = $this->container->jobs()->create( $type, 'cli', time() );
		} catch ( JobConflict ) {
			\WP_CLI::error( Messages::job_error( 'job_conflict' )['message'] );
		}
		if ( [] !== $data ) {
			$job->data = $data + $job->data;
			$this->container->jobs()->save( $job );
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
		$result = $job->result;
		if ( isset( $result['skipped'] ) ) {
			\WP_CLI::warning( Messages::job_error( 'append_only' === $result['skipped'] ? 'repository_append_only' : 'retention_external' )['message'] );
			return;
		}
		match ( $job->type ) {
			/* translators: 1: removed snapshots, 2: kept snapshots. */
			JobType::Forget => \WP_CLI::success( sprintf( __( 'Forget finished: %1$d snapshots removed, %2$d kept.', 'backups-for-restic' ), (int) ( $result['removed'] ?? 0 ), (int) ( $result['keep'] ?? 0 ) ) ),
			/* translators: 1: deleted packs, 2: freed size. */
			JobType::Prune  => \WP_CLI::success( sprintf( __( 'Prune finished: %1$d packs deleted, %2$s freed.', 'backups-for-restic' ), (int) ( $result['packs_deleted'] ?? 0 ), size_format( (int) ( $result['bytes_deleted'] ?? 0 ) ) ) ),
			JobType::Check  => $this->report_check( $result ),
			/* translators: %s: snapshot ID. */
			default         => \WP_CLI::success( sprintf( __( 'Backup finished: snapshot %s.', 'backups-for-restic' ), substr( (string) ( $result['snapshot'] ?? '' ), 0, 8 ) ) ),
		};
	}

	/**
	 * Prints the outcome of a check; damage makes the command fail.
	 *
	 * @param array<string, int|string|bool|null> $result Job result.
	 */
	private function report_check( array $result ): void {
		$errors   = (int) ( $result['errors'] ?? 0 );
		$warnings = (int) ( $result['warnings'] ?? 0 );
		/* translators: 1: packs read, 2: blobs verified, 3: warnings. */
		$summary = sprintf( __( '%1$d packs read, %2$d blobs verified, %3$d warnings.', 'backups-for-restic' ), (int) ( $result['packs_read'] ?? 0 ), (int) ( $result['blobs_verified'] ?? 0 ), $warnings );
		if ( $errors > 0 ) {
			/* translators: 1: number of errors, 2: summary. */
			\WP_CLI::error( sprintf( __( 'The check found %1$d errors. %2$s', 'backups-for-restic' ), $errors, $summary ) );
		}
		/* translators: %s: summary. */
		\WP_CLI::success( sprintf( __( 'The check found no errors. %s', 'backups-for-restic' ), $summary ) );
	}
}
