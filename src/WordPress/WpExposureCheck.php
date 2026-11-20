<?php
/**
 * The HTTP probe of the private directory.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Storage\BrowserProbe;
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
 *
 * Where the site cannot request itself, the administrator's browser makes the request
 * instead (Storage\BrowserProbe), and its report stands in for the answer this probe could
 * not get.
 */
final class WpExposureCheck implements ExposureCheck {

	private const int CACHE_SECONDS = 86400;

	/**
	 * Creates the check.
	 *
	 * @param PrivateDirectory $directory Private directory.
	 * @param SiteState        $state     Where the answer is cached.
	 * @param RandomSource     $random    Randomness for the probe.
	 * @param BrowserProbe     $browser   The probe from the administrator's browser.
	 */
	public function __construct(
		private readonly PrivateDirectory $directory,
		private readonly SiteState $state,
		private readonly RandomSource $random,
		private readonly BrowserProbe $browser
	) {
	}

	/**
	 * Whether the directory is served: what the site's own probe found, or else what a
	 * browser reported.
	 *
	 * @param bool $fresh Ignore the cache of the site's own probe.
	 * @return bool|null True if exposed, false if protected, null if neither could tell.
	 */
	public function exposed( bool $fresh = false ): ?bool {
		$result = $this->probed( $fresh );
		if ( null !== $result ) {
			return $result;
		}
		$urls    = $this->public_urls();
		$browser = isset( $urls[0] ) ? $this->browser->result( $urls[0] . '/probe/' ) : null;
		return true === $browser || 1 === count( $urls ) ? $browser : null;
	}

	/**
	 * Rechecks the mapped URL before writing plaintext. Where loopbacks are blocked, a
	 * recent browser denial for this directory and URL can establish protection.
	 *
	 * @return bool Whether the restore may stage private files.
	 */
	public function permits_staging(): bool {
		$urls = $this->public_urls();
		if ( [] !== $urls ) {
			foreach ( $urls as $url ) {
				$exposed = $this->probe( $url ) ?? $this->browser->result( $url . '/probe/' );
				if ( false !== $exposed ) {
					return false;
				}
			}
			return true;
		}
		return $this->verified_outside();
	}

	/**
	 * Whether the site can positively place private storage outside public WordPress
	 * mappings and the web server's document root.
	 *
	 * @return bool True when verified outside.
	 */
	public function outside_web_root(): bool {
		return [] === $this->public_urls() && $this->verified_outside();
	}

	/**
	 * WordPress can run under a subdirectory of a larger document root. A CLI or cron
	 * worker without a document root may reuse a recent observation from the same site.
	 *
	 * @return bool Whether private storage lies beyond the observed web root.
	 */
	private function verified_outside(): bool {
		$location = $this->browser->location( site_url( '/' ) );
		$record   = $this->state->get( 'staging_boundary' );
		$age      = is_int( $record['at'] ?? null ) ? time() - $record['at'] : self::CACHE_SECONDS;
		$current  = $location === ( $record['location'] ?? null ) && $age >= 0 && $age < self::CACHE_SECONDS;
		// Preserve the filesystem spelling. The server supplies this path, which is used
		// exclusively for containment checks.
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server configuration, checked as a filesystem path below.
		if ( '' === $root ) {
			return $current && true === ( $record['outside'] ?? null );
		}
		$outside = ! str_contains( $root, "\0" ) && is_dir( $root ) && ! $this->directory->is_inside( $root );
		if ( ! $current || ( $record['outside'] ?? null ) !== $outside || $age >= HOUR_IN_SECONDS ) {
			$this->state->set(
				'staging_boundary',
				[
					'location' => $location,
					'outside'  => $outside,
					'at'       => time(),
				]
			);
		}
		return $outside;
	}

	/**
	 * Whether a browser should probe: the directory has a URL, and the site's own probe got
	 * no answer.
	 *
	 * @return bool True if the admin page should run the browser probe.
	 */
	public function needs_browser(): bool {
		return null !== $this->probe_url( 'x' ) && null === $this->probed();
	}

	/**
	 * The URL of a file in the probe subdirectory.
	 *
	 * @param string $name File name.
	 * @return string|null URL, or null if the private directory lies outside the web root.
	 */
	public function probe_url( string $name ): ?string {
		$base = $this->public_urls()[0] ?? null;
		return null === $base ? null : $base . '/probe/' . $name;
	}

	/**
	 * The site's own probe, or its cached answer.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return bool|null True if exposed, false if protected, null if not checkable.
	 */
	public function probed( bool $fresh = false ): ?bool {
		$urls     = $this->public_urls();
		$location = $this->browser->location( implode( "\0", $urls ) );
		$cached   = $this->state->get( 'exposure' );
		if ( ! $fresh && $location === ( $cached['location'] ?? null ) && is_int( $cached['checked_at'] ?? null ) && $cached['checked_at'] > time() - self::CACHE_SECONDS ) {
			$value = $cached['exposed'] ?? null;
			return is_bool( $value ) ? $value : null;
		}

		$exposed = [] === $urls ? null : false;
		foreach ( $urls as $url ) {
			$result = $this->probe( $url );
			if ( true === $result ) {
				$exposed = true;
				break;
			}
			if ( null === $result ) {
				$exposed = null;
			}
		}
		$this->state->set(
			'exposure',
			[
				'checked_at' => time(),
				'location'   => $location,
				'exposed'    => $exposed,
			]
		);
		return $exposed;
	}

	/**
	 * Requests one probe without following redirects or keeping a large error body.
	 *
	 * @param string $url Public URL of the directory.
	 * @return bool|null Whether this mapping served the token.
	 */
	private function probe( string $url ): ?bool {
		$probe = $this->directory->write_probe( $this->random );
		try {
			$response = wp_remote_get(
				$url . '/probe/' . basename( $probe['file'] ),
				[
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 4096,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for requests to the site itself.
				]
			);
			return is_wp_error( $response ) ? null : BrowserProbe::classify( (int) wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), $probe['token'] );
		} finally {
			wp_delete_file( $probe['file'] );
		}
	}

	/**
	 * Public mappings of the configured and resolved private path. A symlink or a
	 * separately served uploads directory can make the same file reachable at two URLs.
	 *
	 * @return list<string> Known public directory URLs.
	 */
	private function public_urls(): array {
		$path      = $this->directory->configured;
		$real_path = realpath( $path );
		$urls      = [];
		$uploads   = wp_upload_dir( null, false );
		$roots     = [
			[ $uploads['basedir'], $uploads['baseurl'] ],
			[ WP_CONTENT_DIR, content_url() ],
			[ ABSPATH, site_url() ],
		];
		foreach ( $roots as [ $directory, $url ] ) {
			$real_root = realpath( $directory );
			foreach ( [ [ $directory, $path ], [ $real_root, $real_path ] ] as [ $root, $candidate ] ) {
				if ( false === $root || false === $candidate ) {
					continue;
				}
				$root      = rtrim( wp_normalize_path( $root ), '/' );
				$candidate = wp_normalize_path( $candidate );
				if ( str_starts_with( $candidate . '/', $root . '/' ) ) {
					$parts  = explode( '/', ltrim( substr( $candidate, strlen( $root ) ), '/' ) );
					$urls[] = rtrim( rtrim( $url, '/' ) . '/' . implode( '/', array_map( 'rawurlencode', $parts ) ), '/' );
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}
}
