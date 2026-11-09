<?php
/**
 * Router for PHP's built-in web server in the test site (bin/test-site.sh start).
 *
 * The built-in server ignores .htaccess files. Like Apache with AllowOverride, this router
 * answers 403 for every path below a directory whose .htaccess denies all access, so the
 * plugin's protected private directory behaves as it does on typical shared hosting.
 * Everything else is served as usual. Development tooling only.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- A development router outside WordPress; the path is resolved with realpath() and confined to the document root.
$document_root = realpath( (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
$request_path  = rawurldecode( explode( '?', (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), 2 )[0] );
// phpcs:enable
$target = false === $document_root ? false : realpath( $document_root . $request_path );

if ( false !== $target && false !== $document_root && str_starts_with( $target, $document_root . '/' ) ) {
	$directory = is_dir( $target ) ? $target : dirname( $target );
	while ( str_starts_with( $directory . '/', $document_root . '/' ) ) {
		$rules = is_file( $directory . '/.htaccess' ) ? file_get_contents( $directory . '/.htaccess' ) : false;
		if ( is_string( $rules ) && 1 === preg_match( '/^\s*(Require all denied|Deny from all)\s*$/mi', $rules ) ) {
			http_response_code( 403 );
			echo "Forbidden\n";
			return true;
		}
		$directory = dirname( $directory );
	}
}

return false;
