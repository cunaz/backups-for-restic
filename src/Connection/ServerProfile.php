<?php
/**
 * Which repository servers are accepted.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * URL rules for the two server options of the wizard (ADR 0025, threat T-26): BorgBase,
 * restricted to *.repo.borgbase.com, and an own restic REST server with any host, port and
 * path. Both require HTTPS and no credentials in the URL.
 */
final class ServerProfile {

	public const string BORGBASE    = 'borgbase';
	public const string REST_SERVER = 'rest-server';

	private const string BORGBASE_SUFFIX = '.repo.borgbase.com';

	/**
	 * Validates and normalizes a repository URL.
	 *
	 * Accepts what users paste from restic documentation or the BorgBase control panel,
	 * including the "rest:" prefix, and returns a URL with a trailing slash and a lowercase
	 * host.
	 *
	 * @param string $server One of the server constants.
	 * @param string $url    URL as entered.
	 * @return string Normalized URL.
	 * @throws InvalidArgument With the code in the context: unknown_server, not_https, credentials_in_url, query_in_url, not_borgbase or invalid_url.
	 */
	public static function normalize( string $server, string $url ): string {
		if ( self::BORGBASE !== $server && self::REST_SERVER !== $server ) {
			InvalidArgument::raise( 'Unknown repository server type.', [ 'code' => 'unknown_server' ] );
		}
		$url = trim( $url );
		if ( str_starts_with( $url, 'rest:' ) ) {
			$url = substr( $url, 5 );
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || '' === ( $parts['host'] ?? '' ) ) {
			InvalidArgument::raise( 'The repository URL is not valid.', [ 'code' => 'invalid_url' ] );
		}
		if ( 'https' !== strtolower( $parts['scheme'] ?? '' ) ) {
			InvalidArgument::raise( 'The repository URL must use HTTPS.', [ 'code' => 'not_https' ] );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			InvalidArgument::raise( 'Enter the username and password in their own fields, not in the URL.', [ 'code' => 'credentials_in_url' ] );
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			InvalidArgument::raise( 'The repository URL must not contain a query or fragment.', [ 'code' => 'query_in_url' ] );
		}
		$host = strtolower( $parts['host'] );
		if ( self::BORGBASE === $server && ( ! str_ends_with( $host, self::BORGBASE_SUFFIX ) || strlen( $host ) <= strlen( self::BORGBASE_SUFFIX ) ) ) {
			InvalidArgument::raise( 'A BorgBase repository URL ends in .repo.borgbase.com.', [ 'code' => 'not_borgbase' ] );
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = '/' . trim( $parts['path'] ?? '', '/' );
		return 'https://' . $host . $port . ( '/' === $path ? '/' : $path . '/' );
	}

	/**
	 * Guesses the server type of a URL, for a URL defined in wp-config.php.
	 *
	 * @param string $url URL.
	 * @return string One of the server constants.
	 */
	public static function detect( string $url ): string {
		$host = strtolower( (string) parse_url( str_starts_with( $url, 'rest:' ) ? substr( $url, 5 ) : $url, PHP_URL_HOST ) );
		return str_ends_with( $host, self::BORGBASE_SUFFIX ) ? self::BORGBASE : self::REST_SERVER;
	}

	/**
	 * Whether two repository URLs name the same server: the same host, compared without regard
	 * to case, and the same port. Credentials stored for one server only ever go to that server.
	 *
	 * @param string $url   URL.
	 * @param string $other Other URL.
	 * @return bool True if both have a host, and host and port are equal.
	 */
	public static function same_server( string $url, string $other ): bool {
		$endpoint = self::endpoint( $url );
		return null !== $endpoint && self::endpoint( $other ) === $endpoint;
	}

	/**
	 * Host and port of a URL; 443 is the port of a URL that names none.
	 *
	 * @param string $url URL.
	 * @return string|null Lowercase host, a colon and the port; null without a host.
	 */
	private static function endpoint( string $url ): ?string {
		$parts = parse_url( str_starts_with( $url, 'rest:' ) ? substr( $url, 5 ) : $url );
		if ( ! is_array( $parts ) || '' === ( $parts['host'] ?? '' ) ) {
			return null;
		}
		return strtolower( $parts['host'] ) . ':' . ( $parts['port'] ?? 443 );
	}

	/**
	 * Host of a URL, for messages and logs.
	 *
	 * @param string $url URL.
	 * @return string Host, or an empty string.
	 */
	public static function host( string $url ): string {
		return strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	}
}
