<?php
/**
 * What the connection test found.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Backend\AppendOnlyStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Result of testing a repository server before anything is saved.
 */
final readonly class ConnectionReport {

	/**
	 * Creates the report.
	 *
	 * @param string           $url         Normalized repository URL.
	 * @param bool             $exists      Whether a repository already exists there.
	 * @param int              $keys        Number of key files.
	 * @param AppendOnlyStatus $append_only Detected deletion policy.
	 */
	public function __construct(
		public string $url,
		public bool $exists,
		public int $keys,
		public AppendOnlyStatus $append_only
	) {
	}

	/**
	 * For the REST API.
	 *
	 * @return array{url: string, exists: bool, keys: int, append_only: string} Fields.
	 */
	public function to_array(): array {
		return [
			'url'         => $this->url,
			'exists'      => $this->exists,
			'keys'        => $this->keys,
			'append_only' => $this->append_only->value,
		];
	}
}
