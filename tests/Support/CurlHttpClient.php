<?php
/**
 * cURL transport for development tools and the conformance suite.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Exception\BackendException;

/**
 * Verifies TLS (optionally against a given CA file), never follows redirects, times out.
 * Inside WordPress the plugin uses the WordPress HTTP API instead.
 */
final class CurlHttpClient implements HttpClient {

	public int $requests = 0;

	public function __construct( private readonly ?string $ca_file = null, private readonly int $timeout = 60 ) {
	}

	public function request( string $method, string $url, array $headers = [], string $body = '' ): HttpResponse {
		++$this->requests;
		$handle = curl_init( $url );
		if ( false === $handle ) {
			throw new BackendException( 'cURL could not be initialized.' );
		}

		$response_headers = [];
		$lines            = [];
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$response_headers ): int {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return strlen( $line );
			},
		];
		if ( '' !== $body ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}
		if ( null !== $this->ca_file ) {
			$options[ CURLOPT_CAINFO ] = $this->ca_file;
		}
		curl_setopt_array( $handle, $options );

		$result = curl_exec( $handle );
		if ( false === $result ) {
			$error = curl_error( $handle );
			throw new BackendException( 'The request to the repository server failed.', [ 'curl' => $error ] );
		}
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		return new HttpResponse( $status, $response_headers, is_string( $result ) ? $result : '' );
	}
}
