<?php
/**
 * Key derivation with scrypt in pure PHP.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

use BackupsForRestic\Exception\CryptoException;

defined( 'ABSPATH' ) || exit;

/**
 * The scrypt function (RFC 7914) with a resumable, optionally file-backed lookup table.
 *
 * The key that unlocks a restic repository is derived with scrypt; N, r and p are stored in the
 * key file. PHP's libsodium binding only offers scrypt with a fixed 32-byte salt and hidden
 * parameters, while restic uses 64-byte salts, so the core loop is implemented here
 * (ADR 0020). PBKDF2-HMAC-SHA256 comes from PHP's hash extension.
 *
 * On shared hosting one derivation can take longer than a PHP request. run() therefore stops
 * at a deadline and checkpoint() returns the state needed to continue in the next request; the
 * lookup table then lives in a file instead of memory. The checkpoint and the table are as
 * sensitive as the derived key and belong in the plugin's private directory only.
 */
final class Scrypt {

	private const int PHASE_FILL = 1;
	private const int PHASE_MIX  = 2;
	private const int PHASE_DONE = 3;

	/** Iterations between deadline checks. */
	private const int CHECK_INTERVAL = 64;

	/**
	 * Output of the first PBKDF2 step: p blocks of 128 * r bytes.
	 *
	 * @var string
	 */
	private string $blocks;

	/**
	 * Current phase.
	 *
	 * @var int
	 */
	private int $phase = self::PHASE_FILL;

	/**
	 * Index of the parallel block being mixed.
	 *
	 * @var int<0, max>
	 */
	private int $block = 0;

	/**
	 * Iteration within the current phase.
	 *
	 * @var int<0, max>
	 */
	private int $iteration = 0;

	/**
	 * The working state X.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * Finished blocks, concatenated.
	 *
	 * @var string
	 */
	private string $mixed = '';

	/**
	 * Lookup table V when kept in memory.
	 *
	 * @var array<int, string>
	 */
	private array $table = [];

	/**
	 * Lookup table V when kept in a file.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Derived key once finished.
	 *
	 * @var string
	 */
	private string $result = '';

	/**
	 * Prepares a derivation.
	 *
	 * @param string              $password   Password.
	 * @param string              $salt       Salt.
	 * @param ScryptParameters    $parameters Cost parameters.
	 * @param positive-int        $length     Output length in bytes.
	 * @param string|null         $table_file File for the lookup table; null keeps it in memory, which cannot be resumed.
	 * @param array<string,mixed> $checkpoint State returned by checkpoint() in an earlier request.
	 * @throws CryptoException If the checkpoint is inconsistent or the table file cannot be used.
	 */
	public function __construct(
		#[\SensitiveParameter] private readonly string $password,
		private readonly string $salt,
		private readonly ScryptParameters $parameters,
		private readonly int $length = MasterKey::KDF_OUTPUT_LENGTH,
		private readonly ?string $table_file = null,
		#[\SensitiveParameter] array $checkpoint = []
	) {
		$block_length = 128 * $parameters->r;
		$this->blocks = hash_pbkdf2( 'sha256', $password, $salt, 1, $parameters->p * $block_length, true );
		$this->state  = substr( $this->blocks, 0, $block_length );

		if ( [] !== $checkpoint ) {
			$this->restore( $checkpoint, $block_length );
		}
		if ( null !== $table_file ) {
			$this->open_table();
		}
	}

	/**
	 * Derives a key in one go with the table in memory.
	 *
	 * @param string           $password   Password.
	 * @param string           $salt       Salt.
	 * @param ScryptParameters $parameters Cost parameters.
	 * @param positive-int     $length     Output length.
	 * @return string Derived key.
	 */
	public static function derive( #[\SensitiveParameter] string $password, string $salt, ScryptParameters $parameters, int $length = MasterKey::KDF_OUTPUT_LENGTH ): string {
		$scrypt = new self( $password, $salt, $parameters, $length );
		$scrypt->run();
		return $scrypt->result();
	}

