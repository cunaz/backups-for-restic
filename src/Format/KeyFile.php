<?php
/**
 * Key files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * A file in keys/: plain JSON that wraps the master key with a key derived from a password.
 *
 *     {"created":…,"username":…,"hostname":…,"kdf":"scrypt","N":…,"r":…,"p":…,
 *      "salt":base64(64 bytes),"data":base64(nonce || encrypted master key JSON || tag)}
 *
 * Several key files may exist; each unlocks the same master key with its own password.
 */
final readonly class KeyFile {

	public const int SALT_LENGTH = 64;

	/**
	 * Creates a key file representation.
	 *
	 * @param string $created  Creation time as stored.
	 * @param string $username Informational user name.
	 * @param string $hostname Informational host name.
	 * @param int    $n        scrypt N.
	 * @param int    $r        scrypt r.
	 * @param int    $p        scrypt p.
	 * @param string $salt     64-byte salt.
	 * @param string $data     Sealed master key.
	 * @throws FormatException If the salt length is wrong.
	 */
	public function __construct(
		public string $created,
		public string $username,
		public string $hostname,
		public int $n,
		public int $r,
		public int $p,
		public string $salt,
		public string $data
	) {
		if ( strlen( $salt ) !== self::SALT_LENGTH ) {
			throw new FormatException( 'Key file salt must be 64 bytes long.' );
		}
		if ( strlen( $data ) < Cipher::OVERHEAD ) {
			throw new FormatException( 'Key file data is too short.' );
		}
	}

	/**
	 * Wraps a master key for a password-derived user key.
	 *
	 * @param MasterKey        $master     Master key to protect.
	 * @param MasterKey        $user_key   Key derived from the password with scrypt.
	 * @param string           $salt       Salt used for the derivation.
	 * @param ScryptParameters $parameters Parameters used for the derivation.
	 * @param RandomSource     $random     Randomness for the nonce.
	 * @param string           $created    Creation time (RFC 3339).
	 * @param string           $username   Informational user name.
	 * @param string           $hostname   Informational host name.
	 * @return self Key file.
	 */
	public static function create( MasterKey $master, MasterKey $user_key, string $salt, ScryptParameters $parameters, RandomSource $random, string $created, string $username, string $hostname ): self {
		$data = ( new Cipher( $user_key, $random ) )->seal( $master->to_json() );
		return new self( $created, $username, $hostname, $parameters->n, $parameters->r, $parameters->p, $salt, $data );
	}

	/**
	 * Parses a key file.
	 *
	 * @param string $json Key file content.
	 * @return self Key file.
	 * @throws UnsupportedRepository If the KDF is not scrypt.
	 * @throws FormatException If fields are missing.
	 */
	public static function from_json( string $json ): self {
		$fields = GoJson::decode_object( $json );
		if ( 'scrypt' !== ( $fields['kdf'] ?? null ) ) {
			throw new UnsupportedRepository( 'Key file uses a key derivation function other than scrypt.' );
		}
		foreach ( [ 'N', 'r', 'p' ] as $name ) {
			if ( ! is_int( $fields[ $name ] ?? null ) ) {
				throw new FormatException( 'Key file lacks scrypt parameters.' );
			}
		}
		if ( ! is_string( $fields['salt'] ?? null ) || ! is_string( $fields['data'] ?? null ) ) {
			throw new FormatException( 'Key file lacks salt or data.' );
		}

		return new self(
			is_string( $fields['created'] ?? null ) ? $fields['created'] : '',
			is_string( $fields['username'] ?? null ) ? $fields['username'] : '',
			is_string( $fields['hostname'] ?? null ) ? $fields['hostname'] : '',
			$fields['N'],
			$fields['r'],
			$fields['p'],
			Bytes::base64_decode( $fields['salt'] ),
			Bytes::base64_decode( $fields['data'] )
		);
	}

	/**
	 * Serializes the key file as restic does; its ID is the SHA-256 of this text.
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		return GoJson::encode(
			[
				'created'  => $this->created,
				'username' => $this->username,
				'hostname' => $this->hostname,
				'kdf'      => 'scrypt',
				'N'        => $this->n,
				'r'        => $this->r,
				'p'        => $this->p,
				'salt'     => Bytes::base64_encode( $this->salt ),
				'data'     => Bytes::base64_encode( $this->data ),
			]
		);
	}

	/**
	 * Returns the validated scrypt parameters.
	 *
	 * @return ScryptParameters Parameters.
	 */
	public function scrypt_parameters(): ScryptParameters {
		return new ScryptParameters( $this->n, $this->r, $this->p );
	}

	/**
	 * Unwraps the master key with a user key derived from the password.
	 *
	 * @param MasterKey    $user_key Key derived with scrypt.
	 * @param RandomSource $random   Randomness (unused for decryption, required by Cipher).
	 * @return MasterKey Master key.
	 * @throws WrongPassword If the user key does not open this key file.
	 */
	public function open( MasterKey $user_key, RandomSource $random ): MasterKey {
		try {
			return MasterKey::from_json( ( new Cipher( $user_key, $random ) )->open( $this->data ) );
		} catch ( AuthenticationFailed | FormatException $exception ) {
			WrongPassword::raise( 'The password does not open this key.', [], $exception );
		}
	}
}
