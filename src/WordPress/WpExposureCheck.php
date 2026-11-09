<?php
/**
 * The HTTP probe of the private directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Storage\ExposureCheck;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a probe file into the private directory and requests its URL (ADR 0007). If the
 * random content comes back, the web server serves the directory and backups stop until it
 * is fixed. The answer is cached for a day.
 *
 * Like core's own loopback checks, the request to the site itself honours the
 * https_local_ssl_verify filter; repository requests never relax TLS.
 */
final class WpExposureCheck implements ExposureCheck {

	private const int CACHE_SECONDS = 86400;

	/**
	 * Creates the check.
	 *
	 * @param PrivateDirectory $directory Private directory.
	 * @param SiteState        $state     Where the answer is cached.
	 * @param RandomSource     $random    Randomness for the probe.
	 */
	public function __construct(
		private readonly PrivateDirectory $directory,
		private readonly SiteState $state,
		private readonly RandomSource $random
	) {
	}

	/**
	 * Checks, or returns the cached answer.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return bool|null True if exposed, false if protected, null if not checkable.
	 */
	public function exposed( bool $fresh = false ): ?bool {
		$cached = $this->state->get( 'exposure' );
		if ( ! $fresh && is_int( $cached['checked_at'] ?? null ) && $cached['checked_at'] > time() - self::CACHE_SECONDS ) {
			$value = $cached['exposed'] ?? null;
			return is_bool( $value ) ? $value : null;
		}

		$base = $this->url_of( $this->directory->path );
		if ( null === $base ) {
			$exposed = null;
		} else {
			$probe    = $this->directory->write_probe( $this->random );
			$response = wp_remote_get(
				$base . '/probe/' . basename( $probe['file'] ),
				[
					'timeout'     => 10,
					'redirection' => 0,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for requests to the site itself.
				]
			);
			wp_delete_file( $probe['file'] );
			$exposed = is_wp_error( $response ) ? null : ( 200 === (int) wp_remote_retrieve_response_code( $response ) && str_contains( wp_remote_retrieve_body( $response ), $probe['token'] ) );
		}
		$this->state->set(
			'exposure',
			[
				'checked_at' => time(),
				'exposed'    => $exposed,
			]
		);
		return $exposed;
	}

	/**
	 * Public URL of a path, if it lies below wp-content or ABSPATH.
	 *
	 * @param string $path Absolute path.
	 * @return string|null URL without trailing slash, or null if the path is outside the web root.
	 */
	private function url_of( string $path ): ?string {
		$content = rtrim( WP_CONTENT_DIR, '/' );
		if ( str_starts_with( $path . '/', $content . '/' ) ) {
			return rtrim( content_url( ltrim( substr( $path, strlen( $content ) ), '/' ) ), '/' );
		}
		$root = rtrim( ABSPATH, '/' );
		if ( str_starts_with( $path . '/', $root . '/' ) ) {
			return rtrim( site_url( ltrim( substr( $path, strlen( $root ) ), '/' ) ), '/' );
		}
		return null;
	}
}