	/**
	 * Works until the derivation is finished or the deadline has passed.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when finished.
	 * @throws CryptoException If the table cannot be read or written.
	 */
	public function run( float $deadline = INF ): bool {
		$n            = $this->parameters->n;
		$mask         = $n - 1;
		$block_length = 128 * $this->parameters->r;
		$tail         = $block_length - 64;

		while ( self::PHASE_DONE !== $this->phase ) {
			if ( self::PHASE_FILL === $this->phase ) {
				while ( $this->iteration < $n ) {
					$this->store( $this->iteration, $this->state );
					$this->state = $this->block_mix( $this->state );
					++$this->iteration;
					if ( 0 === $this->iteration % self::CHECK_INTERVAL && microtime( true ) >= $deadline ) {
						return false;
					}
				}
				$this->phase     = self::PHASE_MIX;
				$this->iteration = 0;
			}

			while ( $this->iteration < $n ) {
				$j           = self::read_uint32( $this->state, $tail ) & $mask;
				$this->state = $this->block_mix( $this->state ^ $this->load( $j, $block_length ) );
				++$this->iteration;
				if ( 0 === $this->iteration % self::CHECK_INTERVAL && microtime( true ) >= $deadline ) {
					return false;
				}
			}

			$this->mixed .= $this->state;
			++$this->block;
			$this->iteration = 0;

			if ( $this->block < $this->parameters->p ) {
				$this->phase = self::PHASE_FILL;
				$this->state = substr( $this->blocks, $this->block * $block_length, $block_length );
				continue;
			}

			$this->result = hash_pbkdf2( 'sha256', $this->password, $this->mixed, 1, $this->length, true );
			$this->phase  = self::PHASE_DONE;
			$this->release_table();
		}
		return true;
	}

	/**
	 * Returns the derived key.
	 *
	 * @return string Key bytes.
	 * @throws CryptoException If the derivation has not finished.
	 */
	public function result(): string {
		if ( self::PHASE_DONE !== $this->phase ) {
			throw new CryptoException( 'Key derivation has not finished.' );
		}
		return $this->result;
	}

	/**
	 * Returns the state needed to resume in a later request.
	 *
	 * @return array<string, mixed> JSON-serializable state; as sensitive as the derived key.
	 * @throws CryptoException If the table is kept in memory.
	 */
	public function checkpoint(): array {
		if ( null === $this->table_file ) {
			throw new CryptoException( 'A derivation with an in-memory table cannot be resumed.' );
		}
		if ( null !== $this->handle ) {
			fflush( $this->handle );
		}
		return [
			'phase'     => $this->phase,
			'block'     => $this->block,
			'iteration' => $this->iteration,
			'state'     => bin2hex( $this->state ),
			'mixed'     => bin2hex( $this->mixed ),
		];
	}

	/**
	 * Returns the fraction of work done, for progress display.
	 *
	 * @return float Between 0 and 1.
	 */
	public function progress(): float {
		$total = 2 * $this->parameters->n * $this->parameters->p;
		$done  = 2 * $this->parameters->n * $this->block + ( self::PHASE_MIX === $this->phase ? $this->parameters->n : 0 ) + $this->iteration;
		return self::PHASE_DONE === $this->phase ? 1.0 : min( 1.0, $done / $total );
	}

	/**
	 * Restores the state from a checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint   State.
	 * @param int                 $block_length Bytes per block.
	 * @throws CryptoException If the checkpoint is inconsistent.
	 */
	private function restore( array $checkpoint, int $block_length ): void {
		$phase     = $checkpoint['phase'] ?? null;
		$block     = $checkpoint['block'] ?? null;
		$iteration = $checkpoint['iteration'] ?? null;
		$state     = is_string( $checkpoint['state'] ?? null ) ? hex2bin( $checkpoint['state'] ) : false;
		$mixed     = is_string( $checkpoint['mixed'] ?? null ) ? hex2bin( $checkpoint['mixed'] ) : false;

		if ( ! in_array( $phase, [ self::PHASE_FILL, self::PHASE_MIX ], true ) || ! is_int( $block ) || ! is_int( $iteration )
			|| false === $state || false === $mixed || strlen( $state ) !== $block_length
			|| $block < 0 || $block >= $this->parameters->p || strlen( $mixed ) !== $block * $block_length
			|| $iteration < 0 || $iteration > $this->parameters->n ) {
			throw new CryptoException( 'Key derivation checkpoint is inconsistent.' );
		}

		$this->phase     = $phase;
		$this->block     = $block;
		$this->iteration = $iteration;
		$this->state     = $state;
		$this->mixed     = $mixed;
	}

