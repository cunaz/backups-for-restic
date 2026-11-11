<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;

/**
 * A rest-server in memory, for code that reaches its repository through RestBackend: one
 * repository per URL path, with the answers of rest-server's API (v2 listings, 403 for an
 * upload that exists, 404 for what is missing).
 *
 * Two hooks stand in for failures. `$before` sees a request first and may answer in the
 * server's place or throw; `$after` sees it once the server has acted and may replace the
 * answer or throw, as when an answer gets lost on the way back.
 */
final class InMemoryRestServer implements HttpClient {

	/**
	 * Stored objects by URL path.
	 *
	 * @var array<string, string>
	 */
	public array $objects = [];

	/**
	 * Requests so far, as "METHOD path".
	 *
	 * @var list<string>
	 */
	public array $requests = [];

	/**
	 * @var (\Closure(string, string): ?HttpResponse)|null
	 */
	public ?\Closure $before = null;

	/**
	 * @var (\Closure(string, string): ?HttpResponse)|null
	 */
	public ?\Closure $after = null;

	public function request( string $method, string $url, array $headers = [], string $body = '' ): HttpResponse {
		$path             = (string) parse_url( $url, PHP_URL_PATH );
		$query            = (string) parse_url( $url, PHP_URL_QUERY );
		$this->requests[] = $method . ' ' . $path . ( '' === $query ? '' : '?' . $query );

		$answer   = null === $this->before ? null : ( $this->before )( $method, $path );
		$answer ??= $this->answer( $method, $path, $query, $headers, $body );
		$replaced = null === $this->after ? null : ( $this->after )( $method, $path );
		return $replaced ?? $answer;
	}

	/**
	 * What rest-server answers.
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	private function answer( string $method, string $path, string $query, array $headers, string $body ): HttpResponse {
		if ( 'POST' === $method && 'create=true' === $query ) {
			return new HttpResponse( 200, [], '' );
		}
		if ( str_ends_with( $path, '/' ) ) {
			$entries = [];
			foreach ( $this->objects as $name => $content ) {
				if ( str_starts_with( $name, $path ) ) {
					$entries[] = [
						'name' => substr( $name, strlen( $path ) ),
						'size' => strlen( $content ),
					];
				}
			}
			return new HttpResponse( 200, [ 'content-type' => 'application/vnd.x.restic.rest.v2' ], (string) json_encode( $entries ) );
		}
		$stored = $this->objects[ $path ] ?? null;
		if ( 'POST' === $method ) {
			if ( null !== $stored ) {
				return new HttpResponse( 403, [], '' );
			}
			$this->objects[ $path ] = $body;
			return new HttpResponse( 200, [], '' );
		}
		if ( null === $stored ) {
			return new HttpResponse( 404, [], '' );
		}
		if ( 'DELETE' === $method ) {
			unset( $this->objects[ $path ] );
			return new HttpResponse( 200, [], '' );
		}
		if ( 'HEAD' === $method ) {
			return new HttpResponse( 200, [ 'content-length' => (string) strlen( $stored ) ], '' );
		}
		if ( 1 === preg_match( '/\Abytes=(\d+)-(\d*)\z/', $headers['Range'] ?? '', $range ) ) {
			$stored = '' === $range[2] ? substr( $stored, (int) $range[1] ) : substr( $stored, (int) $range[1], (int) $range[2] - (int) $range[1] + 1 );
			return new HttpResponse( 206, [], $stored );
		}
		return new HttpResponse( 200, [], $stored );
	}
}
