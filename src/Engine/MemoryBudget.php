<?php
/**
 * The memory a job may use.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Pack\PackEntry;

defined( 'ABSPATH' ) || exit;

/**
 * PHP's memory limit, and what follows from it for a job (ADR 0031).
 *
 * The index of a repository is held in memory, at 50 to 80 bytes per blob, and a backup
 * needs room besides it: about 20 MiB for the file being read and three times the size of a
 * pack, because a pack exists as its blobs, as the finished pack and as the request that
 * uploads it. Running into the limit ends a request without a message that anyone could
 * act on. So the index stops loading, with an error that says why, once the smallest packs
 * the plugin writes would no longer fit beside it; and a backup makes its packs smaller
 * when the configured size does not fit, down to that smallest size.
 *
 * PHP holds against its limit the memory it has reserved from the system, not the memory
 * in use. It reserves blocks of 2 MiB and keeps a block as long as anything in it is in
 * use, so in a process that has run for a while the two figures differ. The records of an
 * index are small and fit into the free parts of such blocks; a pack of several megabytes
 * does not and needs memory of its own. While an index loads, the budget therefore looks
 * at the memory in use, which costs nothing to read. Once the index has loaded, and when a
 * pack size is chosen, it looks at the reserved memory, after PHP has given back the blocks
 * that are free altogether, as PHP itself does before it gives up.
 *
 * A restore and a check read stored blobs beside the index, in ranges of a pack. What reading
 * a range takes follows from the copies that exist at once (ADR 0036): the response while it
 * arrives, and then, blob by blob, the range beside what opening the blob takes. The range is
 * let go when its last blob is taken out of it. A reader asks blob_room() before a download
 * and makes its range smaller when it does not fit, down to one blob.
 *
 * Each copy is counted as what PHP reserves for it at most, not by its length (footprint()):
 * a string smaller than a block is put into one of PHP's blocks of 2 MiB, which in a process
 * whose blocks are full or cut up takes a new block; a larger one gets memory of its own,
 * rounded up to whole pages. Every step also counts one block for the small allocations of
 * the read, or for the empty block that PHP keeps for reuse after a request let go of many
 * small strings and that a string with memory of its own cannot use. So what the budget lets
 * through fits whatever state PHP's allocator is in, and the decoder, which measures the
 * memory left once more before it decodes, finds what the budget left for it.
 */
