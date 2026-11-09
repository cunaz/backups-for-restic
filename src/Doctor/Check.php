<?php
/**
 * One finding of the Doctor.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * A check result with a stable code; the WordPress layer turns it into translated text, the
 * same way Site Health reports its tests.
 */
final readonly class Check {

	public const string GOOD        = 'good';
	public const string RECOMMENDED = 'recommended';
	public const string CRITICAL    = 'critical';

	/**
	 * Creates the result.
	 *
	 * @param string                     $id     Check ID, for example memory_limit.
	 * @param string                     $status good, recommended or critical.
	 * @param string                     $code   Outcome code, for example too_low.
	 * @param array<string, scalar|null> $values Numbers for the message.
	 */
	public function __construct( public string $id, public string $status, public string $code, public array $values = [] ) {
	}

	/**
	 * For the REST API.
	 *
	 * @return array{id: string, status: string, code: string, values: array<string, scalar|null>} Fields.
	 */
	public function to_array(): array {
		return [
			'id'     => $this->id,
			'status' => $this->status,
			'code'   => $this->code,
			'values' => $this->values,
		];
	}
}
