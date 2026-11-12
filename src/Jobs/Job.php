<?php
/**
 * One unit of background work.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * A job as stored in jobs/<id>/job.json (ADR 0014). Steps read and update it during a tick;
 * the runner persists it afterwards. It never holds secrets: steps keep sensitive state in
 * the private directory's kdf/ and cache/ areas, outside jobs/.
 */
final class Job {

	/**
	 * The layout of job.json that this version reads and writes. It is raised with every
	 * change that a version without the change would misread; a document with a higher number
	 * is not loaded.
	 */
	public const int SCHEMA = 1;

	/**
	 * Where the job is in its life.
	 *
	 * @var JobState
	 */
	public JobState $state = JobState::Queued;

	/**
	 * Current step; empty while queued.
	 *
	 * @var string
	 */
	public string $step = '';

	/**
	 * Unix time of the first tick.
	 *
	 * @var int
	 */
	public int $started_at = 0;

	/**
	 * Unix time of the last change.
	 *
	 * @var int
	 */
	public int $updated_at = 0;

	/**
	 * Unix time the job ended.
	 *
	 * @var int
	 */
	public int $finished_at = 0;

	/**
	 * Whether an administrator asked to cancel.
	 *
	 * @var bool
	 */
	public bool $cancel_requested = false;

	/**
	 * Token of the tick that currently advances the job.
	 *
	 * @var string
	 */
	public string $lease_owner = '';

	/**
	 * Unix time at which a lease from a killed tick may be taken over.
	 *
	 * @var int
	 */
	public int $lease_expires_at = 0;

	/**
	 * Number of ticks so far.
	 *
	 * @var int
	 */
	public int $ticks = 0;

	/**
	 * Seconds spent in ticks so far.
	 *
	 * @var float
	 */
	public float $seconds = 0.0;

	/**
	 * Retryable errors in a row.
	 *
	 * @var int
	 */
	public int $consecutive_failures = 0;

	/**
	 * Unix time before which no tick advances the job, after a retryable error.
	 *
	 * @var int
	 */
	public int $not_before = 0;

	/**
	 * The terminal state the cleanup steps lead to.
	 *
	 * @var JobState|null
	 */
	public ?JobState $outcome = null;

	/**
	 * Why the job failed.
	 *
	 * @var ?JobError
	 */
	public ?JobError $error = null;

	/**
	 * Progress counters for the UI.
	 *
	 * @var array<string, int|string>
	 */
	public array $progress = [];

	/**
	 * Results such as the snapshot ID.
	 *
	 * @var array<string, int|string|bool|null>
	 */
	public array $result = [];

	/**
	 * Step state that must survive between ticks; never secrets.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * Warnings for the administrator, at most 50.
	 *
	 * @var list<string>
	 */
	public array $warnings = [];

	/**
	 * Creates a job.
	 *
	 * @param string  $id         Sortable unique ID.
	 * @param JobType $type       Type.
	 * @param string  $trigger    What started it: manual, schedule, cli or setup.
	 * @param int     $created_at Unix time.
	 */
	public function __construct(
		public readonly string $id,
		public readonly JobType $type,
		public readonly string $trigger,
		public readonly int $created_at
	) {
		$this->updated_at = $created_at;
	}

	/**
	 * Adds a warning, keeping the first 50.
	 *
	 * @param string $warning Text without secrets.
	 */
	public function warn( string $warning ): void {
		if ( count( $this->warnings ) < 50 ) {
			$this->warnings[] = $warning;
		}
	}

	/**
	 * For job.json and the REST API.
	 *
	 * @return array<string, mixed> Fields.
	 */
	public function to_array(): array {
		return [
			'schema'               => self::SCHEMA,
			'id'                   => $this->id,
			'type'                 => $this->type->value,
			'trigger'              => $this->trigger,
			'state'                => $this->state->value,
			'step'                 => $this->step,
			'created_at'           => $this->created_at,
			'started_at'           => $this->started_at,
			'updated_at'           => $this->updated_at,
			'finished_at'          => $this->finished_at,
			'cancel_requested'     => $this->cancel_requested,
			'lease_owner'          => $this->lease_owner,
			'lease_expires_at'     => $this->lease_expires_at,
			'ticks'                => $this->ticks,
			'seconds'              => round( $this->seconds, 3 ),
			'consecutive_failures' => $this->consecutive_failures,
			'not_before'           => $this->not_before,
			'outcome'              => $this->outcome?->value,
			'error'                => $this->error?->to_array(),
			'progress'             => $this->progress,
			'result'               => $this->result,
			'data'                 => $this->data,
			'warnings'             => $this->warnings,
		];
	}