	/**
	 * Opens the table file, truncated to the blocks that are known to be complete.
	 *
	 * @throws CryptoException If the file cannot be opened.
	 */
	private function open_table(): void {
		$handle = fopen( (string) $this->table_file, 'c+b' );
		if ( false === $handle ) {
			throw new CryptoException( 'Key derivation table cannot be opened.' );
		}
		$this->handle = $handle;

		if ( self::PHASE_FILL === $this->phase ) {
			// Blocks beyond the checkpoint may be partially written; they are rewritten.
			$complete = $this->iteration * 128 * $this->parameters->r;
			ftruncate( $handle, $complete );
			fseek( $handle, $complete );
		}
	}

	/**
	 * Stores V[i].
	 *
	 * @param int    $index Index.
	 * @param string $value Block.
	 * @throws CryptoException If writing fails.
	 */
	private function store( int $index, string $value ): void {
		if ( null === $this->handle ) {
			$this->table[ $index ] = $value;
			return;
		}
		if ( 0 === $index ) {
			ftruncate( $this->handle, 0 );
			fseek( $this->handle, 0 );
		}
		if ( fwrite( $this->handle, $value ) !== strlen( $value ) ) {
			throw new CryptoException( 'Key derivation table cannot be written.' );
		}
	}

	/**
	 * Loads V[j].
	 *
	 * @param int          $index        Index.
	 * @param positive-int $block_length Bytes per block.
	 * @return string Block.
	 * @throws CryptoException If reading fails.
	 */
	private function load( int $index, int $block_length ): string {
		if ( null === $this->handle ) {
			return $this->table[ $index ];
		}
		fseek( $this->handle, $index * $block_length );
		$value = fread( $this->handle, $block_length );
		if ( false === $value || strlen( $value ) !== $block_length ) {
			throw new CryptoException( 'Key derivation table cannot be read.' );
		}
		return $value;
	}

	/**
	 * Frees the table and deletes the table file.
	 */
	private function release_table(): void {
		$this->table = [];
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
		if ( null !== $this->table_file && is_file( $this->table_file ) ) {
			unlink( $this->table_file );
		}
	}

