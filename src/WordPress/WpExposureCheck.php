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
 *
 * A refusal of the probe file counts as protection only when the same check, with the same
 * kind of request to the same scheme, host and port, gets the plugin's own stylesheet back
 * byte for byte. A firewall, a CDN challenge or another virtual host that answers the site's
 * request with 403 or 404 fails that control, and the result stays unverified, which
 * refuses plaintext staging. The stylesheet is public anyway: the admin page loads it.
 */
final class WpExposureCheck implements ExposureCheck {

	/** The known file of the control request, relative to the plugin's directory. */
	public const string CONTROL = 'assets/css/admin.css';

	/** Largest control file, in bytes; a larger one makes every refusal unverified. */
	public const int CONTROL_BYTES = 65536;

	private const int CACHE_SECONDS = 86400;

	/**
	 * Creates the check.
	 *
	 * @param PrivateDirectory $directory   Private directory.
	 * @param SiteState        $state       Where the answer is cached.
	 * @param RandomSource     $random      Randomness for the probe.
	 * @param BrowserProbe     $browser     The probe from the administrator's browser.
	 * @param string           $plugin_file Main file of the plugin, which locates the control file and its URL.
	 */
	public function __construct(
		private readonly PrivateDirectory $directory,
		private readonly SiteState $state,
		private readonly RandomSource $random,
		private readonly BrowserProbe $browser,
		private readonly string $plugin_file
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
	 * Requests one probe without following redirects or keeping a large error body. A
	 * refusal is confirmed with the control request before it counts.
	 *
	 * @param string $url Public URL of the directory.
	 * @return bool|null Whether this mapping served the token; null if it could not be told.
	 */
	private function probe( string $url ): ?bool {
		$probe = $this->directory->write_probe( $this->random );
		try {
			$response = $this->request( $url . '/probe/' . basename( $probe['file'] ), 4096 );
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$status  = (int) wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$refused = ! str_contains( $body, $probe['token'] ) && BrowserProbe::refused( $status );
			return BrowserProbe::classify( $status, $body, $probe['token'], $refused && $this->confirmed( $url ) );
		} finally {
			wp_delete_file( $probe['file'] );
		}
	}

	/**
	 * Whether a refusal at a URL came from this site: the control request to the same
	 * scheme, host and port gets the plugin's stylesheet back as it is on disk.
	 *
	 * @param string $url Public URL of the directory.
	 * @return bool True if this site's file serving answered.
	 */
	private function confirmed( string $url ): bool {
		$control = $this->control( $url );
		if ( null === $control ) {
			return false;
		}
		$response = $this->request( $control['url'], self::CONTROL_BYTES + 1 );
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && hash_equals( $control['content'], wp_remote_retrieve_body( $response ) );
	}

	/**
	 * The control request for a public URL of the directory: the plugin's stylesheet at the
	 * same scheme, host and port, with a random query so that no cache answers in place of
	 * the site, and the content the answer must have. The browser probe requests it too.
	 *
	 * @param string $url Public URL of the directory.
	 * @return array{url: string, content: string}|null Address and expected bytes, or null if
	 *                                                  the stylesheet is not served at that origin
	 *                                                  or cannot be read.
	 */
	public function control( string $url ): ?array {
		$file = dirname( $this->plugin_file ) . '/' . self::CONTROL;
		$size = is_file( $file ) ? filesize( $file ) : false;
		if ( false === $size || $size < 1 || $size > self::CONTROL_BYTES ) {
			return null;
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin, compared byte for byte.
		$address = plugin_dir_url( $this->plugin_file ) . self::CONTROL;
		if ( false === $content || strlen( $content ) !== $size || ! self::same_origin( $address, $url ) ) {
			return null;
		}
		return [
			'url'     => add_query_arg( 'bfr-control', bin2hex( $this->random->bytes( 8 ) ), $address ),
			'content' => $content,
		];
	}

	/**
	 * Whether two URLs have the same scheme, host and port.
	 *
	 * @param string $first  URL.
	 * @param string $second URL.
	 * @return bool True for the same origin.
	 */
	private static function same_origin( string $first, string $second ): bool {
		$origins = [];
		foreach ( [ $first, $second ] as $url ) {
			$parts  = wp_parse_url( $url );
			$scheme = strtolower( is_array( $parts ) ? ( $parts['scheme'] ?? '' ) : '' );
			$host   = strtolower( is_array( $parts ) ? ( $parts['host'] ?? '' ) : '' );
			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
				return false;
			}
			$port      = is_array( $parts ) && isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
			$origins[] = $scheme . '://' . $host . ':' . $port;
		}
		return $origins[0] === $origins[1];
	}

	/**
	 * A request of the site to itself, as the probe and its control make it: no redirects,
	 * a bounded body, and core's filter for the certificate check of such requests.
	 *
	 * @param string $url   Address.
	 * @param int    $limit Largest body to keep, in bytes.
	 * @return array<string, mixed>|\WP_Error Response.
	 */
	private function request( string $url, int $limit ): array|\WP_Error {
		return wp_remote_get(
			$url,
			[
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => $limit,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for requests to the site itself.
			]
		);
	}

	/**
	 * Public mappings of the configured and resolved private path. A symlink or a
	 * separately served uploads directory can make the same file reachable at two URLs.
	 *
	 * @return list<string> Known public directory URLs.
	 */
	private function public_urls(): array {
		$path      = $this->directory->path;
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