	/**
	 * Restores a job from job.json.
	 *
	 * @param array<mixed> $fields Decoded JSON.
	 * @return self Job.
	 * @throws FormatException If required fields are missing, or if a newer version of the
	 *                         plugin wrote the document.
	 */
	public static function from_array( array $fields ): self {
		// Documents written before the number existed have the first layout.
		$schema = array_key_exists( 'schema', $fields ) ? $fields['schema'] : 1;
		if ( ! is_int( $schema ) || $schema < 1 || $schema > self::SCHEMA ) {
			throw new FormatException( 'The job document has a layout this version does not read.' );
		}
		$type  = JobType::tryFrom( Decoded::string( $fields['type'] ?? null ) );
		$state = JobState::tryFrom( Decoded::string( $fields['state'] ?? null ) );
		if ( null === $type || null === $state || '' === Decoded::string( $fields['id'] ?? null ) ) {
			throw new FormatException( 'The job document is incomplete.' );
		}
		$job                       = new self( Decoded::string( $fields['id'] ), $type, Decoded::string( $fields['trigger'] ?? '' ), Decoded::int( $fields['created_at'] ?? 0 ) );
		$job->state                = $state;
		$job->step                 = Decoded::string( $fields['step'] ?? '' );
		$job->started_at           = Decoded::int( $fields['started_at'] ?? 0 );
		$job->updated_at           = Decoded::int( $fields['updated_at'] ?? 0 );
		$job->finished_at          = Decoded::int( $fields['finished_at'] ?? 0 );
		$job->cancel_requested     = true === ( $fields['cancel_requested'] ?? false );
		$job->lease_owner          = Decoded::string( $fields['lease_owner'] ?? '' );
		$job->lease_expires_at     = Decoded::int( $fields['lease_expires_at'] ?? 0 );
		$job->ticks                = Decoded::int( $fields['ticks'] ?? 0 );
		$job->seconds              = Decoded::number( $fields['seconds'] ?? null );
		$job->consecutive_failures = Decoded::int( $fields['consecutive_failures'] ?? 0 );
		$job->not_before           = Decoded::int( $fields['not_before'] ?? 0 );
		$job->outcome              = is_string( $fields['outcome'] ?? null ) ? JobState::tryFrom( $fields['outcome'] ) : null;
		$error                     = $fields['error'] ?? null;
		if ( is_array( $error ) ) {
			$job->error = new JobError( Decoded::string( $error['code'] ?? 'internal' ), Decoded::string( $error['message'] ?? '' ), true === ( $error['retryable'] ?? false ) );
		}
		$job->progress = array_filter( self::scalars( $fields['progress'] ?? [] ), static fn ( mixed $value ): bool => is_int( $value ) || is_string( $value ) );
		$job->result   = self::scalars( $fields['result'] ?? [] );
		$job->data     = is_array( $fields['data'] ?? null ) ? self::string_keys( $fields['data'] ) : [];
		$job->warnings = is_array( $fields['warnings'] ?? null ) ? array_values( array_filter( $fields['warnings'], 'is_string' ) ) : [];
		return $job;
	}

	/**
	 * Keeps string keys with scalar values.
	 *
	 * @param mixed $value Decoded value.
	 * @return array<string, int|string|bool|null> Filtered map.
	 */
	private static function scalars( mixed $value ): array {
		$result = [];
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && ( is_int( $item ) || is_string( $item ) || is_bool( $item ) || null === $item ) ) {
					$result[ $key ] = $item;
				}
			}
		}
		return $result;
	}

	/**
	 * Keeps string keys.
	 *
	 * @param array<mixed> $value Decoded map.
	 * @return array<string, mixed> Filtered map.
	 */
	private static function string_keys( array $value ): array {
		$result = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}
