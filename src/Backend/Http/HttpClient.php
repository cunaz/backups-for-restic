<?php
/**
 * Minimal HTTP client contract.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend\Http;

use BackupsForRestic\Exception\BackendException;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one HTTP request. Implementations must verify TLS, must not follow redirects and must
 * time out. Inside WordPress the WordPress HTTP API is used; development tools use cURL.
 */
interface HttpClient {

	/**
	 * Sends a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Request body.
	 * @return HttpResponse Response, for every HTTP status.
	 * @throws BackendException On network or TLS errors (no HTTP response).
	 */
	public function request( string $method, string $url, array $headers = [], string $body = '' ): HttpResponse;
}
