<?php
/**
 * What a restore job is asked to restore.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Engine\Restore\Selection;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The snapshot and the paths of a restore job, checked before the job exists and again when
 * a step reads them from the job document.
 *
 * The paths travel through job.json, which is UTF-8. A path with other bytes would come back
 * changed and name another entry, so it is refused here; such an entry is restored by
 * selecting the directory that holds it.
 */
final readonly class RestoreRequest {

	/**
	 * Creates the request.
	 *
	 * @param string       $snapshot Snapshot ID, 64 hexadecimal characters.
	 * @param list<string> $paths    Canonical, disjoint snapshot paths.
	 */
	private function __construct( public string $snapshot, public array $paths ) {
	}

	/**
	 * Checks what a request or a job document carries.
	 *
	 * @param mixed $snapshot Snapshot ID.
	 * @param mixed $paths    List of absolute snapshot paths; null selects the whole snapshot.
	 * @return self The request.
	 * @throws InvalidArgument With the code invalid_snapshot or invalid_paths.
	 */
	public static function from_input( mixed $snapshot, mixed $paths ): self {
		if ( ! is_string( $snapshot ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $snapshot ) ) {
			InvalidArgument::raise( 'A restore needs the full ID of a snapshot.', [ 'code' => 'invalid_snapshot' ] );
		}
		$paths ??= [ '/' ];
		// The number is checked before the entries are: a request must not make the loop long.
		if ( ! is_array( $paths ) || ! array_is_list( $paths ) || [] === $paths || count( $paths ) > Selection::MAX_PATHS ) {
			InvalidArgument::raise( 'A restore needs a list of snapshot paths.', [ 'code' => 'invalid_paths' ] );
		}
		$checked = [];
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! mb_check_encoding( $path, 'UTF-8' ) ) {
				InvalidArgument::raise( 'A restore path must be a string of valid UTF-8.', [ 'code' => 'invalid_paths' ] );
			}
			$checked[] = $path;
		}
		try {
			$selection = new Selection( $checked );
		} catch ( InvalidArgument $refused ) {
			InvalidArgument::raise( $refused->getMessage(), [ 'code' => 'invalid_paths' ], $refused );
		}
		return new self( $snapshot, $selection->paths );
	}

	/**
	 * Reads the request of a job.
	 *
	 * @param Job $job Restore job.
	 * @return self The request.
	 * @throws InvalidArgument If the job document does not hold a valid request.
	 */
	public static function from_job( Job $job ): self {
		return self::from_input( $job->data['snapshot'] ?? null, $job->data['paths'] ?? null );
	}

	/**
	 * The step data a restore job starts with.
	 *
	 * @return array{snapshot: string, paths: list<string>} Data for JobStore::create().
	 */
	public function to_data(): array {
		return [
			'snapshot' => $this->snapshot,
			'paths'    => $this->paths,
		];
	}
}
