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
use BackupsForRestic\Support\WorkFile;

/**
 * Verifies TLS (optionally against a given CA file), never follows redirects, times out.
 * Inside WordPress the plugin uses the WordPress HTTP API instead.
 */
final class CurlHttpClient implements HttpClient {

	public int $requests = 0;

	public function __construct( private readonly ?string $ca_file = null, private readonly int $timeout = 60 ) {
	}

	public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0, ?string $file = null ): HttpResponse {
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
		$received = '';
		$count    = 0;
		$failure  = null;
		$output   = null === $file ? null : new WorkFile( $file );
		$output?->truncate( 0 );
		if ( $limit > 0 || null !== $output ) {
			// Stops the transfer once the body is longer than the caller accepts.
			$options[ CURLOPT_WRITEFUNCTION ] = static function ( $curl, string $chunk ) use ( &$received, &$count, &$failure, $limit, $output ): int {
				$bytes  = $limit > 0 ? substr( $chunk, 0, max( 0, $limit + 1 - $count ) ) : $chunk;
				$count += strlen( $bytes );
				if ( null !== $output ) {
					try {
						$output->write( $bytes );
					} catch ( \Throwable $error ) {
						$failure = $error;
						return 0;
					}
				} else {
					$received .= $bytes;
				}
				return $limit > 0 && $count > $limit ? 0 : strlen( $chunk );
			};
		}
		curl_setopt_array( $handle, $options );

		try {
			$result = curl_exec( $handle );
		} finally {
			$output?->close();
		}
		if ( null !== $failure ) {
			throw $failure;
		}
		$cut = $limit > 0 && $count > $limit;
		if ( false === $result && ! $cut ) {
			$error = curl_error( $handle );
			throw new BackendException( 'The request to the repository server failed.', [ 'curl' => $error ] );
		}
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		if ( null !== $file ) {
			return new HttpResponse( $status, $response_headers, '' );
		}
		if ( $limit > 0 ) {
			return new HttpResponse( $status, $response_headers, substr( $received, 0, $limit + 1 ) );
		}
		return new HttpResponse( $status, $response_headers, is_string( $result ) ? $result : '' );
	}
}
