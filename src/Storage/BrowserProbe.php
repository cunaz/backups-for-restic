<?php
/**
 * The probe of the private directory from the administrator's browser.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * On many shared hosts a site cannot request its own address, and the HTTP probe of ADR 0007
 * cannot run. The administrator's browser can: it reaches the site from outside, which is
 * also where an attacker would come from. The admin page asks for a probe file, requests its
 * URL and reports what came back; this class decides what that means.
 *
 * A report that carries the file's content proves that the directory is served. A refusal
 * counts as protection only when the browser, in the same probe and with the same kind of
 * request, also got a known file of the plugin back byte for byte: a firewall, a CDN
 * challenge or another site can refuse a request as well, while the web server of this site
 * would serve the directory. Beyond that the report is taken on trust: it comes from an
 * administrator's session, and an administrator who wants to deceive the plugin has easier
 * ways.
 */
final class BrowserProbe {

	/** Seconds a browser has to report on a probe file. */
	public const int PENDING_SECONDS = 300;

	public const int RESULT_SECONDS = 86400;

	private const string ENTRY = 'exposure_browser';

	/**
	 * Creates the probe.
	 *
	 * @param PrivateDirectory $directory Private directory.
	 * @param SiteState        $state     Where the pending probe and the result are kept.
	 * @param RandomSource     $random    Randomness for the probe file.
	 * @param Clock            $clock     Time source.
	 */
	public function __construct(
		private readonly PrivateDirectory $directory,
		private readonly SiteState $state,
		private readonly RandomSource $random,
		private readonly Clock $clock
	) {
	}

	/**
	 * Writes a probe file for a browser to request. A probe that was begun before and never
	 * reported on is given up.
	 *
	 * @param string      $location Public URL prefix whose protection is being checked.
	 * @param string|null $control  SHA-256 of the known file that the browser requests beside
	 *                              the probe file, or null if there is none at that origin:
	 *                              then a refusal proves nothing.
	 * @return string Name of the file in the subdirectory probe/.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public function begin( string $location = '', ?string $control = null ): string {
		$entry = $this->state->get( self::ENTRY );
		$this->remove( $entry );
		$probe            = $this->directory->write_probe( $this->random );
		$entry['pending'] = [
			'location' => $this->location( $location ),
			'file'     => basename( $probe['file'] ),
			'token'    => $probe['token'],
			'control'  => $control,
			'at'       => $this->clock->now()[0],
		];
		$this->state->set( self::ENTRY, $entry );
		return basename( $probe['file'] );
	}

	/**
	 * Takes the browser's report on the probe file and on the known file beside it, and
	 * removes the probe file.
	 *
	 * A report on another probe than the pending one changes nothing: a second tab may have
	 * begun a probe of its own in the meantime, and that one still waits for its report.
	 *
	 * @param string $probe          Name of the probe file the report is about, as begin() returned it.
	 * @param int    $status         HTTP status the browser got, or 0 if its request failed.
	 * @param string $body           What it received.
	 * @param int    $control_status HTTP status of the known file, or 0 if it was not requested or the request failed.
	 * @param string $control_body   What the browser received for the known file.
	 * @return bool|null True if the directory is served, false if not, null if the report says
	 *                   nothing: no such probe was pending, it was too old, the request failed,
	 *                   or a refusal came without the known file to show that this site sent it.
	 */
	public function finish( string $probe, int $status, string $body, int $control_status = 0, string $control_body = '' ): ?bool {
		$entry   = $this->state->get( self::ENTRY );
		$pending = is_array( $entry['pending'] ?? null ) ? $entry['pending'] : null;
		if ( null !== $pending && ( $pending['file'] ?? null ) !== $probe ) {
			return null;
		}
		$this->remove( $entry );
		unset( $entry['pending'] );
		$now   = $this->clock->now()[0];
		$token = is_string( $pending['token'] ?? null ) ? $pending['token'] : '';
		$fresh = null !== $pending && '' !== $token && $now - Decoded::int( $pending['at'] ?? null ) <= self::PENDING_SECONDS;
		if ( ! $fresh || 0 === $status ) {
			$this->state->set( self::ENTRY, $entry );
			return null;
		}
		// Whatever the status says: who got the content has read the file.
		$control   = is_string( $pending['control'] ?? null ) ? $pending['control'] : '';
		$confirmed = 1 === preg_match( '/\A[0-9a-f]{64}\z/', $control ) && 200 === $control_status && hash_equals( $control, hash( 'sha256', $control_body ) );
		$exposed   = self::classify( $status, $body, $token, $confirmed );
		if ( null === $exposed ) {
			unset( $entry['result'] );
			$this->state->set( self::ENTRY, $entry );
			return null;
		}
		$entry['result'] = [
			'location' => $pending['location'] ?? null,
			'exposed'  => $exposed,
			'at'       => $now,
		];
		$this->state->set( self::ENTRY, $entry );
		return $exposed;
	}

