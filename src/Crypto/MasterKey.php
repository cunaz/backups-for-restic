<?php
/**
 * The repository master key.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Crypto;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * A restic key: 32 bytes for AES-256 plus a Poly1305-AES MAC key made of k (16 bytes, AES-128
 * key) and r (16 bytes, Poly1305 key).
 *
 * The same structure serves as the user key derived with scrypt from the password and as the
 * master key stored inside a key file. Instances never reveal their bytes in var_dump(),
 * print_r() or serialize().
 */
final class MasterKey {

	public const int ENCRYPTION_KEY_LENGTH = 32;
	public const int MAC_KEY_LENGTH        = 16;
	public const int KDF_OUTPUT_LENGTH     = 64;

	/**
	 * Creates a key from its parts.
	 *
	 * @param string $encryption_key AES-256 key.
	 * @param string $mac_k          AES-128 key of the Poly1305-AES MAC.
	 * @param string $mac_r          Poly1305 key.
	 * @throws FormatException If a part has the wrong length or is all zeros.
	 */
	private function __construct(
		#[\SensitiveParameter] private string $encryption_key,
		#[\SensitiveParameter] private string $mac_k,
		#[\SensitiveParameter] private string $mac_r
	) {
		if ( strlen( $encryption_key ) !== self::ENCRYPTION_KEY_LENGTH
			|| strlen( $mac_k ) !== self::MAC_KEY_LENGTH
			|| strlen( $mac_r ) !== self::MAC_KEY_LENGTH ) {
			throw new FormatException( 'Key material has the wrong length.' );
		}
		// restic rejects keys whose parts are all zero (crypto.Key.Valid()).
		foreach ( [ $encryption_key, $mac_k, $mac_r ] as $part ) {
			if ( '' === trim( $part, "\0" ) ) {
				throw new FormatException( 'Key material must not be all zeros.' );
			}
		}
	}

	/**
	 * Generates a new random master key.
	 *
	 * @param RandomSource $random Randomness.
	 * @return self New key.
	 */
	public static function generate( RandomSource $random ): self {
		return new self(
			$random->bytes( self::ENCRYPTION_KEY_LENGTH ),
			$random->bytes( self::MAC_KEY_LENGTH ),
			$random->bytes( self::MAC_KEY_LENGTH )
		);
	}

	/**
	 * Splits the 64-byte scrypt output into a user key, as restic's crypto.KDF() does.
	 *
	 * @param string $bytes scrypt output.
	 * @return self User key.
	 * @throws FormatException If the output has the wrong length.
	 */
	public static function from_kdf_output( #[\SensitiveParameter] string $bytes ): self {
		if ( strlen( $bytes ) !== self::KDF_OUTPUT_LENGTH ) {
			throw new FormatException( 'Key derivation output has the wrong length.' );
		}
		return new self( substr( $bytes, 0, 32 ), substr( $bytes, 32, 16 ), substr( $bytes, 48, 16 ) );
	}

	/**
	 * Parses the master key JSON stored inside a key file.
	 *
	 * @param string $json {"mac":{"k":base64,"r":base64},"encrypt":base64}.
	 * @return self Master key.
	 * @throws FormatException If the JSON does not describe a key.
	 */
	public static function from_json( #[\SensitiveParameter] string $json ): self {
		$data = GoJson::decode_object( $json );
		$mac  = $data['mac'] ?? null;
		if ( ! is_array( $mac ) || ! is_string( $mac['k'] ?? null ) || ! is_string( $mac['r'] ?? null ) || ! is_string( $data['encrypt'] ?? null ) ) {
			throw new FormatException( 'Master key JSON is incomplete.' );
		}
		return new self(
			Bytes::base64_decode( $data['encrypt'] ),
			Bytes::base64_decode( $mac['k'] ),
			Bytes::base64_decode( $mac['r'] )
		);
	}

	/**
	 * Serializes the key exactly as restic does.
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		return GoJson::encode(
			[
				'mac'     => [
					'k' => Bytes::base64_encode( $this->mac_k ),
					'r' => Bytes::base64_encode( $this->mac_r ),
				],
				'encrypt' => Bytes::base64_encode( $this->encryption_key ),
			]
		);
	}

	/**
	 * Returns the AES-256 key.
	 *
	 * @return string 32 bytes.
	 */
	public function encryption_key(): string {
		return $this->encryption_key;
	}

	/**
	 * Returns the AES-128 key used to derive the per-message Poly1305 key.
	 *
	 * @return string 16 bytes.
	 */
	public function mac_k(): string {
		return $this->mac_k;
	}

	/**
	 * Returns the Poly1305 key part r.
	 *
	 * @return string 16 bytes.
	 */
	public function mac_r(): string {
		return $this->mac_r;
	}

	/**
	 * Hides key material from var_dump() and print_r().
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return [ 'key' => '[redacted]' ];
	}

	/**
	 * Prevents serialization, which would write key material to caches or logs.
	 *
	 * @return never
	 * @throws \LogicException Always.
	 */
	public function __serialize(): never {
		throw new \LogicException( 'Master keys cannot be serialized.' );
	}
}
