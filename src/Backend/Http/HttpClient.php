<?php
/**
 * Minimal HTTP client contract.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend\Http;

use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one HTTP request. Implementations must verify TLS, must not follow redirects, must
 * time out, and must not read more of a response than they are told to. Inside WordPress the
 * WordPress HTTP API is used; development tools use cURL.
 */
interface HttpClient {

	/**
	 * Sends a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Request body.
	 * @param int                   $limit   Most bytes the caller accepts as response body; 0 for no limit.
	 *                                       A longer body is cut off after one byte more, so that the
	 *                                       caller can tell, and the rest is never held in memory.
	 * @param string|null           $file    Existing private file to truncate and stream into. The
	 *                                       response body is empty; bytes must not accumulate in memory.
	 * @return HttpResponse Response, for every HTTP status.
	 * @throws BackendException On network or TLS errors (no HTTP response).
	 * @throws StorageFailed If the destination cannot be written.
	 */
	public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0, ?string $file = null ): HttpResponse;
}