	/**
	 * What the last report said.
	 *
	 * @param string $location Current public URL prefix.
	 * @return bool|null True if the directory was served, false if protected, null without a current report.
	 */
	public function result( string $location = '' ): ?bool {
		$result  = $this->state->get( self::ENTRY )['result'] ?? null;
		$exposed = is_array( $result ) ? ( $result['exposed'] ?? null ) : null;
		if ( ! is_bool( $exposed ) || $this->location( $location ) !== ( $result['location'] ?? null ) || $this->clock->now()[0] - Decoded::int( $result['at'] ?? null ) >= self::RESULT_SECONDS ) {
			return null;
		}
		return $exposed;
	}

	/**
	 * Interprets either HTTP probe. A redirect, busy proxy or maintenance page gives no
	 * evidence that a normal request cannot retrieve files. Neither does a refusal on its
	 * own: a firewall, a CDN challenge or another virtual host answers 403 or 404 as well,
	 * while this site's web server may serve the directory. A refusal counts only when the
	 * same check got a known file of this site back, which shows that its requests reach
	 * this site's own file serving.
	 *
	 * @param int    $status    HTTP response status.
	 * @param string $body      Response body.
	 * @param string $token     Content of the probe file.
	 * @param bool   $confirmed Whether the same check got the known file of this site back.
	 * @return bool|null True if served, false on a confirmed denial, otherwise unknown.
	 */
	public static function classify( int $status, string $body, string $token, bool $confirmed ): ?bool {
		if ( str_contains( $body, $token ) ) {
			return true;
		}
		return $confirmed && self::refused( $status ) ? false : null;
	}

	/**
	 * Whether a status is the refusal of a web server that does not serve a file.
	 *
	 * @param int $status HTTP response status.
	 * @return bool True for 403, 404 and 410.
	 */
	public static function refused( int $status ): bool {
		return in_array( $status, [ 403, 404, 410 ], true );
	}

	/**
	 * Identifies both the directory and its public mapping.
	 *
	 * @param string $location Public URL prefix.
	 * @return string Cache key.
	 */
	public function location( string $location ): string {
		$real = realpath( $this->directory->path );
		return hash( 'sha256', ( false === $real ? $this->directory->path : $real ) . "\0" . $location );
	}

	/**
	 * Deletes the probe file of a pending probe.
	 *
	 * @param array<string, mixed> $entry Stored entry.
	 */
	private function remove( array $entry ): void {
		$name = is_array( $entry['pending'] ?? null ) ? ( $entry['pending']['file'] ?? null ) : null;
		// The name comes from the state option: only a plain file name is followed.
		if ( is_string( $name ) && 1 === preg_match( '/\A[0-9a-f]{16}\.txt\z/', $name ) ) {
			$file = $this->directory->subdirectory( 'probe' ) . '/' . $name;
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}
}
