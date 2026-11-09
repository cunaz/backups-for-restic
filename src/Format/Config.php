<?php
/**
 * Repository configuration.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Format\Chunker\Polynomial;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * The encrypted "config" file: repository version, random repository ID and the chunker
 * polynomial that makes chunk boundaries repository-specific.
 */
final readonly class Config {

	/** Version the plugin writes: no compression anywhere (ADR 0019). */
	public const int VERSION_1 = 1;
	public const int VERSION_2 = 2;

	/**
	 * Creates a configuration.
	 *
	 * @param int    $version            Repository version (1 or 2).
	 * @param string $id                 Repository ID, 64 hex digits.
	 * @param int    $chunker_polynomial Irreducible polynomial of degree 53 at most.
	 * @throws UnsupportedRepository If the version is unknown.
	 * @throws FormatException If the ID or the polynomial is invalid.
	 */
	public function __construct( public int $version, public string $id, public int $chunker_polynomial ) {
		if ( self::VERSION_1 !== $version && self::VERSION_2 !== $version ) {
			UnsupportedRepository::raise( 'Repository version is not supported.', [ 'version' => $version ] );
		}
		if ( '' === $id ) {
			throw new FormatException( 'Repository ID is empty.' );
		}
		$degree = Polynomial::degree( $chunker_polynomial );
		if ( $degree < 9 || $degree > 53 ) {
			throw new FormatException( 'Chunker polynomial has an unsupported degree.' );
		}
	}

	/**
	 * Creates the configuration for a new repository.
	 *
	 * @param RandomSource $random Randomness.
	 * @return self Version 1 configuration with a fresh ID and polynomial.
	 */
	public static function create( RandomSource $random ): self {
		return new self( self::VERSION_1, bin2hex( $random->bytes( 32 ) ), Polynomial::random( $random ) );
	}

	/**
	 * Parses the decrypted config file and verifies the polynomial like restic does.
	 *
	 * @param string $json JSON.
	 * @return self Configuration.
	 * @throws FormatException If fields are missing or the polynomial is not irreducible.
	 */
	public static function from_json( string $json ): self {
		$data       = GoJson::decode_object( $json );
		$version    = $data['version'] ?? null;
		$id         = $data['id'] ?? null;
		$polynomial = $data['chunker_polynomial'] ?? null;
		if ( ! is_int( $version ) || ! is_string( $id ) || ! is_string( $polynomial ) || 1 !== preg_match( '/^[0-9a-f]{1,16}$/', $polynomial ) ) {
			throw new FormatException( 'Repository configuration is incomplete.' );
		}

		$config = new self( $version, $id, (int) hexdec( $polynomial ) );
		if ( ! Polynomial::is_irreducible( $config->chunker_polynomial ) ) {
			throw new FormatException( 'Chunker polynomial is not irreducible.' );
		}
		return $config;
	}

	/**
	 * Serializes the configuration as restic does.
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		return GoJson::encode(
			[
				'version'            => $this->version,
				'id'                 => $this->id,
				'chunker_polynomial' => dechex( $this->chunker_polynomial ),
			]
		);
	}
}
