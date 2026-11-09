<?php
/**
 * HTTP response.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Status, headers (names in lowercase) and body of a response.
 */
final readonly class HttpResponse {

	/**
	 * Creates a response.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Headers with lowercase names.
	 * @param string                $body    Body.
	 */
	public function __construct( public int $status, public array $headers, public string $body ) {
	}

	/**
	 * Returns a header value.
	 *
	 * @param string $name Header name (case-insensitive).
	 * @return string Value, or an empty string.
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
}
