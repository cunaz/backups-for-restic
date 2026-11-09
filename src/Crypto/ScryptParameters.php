<?php
/**
 * Cost parameters of scrypt.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

use BackupsForRestic\Exception\UnsupportedRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Validated scrypt parameters N, r and p.
 *
 * Key files come from the repository server, so their parameters are untrusted: besides the
 * rules restic applies, the plugin caps the work it is willing to do on shared hosting.
 */
final readonly class ScryptParameters {

	/** Largest cost the plugin accepts (2^20, a 1 GiB table with r = 8, see MAX_TABLE_BYTES). */
	public const int MAX_N = 1048576;
	public const int MAX_R = 32;
	public const int MAX_P = 16;

	/** Largest lookup table: file-backed, so this bounds disk use, not memory. */
	public const int MAX_TABLE_BYTES = 1073741824;

	/**
	 * CPU/memory cost, a power of two greater than 1.
	 *
	 * @var int<2, max>
	 */
	public int $n;

	/**
	 * Block size.
	 *
	 * @var positive-int
	 */
	public int $r;

	/**
	 * Parallelism.
	 *
	 * @var positive-int
	 */
	public int $p;

	/**
	 * Creates validated parameters.
	 *
	 * @param int $n CPU/memory cost, a power of two greater than 1.
	 * @param int $r Block size.
	 * @param int $p Parallelism.
	 * @throws UnsupportedRepository If the parameters are invalid or too expensive.
	 */
	public function __construct( int $n, int $r, int $p ) {
		if ( $n <= 1 || ( $n & ( $n - 1 ) ) !== 0 || $r < 1 || $p < 1 || $r * $p >= ( 1 << 30 ) ) {
			UnsupportedRepository::raise(
				'Key file has invalid scrypt parameters.',
				[
					'n' => $n,
					'r' => $r,
					'p' => $p,
				]
			);
		}
		if ( $n > self::MAX_N || $r > self::MAX_R || $p > self::MAX_P || $n * 128 * $r > self::MAX_TABLE_BYTES ) {
			UnsupportedRepository::raise(
				'Key file uses scrypt parameters beyond what this plugin supports.',
				[
					'n' => $n,
					'r' => $r,
					'p' => $p,
				]
			);
		}

		$this->n = $n;
		$this->r = $r;
		$this->p = $p;
	}

	/**
	 * Parameters for keys the plugin creates: restic's defaults when it cannot calibrate.
	 *
	 * @return self N = 2^14, r = 8, p = 1.
	 */
	public static function for_new_keys(): self {
		return new self( 16384, 8, 1 );
	}

	/**
	 * Size of the lookup table V for one parallel block.
	 *
	 * @return positive-int Bytes.
	 */
	public function table_bytes(): int {
		return $this->n * 128 * $this->r;
	}
}
