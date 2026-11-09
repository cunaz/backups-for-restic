<?php
/**
 * Splits a byte source into chunks.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Chunker;

use BackupsForRestic\Support\ByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a source in 512 KiB pieces and returns one chunk at a time, like restic's
 * Chunker.Next(). Memory use is bounded by the maximum chunk size.
 */
final class ChunkReader {

	private const int READ_SIZE = 524288;

	/**
	 * Current read buffer.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Position in the buffer.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Whether the source is exhausted.
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Creates a reader.
	 *
	 * @param ByteSource   $source  Input.
	 * @param RabinChunker $chunker Chunker for the repository polynomial (reset for this input).
	 */
	public function __construct( private readonly ByteSource $source, private readonly RabinChunker $chunker ) {
		$this->chunker->reset();
	}

	/**
	 * Returns the next chunk.
	 *
	 * @return string|null Chunk data, or null at the end of the input.
	 */
	public function next(): ?string {
		$chunk = '';
		while ( true ) {
			if ( $this->position >= strlen( $this->buffer ) ) {
				if ( $this->finished ) {
					return '' === $chunk ? null : $chunk;
				}
				$this->buffer   = $this->source->read( self::READ_SIZE );
				$this->position = 0;
				if ( '' === $this->buffer ) {
					$this->finished = true;
					continue;
				}
			}

			$available = strlen( $this->buffer ) - $this->position;
			$split     = $this->chunker->next_split_point( $this->buffer, $this->position, $available );
			if ( -1 === $split ) {
				$chunk         .= substr( $this->buffer, $this->position );
				$this->position = strlen( $this->buffer );
				continue;
			}

			$chunk          .= substr( $this->buffer, $this->position, $split );
			$this->position += $split;
			return $chunk;
		}
	}
}
