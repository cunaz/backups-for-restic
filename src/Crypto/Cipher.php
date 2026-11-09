<?php
/**
 * Authenticated encryption as restic uses it.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\CryptoException;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and authenticates data exactly like restic's crypto.Key.Seal()/Open().
 *
 * Layout:
 *
 *     ciphertext = nonce (16) || AES-256-CTR(key, nonce, plaintext) || tag (16)
 *     tag        = Poly1305(ciphertext without nonce, key = r || AES-128(k, nonce))
 *
 * The nonce is random per object and also serves as the CTR counter block. Decryption checks
 * the tag in constant time before it decrypts anything. AES runs in OpenSSL; Poly1305 runs in
 * PHP because no PHP extension exposes it (see Poly1305).
 */
final class Cipher {

	public const int NONCE_LENGTH = 16;
	public const int TAG_LENGTH   = 16;
	public const int OVERHEAD     = self::NONCE_LENGTH + self::TAG_LENGTH;

	/**
	 * Creates a cipher for one key.
	 *
	 * @param MasterKey    $key    Key.
	 * @param RandomSource $random Source of nonces.
	 */
	public function __construct(
		private readonly MasterKey $key,
		private readonly RandomSource $random
	) {
	}

	/**
	 * Encrypts and authenticates.
	 *
	 * @param string $plaintext Data.
	 * @return string nonce || ciphertext || tag.
	 * @throws CryptoException If OpenSSL fails.
	 */
	public function seal( string $plaintext ): string {
		do {
			// restic refuses all-zero nonces; with a CSPRNG this loop runs once.
			$nonce = $this->random->bytes( self::NONCE_LENGTH );
		} while ( '' === trim( $nonce, "\0" ) );

		$ciphertext = '' === $plaintext ? '' : openssl_encrypt( $plaintext, 'aes-256-ctr', $this->key->encryption_key(), OPENSSL_RAW_DATA, $nonce );
		if ( false === $ciphertext ) {
			throw new CryptoException( 'AES encryption failed.' );
		}

		return $nonce . $ciphertext . Poly1305::mac( $ciphertext, $this->poly1305_key( $nonce ) );
	}

	/**
	 * Verifies and decrypts.
	 *
	 * @param string $data nonce || ciphertext || tag.
	 * @return string Plaintext.
	 * @throws AuthenticationFailed If the data is too short, the nonce is invalid or the tag does not match.
	 * @throws CryptoException If OpenSSL fails.
	 */
	public function open( string $data ): string {
		if ( strlen( $data ) < self::OVERHEAD ) {
			throw new AuthenticationFailed( 'Encrypted object is too short.' );
		}

		$nonce      = substr( $data, 0, self::NONCE_LENGTH );
		$ciphertext = substr( $data, self::NONCE_LENGTH, -self::TAG_LENGTH );
		$tag        = substr( $data, -self::TAG_LENGTH );

		if ( '' === trim( $nonce, "\0" ) ) {
			throw new AuthenticationFailed( 'Encrypted object has an invalid nonce.' );
		}
		if ( ! Poly1305::verify( $tag, $ciphertext, $this->poly1305_key( $nonce ) ) ) {
			throw new AuthenticationFailed( 'Encrypted object failed authentication.' );
		}
		if ( '' === $ciphertext ) {
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-ctr', $this->key->encryption_key(), OPENSSL_RAW_DATA, $nonce );
		if ( false === $plaintext ) {
			throw new CryptoException( 'AES decryption failed.' );
		}
		return $plaintext;
	}

	/**
	 * Returns the size of the sealed form of a plaintext of the given length.
	 *
	 * @param int $plaintext_length Plaintext length.
	 * @return int Ciphertext length.
	 */
	public static function ciphertext_length( int $plaintext_length ): int {
		return $plaintext_length + self::OVERHEAD;
	}

	/**
	 * Derives the one-time Poly1305 key for a nonce: r || AES-128(k, nonce).
	 *
	 * @param string $nonce 16-byte nonce.
	 * @return string 32-byte Poly1305 key.
	 * @throws CryptoException If OpenSSL fails.
	 */
	private function poly1305_key( string $nonce ): string {
		$block = openssl_encrypt( $nonce, 'aes-128-ecb', $this->key->mac_k(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		if ( false === $block || 16 !== strlen( $block ) ) {
			throw new CryptoException( 'AES block encryption failed.' );
		}
		return $this->key->mac_r() . $block;
	}
}
