<?php
/**
 * Content-defined chunking.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Chunker;

defined( 'ABSPATH' ) || exit;

/**
 * The content-defined chunker of restic (restic/chunker), reproduced bit for bit.
 *
 * A Rabin fingerprint over a 64-byte window is computed with the repository's polynomial. A
 * chunk ends where the low 20 bits of the fingerprint are zero, giving 1 MiB chunks on average,
 * bounded by 512 KiB and 8 MiB. The first 512 KiB - 64 bytes of each chunk are skipped without
 * hashing. Identical boundaries let the plugin deduplicate against data written by restic.
 */
final class RabinChunker {

	public const int MIN_SIZE    = 524288;
	public const int MAX_SIZE    = 8388608;
	public const int WINDOW_SIZE = 64;
	public const int SPLIT_MASK  = 1048575;

	/**
	 * Lookup tables by polynomial.
	 *
	 * @var array<int, array{0: list<int>, 1: list<int>}>
	 */
	private static array $tables = [];

	/**
	 * Fingerprint contribution of the byte leaving the window.
	 *
	 * @var list<int>
	 */
	private array $out;

	/**
	 * Reduction table indexed by the top 8 bits of the fingerprint.
	 *
	 * @var list<int>
	 */
	private array $mod;

	/**
	 * Degree of the polynomial minus 8.
	 *
	 * @var int
	 */
	private int $shift;

	/**
	 * The sliding window.
	 *
	 * @var array<int, int>
	 */
	private array $window;

	/**
	 * Position in the window.
	 *
	 * @var int
	 */
	private int $position;

	/**
	 * Current fingerprint.
	 *
	 * @var int
	 */
	private int $digest;

	/**
	 * Bytes still to skip before hashing starts in this chunk.
	 *
	 * @var int
	 */
	private int $skip;

	/**
	 * Bytes in the current chunk.
	 *
	 * @var int
	 */
	private int $count;

	/**
	 * Creates a chunker for a repository polynomial.
	 *
	 * @param int $polynomial Irreducible polynomial of degree 53 at most.
	 */
	public function __construct( int $polynomial ) {
		if ( ! isset( self::$tables[ $polynomial ] ) ) {
			self::$tables[ $polynomial ] = self::build_tables( $polynomial );
		}
		[ $this->out, $this->mod ] = self::$tables[ $polynomial ];
		$this->shift               = Polynomial::degree( $polynomial ) - 8;
		$this->reset();
	}

	/**
	 * Starts a new chunk, exactly as restic's reset(): empty window, then the byte 1 is slid in.
	 */
	public function reset(): void {
		$this->window    = array_fill( 0, self::WINDOW_SIZE, 0 );
		$this->window[0] = 1;
		$this->position  = 1;
		$this->digest    = 1 ^ $this->mod[0];
		$this->count     = 0;
		$this->skip      = self::MIN_SIZE - self::WINDOW_SIZE;
	}

	/**
	 * Scans bytes for the end of the current chunk.
	 *
	 * @param string $buffer Data.
	 * @param int    $offset First byte to scan.
	 * @param int    $length Number of bytes to scan.
	 * @return int Number of bytes up to and including the cut point, or -1 if the chunk continues.
	 */
	public function next_split_point( string $buffer, int $offset, int $length ): int {
		if ( $this->skip >= $length ) {
			$this->skip  -= $length;
			$this->count += $length;
			return -1;
		}

		$index        = $offset + $this->skip;
		$this->count += $this->skip;
		$this->skip   = 0;

		$end      = $offset + $length;
		$count    = $this->count;
		$digest   = $this->digest;
		$window   = $this->window;
		$position = $this->position;
		$out      = $this->out;
		$mod      = $this->mod;
		$shift    = $this->shift;

		for ( $i = $index; $i < $end; ++$i ) {
			$byte            = ord( $buffer[ $i ] );
			$slot            = $position & 63;
			$digest         ^= $out[ $window[ $slot ] ];
			$window[ $slot ] = $byte;
			++$position;
			$digest = ( ( $digest << 8 ) | $byte ) ^ $mod[ $digest >> $shift ];
			++$count;

			if ( ( 0 === ( $digest & self::SPLIT_MASK ) && $count >= self::MIN_SIZE ) || $count >= self::MAX_SIZE ) {
				$this->reset();
				return $i + 1 - $offset;
			}
		}

		$this->digest   = $digest;
		$this->window   = $window;
		$this->position = $position & 63;
		$this->count    = $count;
		return -1;
	}

	/**
	 * Builds the out and mod tables for a polynomial.
	 *
	 * @param int $polynomial Polynomial.
	 * @return array{0: list<int>, 1: list<int>} Tables.
	 */
	private static function build_tables( int $polynomial ): array {
		$degree = Polynomial::degree( $polynomial );
		$out    = [];
		$mod    = [];
		for ( $byte = 0; $byte < 256; $byte++ ) {
			$hash = Polynomial::mod( $byte, $polynomial );
			for ( $i = 0; $i < self::WINDOW_SIZE - 1; $i++ ) {
				$hash = Polynomial::mod( $hash << 8, $polynomial );
			}
			$out[] = $hash;
			$mod[] = Polynomial::mod( $byte << $degree, $polynomial ) | ( $byte << $degree );
		}
		return [ $out, $mod ];
	}
}
