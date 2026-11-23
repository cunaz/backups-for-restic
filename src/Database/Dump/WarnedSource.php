<?php
/**
 * Generated content that comes with warnings for the backup job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Support\StringByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * A string that is complete after one read, with warnings that the backup records once it is
 * read, as it records those of a table (ResumableByteSource::warnings()).
 */
final class WarnedSource implements ResumableByteSource {

	/**
	 * The content.
	 *
	 * @var StringByteSource
	 */
	private StringByteSource $content;

	/**
	 * Wraps the content.
	 *
	 * @param string       $data     Content.
	 * @param list<string> $warnings Warnings about it, without secrets.
	 */
	public function __construct( string $data, private readonly array $warnings ) {
		$this->content = new StringByteSource( $data );
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; empty at the end.
	 */
	public function read( int $length ): string {
		return $this->content->read( $length );
	}

	/**
	 * Nothing to stop: the content is read in one go.
	 */
	public function stop_at_boundary(): void {
	}

	/**
	 * The content is never left unfinished.
	 *
	 * @return string|null Always null.
	 */
	public function cursor(): ?string {
		return null;
	}

	/**
	 * The content never starts over.
	 *
	 * @return bool Always false.
	 */
	public function restarted(): bool {
		return false;
	}

	/**
	 * The warnings about the content.
	 *
	 * @return list<string> Messages.
	 */
	public function warnings(): array {
		return $this->warnings;
	}
}
