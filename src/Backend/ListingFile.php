<?php
/**
 * Bounded reader for a downloaded REST listing.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * Reads v1 names and v2 name/size objects without decoding the entire JSON array. Offsets
 * point to the comma before the next entry or to the closing bracket, never inside an entry.
 * The caller must finish validating the file before using its entries to authorize deletion.
 */
final class ListingFile {

	/** A normal entry needs about 100 bytes. Larger server-controlled entries are refused. */
	private const int MAX_ENTRY_BYTES = 65536;

	/**
	 * Small read buffer.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Position within the buffer.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Opens the file at a previously returned offset.
	 *
	 * @param WorkFile $file   Downloaded listing.
	 * @param int      $offset Byte offset, zero for the start.
	 */
	private function __construct( private readonly WorkFile $file, private int $offset ) {
		$file->seek( $offset );
	}

	/**
	 * Reads at most a fixed number of entries, including ignored names and duplicates.
	 *
	 * @param string $path   Downloaded listing.
	 * @param int    $offset Previously returned byte offset, or zero.
	 * @param int    $limit  Entries per batch.
	 * @return array{entries: list<array{string, int}>, offset: int, done: bool} Batch and cursor.
	 * @throws BackendException If the listing is invalid.
	 */
	public static function batch( string $path, int $offset, int $limit = 256 ): array {
		$file = new WorkFile( $path );
		try {
			$reader = new self( $file, $offset );
			return $reader->read_batch( max( 1, $limit ) );
		} finally {
			$file->close();
		}
	}

	/**
	 * Parses one batch.
	 *
	 * @param int $limit Entries per batch.
	 * @return array{entries: list<array{string, int}>, offset: int, done: bool} Batch.
	 */
	private function read_batch( int $limit ): array {
		$first = 0 === $this->offset;
		$this->whitespace();
		if ( $first ) {
			if ( 'n' === $this->peek() ) {
				if ( 'null' !== $this->take() . $this->take() . $this->take() . $this->take() ) {
					$this->invalid();
				}
				$this->end();
				return [
					'entries' => [],
					'offset'  => $this->offset,
					'done'    => true,
				];
			}
			if ( '[' !== $this->take() ) {
				$this->invalid();
			}
			$this->whitespace();
		}
		$entries = [];
		for ( $i = 0; $i < $limit; ++$i ) {
			if ( ']' === $this->peek() ) {
				$this->take();
				$this->end();
				return [
					'entries' => $entries,
					'offset'  => $this->offset,
					'done'    => true,
				];
			}
			if ( ! $first ) {
				if ( ',' !== $this->take() ) {
					$this->invalid();
				}
				$this->whitespace();
			}
			$first = false;
			$entry = $this->entry();
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
			$this->whitespace();
			if ( ',' !== $this->peek() && ']' !== $this->peek() ) {
				$this->invalid();
			}
		}
		return [
			'entries' => $entries,
			'offset'  => $this->offset,
			'done'    => false,
		];
	}

	/**
	 * Reads one complete string or object, then checks its REST fields.
	 *
	 * @return array{string, int}|null Name and size, or an ignored non-object name.
	 */
	private function entry(): ?array {
		$first = $this->peek();
		if ( '"' !== $first && '{' !== $first ) {
			$this->invalid();
		}
		$json    = '';
		$depth   = 0;
		$quoted  = false;
		$escaped = false;
		do {
			$byte = $this->take();
			if ( '' === $byte || strlen( $json ) >= self::MAX_ENTRY_BYTES ) {
				$this->invalid();
			}
			$json .= $byte;
			if ( $quoted ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $byte ) {
					$escaped = true;
				} elseif ( '"' === $byte ) {
					$quoted = false;
				}
			} elseif ( '"' === $byte ) {
				$quoted = true;
			} elseif ( '{' === $byte || '[' === $byte ) {
				++$depth;
			} elseif ( '}' === $byte || ']' === $byte ) {
				--$depth;
			}
		} while ( $quoted || $depth > 0 );
		try {
			$entry = GoJson::decode( $json );
		} catch ( FormatException ) {
			$this->invalid();
		}
		if ( is_string( $entry ) ) {
			$name = $entry;
			$size = -1;
		} elseif ( is_array( $entry ) && is_string( $entry['name'] ?? null ) && is_int( $entry['size'] ?? null ) && $entry['size'] >= 0 ) {
			$name = $entry['name'];
			$size = $entry['size'];
		} else {
			$this->invalid();
		}
		return 1 === preg_match( '/\A[0-9a-f]{64}\z/', $name ) ? [ $name, $size ] : null;
	}

	/** Skips only JSON whitespace, with a bound on one uninterrupted stretch. */
	private function whitespace(): void {
		$count = 0;
		while ( '' !== $this->peek() && str_contains( " \t\r\n", $this->peek() ) ) {
			$this->take();
			if ( ++$count > self::MAX_ENTRY_BYTES ) {
				$this->invalid();
			}
		}
	}

	/** Checks that no second JSON value or truncated tail follows the array. */
	private function end(): void {
		$this->whitespace();
		if ( '' !== $this->peek() ) {
			$this->invalid();
		}
	}

	/**
	 * Returns the next byte without consuming it.
	 *
	 * @return string Byte, or empty at EOF.
	 */
	private function peek(): string {
		if ( $this->position >= strlen( $this->buffer ) ) {
			$this->buffer   = $this->file->read( 8192 );
			$this->position = 0;
		}
		return $this->buffer[ $this->position ] ?? '';
	}

	/**
	 * Consumes one byte.
	 *
	 * @return string Byte, or empty at EOF.
	 */
	private function take(): string {
		$byte = $this->peek();
		if ( '' !== $byte ) {
			++$this->position;
			++$this->offset;
		}
		return $byte;
	}

	/**
	 * Rejects an invalid or excessively large entry without logging server-controlled text.
	 *
	 * @throws BackendException Always.
	 */
	private function invalid(): never {
		BackendException::raise( 'Server returned an invalid or excessively large listing entry.' );
	}
}
