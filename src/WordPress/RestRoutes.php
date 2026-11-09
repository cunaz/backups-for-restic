<?php
/**
 * The plugin's REST API.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Connection\Setup;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under backups-for-restic/v1 (docs/02-architecture.md §13.2). The admin UI uses nothing
 * else. Every route requires the backup capability; no response contains a secret except the
 * recovery kit, which needs the administrator's password again.
 */
final class RestRoutes {

	public const string NAMESPACE = 'backups-for-restic/v1';

	/** Settings that the settings page may change; repository and setup have their own routes. */
	private const array EDITABLE = [ 'schedule.', 'retention.', 'backup.', 'notifications.', 'advanced.' ];

	/**
	 * Creates the routes.
	 *
	 * @param Container $container Services.
	 */
	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Registers the routes; hooked to rest_api_init.
	 */
	public function register(): void {
		$routes = [
			'/status'                                    => [ 'GET', 'status' ],
			'/environment'                               => [ 'GET', 'environment' ],
			'/jobs'                                      => [ 'GET', 'jobs', 'POST', 'create_job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})'     => [ 'GET', 'job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})/cancel' => [ 'POST', 'cancel_job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})/log' => [ 'GET', 'job_log' ],
			'/snapshots'                                 => [ 'GET', 'snapshots' ],
			'/settings'                                  => [ 'GET', 'settings', 'PUT', 'update_settings' ],
			'/repository/test'                           => [ 'POST', 'test_repository' ],
			'/repository/save'                           => [ 'POST', 'save_repository' ],
			'/repository/create'                         => [ 'POST', 'create_repository' ],
			'/repository/connect'                        => [ 'POST', 'connect_repository' ],
			'/repository/ca-certificate'                 => [ 'POST', 'upload_ca', 'DELETE', 'delete_ca' ],
			'/setup/complete'                            => [ 'POST', 'complete_setup' ],
			'/recovery-kit'                              => [ 'POST', 'recovery_kit' ],
		];
		foreach ( $routes as $path => $methods ) {
			$endpoints = [];
			foreach ( array_chunk( $methods, 2 ) as [ $method, $callback ] ) {
				$endpoints[] = [
					'methods'             => $method,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ self::class, 'allowed' ],
				];
			}
			register_rest_route( self::NAMESPACE, $path, $endpoints );
		}
	}

	/**
	 * Whether the current user may manage backups.
	 *
	 * @return bool True if allowed.
	 */
	public static function allowed(): bool {
		/**
		 * Filters the capability needed to manage backups.
		 *
		 * @param string $capability Capability, manage_options by default.
		 */
		$capability = apply_filters( 'backups_for_restic_capability', 'manage_options' );
		return current_user_can( $capability );
	}

	/**
	 * Dashboard data.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function status(): \WP_REST_Response {
		$settings = $this->container->settings();
		$active   = $this->container->jobs()->active();
		return $this->respond(
			[
				'setup_completed' => $settings->bool( 'setup.completed' ),
				'repository'      => [
					'server'      => $settings->string( 'repository.server' ),
					'host'        => (string) wp_parse_url( $settings->string( 'repository.url' ), PHP_URL_HOST ),
					'append_only' => $settings->string( 'repository.append_only' ),
				],
				'last_backup'     => $this->container->state()->get( 'last_backup' ),
				'active_job'      => null === $active ? null : $this->job_summary( $active ),
				'next_backup'     => SchedulePolicy::next_backup_slot( $settings, wp_timezone(), time() ),
				'recent_jobs'     => array_map( $this->job_summary( ... ), $this->container->jobs()->latest( 5 ) ),
			]
		);
	}

	/**
	 * Doctor results.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response Response.
	 */
	public function environment( \WP_REST_Request $request ): \WP_REST_Response {
		$checks = [];
		foreach ( $this->container->doctor( self::flag( $request, 'fresh' ) ) as $check ) {
			$checks[] = $check->to_array() + Messages::check( $check );
		}
		return $this->respond( [ 'checks' => $checks ] );
	}

	/**
	 * Recent jobs.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function jobs(): \WP_REST_Response {
		return $this->respond( [ 'jobs' => array_map( $this->job_summary( ... ), $this->container->jobs()->latest( 30 ) ) ] );
	}

	/**
	 * Starts a backup.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_job(): \WP_REST_Response|\WP_Error {
		if ( '' === $this->container->settings()->string( 'repository.url' ) ) {
			return $this->error( 'configuration', 409 );
		}
		try {
			$job = $this->container->jobs()->create( JobType::Backup, 'manual', time() );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		}
		Cron::chain();
		return $this->respond( [ 'job' => $this->job_summary( $job ) ], 201 );
	}

	/**
	 * One job; advances it a little while an administrator watches (admin polling ticks).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id  = self::text( $request, 'id' );
		$job = $this->container->jobs()->get( $id );
		if ( null === $job ) {
			return $this->error( 'not_found', 404 );
		}
		if ( ! $job->state->is_terminal() && false === get_transient( 'backups_for_restic_poll' ) ) {
			set_transient( 'backups_for_restic_poll', 1, 5 );
			// The cron chain and the heartbeat keep the job going; polling only adds speed.
			Cron::tick( $this->container, 5.0, false );
			$job = $this->container->jobs()->get( $id ) ?? $job;
		}
		return $this->respond( [ 'job' => $this->job_summary( $job ) ] );
	}

	/**
	 * Cancels a job: at once while queued, otherwise after the current step's cleanup.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$jobs = $this->container->jobs();
		$job  = $jobs->get( self::text( $request, 'id' ) );
		if ( null === $job ) {
			return $this->error( 'not_found', 404 );
		}
		if ( JobState::Queued === $job->state ) {
			$job->state       = JobState::Cancelled;
			$job->finished_at = time();
		} elseif ( ! $job->state->is_terminal() ) {
			$job->cancel_requested = true;
		}
		$jobs->save( $job );
		$jobs->log( $job )->write( 'info', 'Cancellation requested.', [ 'user' => get_current_user_id() ] );
		return $this->respond( [ 'job' => $this->job_summary( $job ) ] );
	}

	/**
	 * A job's log.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function job_log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$jobs = $this->container->jobs();
		$job  = $jobs->get( self::text( $request, 'id' ) );
		if ( null === $job ) {
			return $this->error( 'not_found', 404 );
		}
		return $this->respond(
			[
				'job'     => $this->job_summary( $job ),
				'entries' => $jobs->log( $job )->read(),
			]
		);
	}

	/**
	 * Snapshots of this site, newest first, cached for five minutes.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function snapshots( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cached = get_transient( 'backups_for_restic_snapshots' );
		if ( is_array( $cached ) && ! self::flag( $request, 'fresh' ) ) {
			return $this->respond( [ 'snapshots' => $cached ] );
		}
		try {
			$repository = $this->container->access()->open_cached();
			if ( null === $repository ) {
				return $this->error( 'configuration', 409 );
			}
			$list = [];
			foreach ( $repository->snapshots() as $id => $snapshot ) {
				$list[] = [
					'id'         => $id,
					'short_id'   => substr( $id, 0, 8 ),
					'time'       => $snapshot->time,
					'hostname'   => $snapshot->hostname,
					'tags'       => $snapshot->tags,
					'data_added' => $snapshot->summary?->data_added,
					'files'      => null === $snapshot->summary ? null : $snapshot->summary->files_new + $snapshot->summary->files_changed + $snapshot->summary->files_unmodified,
					'bytes'      => $snapshot->summary?->total_bytes_processed,
				];
			}
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 502 );
		}
		set_transient( 'backups_for_restic_snapshots', $list, 5 * MINUTE_IN_SECONDS );
		return $this->respond( [ 'snapshots' => $list ] );
	}

	/**
	 * Settings, with which fields wp-config.php sets and whether secrets exist.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function settings(): \WP_REST_Response {
		$settings = $this->container->settings();
		$secrets  = [];
		foreach ( [ SecretName::RepositoryPassword, SecretName::RestPassword ] as $name ) {
			try {
				$set = null !== $this->container->secrets()->get( $name );
			} catch ( PluginException ) {
				$set = false;
			}
			$secrets[ $name->value ] = [
				'set'        => $set,
				'overridden' => $this->container->secrets()->is_overridden( $name ),
			];
		}
		return $this->respond(
			[
				'values'      => $settings->to_array(),
				'overridden'  => $settings->overridden,
				'secrets'     => $secrets,
				'private_dir' => $this->container->private_dir()->path,
			]
		);
	}

	/**
	 * Saves settings from the settings page.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$values  = $request->get_param( 'values' );
		$changes = [];
		foreach ( is_array( $values ) ? $values : [] as $path => $value ) {
			$path    = (string) $path;
			$allowed = false;
			foreach ( self::EDITABLE as $prefix ) {
				$allowed = $allowed || str_starts_with( $path, $prefix );
			}
			if ( ! $allowed ) {
				return $this->error( 'not_editable', 400 );
			}
			$changes[ $path ] = $value;
		}
		try {
			$this->container->settings_store()->update( $changes );
		} catch ( ValidationFailed $e ) {
			return $this->validation_error( $e );
		}
		$this->container->refresh();
		return $this->settings();
	}

	/**
	 * Tests a repository server without saving anything.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function test_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->run_test( $request );
		return $report instanceof \WP_Error ? $report : $this->respond( [ 'report' => $report->to_array() ] );
	}

	/**
	 * Tests and saves a repository server.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function save_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->run_test( $request );
		if ( $report instanceof \WP_Error ) {
			return $report;
		}
		try {
			$this->setup()->save_server( self::text( $request, 'server' ), $report, self::text( $request, 'username' ), $this->secret_param( $request, 'password' ) );
		} catch ( ValidationFailed $e ) {
			return $this->validation_error( $e );
		}
		$this->container->refresh();
		delete_transient( 'backups_for_restic_snapshots' );
		return $this->respond( [ 'report' => $report->to_array() ] );
	}

	/**
	 * Creates a new repository and returns the recovery kit once.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->setup()->create( $this->secret_param( $request, 'password' ) );
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 409 );
		}
		$this->container->refresh();
		return $this->respond( [ 'recovery_kit' => RecoveryKit::text( $this->container ) ], 201, true );
	}

	/**
	 * Connects to an existing repository; the key derivation runs as an unlock job.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function connect_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$password = $this->secret_param( $request, 'password' );
		if ( null === $password && ! $this->container->secrets()->is_overridden( SecretName::RepositoryPassword ) ) {
			return $this->error( 'configuration', 400 );
		}
		if ( null !== $password ) {
			$this->setup()->connect( $password );
		}
		$this->container->refresh();
		try {
			$job = $this->container->jobs()->create( JobType::Unlock, 'setup', time() );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		}
		Cron::chain();
		return $this->respond(
			[
				'job'          => $this->job_summary( $job ),
				'recovery_kit' => RecoveryKit::text( $this->container ),
			],
			201,
			true
		);
	}

	/**
	 * Stores a CA certificate for a self-hosted server with a self-signed certificate.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function upload_ca( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$pem = self::text( $request, 'pem' );
		if ( strlen( $pem ) > 65536 || ! str_contains( $pem, '-----BEGIN CERTIFICATE-----' ) || false === openssl_x509_parse( $pem ) ) {
			return $this->error( 'invalid_certificate', 400 );
		}
		$directory = $this->container->private_dir();
		$directory->ensure();
		file_put_contents( $directory->path . '/ca.pem', $pem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The private directory is written with native functions (docs/04 §3).
		chmod( $directory->path . '/ca.pem', 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Same.
		$this->container->settings_store()->update( [ 'repository.ca_certificate' => 'uploaded' ] );
		$this->container->refresh();
		return $this->respond( [ 'ca_certificate' => 'uploaded' ] );
	}

	/**
	 * Removes the CA certificate.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function delete_ca(): \WP_REST_Response {
		wp_delete_file( $this->container->private_dir()->path . '/ca.pem' );
		$this->container->settings_store()->update( [ 'repository.ca_certificate' => 'none' ] );
		$this->container->refresh();
		return $this->respond( [ 'ca_certificate' => 'none' ] );
	}

	/**
	 * Finishes the wizard once the recovery kit is acknowledged.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function complete_setup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::flag( $request, 'recovery_kit_acknowledged' ) ) {
			return $this->error( 'recovery_kit_not_acknowledged', 400 );
		}
		if ( null === $this->container->access()->open_cached() ) {
			return $this->error( 'configuration', 409 );
		}
		$this->container->settings_store()->update(
			[
				'setup.completed'                    => true,
				'setup.recovery_kit_acknowledged_at' => gmdate( 'c' ),
			]
		);
		$this->container->refresh();
		return $this->respond( [ 'setup_completed' => true ] );
	}

	/**
	 * Shows the recovery kit after the administrator re-enters their password.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function recovery_kit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = wp_get_current_user();
		if ( ! wp_check_password( self::text( $request, 'user_password' ), $user->user_pass, $user->ID ) ) {
			return $this->error( 'wrong_user_password', 403 );
		}
		try {
			$kit = RecoveryKit::text( $this->container );
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 409 );
		}
		return $this->respond( [ 'recovery_kit' => $kit ], 200, true );
	}

	/**
	 * Runs the connection test from request parameters.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \BackupsForRestic\Connection\ConnectionReport|\WP_Error Report or error.
	 */
	private function run_test( \WP_REST_Request $request ): \BackupsForRestic\Connection\ConnectionReport|\WP_Error {
		$password = $this->secret_param( $request, 'password' );
		if ( null === $password ) {
			try {
				$password = $this->container->secrets()->get( SecretName::RestPassword );
			} catch ( PluginException ) {
				$password = null;
			}
		}
		try {
			return $this->container->tester()->test( self::text( $request, 'server' ), self::text( $request, 'url' ), self::text( $request, 'username' ), $password );
		} catch ( InvalidArgument $e ) {
			return new \WP_Error(
				'backups_for_restic_invalid_url',
				Messages::validation( $e->context() ),
				[ 'status' => 400 ]
			);
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 502 );
		}
	}

	/**
	 * The wizard's setup service.
	 *
	 * @return Setup Setup.
	 */
	private function setup(): Setup {
		return new Setup(
			$this->container->settings_store(),
			$this->container->secrets(),
			function () {
				$this->container->refresh();
				return $this->container->access();
			},
			$this->container->random(),
			$this->container->identity()
		);
	}

	/**
	 * A text request parameter.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                 $name    Parameter.
	 * @return string Value, or an empty string.
	 */
	private static function text( \WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * A boolean request parameter: true, 1, "1", "true", "yes" or "on".
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                 $name    Parameter.
	 * @return bool Value.
	 */
	private static function flag( \WP_REST_Request $request, string $name ): bool {
		$value = $request->get_param( $name );
		return true === $value || ( ( is_string( $value ) || is_int( $value ) ) && rest_sanitize_boolean( $value ) );
	}

	/**
	 * A secret request parameter.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                 $name    Parameter.
	 * @return Secret|null Secret, or null if missing or empty.
	 */
	private function secret_param( \WP_REST_Request $request, string $name ): ?Secret {
		$value = $request->get_param( $name );
		return is_string( $value ) && '' !== $value ? new Secret( $value ) : null;
	}

	/**
	 * A job as the UI needs it: no lease and no step data.
	 *
	 * @param Job $job Job.
	 * @return array<string, mixed> Fields.
	 */
	private function job_summary( Job $job ): array {
		$error = null;
		if ( null !== $job->error ) {
			$error = [ 'code' => $job->error->code ] + Messages::job_error( $job->error->code );
		}
		return [
			'id'               => $job->id,
			'type'             => $job->type->value,
			'trigger'          => $job->trigger,
			'state'            => $job->state->value,
			'state_label'      => Messages::state( $job->state->value ),
			'step'             => $job->step,
			'step_label'       => Messages::step( $job->step ),
			'created_at'       => $job->created_at,
			'started_at'       => $job->started_at,
			'finished_at'      => $job->finished_at,
			'cancel_requested' => $job->cancel_requested,
			'ticks'            => $job->ticks,
			'progress'         => $job->progress,
			'result'           => $job->result,
			'warnings'         => $job->warnings,
			'error'            => $error,
		];
	}

	/**
	 * A JSON response that proxies and browsers must not store.
	 *
	 * @param array<string, mixed> $data      Data.
	 * @param int                  $status    HTTP status.
	 * @param bool                 $sensitive Whether it contains the recovery kit.
	 * @return \WP_REST_Response Response.
	 */
	private function respond( array $data, int $status = 200, bool $sensitive = false ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', $sensitive ? 'no-store' : 'no-cache' );
		return $response;
	}

	/**
	 * An error with a translated message and hint.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @return \WP_Error Error.
	 */
	private function error( string $code, int $status ): \WP_Error {
		$texts = match ( $code ) {
			'not_found'                     => [
				'message' => __( 'The job does not exist.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'not_editable'                  => [
				'message' => __( 'This setting cannot be changed here.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_certificate'           => [
				'message' => __( 'This is not a valid PEM certificate.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'recovery_kit_not_acknowledged' => [
				'message' => __( 'Please confirm that you stored the recovery kit.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'wrong_user_password'           => [
				'message' => __( 'Your WordPress password was not correct.', 'backups-for-restic' ),
				'hint'    => '',
			],
			default                         => Messages::job_error( $code ),
		};
		return new \WP_Error(
			'backups_for_restic_' . $code,
			$texts['message'],
			[
				'status' => $status,
				'hint'   => $texts['hint'],
			]
		);
	}

	/**
	 * A validation error with one translated message per field.
	 *
	 * @param ValidationFailed $failure Failure.
	 * @return \WP_Error Error.
	 */
	private function validation_error( ValidationFailed $failure ): \WP_Error {
		$fields = [];
		foreach ( $failure->errors() as $path => $error ) {
			$fields[ $path ] = Messages::validation( $error );
		}
		return new \WP_Error(
			'backups_for_restic_invalid_settings',
			__( 'Some settings are not valid.', 'backups-for-restic' ),
			[
				'status' => 400,
				'fields' => $fields,
			]
		);
	}
}
