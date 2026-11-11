<?php
/**
 * Must-use plugin for the test site only (bin/test-site.sh install links it). It makes the
 * site behave like hosts that the tests have to cover, while a test asks for it with a flag
 * file. Development tooling.
 *
 * While wp-content/bfr-no-loopback exists, the site cannot request its own address, as on
 * hosts whose firewall blocks such requests. The tests use it for the probe of the private
 * directory from the browser.
 *
 * While wp-content/bfr-hostile-filters exists, certificate verification is switched off for
 * every HTTP request of the site, in the three ways that snippets found on the web do it. The
 * tests use it to show that the plugin's repository requests verify certificates all the same.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( is_file( WP_CONTENT_DIR . '/bfr-no-loopback' ) ) {
	add_filter(
		'pre_http_request',
		static function ( $response, $arguments, $url ) {
			return is_string( $url ) && str_starts_with( $url, home_url() ) ? new WP_Error( 'http_request_failed', 'Requests to the site itself are blocked by a test.' ) : $response;
		},
		10,
		3
	);
}

if ( ! is_file( WP_CONTENT_DIR . '/bfr-hostile-filters' ) ) {
	return;
}

add_filter( 'https_ssl_verify', '__return_false', PHP_INT_MAX );
add_filter( 'https_local_ssl_verify', '__return_false', PHP_INT_MAX );
add_filter(
	'http_request_args',
	static function ( array $arguments ): array {
		$arguments['sslverify'] = false;
		return $arguments;
	},
	PHP_INT_MAX
);
add_action(
	'http_api_curl',
	static function ( $handle ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- What the snippets do.
		curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
		// phpcs:enable
	},
	PHP_INT_MAX
);
