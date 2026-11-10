<?php
/**
 * One notification.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Notifications;

use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Something an administrator hears about by e-mail. It carries codes, times and counts only:
 * the WordPress layer writes the sentences, so no secret, path or log line reaches an e-mail.
 */
final readonly class Notice {

	/**
	 * Creates a notice.
	 *
	 * @param NoticeKind $kind     What happened.
	 * @param int        $at       Unix time of the event.
	 * @param string     $job_type Job type, or empty for a stale notice.
	 * @param string     $job_id   The job that caused it; for a stale notice the last successful
	 *                             backup, empty if there was none.
	 * @param string     $code     Error code of a failure.
	 * @param int        $since    Unix time the problem started (recovery), or of the last
	 *                             successful backup or the setup (stale).
	 * @param int        $count    Errors a check found.
	 */
	public function __construct(
		public NoticeKind $kind,
		public int $at,
		public string $job_type = '',
		public string $job_id = '',
		public string $code = '',
		public int $since = 0,
		public int $count = 0
	) {
	}

	/**
	 * Fields for storage.
	 *
	 * @return array{kind: string, at: int, job_type: string, job_id: string, code: string, since: int, count: int} Fields.
	 */
	public function to_array(): array {
		return [
			'kind'     => $this->kind->value,
			'at'       => $this->at,
			'job_type' => $this->job_type,
			'job_id'   => $this->job_id,
			'code'     => $this->code,
			'since'    => $this->since,
			'count'    => $this->count,
		];
	}

	/**
	 * Restores a stored notice.
	 *
	 * @param array<mixed> $fields Stored fields.
	 * @return self|null Notice, or null if the kind is unknown.
	 */
	public static function from_array( array $fields ): ?self {
		$kind = NoticeKind::tryFrom( Decoded::string( $fields['kind'] ?? null ) );
		if ( null === $kind ) {
			return null;
		}
		return new self(
			$kind,
			Decoded::int( $fields['at'] ?? null ),
			Decoded::string( $fields['job_type'] ?? null ),
			Decoded::string( $fields['job_id'] ?? null ),
			Decoded::string( $fields['code'] ?? null ),
			Decoded::int( $fields['since'] ?? null ),
			Decoded::int( $fields['count'] ?? null )
		);
	}
}
