<?php
/**
 * Everything that parses data from the repository server, and the values of a WordPress
 * database that a database exchange reads.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Fuzz;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Backend\Http\StreamedBody;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Backend\ListingFile;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Database\Restore\CreateStatement;
use BackupsForRestic\Database\Restore\InsertStatement;
use BackupsForRestic\Database\Restore\Manifest as DumpManifest;
use BackupsForRestic\Database\Restore\PluginList;
use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Database\Restore\SerializedValue;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Restore\Selection;
use BackupsForRestic\Engine\Snapshot\Manifest;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
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
use BackupsForRestic\Tests\Support\WholeIndexDecoder;
use BackupsForRestic\Tests\Support\WordPressRoles;

/**
 * The fuzz targets: one per parser that reads server data. Seeds come from a repository that
 * restic 0.19.1 wrote (tests/Fixtures), so mutations start from realistic input.
 */
final class Targets {

	/**
	 * Target factories. Discovering PHPUnit data sets must not execute the parsers being tested.
	 *
	 * @return array<string, \Closure(): Target> Factories by target name.
	 */
	public static function factories(): array {
		return [
			'restore-selection'  => static fn (): Target => new Target(
				[ '/', "/a\n/a-b\n/a/child", "/raw-\xff\n/other", "/a\n/a", '/wp-content/uploads/' ],
				false,
				static function ( string $input ): void {
					$paths     = explode( "\n", $input );
					$selection = new Selection( $paths );
					if ( ( new Selection( $selection->paths ) )->paths !== $selection->paths ) {
						throw new \LogicException( 'Restore selection normalization must be idempotent.' );
					}
					foreach ( $paths as $path ) {
						$path    = '/' === $path ? '/' : rtrim( $path, '/' );
						$covered = false;
						foreach ( $selection->paths as $retained ) {
							if ( '/' === $retained || $path === $retained || str_starts_with( $path, $retained . '/' ) ) {
								$covered = true;
								break;
							}
						}
						if ( ! $covered ) {
							throw new \LogicException( 'A selected path must remain selected after normalization.' );
						}
					}
				}
			),
			'snapshot-path'      => static fn (): Target => new Target(
				[ '/', '/site/wp-content/uploads', "/raw\xff/quoted\"name" ],
				false,
				static function ( string $input ): void {
					Path::parts( $input );
				}
			),
			'manifest-locator'   => static fn (): Target => new Target(
				[ Manifest::tag( '/private/spool/manifest.json' ) ],
				false,
				static function ( string $input ): void {
					Manifest::path( [ $input ] );
				}
			),
			'site-manifest'      => static fn (): Target => new Target(
				[ (string) file_get_contents( Vectors::fixtures() . '/site-manifest.json' ) ],
				true,
				static function ( string $input ): void {
					Manifest::decode( $input );
				}
			),
			'dump-create'        => static fn (): Target => new Target(
				[
					"CREATE TABLE `wp_rows` (`id` int, `text` varchar(255) COMMENT 'comma, ; quoted text', `g` int AS (`id` * 2) STORED, KEY `k` (`text`(32)), CONSTRAINT `c` CHECK (`id` >= 0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
					'CREATE TABLE `wp_rows` (`g` int AS (42) STORED) ENGINE=MyISAM ROW_FORMAT=FIXED;',
				],
				false,
				static function ( string $input ): void {
					CreateStatement::parse( $input, 'wp_rows', 'shadow', 'owner' );
				}
			),
			'dump-insert'        => static fn (): Target => new Target(
				[ "INSERT INTO `wp_rows` (`id`,`text`) VALUES (0,'a ; comma, and \\n newline'),(-1,NULL),(2,0xff00);", 'INSERT INTO `wp_rows` () VALUES (),();' ],
				false,
				static function ( string $input ): void {
					InsertStatement::parse( $input, 'wp_rows', 'shadow' );
				}
			),
			'dump-manifest'      => static fn (): Target => new Target(
				[
					'{"format":"backups-for-restic-dump/1","created":"2026-01-01T00:00:00Z","server":"fixture","charset":"utf8mb4","time_zone":"+00:00","prefix":"wp_","tables":[{"name":"wp_rows","file":"010-wp_rows.sql","rows":4,"bytes":500}],"views":[]}',
					// How the dump read each table, and a name in another script that the name check accepts.
					'{"format":"backups-for-restic-dump/1","created":"2026-01-01T00:00:00Z","server":"fixture","charset":"utf8mb4","time_zone":"+00:00","prefix":"wp_","tables":[{"name":"wp_rows","file":"010-wp_rows.sql","rows":4,"bytes":500,"key":["id"],"snapshot":false,"restarts":0,"largest_statement":120},{"name":"wp_\u0430\u0440\u0445\u0438\u0432","file":"010-wp_%D0%B0%D1%80%D1%85%D0%B8%D0%B2.sql","rows":1,"bytes":200,"key":[],"snapshot":true,"restarts":1,"largest_statement":60}],"views":[]}',
				],
				true,
				static function ( string $input ): void {
					DumpManifest::parse( $input, 'wp_' );
				}
			),
			'config'             => static fn (): Target => new Target(
				[ Vectors::restic( 'config.json' ) ],
				true,
				static function ( string $input ): void {
					Config::from_json( $input );
				}
			),
			'key-file'           => static fn (): Target => new Target(
				self::raw( 'keys/*' ),
				true,
				static function ( string $input ): void {
					KeyFile::from_json( $input );
				}
			),
			'master-key'         => static fn (): Target => new Target(
				[ Vectors::restic( 'masterkey.json' ) ],
				true,
				static function ( string $input ): void {
					MasterKey::from_json( $input );
				}
			),
			'snapshot'           => static fn (): Target => new Target(
				[ Vectors::restic( 'snapshot.json' ) ],
				true,
				static function ( string $input ): void {
					Snapshot::from_json( $input );
				}
			),
			'lock'               => static fn (): Target => new Target(
				[ ( new Lock( '2026-11-09T12:00:00.123456789+01:00', true, 'example.com', 'www-data', 4242, 33, 33 ) )->to_json() ],
				true,
				static function ( string $input ): void {
					Lock::from_json( $input );
				}
			),
			'index'              => static fn (): Target => new Target(
				[
					Vectors::restic( 'index.json' ),
					// Shapes that the cutting into packs must not get wrong: a second "packs" member, an
					// escaped key, the key inside another member, brackets and quotes inside strings.
					'{"packs":[{"id":"' . str_repeat( 'ab', 32 ) . '","blobs":[]}],"pack\\u0073":[{"id":"' . str_repeat( 'cd', 32 ) . '","blobs":[{"id":"' . str_repeat( 'ef', 32 ) . '","type":"tree","offset":0,"length":9}]}]}',
					'{"supersedes":[{"packs":[{"x":"]}\\"{["}]}],"packs":[ {"id":"' . str_repeat( '01', 32 ) . '","note":"}]\\\\","blobs":[]} , {"id":"' . str_repeat( '02', 32 ) . '","blobs":[]} ]}',
				],
				true,
				static function ( string $input ): void {
					// Also a finding: the pack-by-pack decoder and the whole-document one disagree.
					WholeIndexDecoder::compare( $input );
				}
			),
			'tree'               => static fn (): Target => new Target(
				[ Vectors::restic( 'root-tree.bin' ), ...self::packs()['trees'] ],
				true,
				static function ( string $input ): void {
					$previous = null;
					foreach ( TreeCodec::decode( $input ) as $node ) {
						if ( null !== $previous && strcmp( $node->name, $previous ) <= 0 ) {
							throw new \LogicException( 'An accepted tree has unordered or duplicate names.' );
						}
						$previous = $node->name;
					}
				}
			),
			'json'               => static fn (): Target => new Target(
				[ Vectors::restic( 'snapshot.json' ), Vectors::restic( 'index.json' ) ],
				true,
				static function ( string $input ): void {
					GoJson::decode( $input );
				}
			),
			'unpacked'           => static fn (): Target => new Target(
				[ Vectors::restic( 'snapshot.json' ), "\x02" . 'not zstd', "\x01" ],
				false,
				static function ( string $input ): void {
					Unpacked::decode( $input, 1 );
					Unpacked::decode( $input, 2 );
				}
			),
			'pack-header'        => static fn (): Target => new Target(
				self::packs()['headers'],
				false,
				static function ( string $input ): void {
					$cipher = self::cipher();
					$sealed = $cipher->seal( $input );
					PackReader::read_header( $cipher, $sealed, PackWriter::MAX_HEADER_SIZE + strlen( $sealed ) + 4 );
				}
			),
			'pack-header-length' => static fn (): Target => new Target(
				[ pack( 'V', 4096 ), pack( 'V', 0 ), "\xff\xff\xff\xff" ],
				false,
				static function ( string $input ): void {
					PackReader::header_length( $input, 16777216 );
				}
			),
			'sealed'             => static fn (): Target => new Target(
				[ ...self::raw( 'index/*' ), ...self::raw( 'snapshots/*' ), Vectors::restic( 'raw/config' ) ],
				false,
				static function ( string $input ): void {
					self::cipher()->open( $input );
				}
			),
			'listing'            => static fn (): Target => new Target(
				[ '[{"name":"' . str_repeat( 'ab', 32 ) . '","size":42}]', '["' . str_repeat( 'cd', 32 ) . '"]', 'null', '[]' ],
				true,
				static function ( string $input ): void {
					foreach ( self::server( $input )->list( ObjectType::Snapshots ) as $name => $size ) {
						Id::from_hex( $name );
					}
				}
			),
			'listing-file'       => static fn (): Target => new Target(
				[ '[{"name":"' . str_repeat( 'ab', 32 ) . '","size":42}]', '["' . str_repeat( 'cd', 32 ) . '"]', 'null', '[]' ],
				true,
				static function ( string $input ): void {
					$file = tempnam( sys_get_temp_dir(), 'bfr-listing-fuzz-' );
					try {
						file_put_contents( $file, $input );
						$offset = 0;
						do {
							$batch = ListingFile::batch( $file, $offset, 1 );
							if ( $batch['offset'] <= $offset && ! $batch['done'] ) {
								throw new \LogicException( 'The parser did not advance.' );
							}
							$offset = $batch['offset'];
						} while ( ! $batch['done'] );
					} finally {
						unlink( $file );
					}
				}
			),
			'chunked-file'       => static fn (): Target => new Target(
				[ "2\r\n[]\r\n0\r\n\r\n", "4;ext=yes\r\nnull\r\n0\r\nDigest: ignored\r\n\r\n" ],
				false,
				static function ( string $input ): void {
					$file = tempnam( sys_get_temp_dir(), 'bfr-chunked-fuzz-' );
					try {
						file_put_contents( $file, $input );
						StreamedBody::decode_chunked( $file, 65536 );
					} finally {
						unlink( $file );
					}
				}
			),
			'go-quote'           => static fn (): Target => new Target(
				[ '"hello"', '"ü\n\x00\\\\ \"quoted\""', '"\xff\xfe"', '"日本"' ],
				false,
				static function ( string $input ): void {
					GoQuote::unquote( $input );
				}
			),
			'rfc3339'            => static fn (): Target => new Target(
				[ '2026-11-09T12:00:00.123456789+01:00', '2026-11-09T12:00:00Z', '0001-01-01T00:00:00Z' ],
				false,
				static function ( string $input ): void {
					Rfc3339::parse( $input );
				}
			),
			'duration'           => static fn (): Target => new Target(
				[ '1y2m3d4h', '30d', '0h' ],
				false,
				static function ( string $input ): void {
					Duration::parse( $input );
				}
			),
			'id'                 => static fn (): Target => new Target(
				[ trim( Vectors::restic( 'snapshot.id' ) ) ],
				false,
				static function ( string $input ): void {
					Id::from_hex( $input );
				}
			),
			// A database exchange reads these from a backup or from tables that other code writes.
			'wordpress-value'    => static fn (): Target => new Target(
				[
					serialize( WordPressRoles::defaults() ),
					serialize(
						[
							0 => 'a/a.php',
							3 => 'é.php',
						]
					),
					serialize(
						[
							'administrator' => true,
							'x'             => '0',
							5               => null,
						]
					),
				],
				false,
				static function ( string $input ): void {
					$value = SerializedValue::decode( $input, 3 );
					// Whatever is read, PHP reads the same: the exchange writes and WordPress reads it.
					if ( unserialize( $input, [ 'allowed_classes' => false ] ) !== $value ) {
						throw new \LogicException( 'The reader disagrees with unserialize().' );
					}
				}
			),
			'wordpress-roles'    => static fn (): Target => new Target(
				[ serialize( WordPressRoles::defaults() ), serialize( WordPressRoles::with( 'subscriber', [ 'manage_options' ] ) ) ],
				false,
				static function ( string $input ): void {
					$roles = RoleMap::parse( $input );
					$roles->changes( RoleMap::parse( serialize( WordPressRoles::defaults() ) ) );
					$roles->missing( [ 'administrator' => true ], WordPressRoles::DATABASE );
				}
			),
			'wordpress-plugins'  => static fn (): Target => new Target(
				[ serialize( [ 'akismet/akismet.php', 'backups-for-restic/backups-for-restic.php' ] ), serialize( [ 2 => 'hello.php' ] ) ],
				false,
				static function ( string $input ): void {
					$list = PluginList::parse( $input )->with( 'backups-for-restic/backups-for-restic.php' );
					if ( PluginList::parse( $list->stored() )->entries !== $list->entries ) {
						throw new \LogicException( 'A stored list of active plugins does not read back.' );
					}
				}
			),
		];
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
	 * The cipher for the recorded restic repository.
	 */
	private static function cipher(): Cipher {
		static $cipher   = null;
		return $cipher ??= Vectors::cipher();
	}

	/**
	 * Plaintext pack headers and tree blobs from the recorded packs.
	 *
	 * @return array{headers: list<string>, trees: list<string>} Seeds.
	 */
	private static function packs(): array {
		$cipher = self::cipher();
		$seeds  = [
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
			public function request( string $method, string $url, array $headers = [], string $body = '', int $limit = 0, ?string $file = null ): HttpResponse {
				if ( null !== $file ) {
					file_put_contents( $file, $this->body );
				}
				return new HttpResponse( 200, [ 'content-type' => 'application/vnd.x.restic.rest.v2' ], null === $file ? $this->body : '' );
			}
		};
		return new RestBackend( $client, 'https://repo.example.com/site/', 'user', 'secret', 'fuzz', 0, static function ( int $ms ): void {} );
	}
}
