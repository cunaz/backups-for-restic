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
 * A report that carries the file's content proves that the directory is served. A report
 * without it is taken on trust: it comes from an administrator's session, and an
 * administrator who wants to deceive the plugin has easier ways.
 */
final class BrowserProbe {

	/** Seconds a browser has to report on a probe file. */
	public const int PENDING_SECONDS = 300;

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
	 * @return string Name of the file in the subdirectory probe/.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public function begin(): string {
		$entry = $this->state->get( self::ENTRY );
		$this->remove( $entry );
		$probe            = $this->directory->write_probe( $this->random );
		$entry['pending'] = [
			'file'  => basename( $probe['file'] ),
			'token' => $probe['token'],
			'at'    => $this->clock->now()[0],
		];
		$this->state->set( self::ENTRY, $entry );
		return basename( $probe['file'] );
	}

	/**
	 * Takes the browser's report on the probe file and removes the file.
	 *
	 * @param int    $status HTTP status the browser got, or 0 if its request failed.
	 * @param string $body   What it received.
	 * @return bool|null True if the directory is served, false if not, null if the report says
	 *                   nothing: no probe was pending, it was too old, or the request failed.
	 */
	public function finish( int $status, string $body ): ?bool {
		$entry   = $this->state->get( self::ENTRY );
		$pending = is_array( $entry['pending'] ?? null ) ? $entry['pending'] : null;
		$this->remove( $entry );
		unset( $entry['pending'] );
		$now   = $this->clock->now()[0];
		$token = is_string( $pending['token'] ?? null ) ? $pending['token'] : '';
		$fresh = null !== $pending && '' !== $token && $now - Decoded::int( $pending['at'] ?? null ) <= self::PENDING_SECONDS;
		if ( ! $fresh || 0 === $status ) {
			$this->state->set( self::ENTRY, $entry );
			return null;
		}
		$exposed         = 200 === $status && str_contains( $body, $token );
		$entry['result'] = [
			'exposed' => $exposed,
			'at'      => $now,
		];
		$this->state->set( self::ENTRY, $entry );
		return $exposed;
	}

	/**
	 * What the last report said.
	 *
	 * @return bool|null True if the directory was served, false if not, null without a report.
	 */
	public function result(): ?bool {
		$result  = $this->state->get( self::ENTRY )['result'] ?? null;
		$exposed = is_array( $result ) ? ( $result['exposed'] ?? null ) : null;
		return is_bool( $exposed ) ? $exposed : null;
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
