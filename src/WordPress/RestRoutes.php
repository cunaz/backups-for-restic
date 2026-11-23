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
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Maintenance\RetentionSettings;
use BackupsForRestic\Jobs\Restore\RestoreRequest;
use BackupsForRestic\Restore\SnapshotBrowser;
use BackupsForRestic\Restore\StagedRestore;
use BackupsForRestic\Scheduling\SchedulePolicy;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Storage\ConfirmationThrottle;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under backups-for-restic/v1 (docs/02-architecture.md §13.2). The admin UI uses nothing
 * else. Every route requires the configured backup capability. The recovery kit contains
 * repository credentials. Sensitive responses, including temporary download and review
 * grants, are delivered with no-store cache policy.
 *
 * @phpstan-type LiveReview array{digest:string,applicable:bool,plan_job?:string,...}
 */
final class RestRoutes {

	public const string REST_NAMESPACE = 'backups-for-restic/v1';

	/** Seconds a request may spend on deleting a staging directory; the heartbeat finishes the rest. */
	private const float REMOVAL_SECONDS = 10.0;

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
			'/jobs/(?P<id>\d{8}T\d{6}Z-[0-9a-f]{6})/kept-entries' => [ 'GET', 'undo_kept' ],
			'/snapshots'                                 => [ 'GET', 'snapshots' ],
			// Any text is taken as the ID, so that a malformed one is answered as such and not as an unknown route.
			'/snapshots/(?P<snapshot>[^/]+)'             => [ 'GET', 'snapshot' ],
			'/snapshots/(?P<snapshot>[^/]+)/tree'        => [ 'GET', 'snapshot_tree' ],
			'/restores'                                  => [ 'GET', 'restores' ],
			// Any name reaches the route, so that a malformed ID is answered with its own error.
			'/restores/(?P<id>[^/]+)'                    => [ 'DELETE', 'remove_restore' ],
			'/restores/(?P<id>[^/]+)/download'           => [ 'POST', 'download' ],
			'/restores/(?P<id>[^/]+)/apply-plan'         => [ 'POST', 'apply_preview' ],
			'/restores/(?P<id>[^/]+)/apply'              => [ 'POST', 'apply_files' ],
			'/restores/(?P<id>[^/]+)/database/plan'      => [ 'POST', 'database_preview' ],
			'/restores/(?P<id>[^/]+)/database/apply'     => [ 'POST', 'apply_database' ],
			'/database-changes'                          => [ 'GET', 'database_undoable' ],
			'/database-changes/(?P<id>[^/]+)/undo-plan'  => [ 'POST', 'database_undo_preview' ],
			'/database-changes/(?P<id>[^/]+)/undo'       => [ 'POST', 'undo_database' ],
			'/database-changes/(?P<id>[^/]+)/discard'    => [ 'POST', 'discard_database' ],
			'/undo'                                      => [ 'GET', 'undoable' ],
			'/undo/(?P<id>[^/]+)/plan'                   => [ 'POST', 'undo_preview' ],
			'/undo/(?P<id>[^/]+)'                        => [ 'POST', 'undo_files' ],
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
					'callback'            => fn ( \WP_REST_Request $request ): mixed => $this->answer( \Closure::fromCallable( [ $this, $callback ] ), $request ),
					'permission_callback' => [
						self::class,
						match ( $callback ) {
								'apply_files', 'undo_files'       => 'live_writes_allowed',
								'apply_database', 'undo_database', 'discard_database' => 'database_writes_allowed',
								default                           => 'allowed',
						},
					],
				];
			}
			register_rest_route( self::REST_NAMESPACE, $path, $endpoints );
		}
	}

	/**
	 * Runs the method of a route. What a method does not answer itself, such as settings that
	 * the database refused to store, becomes an error response instead of a fatal error. A
	 * refusal that says how long to wait also says it in a Retry-After header.
	 *
	 * @param \Closure                               $route   The method.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return mixed What the method answers, or the error.
	 */
	private function answer( \Closure $route, \WP_REST_Request $request ): mixed {
		try {
			$answer = $route( $request );
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 500 );
		}
		$data = $answer instanceof \WP_Error ? $answer->get_error_data() : null;
		if ( $answer instanceof \WP_Error && is_array( $data ) && is_int( $data['retry_after'] ?? null ) ) {
			$response = rest_convert_error_to_response( $answer );
			$response->header( 'Retry-After', (string) $data['retry_after'] );
			return $response;
		}
		return $answer;
	}

	/**
	 * Whether the current user may manage backups.
	 *
	 * @return bool True if allowed.
	 */
	public static function allowed(): bool {
		return current_user_can( self::capability() );
	}

	/** The configured capability shared by the menu, API and background authorization. */
	public static function capability(): string {
		/**
		 * Filters the capability needed to manage backups.
		 *
		 * @param string $capability Capability, manage_options by default.
		 */
		$capability = apply_filters( 'backups_for_restic_capability', 'manage_options' );
		return Decoded::string( $capability, 'manage_options' );
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
		$checks          = [];
		$directory_ready = true;
		foreach ( $this->container->doctor( self::flag( $request, 'fresh' ) ) as $check ) {
			$checks[] = $check->to_array() + Messages::check( $check );
			if ( 'private_directory' === $check->id && 'unavailable' === $check->code ) {
				$directory_ready = false;
			}
		}
		try {
			// The site could not request itself: the admin page lets the browser look.
			$browser = $directory_ready && $this->container->exposure()->needs_browser();
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
	 * Writes a probe file into the private directory for the browser to request (ADR 0007),
	 * and names the known file that the browser requests beside it: without that file a
	 * refusal of the probe proves nothing (Storage\BrowserProbe).
	 *
	 * @return \WP_REST_Response|\WP_Error Response with the probe's name and URL and the control's URL, or null as URL if the directory has none.
	 */
	public function begin_probe(): \WP_REST_Response|\WP_Error {
		try {
			$exposure = $this->container->exposure();
			$location = $exposure->probe_url( '' );
			if ( null === $location ) {
				return $this->respond( [ 'url' => null ] );
			}
			$control = $exposure->control( $location );
			$probe   = $this->container->browser_probe()->begin( $location, null === $control ? null : hash( 'sha256', $control['content'] ) );
			return $this->respond(
				[
					'url'     => $exposure->probe_url( $probe ),
					'probe'   => $probe,
					'control' => null === $control ? null : $control['url'],
				]
			);
		} catch ( PluginException $e ) {
			return $this->error( JobError::from_throwable( $e )->code, 500 );
		}
	}

	/**
	 * Takes what the browser got for the probe file and for the known file beside it.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request with the probe's name, the HTTP status (0 if the request failed) and
	 *                                                        the body, and the same for the control file as control_status and control_body.
	 * @return \WP_REST_Response Response: exposed is true, false, or null if the report says nothing.
	 */
	public function report_probe( \WP_REST_Request $request ): \WP_REST_Response {
		$status  = $request->get_param( 'status' );
		$control = $request->get_param( 'control_status' );
		// The token has 32 characters; a page that serves the file starts with it.
		$body = substr( self::text( $request, 'body' ), 0, 4096 );
		// One byte more than the control file may have, so that a longer answer does not match.
		$control_body = substr( self::text( $request, 'control_body' ), 0, WpExposureCheck::CONTROL_BYTES + 1 );
		return $this->respond( [ 'exposed' => $this->container->browser_probe()->finish( self::text( $request, 'probe' ), is_numeric( $status ) ? (int) $status : 0, $body, is_numeric( $control ) ? (int) $control : 0, $control_body ) ] );
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
	 * Starts a backup, or with type forget, prune or check a maintenance job, or with type
	 * restore the restore of a snapshot into a staging directory: `snapshot` is the full ID,
	 * `paths` a list of paths as the snapshot holds them, and without it the whole snapshot is
	 * restored (ADR 0037). None of them starts before the setup is completed.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = JobType::tryFrom( '' === self::text( $request, 'type' ) ? 'backup' : self::text( $request, 'type' ) );
		if ( ! in_array( $type, [ JobType::Backup, JobType::Restore, JobType::Check, JobType::Forget, JobType::Prune ], true ) ) {
			return $this->error( 'invalid_job_type', 400 );
		}
		$data = [];
		if ( JobType::Restore === $type ) {
			try {
				$data = RestoreRequest::from_input(
					$request->get_param( 'snapshot' ),
					$request->get_param( 'paths' ),
					$request->get_param( 'archive' ) ?? false,
					$request->get_param( 'database_preview' ) ?? false,
					$request->get_param( 'fresh_install' ) ?? false
				)->to_data();
			} catch ( InvalidArgument $refused ) {
				return $this->error( Decoded::string( $refused->context()['code'] ?? 'invalid_paths' ), 400 );
			}
			// Staging needs the capability alone (docs/03 §8); the job view shows who asked for it.
			$data['user'] = get_current_user_id();
		}
		$refusal = $type->refusal( $this->container->settings() );
		if ( null !== $refusal ) {
			return $this->error( $refusal, 409 );
		}
		try {
			$job = $this->container->jobs()->create( $type, 'manual', time(), $data );
		} catch ( JobConflict $conflict ) {
			return $this->error( JobError::from_throwable( $conflict )->code, 409 );
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
		$id  = Decoded::string( $request->get_url_params()['id'] ?? null );
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
		$job = $this->container->runner()->cancel( Decoded::string( $request->get_url_params()['id'] ?? null ), [ 'user' => get_current_user_id() ] );
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
		$job  = $jobs->get( Decoded::string( $request->get_url_params()['id'] ?? null ) );
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
	 * Snapshots, newest first: those of this site, or with scope=repository all of the
	 * repository. Each list is cached for five minutes; fresh=1 reads it again.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function snapshots( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$scope = self::text( $request, 'scope' );
		try {
			$scope = SnapshotBrowser::scope( '' === $scope ? SnapshotBrowser::SCOPE_SITE : $scope );
			// The policy that this request previews with. A setting saved during the read below
			// belongs to a later request, which then finds a list of another policy.
			$policy = RetentionSettings::fingerprint( $this->container->settings() );
			$cached = $this->cached_snapshots( $policy );
			if ( isset( $cached[ $scope ] ) && ! self::flag( $request, 'fresh' ) ) {
				return $this->respond(
					[
						'scope'     => $scope,
						'snapshots' => $cached[ $scope ]['snapshots'],
					]
				);
			}
			$browser = $this->container->snapshot_browser();
			if ( null === $browser ) {
				return $this->error( 'configuration', 409 );
			}
			$list = $browser->snapshots( $scope );
		} catch ( PluginException $e ) {
			[ $code, $status ] = SnapshotBrowser::failure( $e );
			return $this->error( $code, $status );
		}
		// The other list is read again now: a job that ended during the read above deleted the
		// transient, and the list from before that job must not be written back.
		$cached           = $this->cached_snapshots( $policy );
		$cached[ $scope ] = [
			'time'      => time(),
			'policy'    => $policy,
			'snapshots' => $list,
		];
		// One transient holds both lists, so every place that deletes it drops both.
		set_transient( 'backups_for_restic_snapshots', $cached, 5 * MINUTE_IN_SECONDS );
		return $this->respond(
			[
				'scope'     => $scope,
				'snapshots' => $list,
			]
		);
	}

	/**
	 * The cached snapshot lists that are younger than five minutes. Each list has its own
	 * time, because storing one of them renews the transient that holds both. A list also
	 * names the retention settings it was read under and counts as missing under any other:
	 * deleting the transient when a setting is saved does not reach a list that a request
	 * was still reading then, nor a setting that wp-config.php overrides.
	 *
	 * @param string $policy RetentionSettings::fingerprint() of this request's settings.
	 * @return array<string, array{time: int, policy: string, snapshots: array<mixed>}> Lists by scope.
	 */
	private function cached_snapshots( string $policy ): array {
		$stored = get_transient( 'backups_for_restic_snapshots' );
		$lists  = [];
		foreach ( [ SnapshotBrowser::SCOPE_SITE, SnapshotBrowser::SCOPE_REPOSITORY ] as $scope ) {
			$entry = is_array( $stored ) && is_array( $stored[ $scope ] ?? null ) ? $stored[ $scope ] : [];
			$time  = $entry['time'] ?? null;
			$list  = $entry['snapshots'] ?? null;
			if ( is_int( $time ) && is_array( $list ) && $time <= time() && time() - $time < 5 * MINUTE_IN_SECONDS && ( $entry['policy'] ?? null ) === $policy ) {
				$lists[ $scope ] = [
					'time'      => $time,
					'policy'    => $policy,
					'snapshots' => $list,
				];
			}
		}
		return $lists;
	}

	/**
	 * One snapshot with the site's manifest, or manifest null if it has none.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function snapshot( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->browse(
			static function () use ( $request ): \Closure {
				$id = SnapshotBrowser::full_id( self::text( $request, 'snapshot' ) );
				return static fn ( SnapshotBrowser $browser ): array => [ 'snapshot' => $browser->snapshot( $id ) ];
			}
		);
	}

	/**
	 * One page of one directory of a snapshot. The path is an absolute path or the token of
	 * an entry, which names it byte for byte (ADR 0039).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function snapshot_tree( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->browse(
			static function () use ( $request ): \Closure {
				$id                 = SnapshotBrowser::full_id( self::text( $request, 'snapshot' ) );
				[ $offset, $limit ] = SnapshotBrowser::paging( $request->get_param( 'offset' ), $request->get_param( 'limit' ) );
				$path               = $request->get_param( 'path' );
				// No path is the root; a value that is no text is refused as a malformed path.
				$path = null === $path || '' === $path ? '/' : ( is_string( $path ) ? $path : '' );
				SnapshotBrowser::path( $path );
				return static fn ( SnapshotBrowser $browser ): array => $browser->directory( $id, $path, $offset, $limit );
			}
		);
	}

	/**
	 * Answers a read of a snapshot: 409 without a cached key, as the snapshot list does,
	 * and every refusal or error with the code and status that the browser assigns. The
	 * request is checked first, so a malformed ID, path or page is refused with 400 before
	 * the repository is opened, whatever state the repository is in.
	 *
	 * @param \Closure(): (\Closure(SnapshotBrowser): array<string, mixed>) $checked Checks the request and returns the read.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	private function browse( \Closure $checked ): \WP_REST_Response|\WP_Error {
		try {
			$read    = $checked();
			$browser = $this->container->snapshot_browser();
			if ( null === $browser ) {
				return $this->error( 'configuration', 409 );
			}
			return $this->respond( $read( $browser ) );
		} catch ( PluginException $e ) {
			[ $code, $status ] = SnapshotBrowser::failure( $e );
			return $this->error( $code, $status );
		}
	}

	/**
	 * The staging directories of finished restores, newest first, with the hours after which
	 * they expire and the number of directories that are being removed (ADR 0040).
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function restores(): \WP_REST_Response {
		return $this->respond( $this->staged() );
	}

	/**
	 * Issues the form fields for one download to an authorized administrator. A restored file
	 * or archive can hold wp-config.php and the database dump, which together decrypt the
	 * stored secrets, so its grant needs the WordPress password as the recovery kit does and
	 * is recorded (docs/03-security-and-threat-model.md §5.5). The reports hold names, counts
	 * and table metadata, which the page fetches by itself for its previews; they need the
	 * capability alone.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error One-time form, a refused confirmation, or a missing download.
	 */
	public function download( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$kind = self::text( $request, 'kind' );
		if ( ! Downloads::is_metadata( $kind ) ) {
			$refusal = $this->password_refusal( $request );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		$form = ( new Downloads( $this->container ) )->issue( Decoded::string( $request->get_url_params()['id'] ?? null ), $kind, self::text( $request, 'path' ) );
		return null === $form ? $this->error( 'download_not_found', 404 ) : $this->respond( $form, 200, true );
	}

	/**
	 * Removes the staging directory of one restore job. It leaves the list at once; when
	 * deleting a large tree takes longer than a request should, the heartbeat finishes it and
	 * `complete` is false.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function remove_restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = Decoded::string( $request->get_url_params()['id'] ?? null );
		try {
			$complete = $this->container->staging()->remove( $id, microtime( true ) + $this->container->tick_budget( self::REMOVAL_SECONDS ) );
		} catch ( InvalidArgument ) {
			return $this->error( 'invalid_job_id', 400 );
		} catch ( JobConflict $conflict ) {
			$context = $conflict->context();
			if ( 'restore_kept_for_undo' === ( $context['code'] ?? null ) && is_int( $context['until'] ?? null ) ) {
				return $this->kept_for_undo( $context['until'] );
			}
			if ( 'restore_in_use' === ( $context['code'] ?? null ) ) {
				return $this->error( 'restore_in_use', 409 );
			}
			return $this->error( 'job_unreadable' === JobError::from_throwable( $conflict )->code ? 'job_unreadable' : 'restore_active', 409 );
		}
		if ( null === $complete ) {
			return $this->error( 'restore_not_found', 404 );
		}
		return $this->respond(
			[
				'removed' => [
					'job'      => $id,
					'complete' => $complete,
				],
			] + $this->staged()
		);
	}

	/**
	 * The refusal to delete staged files that the undo of an apply still needs, with the time
	 * at which its recovery window ends, in the site's time zone and formats. The time is also
	 * in the data as `until`, a Unix time.
	 *
	 * @param int $until Unix time at which the undo window ends.
	 * @return \WP_Error Error.
	 */
	private function kept_for_undo( int $until ): \WP_Error {
		$format = Decoded::string( get_option( 'date_format' ), 'Y-m-d' ) . ' ' . Decoded::string( get_option( 'time_format' ), 'H:i' );
		return new \WP_Error(
			'backups_for_restic_restore_kept_for_undo',
			/* translators: %s: date and time when the recovery window of the apply ends. */
			sprintf( __( 'These staged files are kept until %s, because the undo of a live file change needs them.', 'backups-for-restic' ), (string) wp_date( $format, $until ) ),
			[
				'status' => 409,
				'hint'   => __( 'An apply that changed live files can be undone for 24 hours after it ended, and the undo needs these staged files to check each entry. Once the apply is undone completely, or those 24 hours have passed, the files can be deleted here; files whose own expiry has passed by then are removed automatically.', 'backups-for-restic' ),
				'until'  => $until,
			]
		);
	}

	/** Live writes can replace WordPress PHP files, including core and other plugins. */
	public static function live_writes_allowed(): bool {
		return self::allowed() && current_user_can( 'update_core' ) && wp_is_file_mod_allowed( 'backups_for_restic_apply' );
	}

	/** A live database exchange can replace roles, accounts, plugins and administration settings. */
	public static function database_writes_allowed(): bool {
		return self::database_user_allowed( get_current_user_id() );
	}

	/**
	 * Rechecks the initiating account when a background tick reaches the database exchange.
	 *
	 * @param int $user_id WordPress administrator that confirmed this operation.
	 * @return bool Whether the account retains all required capabilities.
	 */
	public static function database_user_allowed( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		return user_can( $user_id, self::capability() ) && user_can( $user_id, 'manage_options' ) && user_can( $user_id, 'edit_users' ) && user_can( $user_id, 'promote_users' ) && user_can( $user_id, 'update_core' );
	}

	/**
	 * Rechecks the account that confirmed a live file change, on every tick of the job. A
	 * process that runs many ticks, such as `wp backups-for-restic tick --until-idle`, keeps
	 * the account in WordPress's object cache, so a role that another request took away
	 * would count until the process ends. The check drops the account's cached row and
	 * metadata first and reads both from the database. A persistent object cache loses its
	 * copies too; the next reader stores them again from the database.
	 *
	 * @param int $user_id WordPress user who confirmed the change.
	 * @return bool Whether the account still has the backup capability and update_core.
	 */
	public static function live_user_allowed( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		wp_cache_delete( $user_id, 'users' );
		wp_cache_delete( $user_id, 'user_meta' );
		return user_can( $user_id, self::capability() ) && user_can( $user_id, 'update_core' );
	}

	/** Lists apply jobs whose changes can still be reversed. */
	public function undoable(): \WP_REST_Response {
		return $this->respond( [ 'jobs' => $this->container->file_changes()->undoable() ] );
	}

	/**
	 * One page of the entries that an undo kept, with their reasons.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Page or refusal.
	 */
	public function undo_kept( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$offset = $request->get_param( 'offset' ) ?? '0';
		if ( ! is_scalar( $offset ) || 1 !== preg_match( '/\A[0-9]{1,7}\z/', (string) $offset ) ) {
			return $this->error( 'invalid_plan_page', 400 );
		}
		try {
			return $this->respond( $this->container->file_changes()->kept_page( Decoded::string( $request->get_url_params()['id'] ?? null ), (int) $offset ) );
		} catch ( JobConflict $conflict ) {
			return 'kept_list_unavailable' === ( $conflict->context()['code'] ?? null ) ? $this->error( 'kept_list_unavailable', 409 ) : $this->error( 'not_found', 404 );
		} catch ( InvalidArgument ) {
			return $this->error( 'invalid_plan_page', 400 );
		} catch ( StorageFailed ) {
			// A sealed list whose summary or page is missing or changed. The undo's result stands.
			return $this->error( 'kept_list_unavailable', 409 );
		}
	}

	/** Lists database exchanges with a retained original generation available for undo. */
	public function database_undoable(): \WP_REST_Response {
		return $this->respond( [ 'jobs' => $this->container->database_changes()->undoable() ] );
	}

	/**
	 * Reviews the tables and compatibility of a rehearsed restore.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Plan or refusal.
	 */
	public function database_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->review_database( $request, false );
	}

	/**
	 * Reviews an exchange that would bring the retained tables back into service.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Plan or refusal.
	 */
	public function database_undo_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->review_database( $request, true );
	}

	/**
	 * Issues a session-specific review for the exact generations and preservation policy.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param bool                                   $undo Whether to restore retained tables.
	 * @return \WP_REST_Response|\WP_Error Reviewed plan or refusal.
	 */
	private function review_database( \WP_REST_Request $request, bool $undo ): \WP_REST_Response|\WP_Error {
		$id = Decoded::string( $request->get_url_params()['id'] ?? null );
		try {
			$service = $this->container->database_changes();
			$plan    = $undo ? $service->preview_undo( $id, get_current_user_id() ) : $service->preview( $id, get_current_user_id() );
		} catch ( InvalidArgument | JobConflict $refused ) {
			return $this->plan_error( $refused );
		}
		return $this->reviewed_response( $plan, $id, $undo, false, true );
	}

	/**
	 * Queues the separately confirmed live database exchange.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Queued job or refusal.
	 */
	public function apply_database( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start_database_change( $request, false );
	}

	/**
	 * Queues a separately reviewed reversal of a live database exchange.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Queued job or refusal.
	 */
	public function undo_database( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start_database_change( $request, true );
	}

	/**
	 * Checks the current user's password and reviewed database binding before scheduling.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param bool                                   $undo Whether to undo an earlier exchange.
	 * @return \WP_REST_Response|\WP_Error Queued job or refusal.
	 */
	private function start_database_change( \WP_REST_Request $request, bool $undo ): \WP_REST_Response|\WP_Error {
		$review = $this->confirmed_plan( $request, $undo, true );
		if ( $review instanceof \WP_Error ) {
			return $review;
		}
		[ $id, $digest ] = $review;
		try {
			$service = $this->container->database_changes();
			$job     = $undo ? $service->start_undo( $id, get_current_user_id(), $digest ) : $service->start_apply( $id, get_current_user_id(), $digest );
		} catch ( InvalidArgument | JobConflict $refused ) {
			return $this->plan_error( $refused );
		}
		Cron::chain( $this->container );
		return $this->respond( [ 'job' => $this->job_summary( $job ) ], 201, true );
	}

	/**
	 * Forgets a database restore binding whose private tables are on another database
	 * server or in another database. The current user confirms with their WordPress
	 * password in the JSON body, through the check that counts wrong passwords for every
	 * such route (password_refusal()). Nothing is dropped; the answer names the tables that
	 * a database administrator removes by hand where they are.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error The discarded binding, or a refusal.
	 */
	public function discard_database( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = Decoded::string( $request->get_url_params()['id'] ?? null );
		$refusal = $this->password_refusal( $request );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$lock = $this->container->runner_lock();
		if ( ! $lock->acquire() ) {
			return $this->error( 'job_conflict', 409 );
		}
		try {
			$binding = $this->container->database_changes()->discard( $id, get_current_user_id(), 'rest' );
		} catch ( JobConflict $refused ) {
			return $this->error( JobError::from_throwable( $refused )->code, 409 );
		} finally {
			$lock->release();
		}
		return $this->respond( [ 'discarded' => $binding ], 200, true );
	}

	/**
	 * Previews the live destinations of a staged restore.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Plan or refusal.
	 */
	public function apply_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->live_preview( $request, false );
	}

	/**
	 * Previews reversing the changes a previous apply made.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Plan or refusal.
	 */
	public function undo_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->live_preview( $request, true );
	}

	/**
	 * Builds a read-only plan. Only an applicable plan gets a proof of review bound to
	 * these operations, the authenticated session and the selected configuration scope.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param bool                                   $undo    Whether to inspect an undo.
	 * @return \WP_REST_Response|\WP_Error Plan or refusal.
	 */
	private function live_preview( \WP_REST_Request $request, bool $undo ): \WP_REST_Response|\WP_Error {
		$id             = Decoded::string( $request->get_url_params()['id'] ?? null );
		$include_config = $request->get_param( 'include_config' ) ?? false;
		if ( ! is_bool( $include_config ) || ( $undo && $include_config ) ) {
			return $this->error( 'invalid_config_choice', 400 );
		}
		$plan_job = $request->get_param( 'plan_job' );
		$offset   = $request->get_param( 'offset' ) ?? 0;
		if ( ( null !== $plan_job && ( ! is_string( $plan_job ) || 1 !== preg_match( JobStore::ID_PATTERN, $plan_job ) ) ) || ! is_int( $offset ) || $offset < 0 || ( $offset > 0 && null === $plan_job ) ) {
			return $this->error( 'invalid_plan_page', 400 );
		}
		try {
			$plan = $this->container->file_changes()->request_preview( $id, $undo, $include_config, $plan_job, $offset );
		} catch ( PluginException $refused ) {
			return $this->file_error( $refused );
		}
		if ( $plan instanceof Job ) {
			Cron::chain( $this->container );
			return $this->respond( [ 'job' => $this->job_summary( $plan ) ], 202, true );
		}
		return $this->reviewed_response( $plan, $id, $undo, $include_config, false );
	}

	/**
	 * Provides a confirmation token only for an applicable plan this user may write.
	 *
	 * @param LiveReview $plan Inspected plan.
	 * @param string     $id Source restore or prior apply job.
	 * @param bool       $undo Whether to undo changes.
	 * @param bool       $include_config Whether the file review includes wp-config.php.
	 * @param bool       $database Whether the review concerns database tables.
	 * @return \WP_REST_Response Reviewed plan.
	 */
	private function reviewed_response( array $plan, string $id, bool $undo, bool $include_config, bool $database ): \WP_REST_Response {
		$plan['site_url']      = home_url();
		$plan['write_allowed'] = $database ? self::database_writes_allowed() : self::live_writes_allowed();
		$plan['review_token']  = $plan['applicable'] && $plan['write_allowed'] ? wp_create_nonce( self::review_action( $undo, $id, $include_config, $plan['digest'], $plan['plan_job'] ?? null, $database ) ) : null;
		// A job that a confirmation of this review starts is created at this time or later: the
		// page looks for it by its type and this time when the answer to the confirmation is lost.
		$plan['reviewed_at'] = time();
		return $this->respond( $plan, 200, true );
	}

	/**
	 * Queues the reviewed file changes for a staged restore.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error New job or refusal.
	 */
	public function apply_files( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start_live_change( $request, false );
	}

	/**
	 * Queues undo for one apply job.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error New job or refusal.
	 */
	public function undo_files( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start_live_change( $request, true );
	}

	/**
	 * Re-authenticates the administrator and checks the reviewed plan. Sensitive values
	 * must come in the JSON body. The job only receives its source and plan digest.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param bool                                   $undo    Whether to undo changes.
	 * @return \WP_REST_Response|\WP_Error New job or refusal.
	 */
	private function start_live_change( \WP_REST_Request $request, bool $undo ): \WP_REST_Response|\WP_Error {
		$review = $this->confirmed_plan( $request, $undo, false );
		if ( $review instanceof \WP_Error ) {
			return $review;
		}
		[ $id, $digest, $include_config, $plan_job ] = $review;
		$control                                     = bin2hex( $this->container->random()->bytes( 32 ) );
		$hash                                        = hash( 'sha256', $control );
		try {
			$service = $this->container->file_changes();
			$job     = $undo ? $service->start_undo( $id, $digest, $hash, $plan_job, get_current_user_id() ) : $service->start_apply( $id, $include_config, $digest, $hash, $plan_job, get_current_user_id() );
		} catch ( PluginException $refused ) {
			return $this->file_error( $refused );
		}
		Cron::chain( $this->container );
		// Maintenance permits this job's control requests before WordPress performs its
		// ordinary authentication and capability checks. Only the initiating client gets the token.
		$response = $this->respond(
			[
				'job'           => $this->job_summary( $job ),
				'control_token' => $control,
			],
			201,
			true
		);
		$response->header( 'Set-Cookie', 'bfr_file_control=' . $control . '; Path=/; Max-Age=' . DAY_IN_SECONDS . '; HttpOnly; SameSite=Strict' . ( is_ssl() ? '; Secure' : '' ) );
		return $response;
	}

	/**
	 * Checks an explicit JSON-body confirmation against the signed review and the current user.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param bool                                   $undo Whether to reverse a previous change.
	 * @param bool                                   $database Whether the operation changes database tables.
	 * @return array{string, string, bool, string|null}|\WP_Error Source, digest, configuration scope and background plan, or refusal.
	 */
	private function confirmed_plan( \WP_REST_Request $request, bool $undo, bool $database ): array|\WP_Error {
		$id   = Decoded::string( $request->get_url_params()['id'] ?? null );
		$body = $request->get_json_params();
		if ( true !== ( $body['reviewed'] ?? null ) || home_url() !== ( $body['site_url'] ?? null ) ) {
			return $this->error( 'live_confirmation', 400 );
		}
		$include_config = $body['include_config'] ?? false;
		$digest         = $body['digest'] ?? null;
		$token          = $body['review_token'] ?? null;
		$plan_job       = $body['plan_job'] ?? null;
		if ( null !== $plan_job && ( $database || ! is_string( $plan_job ) || 1 !== preg_match( JobStore::ID_PATTERN, $plan_job ) ) ) {
			return $this->error( $database ? 'database_exchange_changed' : 'live_plan_changed', 409 );
		}
		if ( ! is_bool( $include_config ) || ( ( $undo || $database ) && $include_config ) || ! is_string( $digest ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $digest ) || ! is_string( $token ) || false === wp_verify_nonce( $token, self::review_action( $undo, $id, $include_config, $digest, $plan_job, $database ) ) ) {
			return $this->error( $database ? 'database_exchange_changed' : 'live_plan_changed', 409 );
		}
		$refusal = $this->password_refusal( $request );
		if ( null !== $refusal ) {
			return $refusal;
		}
		return [ $id, $digest, $include_config, $plan_job ];
	}

	/**
	 * Binds a preview to the site, operation, originating job, file scope and inspected data.
	 *
	 * @param bool        $undo    Undo an apply.
	 * @param string      $id      Source job.
	 * @param bool        $include_config Include wp-config.php.
	 * @param string      $digest  Fingerprint of the inspected changes.
	 * @param string|null $plan_job Reviewed background plan, if the selection needed one.
	 * @param bool        $database Whether the review concerns database tables.
	 * @return string WordPress nonce action.
	 */
	private static function review_action( bool $undo, string $id, bool $include_config, string $digest, ?string $plan_job, bool $database ): string {
		return implode( '|', [ 'backups_for_restic_live', home_url(), $database ? 'tables' : 'files', $undo ? 'undo' : 'apply', $id, $include_config ? 'with-config' : 'default', $digest, $plan_job ?? '' ] );
	}

	/**
	 * Reports a refused database review or confirmation without returning details from an
	 * exception.
	 *
	 * @param InvalidArgument|JobConflict $refused Reason.
	 * @return \WP_Error Error.
	 */
	private function plan_error( InvalidArgument|JobConflict $refused ): \WP_Error {
		if ( $refused instanceof JobConflict ) {
			return $this->error( JobError::from_throwable( $refused )->code, 409 );
		}
		return $this->error( 'database_review_required', 400 );
	}

	/**
	 * Reports a refused or failed file preview or confirmation without returning filesystem
	 * details from an exception. A file that changed after the review asks for a new preview;
	 * a refusal with a code of its own keeps it; a private record of the restore or apply that
	 * cannot be read or written is a failure of its own (`live_plan_failed`), not a backup that
	 * this server cannot run.
	 *
	 * @param PluginException $refused Reason.
	 * @return \WP_Error Error.
	 */
	private function file_error( PluginException $refused ): \WP_Error {
		$code = JobError::from_throwable( $refused )->code;
		if ( 'file_changed_after_review' === $code ) {
			return $this->error( 'live_plan_changed', 409 );
		}
		if ( $refused instanceof JobConflict ) {
			return $this->error( in_array( $code, [ 'file_space', 'file_writes_refused', 'undo_nothing_to_revert', 'undo_unavailable', 'file_preview_expired', 'job_unreadable' ], true ) ? $code : 'live_plan_conflict', 409 );
		}
		if ( $refused instanceof InvalidArgument ) {
			return $this->error( in_array( $code, [ 'protected_path_invalid', 'file_staging_gone' ], true ) ? $code : 'live_plan_unavailable', 400 );
		}
		return $this->error( 'live_plan_failed', 500 );
	}

	/**
	 * What GET /restores answers.
	 *
	 * @return array{restores: list<array<string, bool|int|string|null>>, removing: int, expiry_hours: int} The list, the number of directories on their way out, and the setting.
	 */
	private function staged(): array {
		$hours   = $this->container->settings()->int( 'restore.staging_expiry_hours' );
		$staging = $this->container->staging();
		return [
			'restores'     => array_map( static fn ( StagedRestore $restore ): array => $restore->to_array(), $staging->listing( $hours ) ),
			'removing'     => $staging->removing( $hours ),
			'expiry_hours' => $hours,
		];
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
				'downloads'   => $this->download_grants(),
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
			// never has a certificate that is not its own. Nor does a server that wp-config.php
			// names get the certificate of another address that was tested.
			$this->setup()->save_server( self::text( $request, 'server' ), $report, self::text( $request, 'username' ), $this->secret_param( $request, 'password' ), fn ( string $url ): \Closure => $this->keep_candidate_ca( $request, $url === $report->url ) );
		} catch ( JobConflict $conflict ) {
			return $this->error( JobError::from_throwable( $conflict )->code, 409 );
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
	 * @param bool                                   $tested  Whether the address the jobs will use is the one the request tested. With an
	 *                                                        address from wp-config.php it may not be; then the stored certificate
	 *                                                        belongs to that address and stays as it is.
	 * @return \Closure(): void What puts certificate and setting back as they were.
	 * @throws PluginException If the certificate cannot be written.
	 * @throws \Throwable Whatever else stopped the change, after the certificate was put back.
	 */
	private function keep_candidate_ca( \WP_REST_Request $request, bool $tested ): \Closure {
		if ( ! $tested ) {
			return static function (): void {};
		}
		$pem = $this->candidate_ca( $request );
		if ( is_string( $pem ) ) {
			return $this->change_ca( $pem );
		}
		if ( 'rest-server' !== self::text( $request, 'server' ) ) {
			return $this->change_ca( null );
		}
		return static function (): void {};
	}

	/**
	 * Updates the certificate file and its setting together, while the job store holds
	 * the configuration lock. A server save can undo this change if a later write fails.
	 *
	 * @param string|null $pem New certificate, or null to remove it.
	 * @return \Closure(): void Restores the previous file and setting.
	 * @throws StorageFailed If the certificate or setting cannot be changed.
	 * @throws \Throwable On a failed settings write, after restoring the previous certificate.
	 */
	private function change_ca( ?string $pem ): \Closure {
		$directory = $this->container->private_dir();
		$file      = $directory->path . '/ca.pem';
		$store     = $this->container->settings_store();
		$setting   = $store->load( true )->string( 'repository.ca_certificate' );
		$stored    = is_file( $file ) ? file_get_contents( $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preserve the local file byte for byte during rollback.
		if ( false === $stored ) {
			StorageFailed::raise( 'The previous CA certificate could not be read.' );
		}
		$write = static function ( ?string $contents ) use ( $file ): void {
			if ( null !== $contents ) {
				Files::write_atomic( $file, $contents );
			} else {
				wp_delete_file( $file );
				if ( file_exists( $file ) || is_link( $file ) ) {
					StorageFailed::raise( 'The CA certificate could not be removed.' );
				}
			}
		};
		$write( $pem );
		try {
			$store->update( [ 'repository.ca_certificate' => null === $pem ? 'none' : 'uploaded' ] );
		} catch ( \Throwable $failure ) {
			$write( $stored );
			throw $failure;
		}
		return static function () use ( $write, $stored, $store, $setting ): void {
			$write( $stored );
			$store->update( [ 'repository.ca_certificate' => $setting ] );
		};
	}

	/**
	 * Creates a new repository and returns the recovery kit once.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function create_repository( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$refusal = $this->kit_refusal( $request );
		if ( null !== $refusal ) {
			return $refusal;
		}
		try {
			// The answer is the recovery kit: what the kit needs has to be there before a repository exists.
			RecoveryKit::ca_certificate( $this->container );
			$this->setup()->create( $this->secret_param( $request, 'password' ) );
			$this->container->refresh();
			delete_transient( 'backups_for_restic_snapshots' );
			$kit = RecoveryKit::deliver( $this->container, 'create' );
		} catch ( JobConflict $conflict ) {
			return $this->error( JobError::from_throwable( $conflict )->code, 409 );
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
		$refusal = $this->kit_refusal( $request );
		if ( null !== $refusal ) {
			return $refusal;
		}
		try {
			// The answer contains the recovery kit: what the kit needs has to be there first.
			RecoveryKit::ca_certificate( $this->container );
			$job = $this->setup()->connect( $this->secret_param( $request, 'password' ), time() );
		} catch ( JobConflict $conflict ) {
			return $this->error( JobError::from_throwable( $conflict )->code, 409 );
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
			$kit = RecoveryKit::deliver( $this->container, 'connect' );
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
		return $this->save_ca( $pem );
	}

	/**
	 * Removes the CA certificate.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function delete_ca(): \WP_REST_Response|\WP_Error {
		return $this->save_ca( null );
	}

	/**
	 * Changes the configured trust bundle between jobs, under the same lock as a server save.
	 *
	 * @param string|null $pem Certificate, or null for the public authorities.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	private function save_ca( ?string $pem ): \WP_REST_Response|\WP_Error {
		try {
			$this->container->jobs()->while_idle( fn () => $this->change_ca( $pem ) );
		} catch ( JobConflict $conflict ) {
			return $this->error( JobError::from_throwable( $conflict )->code, 409 );
		}
		$this->container->refresh();
		return $this->respond( [ 'ca_certificate' => null === $pem ? 'none' : 'uploaded' ] );
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
		$refusal = $this->password_refusal( $request );
		if ( null !== $refusal ) {
			return $refusal;
		}
		try {
			$kit = RecoveryKit::deliver( $this->container, 'settings' );
		} catch ( PluginException $e ) {
			return $this->kit_error( $e, 409 );
		}
		return $this->respond( [ 'recovery_kit' => $kit ], 200, true );
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
	 * The latest grants to download restored files and archives, for the Settings tab next to
	 * the deliveries of the recovery kit.
	 *
	 * @return list<array{at: int, login: string, job: string, kind: string, path: string}> Newest first.
	 */
	private function download_grants(): array {
		$grants = [];
		foreach ( Decoded::arrays( $this->container->state()->get( Downloads::AUDIT )['grants'] ?? null ) as $grant ) {
			$grants[] = [
				'at'    => Decoded::int( $grant['at'] ?? null ),
				'login' => Decoded::string( $grant['login'] ?? null ),
				'job'   => Decoded::string( $grant['job'] ?? null ),
				'kind'  => Decoded::string( $grant['kind'] ?? null ),
				'path'  => Decoded::string( $grant['path'] ?? null ),
			];
		}
		return array_slice( $grants, 0, 5 );
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
	 * Checks the WordPress password of the current user, as proof that the administrator is
	 * present and not only a session of theirs. Every route that asks for the password asks
	 * here (docs/03-security-and-threat-model.md §5.5). The password is taken from the JSON
	 * body only, never from the address, which access logs keep. Wrong passwords are counted
	 * per user; after too many the password is not checked for a while (PasswordConfirmation).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_Error|null Null if the password is right, otherwise the refusal.
	 */
	private function password_refusal( \WP_REST_Request $request ): ?\WP_Error {
		$checker = new PasswordConfirmation( new ConfirmationThrottle( $this->container->state(), $this->container->clock() ) );
		$refusal = $checker->check( $request->get_json_params()['user_password'] ?? null );
		if ( null === $refusal ) {
			return null;
		}
		if ( 'user_password_throttled' !== $refusal['code'] ) {
			return $this->error( 'wrong_user_password', 403 );
		}
		$texts = Messages::password_throttled( $refusal['wait'] );
		return new \WP_Error(
			'backups_for_restic_user_password_throttled',
			$texts['message'],
			[
				'status'      => 429,
				'hint'        => $texts['hint'],
				'retry_after' => $refusal['wait'],
			]
		);
	}

	/**
	 * Whether a request may get the recovery kit as part of its answer: freely during the
	 * setup, when the wizard shows the kit once; after it only with the WordPress password, as
	 * for the recovery kit route (docs/03-security-and-threat-model.md §5.5).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_Error|null Null if allowed, otherwise the refusal.
	 */
	private function kit_refusal( \WP_REST_Request $request ): ?\WP_Error {
		return $this->container->settings()->bool( 'setup.completed' ) ? $this->password_refusal( $request ) : null;
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
		return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN );
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
			'step_label'       => Messages::step( $job->step, $job->type->value ),
			'created_at'       => $job->created_at,
			'started_at'       => $job->started_at,
			'finished_at'      => $job->finished_at,
			'cancel_requested' => $job->cancel_requested,
			'ticks'            => $job->ticks,
			'progress'         => $job->progress,
			'result'           => $job->result,
			'warnings'         => $job->warnings,
			'error'            => $error,
			'user'             => self::job_user( $job ),
		];
	}

	/**
	 * Who started a job that records it: a restore, or a database change that its
	 * administrator confirmed.
	 *
	 * @param Job $job Job.
	 * @return array{id: int, login: string}|null User ID and login, '' for a deleted account; null if the job records none.
	 */
	private static function job_user( Job $job ): ?array {
		$id = $job->data['user'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return null;
		}
		$user = get_userdata( $id );
		return [
			'id'    => $id,
			'login' => $user instanceof \WP_User ? $user->user_login : '',
		];
	}

	/**
	 * A JSON response with explicit cache policy.
	 *
	 * @param array<string, mixed> $data      Data.
	 * @param int                  $status    HTTP status.
	 * @param bool                 $sensitive Whether the response carries credentials or a temporary grant.
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
			'kept_list_unavailable'         => [
				'message' => __( 'The list of the entries that this undo kept is not available.', 'backups-for-restic' ),
				'hint'    => __( 'The undo recorded what it did, and its result has the counts. The list of the entries it kept could not be sealed, or a part of it is missing or was changed since; if it could not be sealed, the job\'s log says why. Why that undo kept each entry is recorded nowhere else. While the recovery window of the apply lasts, a new undo preview shows what can be reverted now.', 'backups-for-restic' ),
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
				'message' => __( 'Jobs can be of type backup, forget, prune, check or restore.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_snapshot'              => [
				'message' => __( 'A restore needs the full ID of a snapshot.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_plan_page'             => [
				'message' => __( 'This file preview page is invalid.', 'backups-for-restic' ),
				'hint'    => __( 'Open the preview again.', 'backups-for-restic' ),
			],
			'invalid_config_choice'         => [
				'message' => __( 'Choose whether to include wp-config.php.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'live_plan_unavailable'         => [
				'message' => __( 'A file change plan is unavailable for this job.', 'backups-for-restic' ),
				'hint'    => __( 'Check the source job and its expiry, then open the preview again.', 'backups-for-restic' ),
			],
			'live_plan_conflict'            => [
				'message' => __( 'The file change cannot start in the current state.', 'backups-for-restic' ),
				'hint'    => __( 'Wait for active work to finish and review a fresh preview.', 'backups-for-restic' ),
			],
			'live_plan_changed'             => [
				'message' => __( 'Review a fresh preview of these file changes.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'live_plan_failed'              => [
				'message' => __( 'The plugin could not finish this file preview or confirmation.', 'backups-for-restic' ),
				'hint'    => __( 'A file may have changed while it was read, or a private record of this restore or apply could not be read or written. Open the preview again. If it fails again, check the working directory and the free disk space on the Environment tab.', 'backups-for-restic' ),
			],
			'live_confirmation'             => [
				'message' => __( 'Review the listed changes, acknowledge them and type the exact site address.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_job_id'                => [
				'message' => __( 'This is not the ID of a job.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_database_preview'      => [
				'message' => __( 'The database rehearsal option must be true or false.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'invalid_fresh_install'         => [
				'message' => __( 'A fresh-install restore uses the site manifest to select files and requires a database rehearsal.', 'backups-for-restic' ),
				'hint'    => __( 'Choose a site snapshot containing the database.', 'backups-for-restic' ),
			],
			'invalid_archive'               => [
				'message' => __( 'The archive option must be true or false.', 'backups-for-restic' ),
				'hint'    => '',
			],
			'download_not_found'            => [
				'message' => __( 'This staged download is unavailable.', 'backups-for-restic' ),
				'hint'    => __( 'Check the file path and whether the restore has expired.', 'backups-for-restic' ),
			],
			'restore_not_found'             => [
				'message' => __( 'No restored files are kept for this job.', 'backups-for-restic' ),
				'hint'    => __( 'They were removed already, or the restore did not succeed.', 'backups-for-restic' ),
			],
			'restore_active'                => [
				'message' => __( 'This restore is still running.', 'backups-for-restic' ),
				'hint'    => __( 'Wait for it to finish or cancel it. A cancelled restore keeps no files.', 'backups-for-restic' ),
			],
			'restore_in_use'                => [
				'message' => __( 'A job that uses these staged files is still running.', 'backups-for-restic' ),
				'hint'    => __( 'Wait for it to finish or cancel it, then delete the files.', 'backups-for-restic' ),
			],
			'invalid_paths'                 => [
				'message' => __( 'The paths to restore are not valid.', 'backups-for-restic' ),
				'hint'    => __( 'Name up to 256 paths as the snapshot holds them, each starting with a slash, or none for the whole snapshot.', 'backups-for-restic' ),
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
			default                         => Messages::snapshot_error( $code ),
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
