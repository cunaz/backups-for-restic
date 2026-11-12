<?php
/**
 * Verifies streamed HTTP writes and removes chunk framing on disk.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend\Http;

use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress' Requests transports do not check fwrite() results. Their progress hook runs
 * before each write, so comparing its digest with the file detects short and failed writes.
 * The socket transport also leaves HTTP chunk framing in streamed files; cURL removes it.
 */
final class StreamedBody {

	/**
	 * Digest of the bytes the transport promised to write.
	 *
	 * @var \HashContext
	 */
	private readonly \HashContext $digest;

	/** Creates an audit for one response. */
	public function __construct() {
		$this->digest = hash_init( 'sha256' );
	}

	/**
	 * Records only the part of a received chunk that fits the transport's response cap.
	 *
	 * @param string   $bytes    Received chunk, before truncation by the transport.
	 * @param int      $received Bytes retained before this chunk.
	 * @param int|bool $limit    Transport cap, or false for unlimited.
	 */
	public function observe( string $bytes, int $received, int|bool $limit ): void {
		hash_update( $this->digest, is_int( $limit ) && $limit > 0 ? substr( $bytes, 0, max( 0, $limit - $received ) ) : $bytes );
	}

	/**
	 * Verifies all persisted bytes before any JSON entry can become deletion authority.
	 *
	 * @param string $path Download destination.
	 * @throws StorageFailed If the file differs from what the transport received.
	 */
	public function verify( string $path ): void {
		$actual = hash_file( 'sha256', $path );
		if ( false === $actual || ! hash_equals( hash_final( $this->digest ), $actual ) ) {
			StorageFailed::raise( 'The downloaded listing could not be written completely.' );
		}
	}

	/**
	 * Removes socket transport chunk framing into a new private file with bounded buffers.
	 *
	 * @param string $path  Raw downloaded file.
	 * @param int    $limit Most decoded bytes to retain; zero for unlimited.
	 * @throws BackendException If chunk framing is invalid or truncated.
	 * @throws StorageFailed If local file operations fail.
	 */
	public static function decode_chunked( string $path, int $limit ): void {
		$temporary = $path . '.decoded';
		$input     = new WorkFile( $path );
		$output    = new WorkFile( $temporary, true );
		try {
			$written = 0;
			while ( true ) {
				$line = self::line( $input );
				$hex  = explode( ';', $line, 2 )[0];
				// The HTTP response cap is far smaller than this bound. Reject overflow before
				// converting a server-controlled integer to PHP's native representation.
				if ( 1 !== preg_match( '/\A[0-9a-fA-F]{1,8}\z/', $hex ) ) {
					self::invalid();
				}
				$left = (int) hexdec( $hex );
				if ( 0 === $left ) {
					$trailers = 0;
					do {
						$line      = self::line( $input );
						$trailers += strlen( $line ) + 2;
						if ( $trailers > 8192 ) {
							self::invalid();
						}
					} while ( '' !== $line );
					if ( '' !== $input->read( 1 ) ) {
						self::invalid();
					}
					break;
				}
				while ( $left > 0 ) {
					$length = min( 8192, $left );
					$bytes  = $input->read( $length );
					if ( strlen( $bytes ) !== $length ) {
						self::invalid();
					}
					$left -= $length;
					if ( $limit > 0 ) {
						$bytes = substr( $bytes, 0, max( 0, $limit + 1 - $written ) );
					}
					$output->write( $bytes );
					$written += strlen( $bytes );
					if ( $limit > 0 && $written > $limit ) {
						// The caller rejects the oversized body; do not parse a deliberately cut tail.
						break 2;
					}
				}
				if ( "\r\n" !== $input->read( 2 ) ) {
					self::invalid();
				}
			}
			$input->close();
			$output->flush();
			$output->close();
			if ( ! rename( $temporary, $path ) ) {
				StorageFailed::raise( 'The decoded listing could not be replaced.' );
			}
		} finally {
			if ( is_file( $temporary ) && ! unlink( $temporary ) ) {
				StorageFailed::raise( 'An incomplete decoded listing could not be removed.' );
			}
		}
	}

	/**
	 * Reads a bounded CRLF-terminated framing line.
	 *
	 * @param WorkFile $file Input.
	 * @return string Line without CRLF.
	 */
	private static function line( WorkFile $file ): string {
		$line = '';
		for ( $length = 0; $length < 8192; ++$length ) {
			$byte = $file->read( 1 );
			if ( '' === $byte ) {
				self::invalid();
			}
			$line .= $byte;
			if ( str_ends_with( $line, "\r\n" ) ) {
				return substr( $line, 0, -2 );
			}
		}
		self::invalid();
	}

	/**
	 * Rejects malformed framing without logging response content.
	 *
	 * @throws BackendException Always.
	 */
	private static function invalid(): never {
		BackendException::raise( 'The server returned an incomplete or invalid chunked listing.' );
	}
}
