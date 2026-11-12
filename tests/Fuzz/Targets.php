<?php
/**
 * Everything that parses data from the repository server.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Fuzz;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Snapshot\Manifest;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Format\Lock;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Format\Pack\PackWriter;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Format\Unpacked;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * The fuzz targets: one per parser that reads server data. Seeds come from a repository that
 * restic 0.19.1 wrote (tests/Fixtures), so mutations start from realistic input.
 */
final class Targets {

	/**
	 * All targets.
	 *
	 * @return array<string, Target> Targets by name.
	 */
	public static function all(): array {
		$cipher  = Vectors::cipher();
		$packs   = self::packs( $cipher );
		$targets = [
			new Target(
				'snapshot-path',
				[ '/', '/site/wp-content/uploads', "/raw\xff/quoted\"name" ],
				false,
				static function ( string $input ): void {
					Path::parts( $input );
				}
			),
			new Target(
				'manifest-locator',
				[ Manifest::tag( '/private/spool/manifest.json' ) ],
				false,
				static function ( string $input ): void {
					Manifest::path( [ $input ] );
				}
			),
			new Target(
				'site-manifest',
				[ (string) file_get_contents( Vectors::fixtures() . '/site-manifest.json' ) ],
				true,
				static function ( string $input ): void {
					Manifest::decode( $input );
				}
			),
			new Target(
				'config',
				[ Vectors::restic( 'config.json' ) ],
				true,
				static function ( string $input ): void {
					Config::from_json( $input );
				}
			),
			new Target(
				'key-file',
				self::raw( 'keys/*' ),
				true,
				static function ( string $input ): void {
					KeyFile::from_json( $input );
				}
			),
			new Target(
				'master-key',
				[ Vectors::restic( 'masterkey.json' ) ],
				true,
				static function ( string $input ): void {
					MasterKey::from_json( $input );
				}
			),
			new Target(
				'snapshot',
				[ Vectors::restic( 'snapshot.json' ) ],
				true,
				static function ( string $input ): void {
					Snapshot::from_json( $input );
				}
			),
			new Target(
				'lock',
				[ ( new Lock( '2026-11-09T12:00:00.123456789+01:00', true, 'example.com', 'www-data', 4242, 33, 33 ) )->to_json() ],
				true,
				static function ( string $input ): void {
					Lock::from_json( $input );
				}
			),
			new Target(
				'index',
				[ Vectors::restic( 'index.json' ) ],
				true,
				static function ( string $input ): void {
					IndexFile::decode( $input );
				}
			),
			new Target(
				'tree',
				[ Vectors::restic( 'root-tree.bin' ), ...$packs['trees'] ],
				true,
				static function ( string $input ): void {
					TreeCodec::decode( $input );
				}
			),
			new Target(
				'json',
				[ Vectors::restic( 'snapshot.json' ), Vectors::restic( 'index.json' ) ],
				true,
				static function ( string $input ): void {
					GoJson::decode( $input );
				}
			),
			new Target(
				'unpacked',
				[ Vectors::restic( 'snapshot.json' ), "\x02" . 'not zstd', "\x01" ],
				false,
				static function ( string $input ): void {
					Unpacked::decode( $input, 1 );
					Unpacked::decode( $input, 2 );
				}
			),
			new Target(
				'pack-header',
				$packs['headers'],
				false,
				static function ( string $input ) use ( $cipher ): void {
					$sealed = $cipher->seal( $input );
					PackReader::read_header( $cipher, $sealed, PackWriter::MAX_HEADER_SIZE + strlen( $sealed ) + 4 );
				}
			),
			new Target(
				'pack-header-length',
				[ pack( 'V', 4096 ), pack( 'V', 0 ), "\xff\xff\xff\xff" ],
				false,
				static function ( string $input ): void {
					PackReader::header_length( $input, 16777216 );
				}
			),
			new Target(
				'sealed',
				[ ...self::raw( 'index/*' ), ...self::raw( 'snapshots/*' ), Vectors::restic( 'raw/config' ) ],
				false,
				static function ( string $input ) use ( $cipher ): void {
					$cipher->open( $input );
				}
			),
			new Target(
				'listing',
				[ '[{"name":"' . str_repeat( 'ab', 32 ) . '","size":42}]', '["' . str_repeat( 'cd', 32 ) . '"]', 'null', '[]' ],
				true,
				static function ( string $input ): void {
					foreach ( self::server( $input )->list( ObjectType::Snapshots ) as $name => $size ) {
						Id::from_hex( $name );
					}
				}
			),
			new Target(
				'go-quote',
				[ '"hello"', '"ü\n\x00\\\\ \"quoted\""', '"\xff\xfe"', '"日本"' ],
				false,
				static function ( string $input ): void {
					GoQuote::unquote( $input );
				}
			),
			new Target(
				'rfc3339',
				[ '2026-11-09T12:00:00.123456789+01:00', '2026-11-09T12:00:00Z', '0001-01-01T00:00:00Z' ],
				false,
				static function ( string $input ): void {
					Rfc3339::parse( $input );
				}
			),
			new Target(
				'duration',
				[ '1y2m3d4h', '30d', '0h' ],
				false,
				static function ( string $input ): void {
					Duration::parse( $input );
				}
			),
			new Target(
				'id',
				[ trim( Vectors::restic( 'snapshot.id' ) ) ],
				false,
				static function ( string $input ): void {
					Id::from_hex( $input );
				}
			),
		];

		$by_name = [];
		foreach ( $targets as $target ) {
			$by_name[ $target->name ] = $target;
		}
		return $by_name;
	}

