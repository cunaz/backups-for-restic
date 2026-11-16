<?php
/**
 * One-time tokens for downloads.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and redeems tokens that allow one download each.
 *
 * A browser cannot send the REST nonce with a download it starts itself, so the address of
 * a download carries a token instead. A token is 256 random bits as URL-safe text. It is
 * bound to a subject, which is whatever names the download for the caller, such as a job
 * and a path. It is valid once and for one hour.
 *
 * The store is one file in a directory the caller names, written atomically and readable by
 * its owner only. It holds the SHA-256 hash of each token and of its subject and the time
 * it expires, never a token: whoever reads the file, or a copy of it, cannot download
 * anything with it. Issuing and redeeming read, change and write the file under a lock, so
 * of two requests that present the same token at the same moment only one is served.
 * Expired tokens are dropped from the file whenever a token is issued or presented, and the
 * number of tokens is bounded: when the bound is reached, the token that expires first
 * gives way to the new one. A file that cannot be read as a store counts as empty, which
 * refuses every token in it.
 */
final class DownloadTokens {

	/**
	 * Seconds for which a token is valid.
	 */
	public const int LIFETIME = 3600;

	/**
	 * Seconds by which the clock of the server may have been set back since a token was
	 * issued. A token that expires later than that after a full lifetime is not one of this
	 * class.
	 */
	private const int CLOCK_TOLERANCE = 300;

	/**
	 * Random bytes of a token.
	 */
	private const int TOKEN_BYTES = 32;

	/**
	 * Characters of a token: the random bytes as base64url without padding.
	 */
	private const int TOKEN_LENGTH = 43;

	/**
	 * Layout number of the store.
	 */
	private const int VERSION = 1;

	/**
	 * Keeps two requests from changing the store at once.
	 *
	 * @var FileLock
	 */
	private readonly FileLock $lock;

	/**
	 * Whether the last reading left out something the file holds: an expired token or a
	 * record that is none. The next writing removes it from the file.
	 *
	 * @var bool
	 */
	private bool $outdated = false;

	/**
	 * Names the place of the store.
	 *
	 * @param string       $directory Existing directory that only the plugin reads and writes.
	 * @param RandomSource $random    Source of the tokens.
	 * @param Clock        $clock     Time source for the expiry.
	 * @param int          $limit     Largest number of tokens that are valid at once.
	 */
	public function __construct( private readonly string $directory, private readonly RandomSource $random, private readonly Clock $clock, private readonly int $limit = 256 ) {
		$this->lock = new FileLock( $directory . '/download-tokens.lock' );
	}

	/**
	 * Issues a token for one download.
	 *
	 * @param string $subject What the token allows to download, in the caller's own terms.
	 * @return string The token, 43 characters that are safe in a URL. It is not stored and cannot be read again.
	 * @throws StorageFailed If the store cannot be written.
	 */
	public function issue( string $subject ): string {
		$token = Bytes::base64url_encode( $this->random->bytes( self::TOKEN_BYTES ) );
		$this->lock->hold(
			function () use ( $token, $subject ): void {
				$records   = $this->read();
				$records[] = [
					'hash'    => hash( 'sha256', $token ),
					'subject' => hash( 'sha256', $subject ),
					'expires' => $this->clock->now()[0] + self::LIFETIME,
				];
				// The oldest tokens give way, so that a full store never refuses a download.
				usort( $records, static fn ( array $one, array $other ): int => $one['expires'] <=> $other['expires'] );
				$this->write( array_slice( $records, -max( 1, $this->limit ) ) );
			}
		);
		return $token;
	}

	/**
	 * Redeems a token: tells whether it allows this download, and uses it up if it does.
	 *
	 * A token presented for another subject is refused and stays valid for its own.
	 *
	 * @param string $token   Token from the request.
	 * @param string $subject What the request wants to download.
	 * @return bool True exactly once for a token that was issued for this subject and has not expired.
	 * @throws StorageFailed If the store cannot be written; the token is then not used up.
	 */
	public function redeem( string $token, string $subject ): bool {
		if ( self::TOKEN_LENGTH !== strlen( $token ) || self::TOKEN_LENGTH !== strspn( $token, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_' ) ) {
			return false;
		}
		$hash    = hash( 'sha256', $token );
		$subject = hash( 'sha256', $subject );
		return $this->lock->hold(
			function () use ( $hash, $subject ): bool {
				$records = $this->read();
				$found   = false;
				$kept    = [];
				// Every record is compared, each in constant time, wherever the match is.
				foreach ( $records as $record ) {
					$token_matches   = hash_equals( $record['hash'], $hash );
					$subject_matches = hash_equals( $record['subject'], $subject );
					$match           = $token_matches && $subject_matches;
					$found           = $found || $match;
					if ( ! $match ) {
						$kept[] = $record;
					}
				}
				if ( $found || $this->outdated ) {
					// Written before the answer: a token that cannot be removed is not accepted.
					$this->write( $kept );
				}
				return $found;
			}
		);
	}

	/**
	 * Reads the tokens that are still valid.
	 *
	 * @return list<array{hash: string, subject: string, expires: int}> Records; none for a missing or damaged store.
	 */
	private function read(): array {
		$file = $this->file();
		clearstatcache( true, $file );
		// A record takes less than 200 bytes; a larger file is not a store of this class.
		$limit          = 1024 + 256 * max( 1, $this->limit );
		$json           = is_file( $file ) && ! is_link( $file ) ? file_get_contents( $file, false, null, 0, $limit + 1 ) : false;
		$data           = false === $json || strlen( $json ) > $limit ? null : json_decode( $json, true );
		$list           = is_array( $data ) && self::VERSION === ( $data['version'] ?? null ) ? ( $data['tokens'] ?? null ) : null;
		$this->outdated = false;
		if ( ! is_array( $list ) ) {
			return [];
		}
		$now     = $this->clock->now()[0];
		$records = [];
		foreach ( $list as $record ) {
			$hash    = is_array( $record ) ? ( $record['hash'] ?? null ) : null;
			$subject = is_array( $record ) ? ( $record['subject'] ?? null ) : null;
			$expires = is_array( $record ) ? ( $record['expires'] ?? null ) : null;
			if ( self::is_hash( $hash ) && self::is_hash( $subject ) && is_int( $expires ) && $expires > $now && $expires <= $now + self::LIFETIME + self::CLOCK_TOLERANCE && count( $records ) < $this->limit ) {
				$records[] = [
					'hash'    => $hash,
					'subject' => $subject,
					'expires' => $expires,
				];
			}
		}
		$this->outdated = count( $records ) !== count( $list );
		return $records;
	}

	/**
	 * Replaces the store.
	 *
	 * @param list<array{hash: string, subject: string, expires: int}> $records Records to keep.
	 * @throws StorageFailed If the directory is missing or the file cannot be written.
	 */
	private function write( array $records ): void {
		$json = json_encode(
			[
				'version' => self::VERSION,
				'tokens'  => $records,
			]
		);
		if ( false === $json || ! is_dir( $this->directory ) ) {
			StorageFailed::raise( 'The download tokens could not be stored.' );
		}
		Files::write_atomic( $this->file(), $json );
	}

	/**
	 * Tells whether a stored value has the form of a SHA-256 hash.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool True for 64 lower-case hexadecimal digits.
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_hash( mixed $value ): bool {
		return is_string( $value ) && 64 === strlen( $value ) && 64 === strspn( $value, '0123456789abcdef' );
	}

	/**
	 * Returns the path of the store.
	 *
	 * @return string File in the directory the caller named.
	 */
	private function file(): string {
		return $this->directory . '/download-tokens.json';
	}
}
