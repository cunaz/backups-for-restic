<?php
/**
 * Must-use plugin for the test site only (bin/test-site.sh install links it).
 *
 * Logs the start and end of every request to wp-content/requests.log, with the time it took,
 * whether the client went away and the last PHP error, so that a request that dies without
 * any message still leaves a trace: a start line without an end line. Development tooling.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.Security.ValidatedSanitizedInput -- Test-site diagnostics written next to WordPress's own debug.log.
$bfr_diagnostics = [
	'start' => microtime( true ),
	'id'    => bin2hex( random_bytes( 4 ) ),
	'file'  => WP_CONTENT_DIR . '/requests.log',
];
file_put_contents( $bfr_diagnostics['file'], sprintf( "%s start %s pid=%d %s %s\n", gmdate( 'H:i:s' ), $bfr_diagnostics['id'], getmypid(), (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ), (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), FILE_APPEND );
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