	/**
	 * Raw repository files recorded from restic.
	 *
	 * @param string $pattern Glob below raw/.
	 * @return list<string> Contents.
	 */
	private static function raw( string $pattern ): array {
		return array_map( static fn ( string $file ): string => (string) file_get_contents( $file ), Vectors::restic_files( $pattern ) );
	}

	/**
	 * Plaintext pack headers and tree blobs from the recorded packs.
	 *
	 * @param Cipher $cipher Cipher with the recorded master key.
	 * @return array{headers: list<string>, trees: list<string>} Seeds.
	 */
	private static function packs( Cipher $cipher ): array {
		$seeds = [
			'headers' => [],
			'trees'   => [],
		];
		foreach ( self::raw( 'data/*/*' ) as $pack ) {
			$length             = PackReader::header_length( substr( $pack, -4 ), strlen( $pack ) );
			$sealed             = substr( $pack, -4 - $length, $length );
			$seeds['headers'][] = $cipher->open( $sealed );
			foreach ( PackReader::read_header( $cipher, $sealed, strlen( $pack ) ) as $entry ) {
				if ( BlobType::Tree === $entry->type ) {
					$seeds['trees'][] = PackReader::open_blob( $cipher, $entry, substr( $pack, $entry->offset, $entry->length ) );
				}
			}
		}
		return $seeds;
	}

	/**
	 * A REST backend whose server answers every request with the given body.
	 *
	 * @param string $body Response body.
	 * @return RestBackend Backend.
	 */
	private static function server( string $body ): RestBackend {
		$client = new class( $body ) implements HttpClient {
			/**
			 * Remembers the body.
			 *
			 * @param string $body Response body.
			 */
			public function __construct( private readonly string $body ) {
			}

			/**
			 * Answers with the body.
			 *
			 * @param string                $method  Method.
			 * @param string                $url     URL.
			 * @param array<string, string> $headers Headers.
			 * @param string                $body    Body.
			 * @param int                   $limit   Most bytes of response body.
			 * @return HttpResponse Response.
			 */
			public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0 ): HttpResponse {
				return new HttpResponse( 200, [ 'content-type' => 'application/vnd.x.restic.rest.v2' ], $this->body );
			}
		};
		return new RestBackend( $client, 'https://repo.example.com/site/', 'user', 'secret', 'fuzz', 0, static function ( int $ms ): void {} );
	}
}
