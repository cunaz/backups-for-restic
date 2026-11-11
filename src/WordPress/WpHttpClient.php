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
 *
 * "Always on" has to hold against the site's other code as well. Snippets that switch
 * certificate verification off for every request of the site are common: a filter on
 * https_ssl_verify, on the request arguments, or a line in the http_api_curl action. While a
 * repository request is under way this class therefore registers its own callbacks last on
 * these hooks and puts verification, the certificate bundle and the ban on redirects back.
 * It cannot hold against code that means harm, which could as well read the passwords.
 */
final class WpHttpClient implements HttpClient {

	/** Bytes allowed on top of a response limit for the framing of a chunked answer. */
	private const int FRAMING_BYTES = 4096;

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
	 * @param int                   $limit   Most bytes of response body the caller accepts; 0 for no limit.
	 * @return HttpResponse Response, for every HTTP status.
	 * @throws BackendException On network or TLS errors.
	 */
	public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0 ): HttpResponse {
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
			// A repository's objects are encrypted and do not compress; nothing is gained by
			// a compressed answer, and unpacking one is not held back by the limit below.
			'decompress'  => false,
		];
		$arguments['headers']['Accept-Encoding'] = 'identity';
		if ( $limit > 0 ) {
			// One byte more than accepted: the caller sees that the body was longer. And room
			// for the framing of a chunked answer, which one of WordPress' transports counts
			// before it removes it; without it a valid answer in chunks would be cut short.
			$arguments['limit_response_size'] = $limit + 1 + self::FRAMING_BYTES + intdiv( $limit, 64 );
		}
		// Without an uploaded certificate, the bundle that WordPress itself uses by default.
		$bundle                       = null !== $this->ca_file && '' !== $this->ca_file ? $this->ca_file : ABSPATH . 'wp-includes/certificates/ca-bundle.crt';
		$arguments['sslcertificates'] = $bundle;

		// Each callback looks at the URL: a request that other code sends from inside a hook of
		// this one keeps its own arguments.
		$keep_arguments = static function ( mixed $filtered, mixed $requested = null ) use ( $bundle, $url ): mixed {
			if ( $requested !== $url || ! is_array( $filtered ) ) {
				return $filtered;
			}
			$filtered['sslverify']       = true;
			$filtered['sslcertificates'] = $bundle;
			$filtered['redirection']     = 0;
			return $filtered;
		};
		// WordPress hands the bundle's path, or false for "do not verify", to this filter.
		$keep_bundle = static fn ( mixed $verify, mixed $requested = null ): mixed => $requested === $url ? $bundle : $verify;
		$keep_curl   = static function ( mixed $handle, mixed $parsed = null, mixed $requested = null ) use ( $bundle, $url ): void {
			if ( $requested === $url && $handle instanceof \CurlHandle ) {
				// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The purpose of the http_api_curl action; these options undo what an earlier callback of it may have set.
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $handle, CURLOPT_CAINFO, $bundle );
				curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
				// phpcs:enable
			}
		};
		add_filter( 'http_request_args', $keep_arguments, PHP_INT_MAX, 2 );
		add_filter( 'https_ssl_verify', $keep_bundle, PHP_INT_MAX, 2 );
		add_filter( 'https_local_ssl_verify', $keep_bundle, PHP_INT_MAX, 2 );
		add_action( 'http_api_curl', $keep_curl, PHP_INT_MAX, 3 );
		try {
			$response = wp_remote_request( $url, $arguments );
		} finally {
			remove_filter( 'http_request_args', $keep_arguments, PHP_INT_MAX );
			remove_filter( 'https_ssl_verify', $keep_bundle, PHP_INT_MAX );
			remove_filter( 'https_local_ssl_verify', $keep_bundle, PHP_INT_MAX );
			remove_action( 'http_api_curl', $keep_curl, PHP_INT_MAX );
		}
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
