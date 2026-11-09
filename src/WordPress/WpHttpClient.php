<?php
/**
 * HTTP adapter on the WordPress HTTP API.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Exception\BackendException;

defined( 'ABSPATH' ) || exit;

/**
 * Sends repository requests with wp_remote_request() (docs/03-security-and-threat-model.md
 * §7): TLS verification always on, no redirects, explicit timeouts, no cookies. An uploaded
 * CA certificate applies to these requests only (threat T-27).
 */
final class WpHttpClient implements HttpClient {

	/**
	 * Creates the client.
	 *
	 * @param int         $timeout Seconds per request.
	 * @param string|null $ca_file PEM file to trust for the repository server, or null for the usual CAs.
	 */
	public function __construct( private readonly int $timeout, private readonly ?string $ca_file = null ) {
	}

	/**
	 * Sends a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Request body.
	 * @return HttpResponse Response, for every HTTP status.
	 * @throws BackendException On network or TLS errors.
	 */
	public function request( string $method, string $url, array $headers = [], string $body = '' ): HttpResponse {
		$user_agent = $headers['User-Agent'] ?? 'backups-for-restic';
		unset( $headers['User-Agent'] );
		$arguments = [
			'method'      => $method,
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => (float) $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => $user_agent,
			'cookies'     => [],
		];
		if ( null !== $this->ca_file ) {
			$arguments['sslcertificates'] = $this->ca_file;
		}

		$response = wp_remote_request( $url, $arguments );
		if ( is_wp_error( $response ) ) {
			BackendException::raise(
				'The repository server could not be reached.',
				[
					'error'  => $response->get_error_code(),
					'detail' => $response->get_error_message(),
				]
			);
		}

		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value, 'is_string' ) );
			}
			$headers[ strtolower( (string) $name ) ] = is_scalar( $value ) ? (string) $value : '';
		}
		return new HttpResponse( (int) wp_remote_retrieve_response_code( $response ), $headers, wp_remote_retrieve_body( $response ) );
	}
}
