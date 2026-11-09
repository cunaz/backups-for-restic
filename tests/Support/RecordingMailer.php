<?php
/**
 * Mailer that records notices.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Notifications\Mailer;
use BackupsForRestic\Notifications\Notice;

/**
 * Test double for e-mail delivery: remembers every notice it accepted and can refuse them.
 */
final class RecordingMailer implements Mailer {

	/** @var list<Notice> */
	public array $sent = [];

	/** Number of refused deliveries. */
	public int $refused = 0;

	public bool $accept = true;

	public function send( Notice $notice ): bool {
		if ( ! $this->accept ) {
			++$this->refused;
			return false;
		}
		$this->sent[] = $notice;
		return true;
	}

	/**
	 * Kinds of the accepted notices, in order.
	 *
	 * @return list<string> Kinds.
	 */
	public function kinds(): array {
		return array_map( static fn ( Notice $notice ): string => $notice->kind->value, $this->sent );
	}
}
