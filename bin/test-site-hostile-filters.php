<?php
/**
 * Must-use plugin for the test site only (bin/test-site.sh install links it).
 *
 * While the file wp-content/bfr-hostile-filters exists, this switches certificate
 * verification off for every HTTP request of the site, in the three ways that snippets found
 * on the web do it. The integration tests use it to show that the plugin's repository
 * requests verify certificates all the same. Development tooling.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

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
