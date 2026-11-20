<?php
/**
 * File data allocated by the apply journal on its destination filesystem.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * A create publishes one dense private candidate. A replace also retains a dense copy
 * of the old live file. Undo renames the retained copy or unlinks a created entry.
 * The filesystem may keep superseded blocks for snapshots, reflinks or an open handle.
 *
 * @phpstan-import-type Row from ApplyManifest
 * @phpstan-type Estimate array{required_bytes:int|null,available_bytes:int|null,enough_space:bool|null}
 */
final class FileSpace {

	/**
	 * Filesystem-reported I/O block size used to estimate dense writes.
	 *
	 * @var int|null
	 */
	private ?int $unit = null;

	/**
	 * Opens the same private storage that receives candidates and undo preimages.
	 *
	 * @param string                       $directory Private jobs directory.
	 * @param (\Closure(string):?int)|null $available Optional host free-space probe.
	 */
	public function __construct( private readonly string $directory, private readonly ?\Closure $available = null ) {
	}

	/**
	 * Counts the payloads in a verified row using the sizes bound to its fingerprints.
	 * No credit is taken for deleting or replacing a live inode.
	 *
	 * @param Row $row Checked apply row, including the source and original stat identities.
	 * @return int Estimated bytes newly allocated for this entry.
	 * @throws StorageFailed If a regular file has no usable reviewed length.
	 */
	public function row( array $row ): int {
		if ( ! in_array( $row['action'], [ 'create', 'replace' ], true ) ) {
			return 0;
		}
		$identities = explode( '/', $row['identity'] );
		if ( count( $identities ) !== ( 'replace' === $row['action'] ? 2 : 1 ) ) {
			throw new StorageFailed( 'The file preview has incomplete payload identities.' );
		}
		$total = 0;
		foreach ( $identities as $identity ) {
			// ApplyManifest::identity binds device, inode, ownership, mode, size and both timestamps.
			$fields = explode( ':', $identity );
			$size   = $fields[5] ?? '';
			if ( 8 !== count( $fields ) || ! ctype_digit( $size ) || (string) (int) $size !== $size ) {
				throw new StorageFailed( 'The file preview has an invalid payload length.' );
			}
			$total = self::add( $total, $this->allocation( (int) $size ) );
		}
		return $total;
	}

	/**
	 * Estimates a dense file rounded to the host's reported block size. Hosts without
	 * this hint report logical data bytes. No percentage or constant reserve is added.
	 *
	 * @param int $bytes File length.
	 * @return int Estimated allocation, saturated at PHP_INT_MAX.
	 * @throws StorageFailed If the volume is unavailable or the length invalid.
	 */
	public function allocation( int $bytes ): int {
		if ( $bytes < 0 ) {
			throw new StorageFailed( 'A file payload length cannot be negative.' );
		}
		if ( null === $this->unit ) {
			$stat = is_dir( $this->directory ) ? stat( $this->directory ) : false;
			if ( false === $stat ) {
				throw new StorageFailed( 'The private file-change volume is unavailable.' );
			}
			$this->unit = max( 1, $stat['blksize'] );
		}
		$remainder = $bytes % $this->unit;
		return 0 === $remainder ? $bytes : self::add( $bytes, $this->unit - $remainder );
	}

	/**
	 * Adds nonnegative allocation estimates without allowing integer wraparound.
	 *
	 * @param int $left Running estimate.
	 * @param int $right Another allocation.
	 * @return int Bounded sum.
	 */
	public static function add( int $left, int $right ): int {
		return $left >= PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
	}

	/**
	 * Samples current free space on the volume where FileChange allocates both copies.
	 *
	 * @param int|null $required Estimated new data blocks, or null for an old sealed review.
	 * @return Estimate Current public values. Quotas and concurrent writers can change the outcome.
	 */
	public function estimate( ?int $required ): array {
		if ( null !== $this->available ) {
			$free = ( $this->available )( $this->directory );
		} else {
			$reported = function_exists( 'disk_free_space' ) ? disk_free_space( $this->directory ) : false;
			$free     = false === $reported || $reported < 0 ? null : ( $reported >= PHP_INT_MAX ? PHP_INT_MAX : (int) $reported );
		}
		return [
			'required_bytes'  => $required,
			'available_bytes' => $free,
			'enough_space'    => null === $required || null === $free ? null : $required <= $free,
		];
	}

	/**
	 * Stops before allocating a candidate or preimage the host already cannot fit.
	 *
	 * @param Estimate $estimate Most recent estimate.
	 * @throws JobConflict If free space is known to be insufficient.
	 */
	public static function require_space( array $estimate ): void {
		if ( false === $estimate['enough_space'] ) {
			JobConflict::raise(
				'Insufficient free space for the reviewed file data and undo copies.',
				[ 'code' => 'file_space' ]
			);
		}
	}
}
