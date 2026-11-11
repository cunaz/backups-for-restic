<?php
/**
 * What happened in a job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

use BackupsForRestic\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only log of one job in jobs/<id>/events.log: one JSON object per line with time,
 * level, message and context. Redacted and capped at 1 MiB, so a long-running job cannot fill
 * the disk of a shared host.
 */
final class EventLog {

	public const int MAX_BYTES = 1048576;

	/**
	 * Opens the log.
	 *
	 * @param string   $file     Log file.
	 * @param Redactor $redactor Removes secrets.
	 */
	public function __construct( private readonly string $file, private readonly Redactor $redactor ) {
	}

	/**
	 * Appends an entry.
	 *
	 * @param string                     $level   info, warning or error.
	 * @param string                     $message Message.
	 * @param array<string, scalar|null> $context Details.
	 */
	public function write( string $level, string $message, array $context = [] ): void {
		clearstatcache( true, $this->file );
		$size = is_file( $this->file ) ? (int) filesize( $this->file ) : 0;
		if ( $size >= self::MAX_BYTES ) {
			return;
		}
		$line = json_encode(
			[
				'time'    => time(),
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ( false === $line ) {
			return;
		}
		$line = $this->redactor->redact( $line );
		if ( $size + strlen( $line ) + 1 > self::MAX_BYTES ) {
			$line = '{"level":"warning","message":"The log reached its size limit; later entries are dropped."}';
		}
		// The result is not checked on purpose: a line that cannot be written must not fail the
		// job it tells about. The job document, which decides what happens next, is checked.
		file_put_contents( $this->file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Reads the entries.
	 *
	 * @param int $limit Maximum number of entries from the end.
	 * @return list<array<string, mixed>> Entries, oldest first.
	 */
	public function read( int $limit = 500 ): array {
		$lines   = is_file( $this->file ) ? file( $this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		$entries = [];
		foreach ( false === $lines ? [] : array_slice( $lines, -$limit ) as $line ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) ) {
				$fields = [];
				foreach ( $entry as $key => $value ) {
					$fields[ (string) $key ] = $value;
				}
				$entries[] = $fields;
			}
		}
		return $entries;
	}
}