	/**
	 * The scryptBlockMix function with Salsa20/8.
	 *
	 * @param string $input 128 * r bytes.
	 * @return string 128 * r bytes.
	 */
	private function block_mix( string $input ): string {
		$count = 2 * $this->parameters->r;
		$x     = substr( $input, ( $count - 1 ) * 64, 64 );
		$even  = '';
		$odd   = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$x = self::salsa20_8( $x ^ substr( $input, $i * 64, 64 ) );
			if ( 0 === ( $i & 1 ) ) {
				$even .= $x;
			} else {
				$odd .= $x;
			}
		}
		return $even . $odd;
	}

	/**
	 * Reads a little-endian 32-bit integer (Integerify for N <= 2^32).
	 *
	 * @param string $bytes  Input.
	 * @param int    $offset Position.
	 * @return int Value.
	 */
	private static function read_uint32( string $bytes, int $offset ): int {
		$value = unpack( 'V', $bytes, $offset );
		return is_array( $value ) ? $value[1] : 0;
	}

	/**
	 * The Salsa20/8 core (RFC 7914 §3), unrolled for speed.
	 *
	 * @param string $input 64 bytes.
	 * @return string 64 bytes.
	 */
	private static function salsa20_8( string $input ): string {
		$words = unpack( 'V16', $input );
		if ( ! is_array( $words ) ) {
			return $input;
		}
		[ 1 => $x0, 2 => $x1, 3 => $x2, 4 => $x3, 5 => $x4, 6 => $x5, 7 => $x6, 8 => $x7, 9 => $x8, 10 => $x9, 11 => $x10, 12 => $x11, 13 => $x12, 14 => $x13, 15 => $x14, 16 => $x15 ] = $words;
		$j0  = $x0;
		$j1  = $x1;
		$j2  = $x2;
		$j3  = $x3;
		$j4  = $x4;
		$j5  = $x5;
		$j6  = $x6;
		$j7  = $x7;
		$j8  = $x8;
		$j9  = $x9;
		$j10 = $x10;
		$j11 = $x11;
		$j12 = $x12;
		$j13 = $x13;
		$j14 = $x14;
		$j15 = $x15;

		for ( $round = 0; $round < 4; $round++ ) {
			$t    = ( $x0 + $x12 ) & 0xffffffff;
			$x4  ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x4 + $x0 ) & 0xffffffff;
			$x8  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x8 + $x4 ) & 0xffffffff;
			$x12 ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x12 + $x8 ) & 0xffffffff;
			$x0  ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x5 + $x1 ) & 0xffffffff;
			$x9  ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x9 + $x5 ) & 0xffffffff;
			$x13 ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x13 + $x9 ) & 0xffffffff;
			$x1  ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x1 + $x13 ) & 0xffffffff;
			$x5  ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x10 + $x6 ) & 0xffffffff;
			$x14 ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x14 + $x10 ) & 0xffffffff;
			$x2  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x2 + $x14 ) & 0xffffffff;
			$x6  ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x6 + $x2 ) & 0xffffffff;
			$x10 ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x15 + $x11 ) & 0xffffffff;
			$x3  ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x3 + $x15 ) & 0xffffffff;
			$x7  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x7 + $x3 ) & 0xffffffff;
			$x11 ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x11 + $x7 ) & 0xffffffff;
			$x15 ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x0 + $x3 ) & 0xffffffff;
			$x1  ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x1 + $x0 ) & 0xffffffff;
			$x2  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x2 + $x1 ) & 0xffffffff;
			$x3  ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x3 + $x2 ) & 0xffffffff;
			$x0  ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x5 + $x4 ) & 0xffffffff;
			$x6  ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x6 + $x5 ) & 0xffffffff;
			$x7  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x7 + $x6 ) & 0xffffffff;
			$x4  ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x4 + $x7 ) & 0xffffffff;
			$x5  ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x10 + $x9 ) & 0xffffffff;
			$x11 ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x11 + $x10 ) & 0xffffffff;
			$x8  ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x8 + $x11 ) & 0xffffffff;
			$x9  ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x9 + $x8 ) & 0xffffffff;
			$x10 ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
			$t    = ( $x15 + $x14 ) & 0xffffffff;
			$x12 ^= ( ( $t << 7 ) | ( $t >> 25 ) ) & 0xffffffff;
			$t    = ( $x12 + $x15 ) & 0xffffffff;
			$x13 ^= ( ( $t << 9 ) | ( $t >> 23 ) ) & 0xffffffff;
			$t    = ( $x13 + $x12 ) & 0xffffffff;
			$x14 ^= ( ( $t << 13 ) | ( $t >> 19 ) ) & 0xffffffff;
			$t    = ( $x14 + $x13 ) & 0xffffffff;
			$x15 ^= ( ( $t << 18 ) | ( $t >> 14 ) ) & 0xffffffff;
		}

		return pack(
			'V16',
			( $x0 + $j0 ) & 0xffffffff,
			( $x1 + $j1 ) & 0xffffffff,
			( $x2 + $j2 ) & 0xffffffff,
			( $x3 + $j3 ) & 0xffffffff,
			( $x4 + $j4 ) & 0xffffffff,
			( $x5 + $j5 ) & 0xffffffff,
			( $x6 + $j6 ) & 0xffffffff,
			( $x7 + $j7 ) & 0xffffffff,
			( $x8 + $j8 ) & 0xffffffff,
			( $x9 + $j9 ) & 0xffffffff,
			( $x10 + $j10 ) & 0xffffffff,
			( $x11 + $j11 ) & 0xffffffff,
			( $x12 + $j12 ) & 0xffffffff,
			( $x13 + $j13 ) & 0xffffffff,
			( $x14 + $j14 ) & 0xffffffff,
			( $x15 + $j15 ) & 0xffffffff
		);
	}
}
