<?php
/**
 * The memory a job may use.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Exception\IndexTooLarge;

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
 */
final readonly class MemoryBudget {

	/** The smallest pack size a backup falls back to: the lower end of advanced.pack_size_mib. */
	public const int SMALLEST_PACK = 4194304;

	/** What a backup needs besides the index and its packs. */
	public const int WORKING_BYTES = 20971520;

	/** How many times its size a pack takes while it is uploaded. */
	public const int PACK_COPIES = 3;

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
	 *
	 * @throws IndexTooLarge If the memory in use is beyond what the index may take.
	 */
	public function check_index(): void {
		$used = memory_get_usage();
		$room = $this->limit - self::WORKING_BYTES - self::PACK_COPIES * self::SMALLEST_PACK;
		if ( $used > $room ) {
			IndexTooLarge::raise(
				'The index of the repository does not fit into the memory this host allows.',
				[
					'memory_limit' => $this->limit,
					'in_use'       => $used,
					'room'         => $room,
				]
			);
		}
	}

	/**
	 * The pack size a backup can afford with the memory that is free now.
	 *
	 * @param int $wanted Configured pack size in bytes.
	 * @return int The configured size, or a smaller one that fits; never below the smallest pack.
	 */
	public function pack_size( int $wanted ): int {
		$affordable = intdiv( $this->limit - memory_get_usage() - self::WORKING_BYTES, self::PACK_COPIES );
		return min( $wanted, max( self::SMALLEST_PACK, $affordable ) );
	}
}
