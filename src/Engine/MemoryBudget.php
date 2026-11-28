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
 * let go when its last blob is taken out of it. A reader measures the memory left before a
 * download (measure()) and makes its range smaller when it does not fit, down to one blob.
 *
 * Each copy is counted as what PHP reserves for it (reserves()), not by its length. A string
 * too large for one of PHP's blocks of 2 MiB gets memory of its own, rounded up to the
 * system's page. The smaller strings of a step go into new blocks, together where they fit,
 * unless PHP has free pages for them in the blocks it holds; whether it has is not a figure
 * PHP reports (the gap between the memory reserved and the memory in use is mostly free slots
 * of small allocations), so when the new blocks would not fit, PHP is asked (placed()). A
 * compressed blob is decoded only when the decoder finds what it requires free beside the
 * memory in use (decoding()). Nothing else is counted: no reserve beside the read, no block
 * per step, and no pages for the small allocations of a step, such as the headers of a
 * response or the nonce and tag of a blob, for which PHP found room in the blocks it holds in
 * every state of its allocator that the memory tests produce (ReadMemoryTest). The memory
 * that other code takes while a response arrives cannot be counted beforehand. A reader looks
 * once the range has arrived: when it arrived alone (arrived_alone()), the plan holds;
 * otherwise it measures again before each blob (rest()) and refuses one that no longer fits
 * instead of running into PHP's limit (ADR 0036).
 */
final class MemoryBudget {

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

	/**
	 * What the raw copy of a response holds in front of its body, the status line and the
	 * headers, as counted: a page, which is what a head of up to 4 KiB adds at most to a copy
	 * with memory of its own. Without it a group whose raw copy takes a page more than its body
	 * runs into PHP's limit (ReadMemoryTest).
	 */
	public const int RESPONSE_HEAD = 4096;

	/** The pages inside a block, of which the first holds the block's own header. */
	private const int BLOCK_PAGE = 4096;

	/** The pages of a block that allocations can use. */
	private const int BLOCK_PAGES = 511;

	/** The largest allocation that PHP puts into a block; a larger one gets memory of its own. */
	private const int LARGEST_IN_BLOCK = self::BLOCK - self::BLOCK_PAGE;

	/**
	 * The page that PHP rounds memory of its own up to, once it is known (page()); 0 before.
	 *
	 * @var int
	 */
	private static int $page = 0;