final readonly class MemoryBudget {

	/** The smallest pack size a backup falls back to: the lower end of advanced.pack_size_mib. */
	public const int SMALLEST_PACK = 4194304;

	/** What a backup needs besides the index and its packs. */
	public const int WORKING_BYTES = 20971520;

	/** How many times its size a pack takes while it is uploaded. */
	public const int PACK_COPIES = 3;

	/**
	 * How many times its size a ranged read is held at once while it arrives. WordPress' HTTP
	 * API keeps the body it collects, the raw response with the headers in front, and the body
	 * cut out of that; the same with cURL and with fsockopen.
	 */
	public const int RESPONSE_COPIES = 3;

	/** A block of PHP's memory manager: what it reserves at once for its smaller allocations. */
	public const int BLOCK = 2097152;

	/** The largest allocation that PHP puts into a block; a larger one gets memory of its own. */
	private const int LARGEST_IN_BLOCK = self::BLOCK - 4096;

	/**
	 * What PHP rounds memory of its own up to: the system's page outside Windows, of at most
	 * 64 KiB, and a whole block on Windows.
	 */
	private const int PAGE = 'Windows' === PHP_OS_FAMILY ? self::BLOCK : 65536;

	/** What a string takes beyond its bytes: its header and closing byte, aligned. */
	private const int STRING_OVERHEAD = 32;

	/** What a response holds beyond its body at most: the status line and the headers. */
	private const int RESPONSE_HEAD = 16384;

	/** Memory that stays free beside a read, for allocation rounding and reporting a refusal. */
	private const int RESERVE = 2097152;

	/**
	 * Creates the budget.
	 *
	 * @param int $limit PHP's memory limit in bytes.
	 */
	public function __construct( public int $limit ) {
	}

	/**
	 * Reads a memory_limit setting such as 128M.
	 *
	 * @param string $value Value of memory_limit.
	 * @return self|null Budget, or null if PHP does not limit memory.
	 */
	public static function from_ini( string $value ): ?self {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^(\d+)\s*([kmg]?)$/i', $value, $match ) ) {
			// -1, or something PHP itself would not take as a limit.
			return null;
		}
		$bytes = (int) $match[1] * match ( strtolower( $match[2] ) ) {
			'g'     => 1073741824,
			'm'     => 1048576,
			'k'     => 1024,
			default => 1,
		};
		return $bytes > 0 ? new self( $bytes ) : null;
	}

	/**
	 * Stops the loading of an index that leaves no room for a backup with the smallest packs.
	 * Called for every pack while an index loads.
	 *
	 * @throws IndexTooLarge If the memory in use is beyond what the index may take.
	 */
	public function check_index(): void {
		$this->refuse_beyond( memory_get_usage(), 'in_use' );
	}

	/**
	 * Makes sure that a backup with the smallest packs fits beside an index that has loaded.
	 *
	 * @throws IndexTooLarge If PHP has reserved more memory than the index may take.
	 */
	public function check_loaded_index(): void {
		$this->refuse_beyond( self::reserved(), 'reserved' );
	}

	/**
	 * Reserves room before reading an encrypted index file or cache piece. Decryption keeps
	 * the response, ciphertext and plaintext at once; the transport may retain another copy.
	 * Two MiB remain for allocation rounding and reporting the refusal.
	 *
	 * @param int $bytes Encrypted input size.
	 * @throws IndexTooLarge If reading and opening the input could exhaust PHP's limit.
	 */
	public function check_index_input( int $bytes ): void {
		$reserved  = self::reserved();
		$available = max( 0, $this->limit - $reserved - 2097152 );
		if ( $bytes < 0 || $bytes > intdiv( $available, 4 ) ) {
			IndexTooLarge::raise(
				'The next index file does not fit beside the memory already in use.',
				[
					'memory_limit' => $this->limit,
					'reserved'     => $reserved,
					'input_bytes'  => $bytes,
				]
			);
		}
	}

	/**
	 * Reserves room before a frame of a compressed index file is decoded. The decoder says what
	 * it takes beside the memory in use (Format\Compression); two MiB remain for allocation
	 * rounding and reporting the refusal, as for the encrypted input. A file that does not fit
	 * is refused here as an index too large for this host, before the decoder would refuse it as
	 * an object too large to decompress.
	 *
	 * @param int $bytes What decoding the next frame takes.
	 * @throws IndexTooLarge If decoding could exhaust PHP's limit.
	 */
	public function check_index_decoding( int $bytes ): void {
		$reserved  = self::reserved();
		$available = max( 0, $this->limit - $reserved - 2097152 );
		if ( $bytes > $available ) {
			IndexTooLarge::raise(
				'The next index file does not fit beside the memory already in use.',
				[
					'memory_limit'   => $this->limit,
					'reserved'       => $reserved,
					'decoding_bytes' => $bytes,
				]
			);
		}
	}

	/**
	 * What a string of the given length takes of the memory that PHP reserves, at most: memory
	 * of its own rounded up to pages, for a string too large for a block; otherwise a whole
	 * block, since none of the blocks there are may have room left for it.
	 *
	 * @param int $bytes Length of the string.
	 * @return int Bytes.
	 */
	public static function footprint( int $bytes ): int {
		$size = $bytes + self::STRING_OVERHEAD;
		return $size > self::LARGEST_IN_BLOCK ? intdiv( $size + self::PAGE - 1, self::PAGE ) * self::PAGE : self::BLOCK;
	}

	/**
	 * What opening one stored blob takes beside the range it was read in, counted by
	 * footprint(): while it is decrypted, its stored bytes, the ciphertext without nonce and tag,
	 * and the plaintext; for a compressed blob then, while it is decoded, the decrypted frame
	 * and the decoder's buffer and output for its content (Format\Compression). The stored bytes
	 * are let go before the frame is decoded (Format\Pack\PackReader::open_blob()). A blob that
	 * claims more content than Compression decodes at all is refused before its frame is
	 * decoded, so only its decryption counts. One block for the small allocations of each step.
	 *
	 * @param PackEntry $entry Index or header entry of the blob.
	 * @return int Bytes.
	 */
	public static function opening( PackEntry $entry ): int {
		$frame      = self::footprint( $entry->length - Cipher::OVERHEAD );
		$decrypting = self::footprint( $entry->length ) + 2 * $frame;
		if ( 0 === $entry->uncompressed_length || $entry->uncompressed_length > Compression::MAX_DECOMPRESSED_SIZE ) {
			return $decrypting + self::BLOCK;
		}
		return max( $decrypting, $frame + 2 * self::footprint( $entry->uncompressed_length ) ) + self::BLOCK;
	}

	/**
	 * Takes a blob's stored bytes out of the range it was downloaded in. The last blob takes the
	 * range with it: the caller passes the result on as a temporary value, and the blob is then
	 * opened beside nothing else of the range (range()).
	 *
	 * @param string $range  Downloaded range; emptied when the last blob is taken.
	 * @param int    $start  Offset of the blob in the range.
	 * @param int    $length Stored length of the blob.
	 * @param bool   $last   Whether no blob that is still to be opened follows in the range.
	 * @return string Stored bytes.
	 */
	public static function slice( string &$range, int $start, int $length, bool $last ): string {
		$bytes = substr( $range, $start, $length );
		if ( $last ) {
			$range = '';
		}
		return $bytes;
	}

	/**
	 * What reading a range of stored blobs takes beside the memory in use: the response while it
	 * arrives, with its headers and one block for the small allocations of the request; then
	 * each blob but the last beside the whole range; then the last one alone, because the range
	 * is let go when it is taken out.
	 *
	 * @param int $range  Bytes of the range.
	 * @param int $before The most that opening one of its blobs takes (opening()), of all but the last; 0 for one blob.
	 * @param int $last   What opening its last blob takes; 0 for a range without one.
	 * @return int Bytes.
	 */
	public static function range( int $range, int $before, int $last ): int {
		return max( self::RESPONSE_COPIES * self::footprint( $range + self::RESPONSE_HEAD ) + self::BLOCK, self::footprint( $range ) + $before, $last );
	}

	/**
	 * The bytes that reading blobs may take now: what the limit leaves beside the reserved
	 * memory, less two MiB for allocation rounding and for reporting a refusal.
	 *
	 * @return int Bytes, at least 0.
	 */
	public function available(): int {
		return max( 0, $this->limit - self::reserved() - self::RESERVE );
	}

	/**
	 * The bytes that a restore's next range of blobs may take, once its first blob is known
	 * to fit (available()). Called before the range is downloaded.
	 *
	 * @param int $first What reading the first blob takes by itself (range()).
	 * @return int Bytes.
	 * @throws IndexTooLarge With the code restore_blob_memory if the first blob does not fit.
	 */
	public function blob_room( int $first ): int {
		$available = $this->available();
		if ( $first > $available ) {
			self::refuse_blob(
				[
					'memory_limit' => $this->limit,
					'available'    => $available,
					'blob_bytes'   => $first,
				]
			);
		}
		return $available;
	}

	/**
	 * Refuses the next blob of a restore: it does not fit beside the memory in use. Raised
	 * before the download (blob_room()), or for a blob whose decoding the decoder found more
	 * than the memory left, as a frame without a declared size and with a large window can be.
	 *
	 * @param array<string, int> $context  What was measured.
	 * @param \Throwable|null    $previous The decoder's refusal, if it refused.
	 * @throws IndexTooLarge With the code restore_blob_memory, always.
	 */
	public static function refuse_blob( array $context, ?\Throwable $previous = null ): never {
		IndexTooLarge::raise( 'The next blob of the restore does not fit beside the memory already in use.', $context + [ 'code' => 'restore_blob_memory' ], $previous );
	}

	/**
	 * The pack size a backup can afford with the memory that is free now.
	 *
	 * @param int $wanted Configured pack size in bytes.
	 * @return int The configured size, or a smaller one that fits; never below the smallest pack.
	 */
	public function pack_size( int $wanted ): int {
		$affordable = intdiv( $this->limit - self::reserved() - self::WORKING_BYTES, self::PACK_COPIES );
		return min( $wanted, max( self::SMALLEST_PACK, $affordable ) );
	}

	/**
	 * Raises the error if a measure of memory is beyond what the index may take.
	 *
	 * @param int    $bytes   Memory in use, or memory reserved.
	 * @param string $measure Name of the measure in the error's context: in_use or reserved.
	 * @throws IndexTooLarge If it is.
	 */
	private function refuse_beyond( int $bytes, string $measure ): void {
		$room = $this->limit - self::WORKING_BYTES - self::PACK_COPIES * self::SMALLEST_PACK;
		if ( $bytes > $room ) {
			IndexTooLarge::raise(
				'The index of the repository does not fit into the memory this host allows.',
				[
					'memory_limit' => $this->limit,
					$measure       => $bytes,
					'room'         => $room,
				]
			);
		}
	}

	/**
	 * The memory PHP has reserved once it has given back the blocks that are free altogether.
	 *
	 * @return int Bytes.
	 */
	private static function reserved(): int {
		gc_mem_caches();
		return memory_get_usage( true );
	}
}
