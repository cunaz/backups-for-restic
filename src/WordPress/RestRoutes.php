<?php
/**
 * The plugin's REST API.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Connection\Setup;
use BackupsForRestic\Doctor\Doctor;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under backups-for-restic/v1 (docs/02-architecture.md §13.2). The admin UI uses nothing
 * else. Every route requires the backup capability; no response contains a secret except the
 * recovery kit, which the wizard shows once during the setup and which needs the
 * administrator's WordPress password afterwards.
 */
final class RestRoutes {

	public const string NAMESPACE = 'backups-for-restic/v1';

	/** Settings that the settings page may change; repository and setup have their own routes. */
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
			'/environment/probe'                         => [ 'POST', 'begin_probe' ],
			'/environment/probe/report'                  => [ 'POST', 'report_probe' ],
			'/jobs'                                      => [ 'GET', 'jobs', 'POST', 'create_job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})'     => [ 'GET', 'job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})/cancel' => [ 'POST', 'cancel_job' ],
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})/log' => [ 'GET', 'job_log' ],
			'/snapshots'                                 => [ 'GET', 'snapshots' ],
			'/settings'                                  => [ 'GET', 'settings', 'PUT', 'update_settings' ],
			'/cache'                                     => [ 'DELETE', 'clear_cache' ],
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
		$state    = $this->container->state();
		$active   = $this->container->jobs()->active();
		$zone     = wp_timezone();
		return $this->respond(
			[
				'setup_completed' => $settings->bool( 'setup.completed' ),
				'repository'      => [
					'server'      => $settings->string( 'repository.server' ),
					'host'        => (string) wp_parse_url( $settings->string( 'repository.url' ), PHP_URL_HOST ),
					'append_only' => $this->container->append_only(),
					'usage'       => [
						'bytes' => Decoded::int( $state->get( 'usage' )['bytes'] ?? null ),
						'packs' => Decoded::int( $state->get( 'usage' )['packs'] ?? null ),
						'at'    => Decoded::int( $state->get( 'usage' )['at'] ?? null ),
					],
				],
				'last_backup'     => $state->get( 'last_backup' ),
				'active_job'      => null === $active ? null : $this->job_summary( $active ),
				'next_backup'     => SchedulePolicy::next_backup_slot( $settings, $zone, time() ),
				'recent_jobs'     => array_map( $this->job_summary( ... ), $this->container->jobs()->latest( 5 ) ),
				'retention'       => [
					'mode'           => $settings->string( 'retention.mode' ),
					'after_backup'   => $settings->bool( 'retention.run_forget_after_backup' ),
					'proposed_since' => Decoded::int( $state->get( 'retention_proposal' )['since'] ?? null ),
					'switched_at'    => Decoded::int( $state->get( 'retention_switch' )['at'] ?? null ),
				],
				'maintenance'     => [
					'forget' => $this->last_maintenance( 'forget', [ 'removed', 'keep', 'skipped' ] ),
					'prune'  => $this->last_maintenance( 'prune', [ 'packs_deleted', 'bytes_deleted', 'reclaimable_bytes', 'skipped' ] ) + [ 'next' => SchedulePolicy::next_maintenance_slot( $settings, $zone, time(), 'prune' ) ],
					'check'  => $this->last_maintenance( 'check', [ 'errors', 'warnings', 'packs_read', 'bytes_read' ] ) + [ 'next' => SchedulePolicy::next_maintenance_slot( $settings, $zone, time(), 'check' ) ],
				],
				'check_alert'     => $this->check_alert(),
			]
		);
	}

	/**
	 * The last run of a maintenance task, with the result fields the dashboard shows.
	 *
	 * @param string       $task   forget, prune or check.
	 * @param list<string> $fields Result fields.
	 * @return array<string, mixed> Finish time and fields; finished is 0 if it never ran.
	 */
	private function last_maintenance( string $task, array $fields ): array {
		$last   = $this->container->state()->get( 'last_' . $task );
		$result = is_array( $last['result'] ?? null ) ? $last['result'] : [];
		$data   = [ 'finished' => Decoded::int( $last['finished'] ?? null ) ];
		foreach ( $fields as $field ) {
			$data[ $field ] = $result[ $field ] ?? null;
		}
		return $data;
	}

	/**
	 * The persistent alert of a check that found damage, with translated findings.
	 *
	 * @return array<string, mixed>|null Alert, or null if the last check found no errors.
	 */
	private function check_alert(): ?array {
		$alert = $this->container->state()->get( 'check_alert' );
		if ( [] === $alert ) {
			return null;
		}
		$findings = [];
		foreach ( Decoded::arrays( $alert['findings'] ?? null ) as $finding ) {
			$findings[] = [
				'severity' => Decoded::string( $finding['severity'] ?? null ),
				'code'     => Decoded::string( $finding['code'] ?? null ),
				'object'   => substr( Decoded::string( $finding['object'] ?? null ), 0, 8 ),
				'label'    => Messages::check_finding( Decoded::string( $finding['code'] ?? null ) ),
			];
		}
		return [
			'at'       => Decoded::int( $alert['at'] ?? null ),
			'errors'   => Decoded::int( $alert['errors'] ?? null ),
			'findings' => $findings,
		];
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
		try {
			// The site could not request itself: the admin page lets the browser look.
			$browser = $this->container->exposure()->needs_browser();
		} catch ( PluginException ) {
			$browser = false;
		}
		return $this->respond(
			[
				'checks'        => $checks,
				'browser_probe' => $browser,
			]
		);
	}

	/**
	 * Writes a probe file into the private directory for the browser to request (ADR 0007).
	 *
	 * @return \WP_REST_Response|\WP_Error Response with the probe's name and URL, or null as URL if the directory has none.
	 */
	public function begin_probe(): \WP_REST_Response|\WP_Error {
		try {
			$exposure = $this->container->exposure();
			if ( null === $exposure->probe_url( 'x' ) ) {
				return $this->respond( [ 'url' => null ] );
			}
			$probe = $this->container->browser_probe()->begin();
			return $this->respond(
				[
					'url'   => $exposure->probe_url( $probe ),
					'probe' => $probe,
				]
			);
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 500 );
		}
	}

	/**
	 * Takes what the browser got for the probe file.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request with the probe's name, the HTTP status (0 if the request failed) and the body.
	 * @return \WP_REST_Response Response: exposed is true, false, or null if the report says nothing.
	 */
	public function report_probe( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' );
		// The token has 32 characters; a page that serves the file starts with it.
		$body = substr( self::text( $request, 'body' ), 0, 4096 );
		return $this->respond( [ 'exposed' => $this->container->browser_probe()->finish( self::text( $request, 'probe' ), is_numeric( $status ) ? (int) $status : 0, $body ) ] );
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
	 * Starts a backup, or with type forget, prune or check a maintenance job. None of them
	 * starts before the setup is completed.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = JobType::tryFrom( '' === self::text( $request, 'type' ) ? 'backup' : self::text( $request, 'type' ) );
		if ( null === $type || JobType::Unlock === $type ) {
			return $this->error( 'invalid_job_type', 400 );
		}
		$refusal = $type->refusal( $this->container->settings() );
		if ( null !== $refusal ) {
			return $this->error( $refusal, 409 );
		}
		try {
			$job = $this->container->jobs()->create( $type, 'manual', time() );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		}
		Cron::chain( $this->container );
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
	 * Cancels a job: at once while queued, otherwise after the current step's cleanup. The
	 * runner records the request so that a tick running right now can neither lose it nor be
	 * overwritten by it.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$job = $this->container->runner()->cancel( self::text( $request, 'id' ), [ 'user' => get_current_user_id() ] );
		if ( null === $job ) {
			return $this->error( 'not_found', 404 );
		}
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
				'cache_bytes' => $this->container->cache()->size(),
				'kit_views'   => $this->kit_views(),
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
			$path = (string) $path;
			if ( ! Schema::editable( $path ) ) {
				return $this->error( 'not_editable', 400 );
			}
			$changes[ $path ] = $value;
		}
		try {
			$this->container->save_settings( $changes );
		} catch ( ValidationFailed $e ) {
			return $this->validation_error( $e );
		}
		return $this->settings();
	}

	/**
	 * Deletes the local cache; the next job rebuilds it from the repository. The runner lock is
	 * held from the check for jobs to the end of the deletion, so no tick can start in between
	 * and write to the cache while it is removed.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function clear_cache(): \WP_REST_Response|\WP_Error {
		$lock = $this->container->runner_lock();
		if ( ! $lock->acquire() ) {
			return $this->error( 'job_conflict', 409 );
		}
		try {
			if ( null !== $this->container->jobs()->active() ) {
				return $this->error( 'job_conflict', 409 );
			}
			$this->container->cache()->clear();
		} finally {
			$lock->release();
		}
		return $this->respond( [ 'cache_bytes' => $this->container->cache()->size() ] );
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
			// The certificate the test just used becomes the saved server's with the server, under
			// the same lock, and is put back as it was should the save fail: a saved server
			// never has a certificate that is not its own.
			$this->setup()->save_server( self::text( $request, 'server' ), $report, self::text( $request, 'username' ), $this->secret_param( $request, 'password' ), fn (): \Closure => $this->keep_candidate_ca( $request ) );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		} catch ( ValidationFailed $e ) {
			return $this->validation_error( $e );
		} catch ( InvalidArgument $e ) {
			return $this->server_error( $e );
		} catch ( PluginException $e ) {
			// The REST password did not survive being stored.
			return $this->error( JobError::from_throwable( $e )->code, 500 );
		}
		$this->container->refresh();
		delete_transient( 'backups_for_restic_snapshots' );
		return $this->respond( [ 'report' => $report->to_array() ] );
	}

	/**
	 * The CA certificate a request to test or save a server brings along, for an own server
	 * with a certificate of its own authority.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return string|\WP_Error|null PEM text, null if the request brings none, or the error for one that is no certificate.
	 */
	private function candidate_ca( \WP_REST_Request $request ): string|\WP_Error|null {
		$pem = trim( self::text( $request, 'ca_certificate' ) );
		if ( '' === $pem || 'rest-server' !== self::text( $request, 'server' ) ) {
			return null;
		}
		return self::is_certificate( $pem ) ? $pem : $this->error( 'invalid_certificate', 400 );
	}

	/**
	 * Whether a text is a certificate in PEM form of a size that one can have.
	 *
	 * @param string $pem Text.
	 * @return bool True if it parses.
	 */
	private static function is_certificate( string $pem ): bool {
		return strlen( $pem ) <= 65536 && str_contains( $pem, '-----BEGIN CERTIFICATE-----' ) && false !== openssl_x509_parse( $pem );
	}

	/**
	 * While a server is being saved: stores the certificate the request brought as the
	 * server's, and removes a stored one for a server of the preset, which has a public
	 * certificate.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \Closure(): void What puts certificate and setting back as they were.
	 * @throws PluginException If the certificate cannot be written.
	 */
	private function keep_candidate_ca( \WP_REST_Request $request ): \Closure {
		$pem       = $this->candidate_ca( $request );
		$directory = $this->container->private_dir();
		$file      = $directory->path . '/ca.pem';
		$store     = $this->container->settings_store();
		$setting   = $this->container->settings()->string( 'repository.ca_certificate' );
		try {
			$stored = $directory->ca_certificate();
		} catch ( PluginException ) {
			$stored = null;
		}
		$undo = static function () use ( $file, $store, $setting, $stored ): void {
			if ( null === $stored ) {
				wp_delete_file( $file );
			} else {
				Files::write_atomic( $file, $stored );
			}
			$store->update( [ 'repository.ca_certificate' => $setting ] );
		};
		if ( is_string( $pem ) ) {
			$directory->ensure();
			Files::write_atomic( $file, $pem );
			$store->update( [ 'repository.ca_certificate' => 'uploaded' ] );
		} elseif ( 'rest-server' !== self::text( $request, 'server' ) && 'uploaded' === $setting ) {
			wp_delete_file( $file );
			$store->update( [ 'repository.ca_certificate' => 'none' ] );
		}
		return $undo;
	}

	/**
	 * Creates a new repository and returns the recovery kit once.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->may_see_kit( $request ) ) {
			return $this->error( 'wrong_user_password', 403 );
		}
		try {
			// The answer is the recovery kit: what the kit needs has to be there before a repository exists.
			RecoveryKit::ca_certificate( $this->container );
			$this->setup()->create( $this->secret_param( $request, 'password' ) );
			$this->container->refresh();
			delete_transient( 'backups_for_restic_snapshots' );
			$kit = $this->kit( 'create' );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		} catch ( PluginException $e ) {
			if ( 'password_too_short' === ( $e->context()['code'] ?? null ) ) {
				return $this->error( 'password_too_short', 400 );
			}
			return $this->kit_error( $e, 409 );
		}
		return $this->respond( [ 'recovery_kit' => $kit ], 201, true );
	}

	/**
	 * Connects to an existing repository; the key derivation runs as an unlock job.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function connect_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->may_see_kit( $request ) ) {
			return $this->error( 'wrong_user_password', 403 );
		}
		try {
			// The answer contains the recovery kit: what the kit needs has to be there first.
			RecoveryKit::ca_certificate( $this->container );
			$job = $this->setup()->connect( $this->secret_param( $request, 'password' ), time() );
		} catch ( JobConflict ) {
			return $this->error( 'job_conflict', 409 );
		} catch ( InvalidArgument ) {
			return $this->error( 'configuration', 400 );
		} catch ( PluginException $e ) {
			// The CA certificate is missing, or the repository password did not survive being stored.
			return $this->kit_error( $e, 500 );
		}
		$this->container->refresh();
		delete_transient( 'backups_for_restic_snapshots' );
		Cron::chain( $this->container );
		try {
			$kit = $this->kit( 'connect' );
		} catch ( PluginException $e ) {
			return $this->kit_error( $e, 409 );
		}
		return $this->respond(
			[
				'job'          => $this->job_summary( $job ),
				'recovery_kit' => $kit,
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
		if ( ! self::is_certificate( $pem ) ) {
			return $this->error( 'invalid_certificate', 400 );
		}
		$directory = $this->container->private_dir();
		try {
			$directory->ensure();
			// Only a certificate that is on disk may be announced in the settings.
			Files::write_atomic( $directory->path . '/ca.pem', $pem );
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 500 );
		}
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
		// The wizard's script stops at a critical finding of the Doctor; a request must not get past it either.
		if ( Doctor::blocked( $this->container->doctor() ) ) {
			return $this->error( 'environment_blocked', 409 );
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
		if ( ! $this->password_confirmed( $request ) ) {
			return $this->error( 'wrong_user_password', 403 );
		}
		try {
			$kit = $this->kit( 'settings' );
		} catch ( PluginException $e ) {
			return $this->kit_error( $e, 409 );
		}
		return $this->respond( [ 'recovery_kit' => $kit ], 200, true );
	}

	/** Deliveries of the recovery kit that are remembered. */
	private const int KIT_VIEWS = 20;

	/**
	 * Builds the recovery kit for an answer and records that it was handed out: to whom, when
	 * and on which way. The kit carries every secret of the backups, so an administrator
	 * should be able to see who else looked at it.
	 *
	 * @param string $via create, connect or settings.
	 * @return string The kit.
	 * @throws PluginException If the kit cannot be complete.
	 */
	private function kit( string $via ): string {
		$kit   = RecoveryKit::text( $this->container, 'connect' === $via );
		$user  = wp_get_current_user();
		$state = $this->container->state();
		$view  = [
			'at'    => time(),
			'user'  => $user->ID,
			'login' => $user->user_login,
			'via'   => $via,
		];
		// Added to what is stored now: two administrators may look at the kit at the same time.
		$state->update( 'recovery_kit', static fn ( array $entry ): array => [ 'views' => array_slice( [ $view, ...Decoded::arrays( $entry['views'] ?? null ) ], 0, self::KIT_VIEWS ) ] );
		/**
		 * Fires when the recovery kit, which contains the repository password, was put into a
		 * response for an administrator.
		 *
		 * @param int    $user_id User the kit was shown to.
		 * @param string $via     create or connect (the setup wizard and the repository forms), or settings (the recovery kit form).
		 */
		do_action( 'backups_for_restic_recovery_kit_shown', $user->ID, $via );
		return $kit;
	}

	/**
	 * The latest deliveries of the recovery kit, for the Settings tab.
	 *
	 * @return list<array{at: int, login: string, via: string}> Newest first.
	 */
	private function kit_views(): array {
		$views = [];
		foreach ( Decoded::arrays( $this->container->state()->get( 'recovery_kit' )['views'] ?? null ) as $view ) {
			$views[] = [
				'at'    => Decoded::int( $view['at'] ?? null ),
				'login' => Decoded::string( $view['login'] ?? null ),
				'via'   => Decoded::string( $view['via'] ?? null ),
			];
		}
		return array_slice( $views, 0, 5 );
	}

	/**
	 * The error for a recovery kit that cannot be complete, or for another failure on the way
	 * to it.
	 *
	 * @param PluginException $failure Failure.
	 * @param int             $status  HTTP status of any other failure.
	 * @return \WP_Error Error.
	 */
	private function kit_error( PluginException $failure, int $status ): \WP_Error {
		if ( 'ca_certificate_missing' === ( $failure->context()['code'] ?? null ) ) {
			return $this->error( 'ca_certificate_missing', 409 );
		}
		return $this->error( JobError::from_throwable( $failure )->code, $status );
	}

	/**
	 * Whether the request carries the WordPress password of the current user, as proof that the
	 * administrator is present and not only a session of theirs.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return bool True if the password is right.
	 */
	private function password_confirmed( \WP_REST_Request $request ): bool {
		$user = wp_get_current_user();
		return wp_check_password( self::text( $request, 'user_password' ), $user->user_pass, $user->ID );
	}

	/**
	 * Whether a request may get the recovery kit as part of its answer: freely during the
	 * setup, when the wizard shows the kit once; after it only with the WordPress password, as
	 * for the recovery kit route (docs/03-security-and-threat-model.md §5.5).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return bool True if allowed.
	 */
	private function may_see_kit( \WP_REST_Request $request ): bool {
		return ! $this->container->settings()->bool( 'setup.completed' ) || $this->password_confirmed( $request );
	}

	/**
	 * Runs the connection test from request parameters. Without a password in the request the
	 * stored one is used, but only for the server and user name it is stored for; for any
	 * other the request is refused before anything is sent.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \BackupsForRestic\Connection\ConnectionReport|\WP_Error Report or error.
	 */
	private function run_test( \WP_REST_Request $request ): \BackupsForRestic\Connection\ConnectionReport|\WP_Error {
		$server   = self::text( $request, 'server' );
		$url      = self::text( $request, 'url' );
		$username = self::text( $request, 'username' );
		$pem      = $this->candidate_ca( $request );
		if ( $pem instanceof \WP_Error ) {
			return $pem;
		}
		// A certificate that comes with the request is used for this test only, from a
		// file of its own in the private directory. A server of the preset is tested against the public authorities,
		// whatever certificate is stored for the server that was saved before.
		$file = null;
		$http = null;
		try {
			if ( is_string( $pem ) ) {
				// In the private directory like every file the plugin writes; a certificate is
				// no secret, and the name is of this request alone.
				$directory = $this->container->private_dir();
				try {
					$directory->ensure();
					$file = $directory->subdirectory( 'tmp' ) . '/ca-' . bin2hex( $this->container->random()->bytes( 8 ) ) . '.pem';
					Files::write_atomic( $file, $pem );
				} catch ( PluginException $e ) {
					return $this->error( JobError::from_throwable( $e )->code, 500 );
				}
			}
			if ( null !== $file || 'rest-server' !== $server ) {
				$http = new WpHttpClient( $this->container->settings()->int( 'advanced.upload_timeout_seconds' ), $file );
			}
			$password = $this->setup()->rest_password( $server, $url, $username, $this->secret_param( $request, 'password' ) );
			return $this->container->tester( $http )->test( $server, $url, $username, $password );
		} catch ( InvalidArgument $e ) {
			return $this->server_error( $e );
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 502 );
		} finally {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * The error for a server that a request names and the core refused: a missing REST
	 * password, or an address that breaks the rules.
	 *
	 * @param InvalidArgument $refusal Refusal with its code.
	 * @return \WP_Error Error.
	 */
	private function server_error( InvalidArgument $refusal ): \WP_Error {
		if ( 'password_required' === ( $refusal->context()['code'] ?? null ) ) {
			return $this->error( 'password_required', 400 );
		}
		return new \WP_Error(
			'backups_for_restic_invalid_url',
			Messages::validation( $refusal->context() ),
			[ 'status' => 400 ]
		);
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
			$this->container->identity(),
			$this->container->repository_record(),
			$this->container->jobs()
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
			'type_label'       => Messages::job_type( $job->type->value ),
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
			'invalid_job_type'              => [
				'message' => __( 'Jobs can be of type backup, forget, prune or check.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'ca_certificate_missing'        => [
				'message' => __( 'The CA certificate of the repository server is missing.', 'backups-for-restic' ),
				'hint'    => __( 'Without it restic cannot reach the server, so the recovery kit would be useless. Upload the certificate again.', 'backups-for-restic' ),
			],
			'password_too_short'            => [
				/* translators: %d: number of characters. */
				'message' => sprintf( __( 'The repository password must have at least %d characters.', 'backups-for-restic' ), Setup::MIN_PASSWORD_LENGTH ),
				'hint'    => __( 'Leave it empty and the plugin creates a strong one. A password set in wp-config.php has to be changed there.', 'backups-for-restic' ),
			],
			'environment_blocked'           => [
				'message' => __( 'This server does not meet a requirement for backups yet.', 'backups-for-restic' ),
				'hint'    => __( 'Open the first step of the setup again, or the Site Health screen, and fix what is marked as critical.', 'backups-for-restic' ),
			],
			'password_required'             => [
				'message' => __( 'Enter the REST password for this server.', 'backups-for-restic' ),
				'hint'    => __( 'The stored password is only sent to the server and user name it was saved for. If wp-config.php defines the password, enter that one.', 'backups-for-restic' ),
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
