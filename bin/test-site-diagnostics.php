<?php
/**
 * Must-use plugin for the test site only (bin/test-site.sh install links it).
 *
 * Logs the start and end of every request with the time it took, whether the client went
 * away and the last PHP error, so that a request that dies without any message still leaves a
 * trace: a start line without an end line. The log is the file named by the environment
 * variable BFR_SITE_REQUEST_LOG, which bin/test-site.sh points outside the document root.
 * Only the path, the names of query parameters and the REST route are logged, never values
 * that could carry a token. Development tooling.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.Security.ValidatedSanitizedInput -- Test-site diagnostics outside the document root.
$bfr_diagnostics = [
	'start' => microtime( true ),
	'id'    => bin2hex( random_bytes( 4 ) ),
	'file'  => (string) getenv( 'BFR_SITE_REQUEST_LOG' ),
];
if ( '' === $bfr_diagnostics['file'] ) {
	return;
}
$bfr_diagnostics_uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
$bfr_diagnostics_query = [];
parse_str( (string) parse_url( $bfr_diagnostics_uri, PHP_URL_QUERY ), $bfr_diagnostics_query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Before WordPress is loaded.
$bfr_diagnostics_names = [];
foreach ( array_keys( $bfr_diagnostics_query ) as $bfr_diagnostics_name ) {
	$bfr_diagnostics_value   = $bfr_diagnostics_query[ $bfr_diagnostics_name ];
	$bfr_diagnostics_names[] = 'rest_route' === $bfr_diagnostics_name && is_string( $bfr_diagnostics_value ) ? 'rest_route=' . $bfr_diagnostics_value : (string) $bfr_diagnostics_name;
}
file_put_contents(
	$bfr_diagnostics['file'],
	sprintf(
		"%s start %s pid=%d %s %s%s\n",
		gmdate( 'H:i:s' ),
		$bfr_diagnostics['id'],
		getmypid(),
		(string) ( $_SERVER['REQUEST_METHOD'] ?? '' ),
		(string) parse_url( $bfr_diagnostics_uri, PHP_URL_PATH ), // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Same.
		[] === $bfr_diagnostics_names ? '' : '?' . implode( '&', $bfr_diagnostics_names )
	),
	FILE_APPEND
);
register_shutdown_function(
	static function () use ( $bfr_diagnostics ): void {
		$error = error_get_last();
		file_put_contents(
			$bfr_diagnostics['file'],
			sprintf(
				"%s end   %s %.1fs aborted=%d peak=%dM error=%s\n",
				gmdate( 'H:i:s' ),
				$bfr_diagnostics['id'],
				microtime( true ) - $bfr_diagnostics['start'],
				connection_aborted(),
				memory_get_peak_usage() >> 20,
				null === $error ? '-' : $error['message'] . ' @ ' . basename( $error['file'] ) . ':' . $error['line']
			),
			FILE_APPEND
		);
	}
);
// phpcs:enable