	/**
	 * Creates the budget, early in a request: if the page must be measured (page()), it is
	 * measured now, while memory is not short.
	 *
	 * @param int $limit PHP's memory limit in bytes.
	 */
	public function __construct( public readonly int $limit ) {
		self::page();
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
	 * The page that PHP rounds memory of its own up to: the system's page, which PHP reads at
	 * startup (sysconf), as posix_sysconf() reports it; without the POSIX extension it is
	 * measured once (measure_page()). On Windows PHP rounds to a whole block.
	 *
	 * @return int Bytes.
	 */
	public static function page(): int {
		if ( 0 === self::$page ) {
			$system     = function_exists( 'posix_sysconf' ) && defined( 'POSIX_SC_PAGESIZE' ) ? posix_sysconf( POSIX_SC_PAGESIZE ) : 0;
			self::$page = match ( true ) {
				'Windows' === PHP_OS_FAMILY => self::BLOCK,
				$system > 0 && 0 === ( $system & ( $system - 1 ) ) => $system,
				default => self::measure_page(),
			};
		}
		return self::$page;
	}

	/**
	 * The bytes PHP allocates for a string of the given length: its header and closing byte
	 * beside the bytes, aligned to eight.
	 *
	 * @param int $bytes Length of the string.
	 * @return int Bytes.
	 */
	public static function string_size( int $bytes ): int {
		return ( $bytes + 32 ) & ~7;
	}

	/**
	 * What strings that exist at once add to the memory PHP reserves, at most: a string too
	 * large for a block takes its size rounded up to whole pages; the others go, in the order
	 * they are made, into new blocks, together where they fit into one.
	 *
	 * @param list<int> $strings Lengths of the strings, in the order they are made.
	 * @return int Bytes.
	 */
	public static function reserves( array $strings ): int {
		[ $huge, $small ] = self::split( $strings );
		$blocks           = [];
		foreach ( $small as $bytes ) {
			$pages = intdiv( self::string_size( $bytes ) + self::BLOCK_PAGE - 1, self::BLOCK_PAGE );
			foreach ( $blocks as $number => $left ) {
				if ( $pages <= $left ) {
					$blocks[ $number ] = $left - $pages;
					continue 2;
				}
			}
			$blocks[] = self::BLOCK_PAGES - $pages;
		}
		return $huge + count( $blocks ) * self::BLOCK;
	}

	/**
	 * Whether strings smaller than a block find room in the free pages of the blocks that PHP
	 * holds, so that PHP reserves nothing more for them. PHP does not report its free pages, so
	 * it is asked: the strings are made in the order the step makes them until PHP reserves
	 * more, and are let go again. That takes at most one new block, so it is asked only with at
	 * least a block left (step()).
	 *
	 * @param list<int> $strings Lengths of strings smaller than a block, in the order they are made.
	 * @return bool Whether all of them fit.
	 */
	public static function placed( array $strings ): bool {
		$before = memory_get_usage( true );
		$held   = [];
		foreach ( $strings as $bytes ) {
			$held[] = str_repeat( "\0", $bytes );
			if ( memory_get_usage( true ) !== $before ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * What reading a range takes while its response arrives: WordPress' HTTP API holds the body
	 * it collects, the raw response with the status line and headers in front, and the body cut
	 * out of that (RESPONSE_COPIES).
	 *
	 * @param int $range Bytes of the range.
	 * @param int $room  What may be taken (measure()); with less than the most it can take, PHP is asked (step()).
	 * @return int Bytes.
	 */
	public static function response( int $range, int $room = PHP_INT_MAX ): int {
		return self::step( [ $range, $range + self::RESPONSE_HEAD, $range ], 0, $room );
	}

	/**
	 * What the decoder of a compressed blob requires to be free beside the memory in use before
	 * it decodes the blob (Format\Compression), with the reserve that it keeps.
	 *
	 * @param int $content Uncompressed length of the blob.
	 * @return int Bytes.
	 */
	public static function decoding( int $content ): int {
		return Compression::decoding( $content ) + Compression::RESERVE;
	}

	/**
	 * What opening one stored blob takes beside the memory in use, with the range it was read in
	 * still held or not: while it is decrypted, its stored bytes, the ciphertext without nonce
	 * and tag, and the plaintext; for a compressed blob then, while it is decoded, the decrypted
	 * frame and what the decoder requires free (decoding()). The stored bytes are let go before
	 * the frame is decoded (Format\Pack\PackReader::open_blob()). A blob that claims more
	 * content than Compression decodes at all is refused before its frame is decoded, so only
	 * its decryption counts.
	 *
	 * @param PackEntry $entry Index or header entry of the blob.
	 * @param int       $range Bytes of the range still held beside the blob; 0 for none.
	 * @param int       $room  What may be taken (measure()); with less than the most it can take, PHP is asked (step()).
	 * @return int Bytes.
	 */
	public static function opening( PackEntry $entry, int $range, int $room = PHP_INT_MAX ): int {
		$held       = 0 === $range ? [] : [ $range ];
		$frame      = $entry->length - Cipher::OVERHEAD;
		$decrypting = self::step( [ ...$held, $entry->length, $frame, $frame ], 0, $room );
		if ( 0 === $entry->uncompressed_length || $entry->uncompressed_length > Compression::MAX_DECOMPRESSED_SIZE ) {
			return $decrypting;
		}
		return max( $decrypting, self::step( [ ...$held, $frame ], self::decoding( $entry->uncompressed_length ), $room ) );
	}

	/**
	 * What opening a blob still takes once its range has arrived, beside the memory in use now,
	 * which holds the range: before the last blob, what opening it takes beside the range; for
	 * the last one, the range is let go when the blob is taken out of it, which gives back the
	 * range's memory of its own, and when the blob is the whole range its stored bytes are the
	 * range.
	 *
	 * @param PackEntry $entry The blob.
	 * @param int       $range Bytes of the range that is held.
	 * @param bool      $last  Whether the blob is the last one of the range to be opened.
	 * @param int       $room  What may be taken now (measure()); with less than the most it can take, PHP is asked (step()).
	 * @return int Bytes.
	 */
	public static function rest( PackEntry $entry, int $range, bool $last, int $room = PHP_INT_MAX ): int {
		$frame    = $entry->length - Cipher::OVERHEAD;
		$released = $last && self::string_size( $range ) > self::LARGEST_IN_BLOCK ? self::reserves( [ $range ] ) : 0;
		if ( $last && $range === $entry->length ) {
			// The stored bytes are the range itself, held until the blob is decrypted.
			$decrypting = self::step( [ $frame, $frame ], 0, $room );
		} else {
			$decrypting = max( self::step( [ $entry->length ], 0, $room ), self::step( [ $entry->length, $frame, $frame ], 0, $room ) - $released );
		}
		if ( 0 === $entry->uncompressed_length || $entry->uncompressed_length > Compression::MAX_DECOMPRESSED_SIZE ) {
			return max( 0, $decrypting );
		}
		return max( 0, $decrypting, self::step( [ $frame ], self::decoding( $entry->uncompressed_length ), $room ) - $released );
	}

	/**
	 * Whether a range arrived alone: since the given figures were measured, before its download,
	 * PHP has reserved no more than the range takes (reserves()) and holds in use no more than
	 * the range itself. Then no other code kept memory while the response arrived, and what the
	 * plan of the range let through fits as planned: its blobs are opened without measuring
	 * again (rest()), which would count new blocks for copies that find room in the block that
	 * the range took. Less than a page in use is allowed beside the range: PHP keeps a small
	 * record of each string with memory of its own. Other code that keeps no more is not seen.
	 *
	 * @param int $reserved What PHP had reserved before the download (memory_get_usage( true )).
	 * @param int $used     What it had in use then (memory_get_usage()).
	 * @param int $range    Bytes of the range, which is held now.
	 * @return bool Whether the range arrived alone.
	 */
	public static function arrived_alone( int $reserved, int $used, int $range ): bool {
		$takes = self::reserves( [ $range ] );
		$holds = ( self::string_size( $range ) > self::LARGEST_IN_BLOCK ? $takes : self::string_size( $range ) ) + self::BLOCK_PAGE - 1;
		return memory_get_usage( true ) - $reserved <= $takes && memory_get_usage() - $used <= $holds;
	}

	/**
	 * Of two blobs, the one whose opening takes the most by itself.
	 *
	 * @param PackEntry|null $one   A blob, or none.
	 * @param PackEntry|null $other Another blob, or none.
	 * @return PackEntry|null The larger one, or the one given.
	 */
	public static function larger( ?PackEntry $one, ?PackEntry $other ): ?PackEntry {
		if ( null === $one || null === $other ) {
			return $one ?? $other;
		}
		return self::opening( $other, 0 ) > self::opening( $one, 0 ) ? $other : $one;
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
	 * arrives (response()); then each blob but the last beside the whole range; then the last
	 * one alone, because the range is let go when it is taken out.
	 *
	 * @param int            $range  Bytes of the range.
	 * @param PackEntry|null $before Of the blobs before the last one that are opened, the one whose opening takes the most; null for none.
	 * @param PackEntry|null $last   The last blob that is opened; null for a range without one.
	 * @param int            $room   What may be taken (measure()); with less than the most it can take, PHP is asked (step()).
	 * @return int Bytes.
	 */
	public static function range( int $range, ?PackEntry $before, ?PackEntry $last, int $room = PHP_INT_MAX ): int {
		return max(
			self::response( $range, $room ),
			null === $before ? 0 : self::opening( $before, $range, $room ),
			null === $last ? 0 : self::opening( $last, 0, $room )
		);
	}

	/**
	 * The bytes that reading blobs may take now: what the limit leaves beside the reserved
	 * memory. With $give_back PHP first gives back the pages that freed strings keep for reuse
	 * and the blocks that are free altogether, as it does itself before it refuses an
	 * allocation; that costs time in a heap of many small strings, so a reader asks for it only
	 * when what it reads would not fit otherwise.
	 *
	 * @param bool $give_back Whether PHP gives back what it keeps first.
	 * @return int Bytes, at least 0.
	 */
	public function measure( bool $give_back = false ): int {
		if ( $give_back ) {
			gc_mem_caches();
		}
		return max( 0, $this->limit - memory_get_usage( true ) );
	}

	/**
	 * Refuses the next blob of a restore: it does not fit beside the memory in use. Raised
	 * before the download, or after it, when the memory left no longer takes what opening the
	 * blob takes, or the decoder found its decoding more than the memory left, as a frame
	 * without a declared size and with a large window can be.
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
	 * What a step of a read takes: strings that exist at once, and what a decoder requires free
	 * beside them. When the most that the strings can take (reserves()) does not fit into the
	 * room, but at least a block is left, PHP is asked whether the strings smaller than a block
	 * find free pages in its blocks (placed()); then only the larger ones count.
	 *
	 * @param list<int> $strings Lengths of the strings.
	 * @param int       $decoder What a decoder requires free beside them; 0 for none.
	 * @param int       $room    What may be taken.
	 * @return int Bytes.
	 */
	private static function step( array $strings, int $decoder, int $room ): int {
		$most = self::reserves( $strings ) + $decoder;
		if ( $most <= $room || $room < self::BLOCK ) {
			return $most;
		}
		[ $huge, $small ] = self::split( $strings );
		return [] !== $small && self::placed( $small ) ? $huge + $decoder : $most;
	}

	/**
	 * Splits strings into those too large for a block, by what they take, and the others.
	 *
	 * @param list<int> $strings Lengths of the strings.
	 * @return array{int, list<int>} What the large ones take, and the lengths of the others, in their order.
	 */
	private static function split( array $strings ): array {
		$page  = self::page();
		$huge  = 0;
		$small = [];
		foreach ( $strings as $bytes ) {
			$size = self::string_size( $bytes );
			if ( $size > self::LARGEST_IN_BLOCK ) {
				$huge += intdiv( $size + $page - 1, $page ) * $page;
			} else {
				$small[] = $bytes;
			}
		}
		return [ $huge, $small ];
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

	/**
	 * Measures the page that PHP rounds memory of its own up to: by how much the reserved
	 * memory grows for a string of a block's size, which is too large for a block. Where that
	 * string would not fit beside the memory in use, or the measure is disturbed, the largest
	 * page that Linux uses is assumed.
	 *
	 * @return int Bytes.
	 */
	private static function measure_page(): int {
		$limit = ini_parse_quantity( ini_get( 'memory_limit' ) );
		if ( $limit > 0 && $limit - memory_get_usage( true ) < 2 * self::BLOCK ) {
			return 65536;
		}
		$before = memory_get_usage( true );
		$probe  = str_repeat( "\0", self::BLOCK );
		$grown  = memory_get_usage( true ) - $before;
		unset( $probe );
		$size = self::string_size( self::BLOCK );
		foreach ( [ 4096, 16384, 65536 ] as $page ) {
			if ( intdiv( $size + $page - 1, $page ) * $page === $grown ) {
				return $page;
			}
		}
		return 65536;
	}
}
